# PROGRESS

Last updated: 2026-10-07 17:50 SLST by ml-engine-v1 (Claude Code session).

## Current state

- **Current phase:** **P4B done 30/30 (R-018); optional layers done, no value (R-020); P5 T0 baseline running.** Reviewer handoff: `CROSSCHECK_HANDOFF_2026-10-07.md`. Full owner status report:
  `STATUS_REPORT_2026-10-07.md`.
- **Running:** `D:/autofinx-ml-v4/run-p4b-p5.sh` → `logs/p4b-p5.log`. One symbol × horizon per python
  process, waits for >= 6 GB free commit memory: (1) 7 missing P4B cells (BTC h120/h240, ETH h240,
  BNB h120/h240, SOL h240, SUI h1440 — lost to out-of-memory / fork failures); (2) optional layers h15
  (g7t + v13, D-030); (3) P5 arm3 for all 30 cells. P5 now also writes `p5_final/decision.json`
  (calibration map, EV params, base rate, OOD stats) so P6 only applies frozen inputs; `--arm arm2`
  added for the Arm 2 benchmark.
- **Owner plan 2026-10-07 (Profitability Intelligence):** accepted as direction; review + mapping in
  `PLAN_PROFITABILITY_2026-10-07.md` (new IDs D-032..D-035). The queued P5 full run = T0 baseline only.
- **Owner "start" 2026-10-07 19:10:** G7-T now 15 features (flip_48, range_pos_48, vol_confirm_12,
  persistence_12 added; re-exported before the layers run). Stage B audit code ready (`v4.cli p4b-audit`,
  runs when P4B is 30/30). D-033 (TAKE/SKIP) and D-035 (cost C0/C1) PROPOSED — owner approval needed.
- **D-033 / D-035 code done** (90 tests); BTC h60 smoke running (`logs/meta-smoke-btc60.log`); full
  30-cell run after the P5 baseline.
- **D-036 (owner): Codex review adopted.** Roadmap P0→P6 in `REVIEW_CODEX_2026-10-07.md`. P0 fixes A–E done
  (97 tests). Meta waiter cancelled. P5 baseline continues from the frozen snapshot (`logs/p5-baseline.log`).
  Next: replay P4B / audit / layers from a P0 snapshot after the baseline; build P1 signal diagnostics.
- **Gate before P6:** owner approves D-032 (holdout pass rule + P7 classification, pre-registered BEFORE
  the holdout is opened). P6/P7/P8 code not written yet. If P5 freezes no cell, the holdout stays sealed
  and P8 is the honest "no edge" report.
- **Next action:** when the runner finishes: record R-018 (P4B 30 cells), R-020 (optional layers),
  R-021 (P5 30 cells); write P6/P7/P8 code; propose D-032; report to the owner.
- **Strategy Lab (D-031):** backend deployed + migrated on the server (flag on, no WORK_DIR there →
  RUNNER_UNAVAILABLE by design); frontend built (build + eslint clean), production deploy pending; PC
  setup (`AUTOFINX_STRATEGY_LAB_WORK_DIR`, `_PYTHON_BIN`, local migrate, queue worker) after training.
- **Candidate autofinx-v13 (owner Pine):** S0 REJECTED_NO_VALUE (R-019); components tested in optional
  layers (FEATURE / WAIT / RULE) in step (2) above.
- **Candidate autofinx-v13 (owner Pine):** S0 done (R-019, rejected standalone); S1 soft-feature ablation
  queued after P4B (extend `ablation.py` to take `v13.parquet` as one extra group, D-025 rule, no hard filter).
- **Queued after P4B (owner proposal 2026-10-07, PROPOSED):** Strategy Lab + Tradeability Intelligence —
  see `strategy-lab/README.md` (summary, agent review, order). Next: SL0 read-only audit → draft SPEC
  §104/§105 + D-030/D-031 PROPOSED → STOP. No build before "approve Strategy Lab build".
- **Server:** ML paused with `ml-server-pause.sh --apply` on 2026-10-07 00:53 +08 (flag false, worker
  autostart off, 1.4 GB exports removed). Load stayed ~27–29, so ML was not the main server load.
  `--free-disk` (~16 GB) recommended, owner to decide.
- **Owner action (server):** run `bash ml-server-pause.sh` (report) then `--apply` on the server to
  stop the server-side ML scheduler, training worker and batch scripts (D-001). Optional `--free-disk`.
- (done 2026-10-07) P1: when the full run finishes, record R-007 (rows, coverage, gaps, alignment, G7
  coverage per symbol, runtime, disk); decide G7 inclusion per L3; then P1 gate → owner approves P2.
  P1 detailed plan (kept for reference):
