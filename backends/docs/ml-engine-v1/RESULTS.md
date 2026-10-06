# RESULTS (append-only)

Every run/experiment: id, date, what ran, data range, config, numbers, verdict, where the artifacts
are. Numbers are copied from real output, never estimated after the fact.

---

## R-001 · 2026-10-06 · Production server capacity (why training moved off the server)
- Host: 4 cores, 7.7 GB RAM. Observed load average 18–46, swap 96% used, disk / 94% used,
  vmstat idle 0–2%, iowait 0–2% (CPU-bound, not disk-bound).
- Top CPU: mariadbd ~46%, plus many short-lived artisan processes every minute (schedule:finish ×6–7,
  future-trade:user-stream, quick-trades:settle, future-trade:tick, robot-sessions:evaluate,
  jobs:maintain-backlog, runtime:repair, ai-backfill-outcomes), 8 queue workers, Imunify, csf.
- `ml_feature_rows` = 12,592 MB data / 3,942,361 rows; `ml_labels` = 1,801 MB / 12,183,845 rows.
- Server-side export of ETH features ran at ~2,000–2,400 rows/min even after renice to 0
  (≈5 h per coin). Verdict: server cannot host ML training or bulk exports (→ D-001, D-002).
- Separate open item (not ML): the platform's own scheduler fan-out saturates the server; worth an
  audit later because it also affects trading latency.

## R-002 · 2026-10-06 · Read-only audit of current V3 (verified in code)
- Features: `qt-shadow-features-v3` (ShadowFeatureBuilder.php: SCHEMA_V3, LOOKBACK 64,
  LONG_LOOKBACK 865); causal 5m candles; also v1 built for the anchor/label set.
- Anchor T0 = close time of the anchor 5m candle; entry = anchor_close (no delay modelled).
- Label: future_return = close of the candle closing exactly at T+H / anchor_close − 1
  (FeatureLabelService). BUY label = return > required_move + funding; SELL mirrored
  (train_side_classifier.py side_labels).
- Exit: T+H only; no SL / TP / trailing anywhere (shadow_sim.simulate; MlPaperAutopilotService exits
  at T+h, entry at live quote).
- Costs: cost_pct = (taker 0.05% + taker 0.05%) + slippage buffer 0.02% = **0.12%** deducted from
  simulated P&L; required_move = cost × multiplier 1.5 = **0.18%** used only for labels. Funding:
  actual historical, per side.
- Model: LightGBM BUY + SELL classifiers (300 trees, lr 0.03, 31 leaves, min_child 200), isotonic
  calibration on the newest 20% of the fit window, EV decision with probability floor 0.55,
  recency half-life 180 days.
- Split: chronological; holdout = newest 15%; walk-forward folds start at 55/65/75% (10% each);
  purge = horizon steps.
- Simulation: non-overlapping, one position per symbol × horizon; baselines never/always buy/sell.
- Gates (server settings): ≥3 folds, holdout net > 0, min trades per horizon, avg net ≥ 0.08%,
  t-stat ≥ 1.5, positive-fold share ≥ 0.66, beat always-buy/always-sell (ClassifierPromotionPolicy).
- Main weakness: judges the trade only by the T+H close; a +2% path ending −0.1% is a loss, an SL-hit
  path that recovers is a win — exits that real trading cannot do.

## R-003 · 2026-10-06 · V3 local training, BTC + ETH, 5 years (ml-offload.ps1)
- Data: Binance futures 5m, 2021-10-06 → 2026-10-06. BTC 525,997 candles, ETH 526,001; funding
  5,480 rows each; features v1+v3 ≈1.05M rows/coin, labels ≈3.16M/coin. Data build: BTC 26.4 min,
  ETH 33.6 min (one cURL timeout, auto-resumed).
- Training: 12 models, 3.4–6.6 min each, total run ≈2 h 45 min on i7-11850H / 32 GB.
- Costs/gates: server settings (cost 0.12%, required 0.18%, gates as R-002).
- Verdicts — all **REJECTED**:

| Horizon | BTCUSDT | ETHUSDT |
|---|---|---|
| 5m (local only) | holdout_net_not_positive_after_costs | holdout_net_not_positive_after_costs |
| 15m | holdout_net_not_positive_after_costs | too_few_non_overlapping_trades |
| 60m | edge_not_statistically_significant | too_few_non_overlapping_trades |
| 120m | holdout_net_not_positive_after_costs | holdout_net_not_positive_after_costs |
| 240m | edge_not_statistically_significant | edge_not_statistically_significant |
| 1440m | holdout_net_not_positive_after_costs | holdout_net_not_positive_after_costs |

