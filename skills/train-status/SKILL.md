---
name: train-status
description: Check the reported running status of an Indian train for a given origin departure date, such as delay and last known location. Use when the user asks where a train is, whether it is late, or how it is running.
---

# Train running status

1. Make sure you have a train number. If the user gave a name, resolve it first with `search_trains` and confirm the match.
2. Ask for, or infer from context, the date the train departed from its origin (YYYY-MM-DD, Asia/Kolkata). Today's date is fine only if the user means a train that left its origin today.
3. Call `get_train_running_status` with the train number and `origin_departure_date`.
4. Summarise the current position, delay and next stops. Starred times are estimates; say so.

Important:
- The tool returns the latest live NTES position for the run (last station, event, time, delay). If no status is reported for that train and date, it returns an error. Tell the user the status is not available right now (the train may not have started or may not run that day). Do not retry in a loop and do not invent a status.
- Check `observation_updated_at` and `freshness` in the result to say how recent the position is. The timetable is not included; use `get_train_info` for stops.
- Retrieval time is not observation time. Mention when the data was retrieved.
- Include the source link from the result.