- **Locked plan:** D-015 phase order; SPEC §102 L1–L9 constraints.
- P1 plan (done, kept for reference):
  1. V4 venv `D:\autofinx-ml-v4\venv` with pinned lightgbm / numpy / scikit-learn / pyarrow (§93).
  2. Python downloader (in `external-predictive-engine/v4/`): Binance futures UM klines 1m + native
     5m from data.binance.vision monthly zips (+ daily zips for the current month) + REST tail,
     funding from REST; checkpoints, retry with backoff, atomic writes, byte/row progress + ETA,
     Parquet per symbol/timeframe/month under `D:\autofinx-ml-v4\data\<SYMBOL>\{1m,5m,funding}\`.
  3. VERIFY stage: ascending, no duplicates, exact interval, valid OHLC, volume ≥ 0, closed candles,
     1m gap list, 1m↔5m alignment (5m OHLCV equals aggregated 1m where both exist), coverage report
     (requested vs actual history, listing date) per §3/§46.
  4. Runner `ml-v4-local.ps1` with stages CHECK / DOWNLOAD / VERIFY, resume markers, STOP file,
     options per §6 (later stages added in P2+). No SSH.
  5. G7 coverage audit: Binance daily `metrics` (open interest, long/short ratios) per symbol —
     earliest/latest timestamp, frequency, gaps, coverage % (L3). Download only; no feature yet.
  6. Tests for the downloader/verifier (parsing, gap detection, alignment, resume idempotency).
  7. Run on BTCUSDT for a small window first, then report sizes and real timings in RESULTS.md.
- **Open questions:**
  1. Market SL cap — decided in P5 from P3 distributions (L2). Not needed now.
  2. (resolved by D-018) V4 split fractions: holdout newest 15%, dev 70/30 with purge.

## Phase tracker

| Phase | Status | Notes |
|---|---|---|
| P0 Audit | ✅ done 2026-10-06 | RESULTS R-002 |
| P1 Data (1m + 5m + funding, verify, runner skeleton) | ✅ done 2026-10-07 (G7 metrics partial) | R-006, R-007 |
| P2 Layer A path targets + exact features | ✅ done 2026-10-07 | R-008, R-010 |
| P3 Label audit + path distribution + policy lab | 🔄 code + BTC h15/h240 done (R-009) | dev data only |
| P4A V4 models on V3 features | 🔄 code done, BTC h15 first result (R-011); BTC 15/60/240 running | holdout sealed |
| P4B Feature schema v4, G1–G6 ablation (G7 optional) | 🔧 PHP features + tests ready (not run on data yet) | holdout sealed |
| P5 Policy calibration → FREEZE (incl. 3 arms, primary = Arm 3) | 🔧 design locked (D-028), rules + 11 tests done; orchestration next | every frozen value in DECISIONS |
| P6 Walk-forward + one final holdout, all arms | not started | opened once |
| P7 Stress tests | not started | |
| P8 Reports | not started | STOP after P8 |

## Background already in place (from the V3 offload work)

- `ml-offload.ps1` (stable, do not break): PC-side V3 training — Binance → local sqlite on
  `D:\autofinx-ml`, server settings via SSH, pack/upload report. Works end to end (RESULTS R-003).
- Reusable for V4 feature parity: `ml:candles:backfill`, `ml:funding:sync`, `ml:features:build`
  (PHP ShadowFeatureBuilder, §72) in an isolated local sqlite via env overrides.
- SSH from the PC: `root@62.72.45.72 -p 333`, key `~/.ssh/id_ed25519` (V3 offload only; V4 needs none).

## Session log (newest first)

- **2026-10-06 (f)** — P2: Layer A generator (24 tests incl. reference), BTC all horizons in ~95 s,
  PHP feature bridge (526,673 v3 rows, 0 NaN), PHP-vs-Python endpoint cross-check corr 0.99999.
  Runner gained features/targets stages. Server ML paused by the owner.
- **2026-10-06 (e)** — P1 built: v4 data package, 11 tests pass, BTC 2-month smoke OK (0 alignment
  mismatches), runner `ml-v4-local.ps1`, `ml-server-pause.sh` for the server. Full run started.
- **2026-10-06 (d)** — Owner approved §102 with locks L1–L8 (+ agent L9: Arm 2 is primary if no
  feature group survives ablation). Locked as D-014; final phase order D-015. Waiting for "go P1".
- **2026-10-06 (c)** — Reviewed owner proposal (pattern intelligence, P4A/P4B, four brains) against
  code; recorded as SPEC §102 PROPOSED with adjustments A1–A6; feature inventory + metrics data check
  (RESULTS R-005). Waiting for owner validation.
- **2026-10-06 (b)** — SPEC §74–101 received and merged; phase order reconciled (D-012); scope
  rules (D-013). P1 ready.
- **2026-10-06 (a)** — Audit of V3 done; V4 spec 0–73 + corrections C1–C10 locked; agent pack
  `docs/ml-engine-v1/` created with Claude Code wrappers.
