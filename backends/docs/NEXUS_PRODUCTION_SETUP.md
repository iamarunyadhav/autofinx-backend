# Nexus production setup (Phase 0)

`scripts/nexus-production-setup.sh` installs Nexus on the production host in a
safe **Phase 0** configuration. That means Nexus is enabled at
`AUTOFINX_NEXUS_AUTONOMY_LEVEL=0`: local observations, reports and the knowledge
export only. There is no external source fetching and no LLM path. It runs two
isolated queue workers, and exactly one trigger fires `nexus:run-cycle` every
15 minutes. Run it as root. The script switches to `zcadmin` itself for every
artisan, tinker, git and cron-verification command, using a clean environment.

Background: `docs/NEXUS_OPERATIONS.md`, `agent/AUTONOMY_POLICY.md`, `agent/RISK_POLICY.md`.

## Commands

```sh
cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends
# (deploy/pull the code yourself first -- the script never touches git)

sudo bash scripts/nexus-production-setup.sh            # 1. DRY RUN: preflight + full plan, changes nothing
sudo bash scripts/nexus-production-setup.sh --apply    # 2. apply (type APPLY to confirm)
sudo bash scripts/nexus-production-setup.sh --verify   # 3. ~25 min later: proves the 15-min trigger fired
```

The dry run exits non-zero if anything would block `--apply`. Fix what it
reports and run it again. Its only write is its own log file,
`storage/logs/nexus-production-setup-<mode>-<UTC timestamp>.log`.

Optional flags:
- `--with-wait-shadows` also runs the owner's pending
  `2026_09_30_000000_create_quick_trade_wait_shadows` migration. It only does so
  if the file matches the audited sha256 and has `reference_at` nullable (the
  MariaDB 1067 fix).
- `--workspace <uuid>` sets the workspace for the verification cycle. The default
  is the oldest workspace.
- `--schedule auto|scheduler|cron` sets who fires the cycle. The default is `auto`.
- `--accept-config-drift` is explained under the FAIL entries below.
- `--skip-db-backup` is for passwords that can't be written into a MySQL option
  file.
- `--settle-seconds N` sets the worker stability window (default 75).
- `--cycle-timeout N` sets how long to wait for the verification cycle.
- `--yes` skips the confirmation prompt.

## What `--apply` does, in order (each step verifies itself)

| # | Step | Verification |
|---|------|--------------|
| 0 | Preflight: runs as root, checks that `zcadmin` exists, PHP >= 8.2 (prefers `/usr/local/bin/ea-php82`), `.env` is owned by `zcadmin:zcadmin` and readable by `zcadmin`, storage/bootstrap dirs are writable by `zcadmin`, artisan and the Nexus commands exist, and DB/Redis/queue are reachable through `artisan tinker` as `zcadmin` | Any FAIL aborts before anything changes |
| 1 | Backups to `storage/app/deploy-backups/nexus-setup-<ts>/`, owned by `zcadmin`, dir 700, files 600: `.env`, `bootstrap/cache/config.php`, the zcadmin crontab, any previous Supervisor file, and a `mysqldump` of `migrations` (+ nexus tables if present). DB credentials come from Laravel's config and go into a temporary 600 `--defaults-extra-file`. They are never on the command line, and `HOME` is pointed away from `/root/.my.cnf` | Copies compared; the dump must end with `Dump completed`; SHA256SUMS written |
| 2 | Migration, run **before** Nexus is enabled: only `2026_09_28_000000_create_nexus_tables.php`, via `--path`, as `zcadmin` | sha256 pin; static lint (no NOT NULL timestamp); `--pretend` SQL lint; FK targets `workspaces.id`/`users.id` are `char(36)` with the same charset/collation and InnoDB; the 4 tables and their columns exist afterwards. If the migration fails, Nexus-only tables that this run created and that are still empty are dropped (MySQL DDL is not transactional) and apply stops |
| 3 | `.env`: sets exactly `AUTOFINX_NEXUS_ENABLED=true`, `AUTOFINX_NEXUS_AUTONOMY_LEVEL=0`, `AUTOFINX_NEXUS_QUEUE_CONNECTION=nexus`, `AUTOFINX_NEXUS_LLM_ENABLED=false`. It replaces the lines if present, otherwise appends them under a marker comment. The write is an atomic rename that keeps the original owner and mode. Then `config:clear` + `config:cache` as `zcadmin`, but only if a config cache existed before | `zcadmin` can read `.env`; the app still resolves the same DB driver (not the sqlite fallback) and DB/Redis ping OK; effective config is enabled/0/nexus/LLM off; **no non-Nexus config key changed** in the cache. Any failure restores the `.env` backup automatically |
| 4 | Supervisor: `/etc/supervisord.d/autofinx-nexus.ini` gets `autofinx-nexus-research` (`agent_research`) and `autofinx-nexus-training` (`agent_training`), both `queue:work nexus`, `user=zcadmin`, logs in `/var/log/autofinx/`, `stopasgroup`/`killasgroup`, `stopwaitsecs=150`. Then `supervisorctl reread` and `supervisorctl update <those two only>` | The include glob covers the file; both programs RUNNING as `zcadmin` with the expected args; same PID after a 75 s window; the live command lines are re-checked against every watchdog kill/dedupe pattern |
| 5 | 15-minute trigger (see below) | Scheduler mode: `schedule:list` shows `nexus:run-cycle` and `autofinx-scheduler` is RUNNING. Cron mode: exactly one managed line, other cron lines byte-identical to the backup, and the exact cron command is run once as `zcadmin` and must log `Nexus cycles requested` |
| 6 | Post-verify: `nexus:doctor` and `nexus:status` (enabled, database_ready, retry_after_safe, connection=nexus, autonomy 0, live-trading/risk-override/deploy all false), one cycle dispatched, `nexus_runs` polled until `completed`/`partial` | Every existing Supervisor program that was RUNNING before must still be RUNNING |

