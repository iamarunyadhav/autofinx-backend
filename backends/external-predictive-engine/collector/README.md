# AutoFinX market-data collector (V6 Track B)

Records PUBLIC Binance USD-M futures market data for research. No API keys, no orders, no production server or DB.
Full format/volume description: `docs/ml-engine-v1/COLLECTOR_V1.md`.

## Setup (once)
```
python -m venv D:\autofinx-collector\venv
D:\autofinx-collector\venv\Scripts\python.exe -m pip install websockets pytest
```
Only `websockets` is needed at runtime (pytest for tests). Paths live in `collector.config.json` (`data_dir`,
relative paths resolve against the config file); override the file with `--config` or env `AUTOFINX_COLLECTOR_CONFIG`.
Linux server: see `deploy/linux/README_DEPLOY.md` (D-052; `~` and `$VAR` work in `data_dir`; `stop --wait N`; clean stop on SIGTERM).

## Commands (run from `external-predictive-engine/`)
```
PY=D:\autofinx-collector\venv\Scripts\python.exe
%PY% -m collector status          # RUNNING?, heartbeat age, per-stream counts + last message age, last gap
%PY% -m collector stop            # writes the STOP file; clean shutdown within a few seconds
%PY% -m collector report [--day YYYY-MM-DD]
%PY% -m collector start [--duration SECONDS]   # foreground (smoke tests)
powershell -File collector\start-collector.ps1  # detached, hidden, below-normal priority; safe to repeat
%PY% -m pytest collector/tests -q               # 15 tests, no network
```
Restart = `stop`, wait for `status` to say NOT RUNNING, then `start-collector.ps1`.
After a crash/reboot just run `start-collector.ps1` (a stale STOP/lock is cleaned if no live instance exists).
Optional boot start: `register-boot-task.ps1` (elevated; NOT registered by the agent). Remove with `Unregister-ScheduledTask -TaskName AutoFinxCollector`.

## Owner checklist (the collector never changes Windows power settings)
- Disable sleep/hibernate while plugged in (Settings > System > Power), keep the PC on AC power. Any sleep, reboot or
  network loss becomes a hole in the data; it is detected and written to `gaps.jsonl`, never hidden.
- Keep D: free space above 20 GB. Below that the collector pauses writing and logs why (`disk_guard_stop`).
- Keep the PC clock synced (Settings > Time & language > Sync now). Each record has local receive time AND exchange event time;
  `clock.jsonl` logs the offset vs Binance every 5 min so timestamps can be corrected afterwards.

## Integrity features
per-stream counters + last-message age; silent-stream alarm (the silent-drop failure) that also forces a reconnect; reconnect with
backoff 1 -> 60 s; `gaps.jsonl` (append-only: starts/stops, disconnects, silences, missing aggTrade ids, disk guard, clock warnings, fetch failures);
aggTrade id continuity per symbol; clock offset + RTT check every 300 s; `heartbeat.json` every 10 s; daily JSON+MD report in `reports/`;
STOP file; single-instance lock.
