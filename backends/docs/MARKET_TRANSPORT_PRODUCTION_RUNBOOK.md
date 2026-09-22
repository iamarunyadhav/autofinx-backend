# Market transport production runbook

This release has no database migration. It changes only the bounded websocket
transport runtime. The market-feed owner remains the existing Supervisor
program `autofinx-ratio-feed`; do not run a second `market:ws-consume` command.

## One-command deployment

From the deployed backend directory, as the application user that can run
`supervisorctl`:

```bash
APP_DIR="$PWD" PHP_BIN="/opt/cpanel/ea-php82/root/usr/bin/php" \
bash scripts/autofinx-market-transport-deploy.sh --apply --test
```

The script backs up `.env` under `storage/app/deploy-backups`, writes only the
eight transport/runtime ownership keys in
`deploy/autofinx-market-transport.env.example`, rebuilds config, signals queue
workers, and restarts only `autofinx-ratio-feed`. It never runs migrations,
edits crontab, starts a manual worker, or restarts Future Trade's separate feed.

Run without `--apply` for a preflight-only dry run.

## Supervisor and cron ownership

Keep one process owner for each role:

| Role | Owner |
| --- | --- |
| Ratio-market websocket | `autofinx-ratio-feed` Supervisor program |
| Laravel scheduler | `autofinx-scheduler` Supervisor program |
| Scheduler market consumers | disabled with `AUTOFINX_SCHEDULER_MARKET_CONSUMERS=false` |

If `autofinx-ratio-feed` is missing or is not running `market:ws-consume`, stop
and install/review `deploy/autofinx-ratio-feed.supervisor.conf.example` through
your server's Supervisor include directory. Do not install it beside an
existing owner. Cron must not run `market:ws-consume`, `schedule:work`, or
duplicate queue workers.

## Evidence bundle and analysis

The deployment ends by running the read-only report:

```bash
APP_DIR="$PWD" PHP_BIN="/opt/cpanel/ea-php82/root/usr/bin/php" \
bash scripts/autofinx-market-transport-report.sh
```

It creates `storage/logs/diagnostics/market-feed-<UTC>.tar.gz` (mode 600) with
Supervisor status/tails, feed telemetry, schedule ownership, runtime audit,
transport benchmark, and `SUMMARY.txt`. Download that archive through your
normal authenticated server transfer method; do not make `storage/logs`
web-accessible.

`FAIL` in `SUMMARY.txt` means live trading must remain disabled. `WARN` needs
review, especially reconnects, stale quotes, empty candle cache, or Supervisor
BACKOFF/FATAL status.

## Rollback

Restore the `.env` backup created by the script, deploy the previous code
revision, run `php artisan optimize:clear && php artisan config:cache`, then
restart only `autofinx-ratio-feed`. No migration rollback is needed.