The script is safe to re-run. Unchanged steps report `SKIP`. The first backup is
the one rollback restores, and later runs never overwrite it.

## Who fires `nexus:run-cycle` every 15 minutes

`routes/console.php` already registers
`nexus:run-cycle ->everyFifteenMinutes()->onOneServer()->withoutOverlapping(5)`
whenever Nexus is enabled. `autofinx-runtime-master.sh` and the DoctorCleanup
notes identify Supervisor's `autofinx-scheduler` as the `schedule:work` owner.
However, its `[program]` block isn't in this repo, so the script checks at runtime:

- It reads the scheduler's `command=`, its live process tree, and a predicted
  `schedule:list` with Nexus enabled.
- **If** `autofinx-scheduler` is RUNNING, runs `schedule:work`/`schedule:run`,
  and the schedule lists the command, then the scheduler is the single owner and
  **no cron is added**.
- **Otherwise** it adds one flock'd `zcadmin` cron line between
  `# >>> autofinx-nexus-cycle` markers, at minutes 7/22/37/52, logging to
  `storage/logs/nexus-cron.log`. `schedule:run` stays disabled.

Double runs are impossible either way. Each workspace gets at most one
`nexus_runs` row per 900-second slot (unique `workspace_id`+`slot`, plus a
dispatch lock), and the cron offsets fall inside the same slot as the scheduler
ticks.

## Reading the summary

The script prints one row per check, with one of these statuses:
- `PASS`: verified.
- `WARN`: not blocking, but read it.
- `FAIL`: blocking.
- `INFO` / `PLAN` / `SKIP`: facts, dry-run actions, and steps with nothing to do.

`RESULT: PASS` with exit 0 means every verification held. The full output,
including the pretend SQL and the generated Supervisor file, is in the log.

Common FAILs:
- **`.env owner`**: fix with `chown zcadmin:zcadmin .env && chmod 600 .env`,
  then run again.
- **`config drift`**: the live config cache differs from `.env` in non-Nexus
  keys, so re-caching would activate those edits. Review the listed key names.
  Pass `--accept-config-drift` only if those changes are intended.
- **`FK target ... .id`**: the collation or engine differs, so creating the
  table would fail with errno 150. Don't force it.
- **`kill-pattern collision`**: a watchdog would kill the workers. Report it
  rather than editing the watchdog.
- **`<program> stable`**: check `/var/log/autofinx/nexus-*.err.log`.

## Rollback

```sh
sudo bash scripts/nexus-production-setup.sh --rollback                        # cron block, .env keys, workers
sudo bash scripts/nexus-production-setup.sh --rollback --rollback-migration   # + drop the Nexus tables (dumped first)
```

Rollback does these steps in order:

1. Removes the managed cron block.
2. Restores `.env`. If the file is unchanged since apply, the backup is restored
   byte-for-byte. Otherwise only the 4 Nexus keys are restored from the backup,
   so your other edits are kept.
3. Rebuilds config the way it was before.
4. Stops the Nexus programs, then removes or restores the Supervisor file with a
   targeted update.

`--rollback-migration` dumps the `nexus_*` tables first. It runs
`migrate:rollback --path=<nexus migration> --batch=<batch this script
recorded>`, and refuses if the script didn't run that migration itself. The
wait-shadows migration is never rolled back.

State lives in `/var/lib/autofinx-nexus-setup/state` (root-only, 600). It is
parsed as data, never sourced.

## Deliberately NOT done

- No LLM key, URL or model is written. `AUTOFINX_NEXUS_LLM_ENABLED` is forced to
  `false`. Any existing key is left untouched and reported.
- No autonomy above 0. Moving to level 1 (public sources) or 2 (experiments) is
  a separate, manual `.env` decision.
- Laravel `schedule:run` is not re-enabled in cron, and the existing crontab
  lines are left unchanged.
- No `queue:restart` broadcast (it would recycle every trading worker). No
  global `supervisorctl update`. No restart or edit of any existing trading
  program, `autofinx-runtime-master.sh` or `autofinx-dedupe-runtime.sh`.
- No other pending migration runs (e.g. `2026_09_29_000000_add_heartbeat_to_fast2x_states`).
  They are listed, not run.
- No `optimize:clear`, no route/view cache changes, no git operations, and no
  deployment or pull.

## Related

`scripts/autofinx-stuck-requests-diagnose.sh` is a read-only report for "all API
requests pending / 7-8 s". It covers PHP-FPM, LVE, Apache/LiteSpeed, Redis,
MySQL locks, origin-vs-public curl timing and Laravel logs:
`sudo bash scripts/autofinx-stuck-requests-diagnose.sh`. The report goes to
`storage/logs/stuck-requests-diagnose-<ts>.txt`.