- Correct reading (D-004): this combination did not show sufficient out-of-sample net edge under the
  gates. It does not prove the features carry no edge.
- Artifacts: `D:\autofinx-ml\` (ml.sqlite, models\, logs\train-*.log, results.txt,
  bundle-20261006-161147). Not installed on the server (all REJECTED).

## R-004 · 2026-10-06 · Binance bulk 1m reachability check (for P1)
- `data.binance.vision/data/futures/um/monthly/klines/BTCUSDT/1m/BTCUSDT-1m-2021-10.zip`: HTTP 200,
  2,040,146 bytes, 2.7 s from the owner's PC. SUIUSDT 2023-05 monthly file exists (HTTP 200).
- Local venv `D:\autofinx-ml\venv` has lightgbm 4.7.0, numpy 2.2.6, scikit-learn 1.7.2; **no pyarrow,
  no pandas** yet.
- Single-file check only — not a runtime estimate (D-007).

## R-005 · 2026-10-06 · Feature inventory + derivatives data availability (for SPEC §102 review)
- Current V3 = 37 features (ShadowFeatureBuilder.php FEATURES_V1/V2/V3): returns 1/3/6/12/24/48 bars,
  vol_12/48, range_1, range_mean_12, body_1, dist_high/low_48, volume_ratio_12/48, rsi_14,
  ema_gap_12/48, hour/dow sin-cos, taker_ratio_1/12/48/shift, ret_12h/24h/72h, vol_24h, vol_regime,
  dist_high/low_24h, range_pos_72h, vwap_gap_24h, taker_ratio_24h, volume_ratio_24h.
- Not present today: swing structure (HH/HL/LH/LL), breakout/retest, wick shape and candle
  sequences, RSI/MACD slopes, ATR/Bollinger squeeze, explicit multi-timeframe alignment, open
  interest / long-short ratio.
- Binance bulk `futures/um/daily/metrics/BTCUSDT/…` files: HTTP 200 for 2021-10-06, 2022-01-01
  (5.6 KB), 2026-10-01; monthly metrics path 404 (daily files only). Coverage and granularity for all
  six symbols still to be verified in P1.

## R-006 · 2026-10-06 · P1 smoke test — BTCUSDT, 2 months + current month (real Binance)
- Formats confirmed: older bulk kline CSVs have no header, newer have one; `.CHECKSUM` = sha256
  (verified, matches); current month only as daily files (monthly 404); funding monthly bulk =
  `calc_time, funding_interval_hours, last_funding_rate` (calc_time can be +5 ms); metrics daily =
  5-minute snapshots with `create_time` text timestamps.
- Unit tests: `tests/v4/test_data_layer.py` 11/11 passed (V4 venv, pytest 9.1.1).
- Download 2026-08-01 → now: 1m 96,044 rows, 5m 19,208, funding 201 (all 8 h), metrics 66/66 days,
  step 5.0 min, 0 gaps > 15 min. Time 44.6 s first run; resume run 2.5 s (all cached).
- Verify: status OK — 0 gaps, 0 duplicates, 0 invalid OHLC, 0 unclosed, coverage 100%;
  **1m→5m alignment: 19,208 native 5m candles checked, 0 price and 0 volume mismatches.**
- Disk: 7.9 MB for 2 months (→ roughly 0.25 GB per coin for 5 years, an estimate, not a promise).
- Runner `ml-v4-local.ps1 -Symbols BTCUSDT -Months 2`: CHECK → VENV → DOWNLOAD → VERIFY, exit 0.
- Full six-coin P1 run started in the background (log `D:\autofinx-ml-v4\logs\full-run-p1.log`);
  results go to R-007.
- Same evening the Binance CDN was much slower per connection: one 2 MB monthly file took 31–38 s
  (vs 2.7 s earlier). Four parallel transfers ran at about 3× the single-connection throughput, so
  monthly kline downloads now use 4 workers (`download.py WORKERS`). The STOP marker was exercised
  for real: the run stopped after the current file, kept 5 months, and resumed from there.

## R-007 · 2026-10-07 · P1 full data run — six coins (no G7 metrics)
- Runtime 66.4 min (slow Binance CDN evening; 4 parallel workers). Exit 1 = warnings only.
- | Symbol | 1m rows | 5m rows | funding | coverage 1m | 1m→5m mismatches (price / volume) |
  |---|---|---|---|---|---|
  | BTCUSDT (60 m) | 2,637,685 | 527,537 | 5,496 | 100.0% | 6 / 10 of 527,537 |
  | ETHUSDT (60 m) | 2,637,700 | 527,540 | 5,496 | 100.0% | 5 / 9 |
  | BNBUSDT (60 m) | 2,637,715 | 527,543 | 5,496 | 100.0% | 4 / 7 |
  | SOLUSDT (36 m) | 1,586,527 | 317,305 | 3,306 | 100.0% | 3 / 3 |
  | XRPUSDT (36 m) | 1,586,542 | 317,308 | 3,306 | 100.0% | 3 / 3 |
  | SUIUSDT (36 m) | 1,586,549 | 317,310 | 3,306 | 100.0% | 5 / 4 |
- 0 gaps, 0 duplicates, 0 invalid OHLC, 0 unclosed candles for every symbol. All six have the full
  requested history (SUI listed 2023-05, inside its 36-month window).
- The few 1m→5m mismatches sit on the SAME timestamps across coins (e.g. 2023-11-10/14,
  2024-10-28 21:10, 2025-01-14 13:30/15:00, 2025-01-29 01:35; BTC 2022-06/08 volume only) → Binance
  archive events, not our code. Largest price diff: SUI open 2.4% on one bar. Not repaired (§46): Layer A
  now marks any anchor whose own 5m candle or path touches such a bucket as `SOURCE_CONFLICT`
  (status 3) and excludes it.
- G7 metrics: BTC 1,350 days fetched before the run switched to `-NoMetrics` (2021-10-01 → 2026-10-05,
  5-min step, 8 gaps > 15 min). Remaining days/coins later, before P4B.

## R-011 · 2026-10-07 · P4A first result — BTCUSDT h15 (development data only)
- Owner said "go P4A" while the full P3 run was still running (BTC P3 reports were complete).
- Rows: fit 249,998 · calibration 62,501 · dev_val 134,416 (holdout asserted untouched). 531 s.
- Classifiers on dev_val: V3 endpoint labels AUC 0.706 (long) / 0.709 (short), base rate ~14.5%;
  V4 path labels (REFERENCE policy, net ≥ 0.06%) AUC 0.640 / 0.646, base rate ~10.7%. Brier below
  climatology for all four (e.g. V4 long 0.0939 vs 0.0962).
- With the inherited V3 probability floor 0.55 almost nothing trades (calibrated p ≥ 0.55 on 0.004–0.055%
  of rows): ARM1_V3 57 trades avg +0.033% (t 0.25); V3 signal + new exits 78 trades −0.128%;
  ARM2_V4 4 trades. Baselines always-long/short −0.127%.
- Finding: a fixed 0.55 floor does not fit horizons whose success base rate is ~11–15%; the decision
  gate must be calibrated in P5 on validation. Added clearly-labelled diagnostic arms with the EV gate
  only (no floor) — not tuned, not frozen.
- MFE/MAE quantile models trained (q25/q50/q75 per side); coverage and pinball vs constant baseline
  are in `D:\autofinx-ml-v4\reports\p4a\BTCUSDT_h15.json`.

## R-010 · 2026-10-07 · P2 gate — features + Layer A targets for all six coins
- Runner `-TargetsOnly -ForceRebuildTargets`: 47.9 min, exit 0.
- Exact V3 features (PHP builder, 0 NaN cells): BTC 526,673 · ETH 526,676 · BNB 526,679 ·
  SOL 316,441 · XRP 316,444 · SUI 316,446 rows (PHP build 5.7–10.4 min per coin).
- Layer A targets for 15/60/120/240/1440: 0 gap-invalid anchors on every coin. SOURCE_CONFLICT
  exclusions (Binance archive events, R-007): 4–12 buckets per coin → h15 16–48 anchors, h1440
  1,156–3,197 anchors (≤ 0.61%). h1440 build 56–97 s per coin.
- P2 status: DONE (pending owner sign-off with the P3 report).

## R-009 · 2026-10-07 · P3 first results — BTCUSDT h15 and h240 (development data only)
- Split (D-016 proposed): dev 2021-10-01 → 2026-01-03 23:15; holdout sealed from 2026-01-04 23:15
  (79,131 rows, untouched). 447,254 valid dev anchors with a causal feature row.
- Tests: Layer B simulator 16 tests (SPEC §78 A–F, G, H, I, L + gap fill + reference simulator on
  random paths, 4 seeds × 2 horizons × 2 sides × 3 policies × 2 chunk sizes); splits test; 42 V4 tests total.
- **Label audit (§79), reference policy (SL = p75 MAE-before-profit from dev-train, activation 0.18%):**
  - h240: V3 BUY-positive 169,981; of these **81,970 (48%) hit the stop first** on the realizable path;
    endpoint missed 5,649 profitable paths; V4 long opportunities 87,473; V4 WAIT 61.4%.
  - h15: V3 BUY-positive 71,337; 12,005 (17%) stop first; endpoint missed 3,323; V4 WAIT 78.5%.
  - ⇒ the endpoint label materially misstates what is realizable (first evidence for the V4 redesign).
- **Policy lab (always-LONG / always-SHORT, non-overlapping):** every policy (endpoint, reference,
  2×2 grid, volatility) averages ≈ −0.12% per trade (≈ the cost) on both dev_train and dev_val, e.g.
  h15 long endpoint val −0.119%, volatility val −0.127%; h240 long endpoint val −0.106%. The exit
  engine creates no fake performance; without a signal there is no edge (diagnostic only, C3).
- Weakness found in the reference: Layer A `peak_retracement` (max drop from peak to the path end)
  is too wide as a trailing reference (h240 p50 ≈ 1.0%), which makes activation irrelevant (GRID act1 =
  act2 identical at h240). P4/P5 need a "pullback before continuation" measure instead.
- Same-bar trailing ambiguity: up to 15% of trades (volatility policy, h15); 6% for the reference.
  Material — candidate for the optional aggTrade resolver (§26), owner decision before P5.
- Reports: `D:\autofinx-ml-v4\reports\p3\BTCUSDT_h15.json`, `BTCUSDT_h240.json`.

## R-008 · 2026-10-06 · P2 Layer A + exact V3 features — BTCUSDT 5 years
- Layer A definitions: `external-predictive-engine/v4/targets.py` docstring (entry = open of the 1m bar
  starting at T0; path = 1m bars in [T0, T0+H); times = end of the first bar; cost 0.12%).
- Tests: `tests/v4/test_targets.py` 13 tests incl. a slow per-anchor reference implementation compared
  on random paths with gaps, funding and chunk sizes 3/1000 for H = 1, 15, 60 — all equal. Total V4
  tests 24/24.
- Run (BTC, 527,537 anchors, 2021-10 → 2026-10): h15 1.6 s, h60 3.6 s, h120 8.6 s, h240 12.9 s,
  h1440 68.0 s (load 1.2 s); RAM estimate ≤ 330 MB (chunked, never N × H); 0 gap-invalid anchors;
  out-of-range = the newest H minutes only. Targets on disk: 255 MB for 5 horizons.
- Sanity on the **first 50% of anchors only** (holdout sealed): h15 long MFE p50 0.117% (≈ the 0.12%
  cost), long time-exit net p50 −0.119%, reaches +cost at some point 49.1%, profit-bar ambiguous 17.8%;
  h240 MFE p50 0.519%, MAE-before-profit p50 0.087% / p90 0.481%, reaches +cost 86.4%, ambiguous 10.2%.
- Exact V3 features through the PHP builder (isolated sqlite `D:\autofinx-ml-v4\features\features.sqlite`):
  527,537 native 5m candles imported; `ml:features:build --full` 533 s; exported 526,673 v3 rows
  (= anchors − 865 lookback), 37 features, 0 NaN; 2,637,370 V3 label rows exported for the P3 audit.
- Cross-check PHP V3 endpoint label vs Python Layer A time-exit gross (first 50%, 263,705 anchors):
  corr 0.999985 (h15) / 0.999999 (h240), median |diff| 0.0000%, p99 0.005% (entry at the next 1m open
  vs the anchor close). 100% of feature anchors exist in the targets.
- Feature-side leakage test K already exists in PHP: `tests/Unit/Ml/ShadowFeatureLabelTest.php`
  `test_future_candles_cannot_enter_the_feature_vector` (all schemas).
