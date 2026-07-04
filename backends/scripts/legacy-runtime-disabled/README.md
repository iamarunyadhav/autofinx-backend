Legacy Runtime Scripts
======================

These scripts used to start queue workers, Future Trade live-feed, scanner ticks,
user-stream loops, or heartbeat cron jobs directly with `nohup` / cron.

They are intentionally disabled from the project root because production runtime
ownership is now centralized:

- Supervisor owns the actual long-running workers.
- `autofinx-runtime-master.sh` is the single guardian that starts missing
  Supervisor programs, removes duplicate manual processes, and restarts stale
  live-feed safely.
- Cron may run maintenance only; it must not start workers or websocket loops.

Do not run these scripts for production runtime recovery. Use:

```bash
APP_DIR="$(pwd)" PHP=/usr/local/bin/ea-php82 ./autofinx-runtime-master.sh status
APP_DIR="$(pwd)" PHP=/usr/local/bin/ea-php82 ./autofinx-runtime-master.sh once
```
