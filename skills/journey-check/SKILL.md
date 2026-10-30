---
name: journey-check
description: Find Indian trains that run between two stations, or check one train for a trip, and show departure, arrival, travel time, operating days and, if asked, live delay. Use when the user asks about travelling from one station to another.
---

# Journey check

Use the RailJournal tools to find trains between two stations and check a trip on a specific train.

1. Resolve both stations with `search_stations`. If a name matches several stations, show the candidates and ask the user to choose. Never guess.
2. If the user did not name a train, call `find_trains_between_stations` with the two station codes (origin first). Show the trains with departure, arrival, travel time and operating days. If the result says `truncated`, tell the user only the first 25 are shown. An empty list means none was found in RailJournal data, not that none exists; also try the nearby station codes the user may mean (for example New Delhi and Hazrat Nizamuddin are different stations).
3. If the user named a train, resolve it with `search_trains` and confirm the match, then call `get_train_info`. Check that it stops at both stations and that the origin comes before the destination in stop order. If it does not, say so.
4. Report the departure from the origin, the arrival at the destination, the day offset if the trip crosses midnight, the travel time, the operating days and the travel classes (use `get_train_info` when classes are needed). Platforms are scheduled, not live.
5. Only if the user asks how it is running today, call `get_train_running_status`. It needs the date the train left its own first station, which may differ from the date the user boards. Report the delay and last known location, and note that starred times are estimates.

Rules:
- Call tools one at a time and do not loop on failures.
- Dates and times are in Asia/Kolkata.
- Only report what the tools return. Say so plainly when something is not available.
- Do not give seat availability, fares or booking help; the plugin has no such data.
- Include the source link from each result.
