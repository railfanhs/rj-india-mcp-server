<?php
declare(strict_types=1);

use RailJournal\Plugin\DataClient;
use RailJournal\Plugin\BusyError;
use RailJournal\Plugin\LightweightRuntime;
use RailJournal\Plugin\RpcError;
use RailJournal\Plugin\Server;
use RailJournal\Plugin\RequestLog;

require_once dirname(__DIR__, 2) . '/config/claude_mcp_request_log.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$callLog = null;
try {
    $callLog = new RequestLog(dirname(__DIR__, 3) . '/private/claude-mcp-logs', $_SERVER['REQUEST_METHOD'] ?? '');
    register_shutdown_function([$callLog, 'shutdown']);
} catch (RuntimeException $error) {
    error_log('Claude MCP request logging failed: ' . $error->getMessage());
    rj_plugin_error(-32603, 'Request logging is temporarily unavailable. Please retry later.', null, 503);
}
require_once __DIR__ . '/railjournal.php';

function rj_plugin_send(?array $body, int $status): never
{
    global $callLog;
    $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    try {
        $callLog?->finish($body, $status);
    } catch (RuntimeException $error) {
        error_log('Claude MCP request logging failed: ' . $error->getMessage());
        $status = 503;
        $body = ['jsonrpc' => '2.0', 'id' => $body['id'] ?? null, 'error' => [
            'code' => -32603, 'message' => 'Request logging is temporarily unavailable. Please retry later.',
        ]];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    http_response_code($status);
    if ($status !== 204) {
        header('Content-Length: ' . strlen($json));
    }
    if ($body !== null) {
        echo $json;
    }
    exit;
}

function rj_plugin_error(int $code, string $message, mixed $id = null, int $status = 400): never
{
    rj_plugin_send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, ['https://claude.ai', 'https://claude.com', 'https://railjournal.in'], true)) {
    rj_plugin_error(-32000, 'Origin is not allowed.', null, 403);
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    rj_plugin_send(null, 204);
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    rj_plugin_error(-32000, 'Use POST for MCP requests; this server does not offer an SSE stream.', null, 405);
}
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    rj_plugin_error(-32600, 'Content-Type must be application/json.', null, 415);
}
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
if (!str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
    rj_plugin_error(-32600, 'Accept must include application/json and text/event-stream.', null, 406);
}
if (isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'])
    && !in_array($_SERVER['HTTP_MCP_PROTOCOL_VERSION'], Server::PROTOCOLS, true)) {
    rj_plugin_error(-32600, 'Unsupported MCP protocol version.', null, 400);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    rj_plugin_error(-32600, 'Request body exceeds 16 KiB.', null, 413);
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
if ($raw === false || strlen($raw) > 16384) {
    rj_plugin_error(-32600, 'Request body exceeds 16 KiB.', null, 413);
}
try {
    $request = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    rj_plugin_error(-32700, 'Malformed JSON.');
}
if (!$request instanceof stdClass) {
    rj_plugin_error(-32600, 'Expected one JSON-RPC object; batch requests are not supported.');
}
$callLog->request($request, array_column(Server::tools(), 'name'));
// Opt-in submission trace: active only while this flag file exists. Records who is
// calling (client name/version, protocol, user-agent), never arguments, results or IPs.
$traceDir = dirname(__DIR__, 3) . '/private/claude-mcp-logs';
if (is_file($traceDir . '/submission-trace.enabled')) {
    $traceTools = array_column(Server::tools(), 'name');
    $traceParams = ($request->params ?? null) instanceof stdClass ? $request->params : new stdClass();
    $traceClient = ($traceParams->clientInfo ?? null) instanceof stdClass ? $traceParams->clientInfo : new stdClass();
    $traceName = $traceParams->name ?? null;
    $traceLine = json_encode([
        'timestamp_ist' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format(DATE_ATOM),
        'method' => is_string($request->method ?? null) ? substr($request->method, 0, 64) : null,
        'tool' => is_string($traceName) && in_array($traceName, $traceTools, true) ? $traceName : null,
        'client_name' => is_string($traceClient->name ?? null) ? substr($traceClient->name, 0, 80) : null,
        'client_version' => is_string($traceClient->version ?? null) ? substr($traceClient->version, 0, 40) : null,
        'protocol_header' => substr((string) ($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''), 0, 20) ?: null,
        'protocol_requested' => is_string($traceParams->protocolVersion ?? null) ? substr($traceParams->protocolVersion, 0, 20) : null,
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200) ?: null,
        'origin' => substr((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), 0, 80) ?: null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($traceLine !== false) {
        @file_put_contents($traceDir . '/submission-trace.txt', $traceLine . "\n", FILE_APPEND | LOCK_EX);
        @chmod($traceDir . '/submission-trace.txt', 0600);
    }
}
$id = $request->id ?? null;
if (!is_int($id) && !is_string($id)) {
    $id = null;
}
try {
    $root = dirname(__DIR__, 2);
    $private = dirname($root) . '/private';
    $runtime = new LightweightRuntime($private);
    $runtime->admit($_SERVER['REMOTE_ADDR'] ?? '');
    $client = new DataClient($private);
    $server = new Server([$client, 'get'], $runtime);
    $response = $server->dispatch($request);
    rj_plugin_send($response, $response === null ? 202 : 200);
} catch (BusyError $error) {
    header('Retry-After: ' . $error->retryAfter);
    rj_plugin_error($error->getCode(), $error->getMessage(), $id, 429);
} catch (RpcError $error) {
    rj_plugin_error($error->getCode(), $error->getMessage(), $id);
} catch (Throwable $error) {
    error_log('RailJournal plugin server failure: ' . get_class($error) . ': ' . $error->getMessage());
    rj_plugin_error(-32603, 'RailJournal is temporarily unavailable. Please retry later.', $id, 503);
}
