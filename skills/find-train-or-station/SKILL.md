---
name: find-train-or-station
description: Look up Indian Railways trains and stations by name or number, and show a train's timetable, operating days, classes or a station's facts. Use when the user asks about a train, its schedule or stops, or a station code.
---

# Find a train or station

Use the RailJournal tools to answer questions about Indian trains and stations.

1. If the user gives a name or partial number, call `search_trains`. If they name a station, call `search_stations`.
2. If several results match, show the candidates and ask the user to choose. Never guess between matches.
3. For a train, call `get_train_info` with the 4 to 6 digit train number. Summarise the route, operating days, travel classes and the stop-by-stop timetable. Platforms in the timetable are scheduled, not live.
4. For a station, call `get_station_info` with the station code. Report name, zone, division and the facts returned. It does not include live platform assignments.

Rules:
- Call tools one at a time and do not loop on failures.
- Dates and times are in Asia/Kolkata.
- Only report what the tools return. Say so plainly when something is not available.
- Include the source link from each result.
