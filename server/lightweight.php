<?php
declare(strict_types=1);

namespace RailJournal\Plugin;

use Closure;
use RuntimeException;

final class BusyError extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message, -32000);
    }
}

final class LightweightRuntime
{
    // Aggregate request counters only: no per-client or per-request data is stored.
    public const COLD_PER_MINUTE = 30;
    public const COLD_PER_HOUR = 600;
    private readonly string $directory;
    private readonly Closure $clock;

    public function __construct(private readonly string $privateDirectory, ?callable $clock = null)
    {
        $this->directory = $privateDirectory . '/claude-lightweight';
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the private MCP runtime directory.');
        }
        if (!chmod($this->directory, 0700)) {
            throw new RuntimeException('Cannot protect the private MCP runtime directory.');
        }
    }

    /** @return resource */
    private function lock(string $name, float $waitSeconds = 0.0)
    {
        $path = $this->directory . '/' . $name;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the MCP resource guard.');
        }
        if (!chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('Cannot protect the MCP resource guard.');
        }
        $deadline = microtime(true) + $waitSeconds;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new BusyError('RailJournal is busy. Send tool calls sequentially and retry later.', 5);
            }
            usleep(100000);
        }
        return $handle;
    }

    private function state(callable $change): void
    {
        $handle = $this->lock('limits.lock');
        try {
            $path = $this->directory . '/limits.json';
            $raw = is_file($path) ? file_get_contents($path, false, null, 0, 65537) : '';
            if ($raw === false || strlen($raw) > 65536) {
                throw new RuntimeException('Invalid MCP resource-limit state.');
            }
            $state = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new RuntimeException('Invalid MCP resource-limit state.');
            }
            $change($state, ($this->clock)());
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (strlen($json) > 65536) {
                throw new RuntimeException('Cannot persist MCP resource limits.');
            }
            $this->write($path, $json);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function write(string $path, string $json): void
    {
        $temporary = $path . '.new';
        try {
            if (file_put_contents($temporary, $json) !== strlen($json)
                || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot persist the private MCP runtime state.');
            }
        } finally {
            if (is_file($temporary) && !unlink($temporary)) {
                error_log('RailJournal plugin could not remove a runtime temporary file.');
            }
        }
    }

    private static function counters(mixed $row): array
    {
        if (!is_array($row)) {
            throw new RuntimeException('Invalid MCP limit counters.');
        }
        foreach (['hour', 'minute', 'hours', 'minutes'] as $field) {
            if (!is_int($row[$field] ?? null) || $row[$field] < 0) {
                throw new RuntimeException('Invalid MCP limit counters.');
            }
        }
        return $row;
    }

    /**
     * Kept for the caller's sake. Client addresses are deliberately not read,
     * hashed or stored; overload protection uses aggregate counters only.
     */
    public function admit(string $ip): void
    {
    }

    private function coldBudget(): void
    {
        $this->state(static function (array &$state, int $now): void {
            unset($state['clients']);
            $row = self::counters($state['cold'] ?? ['hour' => intdiv($now, 3600), 'minute' => intdiv($now, 60), 'hours' => 0, 'minutes' => 0]);
            if ($row['hour'] !== intdiv($now, 3600)) {
                $row['hour'] = intdiv($now, 3600);
                $row['hours'] = 0;
            }
            if ($row['minute'] !== intdiv($now, 60)) {
                $row['minute'] = intdiv($now, 60);
                $row['minutes'] = 0;
            }
            if (array_key_exists('cooldown', $state) && (!is_int($state['cooldown']) || $state['cooldown'] < 0)) {
                throw new RuntimeException('Invalid MCP cooldown state.');
            }
            if (($state['cooldown'] ?? 0) > $now) {
                throw new BusyError('RailJournal backend is cooling down after an error. Retry later.', $state['cooldown'] - $now);
            }
            if ($row['minutes'] >= self::COLD_PER_MINUTE || $row['hours'] >= self::COLD_PER_HOUR) {
                throw new BusyError('RailJournal low-load backend budget is exhausted.', $row['hours'] >= self::COLD_PER_HOUR ? 3600 - $now % 3600 : 60 - $now % 60);
            }
            $row['hours']++;
            $row['minutes']++;
            $state['cold'] = $row;
        });
    }

    public function tool(string $name, array $input, callable $fetch): array
    {
        if (!in_array($name, [
            'search_trains', 'search_stations', 'get_train_info',
            'get_station_info', 'get_train_running_status', 'server_status', 'find_trains_between_stations',
        ], true)) {
            throw new RuntimeException('Unknown MCP tool.');
        }
        // Nothing about the request or its result is written to disk.
        $handle = $this->lock('backend.lock', 5.0);
        try {
            $this->coldBudget();
            $data = $fetch();
            if (!is_array($data)) {
                throw new RuntimeException('Invalid MCP backend result.');
            }
            return $data;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
