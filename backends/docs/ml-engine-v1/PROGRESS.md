# PROGRESS

Last updated: 2026-10-06 by ml-engine-v1 (Claude Code session).

## Current state

- **Current phase:** **P2 in progress** (owner said "go P2" 2026-10-06). Layer A generator + exact V3
  feature export done and verified on BTC 5 years (RESULTS R-008). P1 full six-coin download (no
  metrics) still running; when it finishes: record R-007, then run
  `ml-v4-local.ps1 -TargetsOnly` to build features + targets for the other five coins, then P2 gate.
- **Server:** ML paused with `ml-server-pause.sh --apply` on 2026-10-07 00:53 +08 (flag false, worker
  autostart off, 1.4 GB exports removed). Load stayed ~27–29, so ML was not the main server load.
  `--free-disk` (~16 GB) recommended, owner to decide.
- **Owner action (server):** run `bash ml-server-pause.sh` (report) then `--apply` on the server to
  stop the server-side ML scheduler, training worker and batch scripts (D-001). Optional `--free-disk`.
- **Next action:** when the full run finishes, record R-007 (rows, coverage, gaps, alignment, G7
  coverage per symbol, runtime, disk); decide G7 inclusion per L3; then P1 gate → owner approves P2.
  P1 detailed plan (kept for reference):
- **Locked plan:** D-015 phase order; SPEC §102 L1–L9 constraints.
- **Next action — P1 Data:**
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
  2. V4 split fractions (V3: holdout = newest 15%, folds at 55/65/75%) — agent proposes in P2 design
     and the owner confirms before any holdout row is used.

## Phase tracker

| Phase | Status | Notes |
|---|---|---|
| P0 Audit | ✅ done 2026-10-06 | RESULTS R-002 |
| P1 Data (1m + 5m + funding, verify, runner skeleton) | 🔄 code done, smoke OK, full run running | R-006 |
| P2 Layer A path targets + exact features | 🔄 BTC done (R-008), other coins after the download | holdout sealed |
| P3 Label audit + path distribution + policy lab | not started | train/validation only |
| P4A V4 models on V3 features | not started | holdout sealed |
| P4B Feature schema v4, G1–G6 ablation (G7 optional) | not started | holdout sealed |
| P5 Policy calibration → FREEZE (incl. 3 arms, primary = Arm 3) | not started | every frozen value in DECISIONS |
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
