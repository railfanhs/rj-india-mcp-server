# RailJournal India MCP server

Server-side code for the RailJournal India MCP endpoint used by the
[rj-india-plugin](https://github.com/railfanhs/rj-india-plugin) Claude plugin.
Endpoint: `https://railjournal.in/AI_plugins/Claude/mcp.php`

This is a snapshot of the deployed files, not a standalone app. It depends on
RailJournal site code that is not in this repo (`config/rj_realtime_cache.php`,
`config/train_services/*`, `config/database.php`).

## Layout

- `server/mcp.php` - HTTP entrypoint, JSON-RPC validation, request logging
- `server/railjournal.php` - tool definitions and data access
- `server/lightweight.php` - low-load runtime (global limits, cooldown)
- `server/privacy.html` - privacy policy
- `config/claude_mcp_request_log.php` - operational request log (`calls.txt`)

## Tools

`search_trains`, `get_train_info`, `get_train_running_status`, `search_stations`,
`get_station_info`, `server_status`.

Running status is served from the website cache and falls back to the live
`trainxntes` table. `server_status` is a no-input health check. A new tool must
be added to both `Server::tools()` and the allowlist in `LightweightRuntime::tool()`.

## Logging

- `private/claude-mcp-logs/calls.txt` records time, HTTP method, MCP method, tool name,
  status and duration for every request. No arguments, results or IPs.
- `private/claude-mcp-logs/submission-trace.txt` additionally records client name/version,
  protocol version, user-agent and origin. It is written only while
  `private/claude-mcp-logs/submission-trace.enabled` exists; delete that file to stop it.
  Update `privacy.html` before leaving it on.
