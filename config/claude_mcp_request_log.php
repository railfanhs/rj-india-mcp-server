<?php
declare(strict_types=1);

namespace RailJournal\Plugin;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use stdClass;

final class RequestLog
{
    private $handle;
    private readonly int $started;
    private readonly string $timestamp;
    private ?string $method = null;
    private ?string $tool = null;
    private bool $finished = false;
    private readonly string $httpMethod;

    public function __construct(string $directory, string $httpMethod)
    {
        $this->started = hrtime(true);
        $this->timestamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DATE_ATOM);
        $this->httpMethod = in_array($httpMethod, ['GET', 'POST', 'OPTIONS', 'HEAD', 'PUT', 'PATCH', 'DELETE'], true)
            ? $httpMethod : 'unknown';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
            || !@chmod($directory, 0700)) {
            throw new RuntimeException('Cannot protect the Claude MCP log directory.');
        }
        $path = $directory . '/calls.txt';
        if (is_link($path)) throw new RuntimeException('Invalid Claude MCP log file.');
        $this->handle = @fopen($path, 'ab');
        if ($this->handle === false || !@chmod($path, 0600)) {
            throw new RuntimeException('Cannot open the Claude MCP log file.');
        }
    }

    public function request(stdClass $request, array $toolNames): void
    {
        $method = $request->method ?? null;
        if (is_string($method)) {
            $this->method = in_array($method, ['initialize', 'ping', 'tools/list', 'tools/call', 'notifications/initialized', 'notifications/cancelled'], true)
                ? $method : (str_starts_with($method, 'notifications/') ? 'notifications/other' : 'unknown');
        }
        if ($this->method === 'tools/call') {
            $name = ($request->params ?? null) instanceof stdClass ? ($request->params->name ?? null) : null;
            $this->tool = is_string($name) && in_array($name, $toolNames, true) ? $name : 'unknown';
        }
    }

    public function finish(?array $body, int $status, bool $interrupted = false): void
    {
        if ($this->finished) return;
        $failed = $status >= 400 || isset($body['error']) || ($body['result']['isError'] ?? false) === true;
        $entry = [
            'timestamp_ist' => $this->timestamp,
            'http_method' => $this->httpMethod,
            'method' => $this->method,
            'tool' => $this->tool,
            'http_status' => $status,
            'result' => $interrupted ? 'interrupted' : ($failed ? 'error' : ($status === 202 ? 'notification' : ($status === 204 ? 'preflight' : 'success'))),
            'rpc_error_code' => is_int($body['error']['code'] ?? null) ? $body['error']['code'] : null,
            'duration_ms' => round((hrtime(true) - $this->started) / 1e6, 2),
        ];
        $line = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (!flock($this->handle, LOCK_EX)) throw new RuntimeException('Cannot lock the Claude MCP log.');
        try {
            $offset = 0;
            while ($offset < strlen($line)) {
                $written = @fwrite($this->handle, substr($line, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Cannot write the Claude MCP log.');
                $offset += $written;
            }
            if (!fflush($this->handle)) throw new RuntimeException('Cannot flush the Claude MCP log.');
            $this->finished = true;
        } finally {
            flock($this->handle, LOCK_UN);
        }
    }

    public function shutdown(): void
    {
        if ($this->finished) return;
        $status = http_response_code();
        try {
            $this->finish(null, is_int($status) && $status >= 400 ? $status : 500, true);
        } catch (RuntimeException $error) {
            error_log('Claude MCP shutdown request logging failed: ' . $error->getMessage());
        }
    }
}
