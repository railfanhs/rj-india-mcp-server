# RailJournal for Claude

Look up Indian Railways trains and stations from [RailJournal](https://railjournal.in) inside Claude.

## What it does

The plugin connects Claude to the RailJournal MCP server, a read-only service with six tools:

| Tool | Purpose |
| --- | --- |
| `search_trains` | Find a train by name or partial number |
| `get_train_info` | Train profile, operating days, classes and timetable |
| `search_stations` | Find a station by name or code |
| `get_station_info` | Station name, location, zone, division and facts |
| `server_status` | Health check: server version and available tools |
| `get_train_running_status` | Live running status from the RailJournal NTES feed |

It also ships two skills that tell Claude how to use the tools well: `find-train-or-station` and `train-status`.

## Example prompts

- "What is train 12951 and which days does it run?"
- "Find the station code for Mumbai Central and tell me about it."
- "How is 12301 running today?"

## Data and privacy

All data comes from RailJournal's own database. No account or login is needed, and the plugin does not read or write personal data. The plugin only asks for train numbers, station names or codes and dates. See the [privacy policy](https://railjournal.in/AI_plugins/Claude/privacy.html).

## Limits

Running status is only available for trains currently reported by NTES. Timetable platforms are scheduled, not live. The service is rate limited, so Claude sends calls one at a time. The plugin does not book tickets or check seat availability.

## Support

Contact railjournalindia@gmail.com.

## License

MIT

---

# Server code

Server-side code for the MCP endpoint behind this plugin.
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

## Tools (server details)

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
