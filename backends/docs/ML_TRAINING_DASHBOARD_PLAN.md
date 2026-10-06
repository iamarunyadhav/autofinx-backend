# ML Training dashboard: plan and API contract

Goal: see, from the UI, everything the ML shadow pipeline does (candle collection,
backfill, features, labels, training runs, models, queue, server load), and control
it safely: start, stop, retry, resume. Shadow-only: nothing here can place an order.

## 1. Why it is needed (verified facts)

- A training attempt is only a `ml_models` row with status `TRAINING`. There is no
  heartbeat, no stop, no retry. If the worker or host restarts mid-run
  (`supervisorctl restart all`), the row stays `TRAINING` forever and the UI would
  show "training now" for a process that no longer exists. The per-model
  `Cache::lock` also stays held until its TTL.
- Runs started from a shell (`artisan ml:train`, `ml-train-batch.sh`) are invisible
  to the web app except through that one row.
- On the production host a model takes hours instead of the ~3 minutes the config
  assumes, because RAM/swap are exhausted. The UI must show the host's resources so
  that is visible, not guessed.
- `ml:status` and per-coin `COUNT(*)` over millions of rows are slow. Dashboard
  endpoints must never run them per request.

## 2. Backend: a control plane (table `ml_training_runs`)

A run is a request to train one symbol x horizon x type; a model row is its outcome.

