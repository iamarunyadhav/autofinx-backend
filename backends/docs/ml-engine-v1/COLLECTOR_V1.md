# COLLECTOR V1 (V6 Track B) - live public market-data recorder

Status: **built and started 2026-10-08** (owner-approved Track B, host = owner's PC). Code: `external-predictive-engine/collector/`
(README there has the commands). Research only: public streams, no API keys, no orders, no production server/DB.
**This is a 30-day PILOT. It is for data quality, descriptive checks and execution realism. No pass/fail or edge claim may be made from it** (D-045).
Any later analysis needs its own frozen pre-registration; the sealed holdout rule is unaffected (this is new data, none of it was seen by any model).

## What it records
| kind (folder) | source | content | stored |
|---|---|---|---|
| `aggTrade` | `wss://fstream.binance.com/market/stream` btc,eth,bnb,sol,xrp,sui usdt | every aggregate trade (id `a`, price, qty, side flag `m`, T) | raw |
| `markPrice` | same, `@markPrice@1s` x6 | mark/index price, funding rate, next funding time | raw |
| `forceOrder` | same, `!forceOrder@arr` | liquidation orders, all symbols | raw |
| `book1s` | `/public/stream` bookTicker btc,eth | per UTC second of receive time: last bid/ask + sizes, update count `n`, min/max spread | **aggregated** (raw bookTicker NOT stored) |
| `depth` | `/public/stream` depth20@500ms btc,eth | 20-level book diffs-as-snapshots | raw |
| `openInterest` | REST `/fapi/v1/openInterest`, every 60 s x6 | open interest, exchange time | raw |
Note: the legacy `/stream` URL silently drops aggTrade/markPrice/forceOrder; the collector uses `/market` and `/public` and alarms on silence.

## Files and formats (UTC)
- `D:\autofinx-collector\data\raw\<kind>\<YYYYMMDD>\<kind>_<YYYYMMDDHH>.jsonl.gz` - one record per line:
  `{"rt": local receive time ns, "et": exchange event time ms, "s": symbol, "d": original Binance payload}`
  (`book1s` and `openInterest` use the same envelope; `d` for book1s = `sec,bid,bsz,ask,asz,n,spr_min,spr_max,u,T`).
- Append-only; hour files are closed at the hour change; a sync flush every 5 s means a crash loses at most a few seconds of the open hour;
  a restart in the same hour appends another gzip member (read with normal `gzip`; truncated final member = `EOFError`, keep what was read).
- `gaps.jsonl` (separate, append-only): `collector_start/stop, ws_disconnect, ws_reconnected, silent_stream, missing_agg_ids, dup_or_out_of_order, disk_guard_stop/resume, clock_warning, oi_fetch_failed, clock_check_failed`.
- `clock.jsonl`: every 300 s `{server_ms, rtt_ms, offset_ms}`; `offset_ms > 0` = this PC clock is BEHIND Binance (`true_time = rt + offset`, up to RTT/2 error).
- `heartbeat.json` every 10 s; `stats/<day>.json` (persisted counters); `reports/<day>.json|md` (daily integrity report; a partial one for the current day is written at shutdown or via `report`); `logs/collector.log`.

## Run control
- Status: `cd C:\xampplast\htdocs\auto_finx\autofinx-backend\backends\external-predictive-engine; D:\autofinx-collector\venv\Scripts\python.exe -m collector status`
- Stop (clean): same with `stop`. Start/restart: `powershell -File collector\start-collector.ps1`.
- Own venv `D:\autofinx-collector\venv` (websockets 16.1.1 only + pytest); below-normal priority; hidden detached process.
- Disk guard: pauses writing below 20 GB free on the data drive (resumes at 22 GB), logs why.
- Not registered at boot (script `register-boot-task.ps1` provided). Power/sleep settings untouched: the owner should disable sleep and stay plugged in.

## Expected volume
Smoke test 185 s (2026-10-08, busy afternoon): aggTrade 172 KB, depth 96 KB, markPrice 31 KB, book1s 16 KB, forceOrder 5 KB, OI 1 KB gz
= about 1.7 KB/s = **about 0.15 GB/day, about 4.5 GB per 30 days** (volatility can multiply aggTrade/depth, so budget up to ~15 GB/30 days; D: had 96 GB free).
bookTicker raw was ~860 msg/s (>> plan estimate 508), which is why it is aggregated.

## 30-day pilot rule
After about 30 days: (1) read the daily reports (coverage, gaps, silent alarms), (2) descriptive checks only (spread/depth/liquidation statistics),
(3) execution-realism measurements, (4) decide whether to continue to 6-12 months or combine with the archive (Track A). Not allowed: any
statement that a rule/model "works" or "fails" from this month of data.

## Deployment on a Linux server (D-052, 2026-10-10)
Owner decision: run the collector on a server because the laptop keeps going off. Bundle: `external-predictive-engine/collector/deploy/linux/`
(`README_DEPLOY.md` = exact commands; `install.sh` = venv + websockets 16.1.1 + tests + 60 s smoke test, no root, no secrets; systemd system / user unit and a Supervisor conf, all
non-root, Nice 19, CPUWeight 5, IO idle, MemoryMax 512 M; `pull_data.sh` daily rsync to the PC; `cleanup_old.sh` retention, dry run by default).
- Portability changes (no data-format change): clean stop on SIGTERM/SIGINT/SIGBREAK, `stop --wait`, `~`/`$VAR` in `data_dir`, priority `idle`/`unchanged`, LF-only log lines.
  The Windows process started on 2026-10-10 07:33Z runs the OLD code until restarted; the Windows scripts still work.
- Disk guard: same mechanism, floor from config (`min_free_gb`; Linux default 8 GB, laptop 20 GB).
- **WARNING: do not host it on the production trading server while that is loaded (load 27-29 seen). Recommended: separate VPS, 1 vCPU / 1-2 GB RAM / 40 GB disk.**
- Datasets: laptop data (about 2 days with gaps) stays untouched as `laptop`; server pull = `server`. Join on exchange event time `et` (aggTrade id `a` to dedupe an overlap), correct `rt` with `clock.jsonl`
  (laptop offset about +6.2 s), mark the hole between and inside datasets as gaps (from both `gaps.jsonl`), never interpolate. D-045 pilot rule unchanged.
- Status: tested on Windows only (see CHANGELOG); real Linux behaviour is proven only by the owner's smoke test.
