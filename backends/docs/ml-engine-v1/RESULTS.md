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