Lifecycle `status`: `QUEUED -> RUNNING -> FINISHED | FAILED | STOPPED | INTERRUPTED`.
`outcome` (only when FINISHED): `CHAMPION | REJECTED`, with `outcome_reason`
(the promotion policy's reason code). A REJECTED model is a valid result, not a failure.

Columns (use `dateTime()`, never `timestamp()`: MariaDB adds `ON UPDATE
CURRENT_TIMESTAMP` to the first TIMESTAMP NOT NULL; store UTC with `App\Casts\UtcDateTime`):
id (uuid), symbol, horizon_minutes, model_type, status, outcome, outcome_reason,
phase (`queued|exporting|fitting|evaluating|promoting|done`), progress_pct (nullable),
source (`ui|scheduler|cli`), triggered_by (user id or null), batch_id (nullable uuid),
attempt, max_attempts, retry_of (nullable uuid), next_retry_at, queued_at, started_at,
heartbeat_at, finished_at, cancel_requested_at, worker_pid, host, error_code,
error_message, rows_exported, log_tail (text, last ~40 lines, redacted), model_id (nullable).

Instrumentation (in `MlShadowTrainer::train()`, so shell runs, queue runs and the
scheduler are all recorded the same way): create or attach the run, set RUNNING,
write `phase`, and while the python trainer runs, poll it in a loop that (a) writes
`heartbeat_at` at least every 10 s, (b) checks `cancel_requested_at` and, if set,
stops that child process and ends the run as STOPPED (model row FAILED with reason
`cancelled_by_operator`). The web app never kills a PID: stop is a cooperative flag
read by the process that owns the run.

Reaper (`ml:training:reap`, scheduled every 2 minutes, light, short overlap lock):
a RUNNING run whose heartbeat is older than `ml.training.stall_seconds` (default 180)
becomes INTERRUPTED, its `TRAINING` model row becomes FAILED (`interrupted`), and the
stale `Cache::lock` for that symbol x horizon is released. Auto-retry: INTERRUPTED and
transient failures (python crash, killed by restart) are re-queued with exponential
backoff (2, 4, 8 min), at most `max_attempts` (default 3), `source=scheduler`. Never
auto-retry REJECTED, TIMEOUT or STOPPED.

Queue: UI runs dispatch `TrainMlShadowModelJob` (extended with `runId` and `force`, so
a manual run does not need new-label eligibility) on the existing `ml_training`
connection. One worker means one model at a time. Known limit: the job timeout is
`ml.training.timeout_seconds + 120` (default 1920 s) and must stay below the queue's
`retry_after` (2400 s). On a healthy host a model takes minutes; on the current
overloaded host a queued run can hit it, and it is shown as TIMEOUT with that hint.

Cheap reads only: cache any COUNT over `ml_feature_rows` / `ml_labels` for 5 minutes
and expose `counts_as_of`; candle freshness uses the indexed latest row.

## 3. API contract (all under `/api/v1/workspaces/{workspace}/ml-training`)

Auth: every route asserts workspace membership (copy `RatioBotController`). Mutations
need role owner or admin (as Nexus does) and `throttle:10,1`. Reads `throttle:120,1`
and `Cache-Control: no-store`. ML data is global, the workspace is for access control.
Errors: HTTP 422 `{message, code}`; codes: `ml_disabled`, `already_active`,
`not_stoppable`, `not_retryable`, `unknown_symbol`, `unknown_horizon`.

### GET /overview
```
{ generated_at, enabled, mode: "shadow_only", counts_as_of,
  alerts: [{ id, severity: "critical"|"warning"|"info", code, title, detail,
             symbol?, horizon_minutes?, since,
             action?: { type: "retry_run"|"resume_backfill"|"open_run", run_id?, symbol? } }],
  symbols: [{ symbol,
    candles:  { state: "live"|"lagging"|"stale"|"missing", latest_close, age_seconds, collected, target, coverage_pct, rejected },
    backfill: { status: "RUNNING"|"COMPLETED"|"FAILED"|"STALLED"|null, pages, rows_written, cursor, updated_at, last_error } ,
    features: { rows, state: "ok"|"building"|"missing" },
    labels:   { pending, mature },
    funding:  { latest, state: "ok"|"stale"|"missing" },
    horizons: [{ horizon_minutes, tradable,
      champion:   { model_version, trained_at } | null,
      last_run:   { id, status, outcome, outcome_reason, finished_at } | null,
      active_run: { id, status, phase, progress_pct, started_at, heartbeat_age_seconds } | null,
      retrain:    { eligible, reason, new_mature, required } }] }],
  active_runs: [RunSummary],
  queue: { pending, reserved, failed },
  pipeline_jobs: [{ command, last_started_at, last_status, age_seconds, state: "ok"|"late"|"never" }] }
```
`candles.state`: live when the newest closed candle is within 2 intervals, lagging
within 10, otherwise stale; missing if none. `backfill.status` STALLED = RUNNING
checkpoint not updated for 10 minutes. Alerts include: a stalled/interrupted run,
a FAILED run, stale candles, a failed or stalled backfill, queue backlog, host
memory/swap critical, a scheduler job that is late.

### RunSummary
```
{ id, symbol, horizon_minutes, model_type, status, outcome, outcome_reason, phase,
  progress_pct, source, triggered_by, batch_id, attempt, max_attempts, retry_of,
  next_retry_at, queued_at, started_at, heartbeat_at, heartbeat_age_seconds, finished_at,
  elapsed_seconds, eta_seconds, rows_exported, error_code, error_message,
  can_stop, can_retry, model_id }
```
`eta_seconds` is an estimate from the median duration of the last 5 finished runs of
the same horizon, null when there is no history. `heartbeat_age_seconds` is computed
by the server.

### GET /runs?status=&symbol=&horizon_minutes=&batch_id=&limit=50&cursor=
`{ data: [RunSummary], next_cursor }`, newest first (UUIDv7 id order).

### GET /runs/{id}
`{ run: RunSummary, log_tail: [string], model: { model_version, status, decision_reason,
metrics, holdout, folds, comparison } | null, attempts: [RunSummary] }`

### POST /runs  `{ symbol, horizon_minutes, type? }` -> 202 `{ run }`
### POST /batches  `{ pairs: [{symbol, horizon_minutes}] }` or `{ preset: "long"|"mid"|"short", symbols? }` -> 202 `{ batch_id, runs: [RunSummary] }`
Presets: long = 240 and 1440, mid = 120 and 60, short = 15. Runs that would duplicate
an active run for the same pair are skipped and listed under `skipped`.
### POST /runs/{id}/stop -> 202 `{ run }`   (QUEUED: cancelled at once. RUNNING: flag set, ends within seconds.)
### POST /runs/{id}/retry -> 202 `{ run }`  (new run, attempt+1, `retry_of` = id)
### POST /batches/{id}/stop -> 202 `{ stopped, cancelled }`
### POST /backfill/{symbol}/resume -> 202 `{ job }`   (resumable `ml:candles:backfill --no-build`, one per symbol)

### GET /candles/{symbol}?limit=120
`{ symbol, timeframe, generated_at, latest_close, age_seconds, state, candles: [{ t, o, h, l, c, v }] }`
`t` = open time, unix ms UTC. Newest last. `limit` max 500.

### GET /models?symbol=&horizon_minutes=&limit=50
`{ data: [{ model_version, symbol, horizon_minutes, model_type, status, decision_reason,
trained_at, promoted_at, samples: {total, train, holdout}, holdout_summary: {...}, comparison }] }`

### GET /system
```
{ generated_at,
  host: { cores, load: {m1,m5,m15}, memory: {total_mb, available_mb, used_pct},
          swap: {total_mb, used_mb, used_pct}, disk: {path, free_gb, used_pct} },
  health: { level: "ok"|"warning"|"critical", reasons: [string] },
  queue: { connection, name, pending, reserved, failed_recent: [{ id, failed_at, symbol?, horizon_minutes?, summary }] },
  training: { timeout_seconds, stall_seconds, auto_retry, max_attempts },
  thresholds: { load_per_core_warn, load_per_core_critical, swap_used_pct_warn, swap_used_pct_critical, disk_free_gb_warn } }
```
Linux `/proc` only; on other platforms host values are null and health is `ok` with a reason.

## 4. Frontend

New module `ml-training` (default active, path `/ml-training`) with a collapsible sidebar
group and nested routes. `ModuleDefinition` gains an optional `children` list; the
sidebar renders them as sub-items (desktop, collapsed and mobile).

| Route | Page |
|---|---|
| `/ml-training` | Overview: alert banner, host health strip, active runs with live progress, a coin grid (candles / features / labels / backfill / per-horizon verdict chips), counts "as of" |
| `/ml-training/candles` | Per coin: live candle chart (recharts), age and state, coverage bar, rejected count, backfill progress, Resume backfill |
| `/ml-training/runs` | Runs table with filters; start one run; launch a batch by preset; per-run drawer: phase timeline, heartbeat age, ETA, log tail, verdict in plain words, retry chain; Stop and Retry buttons with confirm |
| `/ml-training/models` | Matrix coin x horizon (champion or last verdict), model detail with metrics and walk-forward folds |
| `/ml-training/system` | Host gauges (load per core, RAM, swap, disk), queue depth, recent failed jobs, scheduler heartbeats, training limits |

Behaviour that matters:
- Polling with react-query: overview 10 s, runs 3 s while any run is QUEUED or RUNNING
  (else 15 s), system 10 s, candles 15 s. Pause when the tab is hidden. Show "updated Ns
  ago"; when a request fails show an offline banner and keep the last data (greyed).
- A run is displayed from server fields only: STALLED when `heartbeat_age_seconds` exceeds
  `training.stall_seconds` even before the reaper runs. Elapsed time ticks locally.
- Retry/Stop: confirm dialog, disabled while the request is in flight, errors from the
  422 `code` mapped to plain sentences, toast on state transitions (finished, failed,
  interrupted, auto-retry scheduled with a countdown).
- REJECTED is shown neutrally with the reason explained ("fees would eat the edge",
  "too few independent trades"...), never as an error. A small glossary maps each
  `outcome_reason` code to a sentence; unknown codes show the raw code.
- Empty, loading, error and ML-disabled states everywhere. No horizontal page scroll at
  phone width. Keyboard reachable controls. Use the existing shadcn components and
  recharts; add no new dependency.

## 5. Constraints for implementers

- Zero diff to Ratio Bot, Quick Trade, Future Trade, Pepperstone, ForexGrid. The ML
  module is ours; keep trainer changes minimal and covered by the existing tests.
- Shadow-only. No code path from this feature to an order.
- Production host is small and overloaded: no per-second schedules, no daemons, light queries.
- Migrations are written, never applied by the agent (tests use sqlite in memory).
- Backend: `php artisan test` stays green (baseline 1986 passed, 1 skipped). Frontend:
  `npm run build` and `npx eslint .` clean (0 errors).
- Do not commit or push.

## 6. Acceptance

Backend: runs recorded for CLI, queue and UI starts; heartbeat; cooperative stop ends a
fake python process; reaper marks a dead run INTERRUPTED, releases the lock and
auto-requeues with backoff, but never a REJECTED or TIMEOUT run; retry chain; batch
presets; auth (non-member 403, member cannot mutate); every timestamp tested under
Asia/Colombo.
Frontend: build and lint clean; every page has loading, error, empty and disabled
states; sidebar sub-menu works expanded, collapsed and on mobile.
