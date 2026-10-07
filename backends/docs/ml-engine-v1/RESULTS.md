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

## R-023 · 2026-10-07 · P2 event setups (D-037, fixed rules) — BTC/ETH h60/h240, dev_train, cost 0.12
- Avg gross / net per executed trade (non-overlapping), exit E1 (stop 1.5 ATR + time) unless noted:
  | Setup | BTC h60 | BTC h240 | ETH h60 | ETH h240 |
  |---|---|---|---|---|
  | S1 trend pullback long | +0.001 / −0.120 | +0.010 / −0.112 | +0.007 / −0.114 | +0.016 / −0.107 |
  | S1 trend pullback short | −0.007 / −0.127 | −0.011 / −0.129 | +0.004 / −0.116 | +0.016 / −0.104 |
  | S2 range reversion long | −0.019 / −0.140 | −0.019 / −0.141 | −0.014 / −0.134 | −0.019 / −0.141 |
  | S2 range reversion short | +0.013 / −0.107 | +0.022 / −0.096 | −0.014 / −0.134 | +0.004 / −0.114 |
  | context only (S1 long) | +0.002 / −0.119 | +0.002 / −0.120 | +0.003 / −0.118 | +0.004 / −0.118 |
  | always long | +0.000 / −0.120 | −0.002 / −0.124 | −0.001 / −0.122 | −0.003 / −0.125 |
- E2 (activation + 1 ATR trail) is worse everywhere: gross −0.02 to −0.04. Early trailing cuts winners.
- Every bootstrap interval is below 0. **No setup is promising** under the D-037 rule (net > 0 with
  CI > 0, ≥ 3/4 slices, beats context).
- Triggers move gross by at most ~+0.01–0.02 % versus context/always. The conditioning does not create a
  move large enough to pay the 0.12 % cost.
- Reading:
  - With OHLCV-derived conditions, the expected gross move per trade stays ≈ 0 at 1–4 h. This repeats
    the P4A / P1 picture with completely different (rule-based) entries.
  - Per the D-036 stop rule, P3 payoff modelling on these setups is not justified. The next step needs
    either new information (P4 context: cross-asset, funding, open interest / taker metrics) or
    materially cheaper execution with real fee data (P5).

## R-022 · 2026-10-07 · P1 direction-signal diagnostics — BTC/ETH h60/h240 (dev_train D-025 folds, V3 base, P0 code)
- Paired test: model-chosen side minus opposite side, same anchors, same REFERENCE exits (gross, costs cancel),
  1-day block bootstrap 90 %.
  | Cell | AUC long/short (3 folds) | paired lift %/opportunity (CI90) | P1 rule |
  |---|---|---|---|
  | BTC h60 | .59/.60 · .55/.55 · .54/.55 | +0.002 (−0.005..0.008) · **+0.010 (0.002..0.018)** · **+0.008 (0.002..0.015)** | **pass (2/3)** |
  | BTC h240 | .55/.53 · .54/.55 · .54/.53 | −0.009 · +0.016 · +0.008 (all CIs include 0) | fail |
  | ETH h60 | .56/.58 · .53/.52 · .53/.53 | +0.007 · −0.003 · +0.003 (CIs include 0) | fail |
  | ETH h240 | .50/.52 · .55/.53 · .53/.53 | −0.018 · +0.016 · −0.019 | fail |
- BTC h60 by margin decile: the top decile lift is +0.034 / +0.019 % in folds 2 and 3, i.e. the signal
  concentrates where the model is most confident.
- Executed (non-overlapping) BTC h60 top-margin 10 %: −0.110 / −0.113 %/trade, against always-long −0.122 /
  −0.126. About 0.01–0.015 better, but still about −0.11 after cost. Cost 0.15 / 0.18 only makes it worse.
- Reading:
  - A **statistically detectable but economically tiny** direction signal exists at BTC h60. It is
    ~0.01 %/opportunity, about one tenth of the 0.12 % round-trip cost.
  - 1 of 4 cells passed, so multiple testing applies.
  - The rule allows P2 for BTC h60. The economic gap says P2 must find setups where the move size per
    trade is much larger (event-conditioned entries), not finer ranking of every anchor.

## R-021 · 2026-10-07 · D-033 TAKE/SKIP + D-035 maker entry — smoke BTCUSDT h60 (dev_train folds) — **SUPERSEDED in part (D-036): C1 numbers used the defective fill-minute simulation (fix A); meta used the P4B selection (fix D)**
- Runs end to end (772 s). Proposals on the fold tests: 3,260 / 9,915 / 7,442. Executed OFF trades:
  1,584 / 5,460 / 4,482.
- **Meta model:**
  - Test AUC 0.518 / 0.522 / 0.530: almost no information beyond the base models.
  - META_50 / META_30 change the executed avg net by −0.007 to +0.011 %/trade. All NO_VALUE.
- **EV_GATE (P5-style):** 0–6 trades. Same picture as the P5 smoke.
- **C0 vs C1 on the same proposals (OFF arm):**
  - C0: −0.137 / −0.146 / −0.147 %/trade.
  - C1: −0.105 / −0.113 / −0.113 %/trade (fill rate 93–96 %).
- **Adverse selection is strong:**
  - Proposals that filled: C0 net −0.141 / −0.149 / −0.155.
  - Proposals the limit missed: −0.009 / −0.008 / −0.026. The missed ones were the good ones.
  - The 0.04 % fee saving is partly eaten: C1 helps by about 0.03 %/trade but stays clearly negative.
- Gross per proposal is ≈ −0.02 %: the level-gated proposals carry no positive gross edge at h60.
- No implementation problem seen. The 30-cell run starts automatically after the P5 baseline
  (`run-meta-after-p5.sh`).

## R-020 · 2026-10-07 · Optional layers h15 (D-030): G7-T (15 cols) and v13 components, 6 coins (dev_train folds) — **pre-P0 (fix D, verdict rev); replay pending**
- Baseline = the P4B selection per coin.
- **FEATURE role:**
  - NO_VALUE in 11 of 12 layer × coin cases.
  - Only XRP G7-T was USEFUL_AS_FEATURE (gains +0.0032 / +0.0021 / −0.0002 AUC).
  - v13 was never useful.
- **WAIT role (tradeability TAKE/SKIP on the layer columns):**
  - Tradeability AUC 0.51–0.59.
  - Every verdict was NO_VALUE or OVER_FILTERED.
  - Keeping the top 30 % moved the executed avg net from about −0.13 % to about −0.125 %/trade: still
    ≈ −cost. The always-take reference stream has ≈ 0 gross edge, so filtering it cannot create profit.
- **RULE role (v13 own gates):** chop ≤ 62 gave NO_VALUE (≈ no change); TREND regime gave OVER_FILTERED
  and was slightly worse.
- **Caveat (design flaw found):**
  - With keep share 30 % and the profit-retention floor at 50 %, OVER_FILTERED is near-automatic unless
    the winners are concentrated.
  - The rule should compare retention to the keep share (D-030 revision proposed).
  - Conclusion unchanged: the net improvement is ≤ 0.007 %/trade everywhere.

## R-018 · 2026-10-07 · P4B all 30 cells + Stage B audit (dev_train folds only) — **pre-P0: reference levels leaked across folds (fix C), overlap ranks mishandled ties (fix E); replay pending**
- 30/30 cells. 9 runs were lost to out-of-memory / fork failures and re-run one symbol × horizon per
  process; the code and the rule (D-025) were unchanged. Audit: `D:/autofinx-ml-v4/reports/p4b_audit/AUDIT.md`.
- Selected groups:
  | Sym | h15 | h60 | h120 | h240 | h1440 |
  |---|---|---|---|---|---|
  | BTC | g5 | g3+g5 | g3+g5 | g3+g6 | none |
  | ETH | g4 | g5 | g2 | g1 | g1+g4 |
  | BNB | g4 | none | none | g1 | g1 |
  | SOL | g2 | none | none | g1 | none |
  | XRP | none | none | g1 | none | g1+g2+g3 |
  | SUI | g5 | none | none | g3+g5 | g1+g5 |
- Group stats (kept / tried · median fold AUC gain · cells positive in all 3 folds):
  - g1 8/30 · +0.0005 · 6
  - g2 3/30 · +0.0003 · 5
  - g3 5/30 · +0.0002 · 6
  - g4 3/30 · −0.0004 · 2
  - g5 7/30 · +0.0006 · 4
  - g6 1/30 · −0.0007 · 0
- Overlap (median max |Spearman| vs the other groups, same for every coin within ±0.03):
  - g3 momentum ≈ 0.91 and g4 volatility ≈ 0.89: mostly duplicates of the base / other groups.
  - g6 volume ≈ 0.84.
  - base ≈ 0.80, v13 ≈ 0.74, g1 ≈ 0.70, g5 ≈ 0.64, g2 ≈ 0.49.
  - **g7t ≈ 0.40: the most novel information.**
- Reading:
  - Pattern groups add very little (median gain +0.0002–0.0006 AUC). No group is consistently useful
    across coins or horizons.
  - Baseline AUC falls from ~0.60–0.72 (h15) to ~0.50–0.54 (h1440). Selections at h1440 sit on a near-random
    baseline and are likely noise.
  - v13's components largely duplicate existing features (0.74).
  - G7-T is mostly new information, so the optional-layer test of G7-T is the more informative next step.

## R-019 · 2026-10-07 · Strategy Lab candidate #1 "AutoFinX v13" Pine — S0 standalone (development data only)
- Owner upload, audited in `strategy-lab/candidates/autofinx-v13/AUDIT.md`: causal, no lookahead; the HTF
  read differs between TradingView historical and live; webhook built for live Future Trade.
  Script defaults, 0 variants, AutoFinX execution (1m path, worst-case same minute, 0.12% + funding).
  Holdout cut off before the simulation.
- Avg net per trade (dev_train / dev_val), trades, PF, t:
  | Sym | 5m dev_train | 5m dev_val | 15m dev_train | 15m dev_val |
  |---|---|---|---|---|
  | BTC | 14,322 · −0.117% · PF 0.52 · t −31 | 6,117 · −0.117% · 0.45 · −25 | 4,539 · −0.098% · 0.73 · −8.5 | 1,979 · −0.113% · 0.64 · −8.2 |
  | ETH | 13,272 · −0.119% · 0.60 · −23 | 5,811 · −0.117% · 0.63 · −15 | 4,443 · −0.097% · 0.79 · −6.5 | 1,901 · −0.106% · 0.78 · −4.5 |
  | BNB | 14,483 · −0.115% · 0.58 · −25 | 6,484 · −0.119% · 0.53 · −21 | 4,777 · −0.122% · 0.72 · −8.8 | 2,148 · −0.123% · 0.69 · −7.0 |
  | SOL | 8,835 · −0.123% · 0.70 · −14 | 3,679 · −0.134% · 0.61 · −12 | 2,874 · −0.089% · 0.86 · −3.5 | 1,208 · −0.103% · 0.80 · −3.2 |
  | XRP | 7,230 · −0.108% · 0.70 · −11 | 3,180 · −0.118% · 0.63 · −10 | 2,373 · −0.175% · 0.72 · −5.9 | 1,023 · −0.067% · 0.87 · −1.8 |
  | SUI | 8,891 · −0.109% · 0.77 · −10 | 3,632 · −0.135% · 0.65 · −10 | 2,938 · −0.117% · 0.85 · −3.7 | 1,169 · −0.105% · 0.82 · −2.8 |
- Negative in all 24 cells and in every calendar year. BTC 5m gross is +0.003%/trade, so the entries
  carry ≈ zero direction edge, and costs alone decide the result. Even at TradingView's 0.08% cost and
  no funding, every cell is still negative (e.g. BTC 5m −0.077%).
- BTC 5m exits (dev_train): TP1/TRAIL 37%, STOP/STOP 30%, STOP/TRAIL 18%, FLIP 15%. Average hold 57 min.
- Verdict S0: **REJECTED_NO_VALUE** as a standalone strategy. If it is running live through the
  Future Trade pine-webhook, this evidence says it loses after costs (owner decision; nothing changed).
- S1 (v13 components as soft features, 22 columns, no hard filter) is queued for the P4B-style ablation
  once P4B finishes. Its verdict comes from that ablation, not from S0.

## R-017 · 2026-10-07 · P5 smoke — BTCUSDT h60 / h240 + EV diagnostics (development data only)
- Both ran end to end (543 s / 676 s, CPU shared with the P3 chain), V3 base features. Quality gate passed
  for all components. SL caps: h60 long 0.324% / short 0.317%; h240 0.513% / 0.509%.
- **h60 REJECTED**: 1.0k–5.8k rows per side pass the level gates, 0 trades for all 12 exit policies.
- **h240 REJECTED**: 21k–34k long / 20k–32k short rows pass the level gates, 0 trades.
- EV diagnostics, h240, policy a1.0_b0.8_vol (all 12 similar):
  | Side | avg win | avg other | candidate base rate | break-even p | max EV on tradeable rows |
  |---|---|---|---|---|---|
  | long | +0.310% | −0.266% | 23.8% | ≈ 46% | −0.12% |
  | short | +0.295% | −0.267% | 22.4% | ≈ 48% | −0.14% |
- Consistency check (D-029, proposed): the classifier was trained on REFERENCE-policy labels while the EV
  parameters come from each candidate policy. Its score was re-calibrated per candidate on the dev_train
  calibration slice. Before: mean p 0.19, max EV −0.083%. After: mean p 0.24, max EV −0.12%. The
  calibrated probability never rises much above the base rate, so the classifier score carries almost no
  information about candidate-policy success.
- Reading: the zero trades are a real result, not a proxy artifact. Under these exit policies a BTC 4h trade
  wins about 1 in 4, and a win is only slightly larger than a loss. EV would need p ≈ 0.46, about twice
  the base rate, and the V3 base features do not separate those rows. Honest REJECT. P5-B, P5-C and the
  final retrain are still not exercised on real data.

## R-015 · 2026-10-07 · P4A all coins × all horizons (dev_val, development data only)
- The chain was killed at XRP h120 by a Windows power-off event (06:55, no traceback). The rest (XRP/SUI h120,
  all h240, h1440) were resumed with `run-p4a-resume.sh` (07:01 → 09:00, `logs/chain-p4a-resume.log`).
  All 30 symbol × horizon reports present.
- Trades / avg net % / t-stat on dev_val (non-overlapping, worst-case exits, 0.12% cost + funding):
  | Sym | H | AUC V3 L/S | AUC V4 L/S | ARM1_V3 | V3 signal + new exit | ARM2_V4 | EV-only V4 (diag) |
  |---|---|---|---|---|---|---|---|
  | BTC | 15 | .706/.709 | .653/.649 | 57 / +0.033 / 0.25 | 78 / −0.135 / −6.6 | 31 / −0.053 / −1.4 | 32 / −0.034 / −0.8 |
  | ETH | 15 | .638/.632 | .612/.608 | 130 / −0.049 / −0.4 | 189 / −0.112 / −5.5 | 0 | 0 |
  | BNB | 15 | .685/.673 | .625/.613 | 184 / −0.010 / −0.1 | 319 / −0.146 / −13.7 | 0 | 2 / −0.270 |
  | SOL | 15 | .612/.604 | .570/.561 | 83 / −0.217 / −1.3 | 133 / −0.142 / −6.7 | 0 | 0 |
  | XRP | 15 | .636/.628 | .592/.596 | 125 / +0.116 / 0.5 | 208 / −0.148 / −8.1 | 13 / −0.146 / −2.8 | 17 / −0.132 / −3.1 |
  | SUI | 15 | .582/.588 | .546/.558 | 52 / +0.063 / 0.1 | 88 / −0.101 / −2.6 | 0 | 0 |
  | BTC | 60 | .617/.616 | .559/.564 | 25 / +0.558 / 1.3 | 48 / −0.154 / −4.8 | 0 | 40 / −0.167 / −4.8 |
  | ETH | 60 | .578/.565 | .555/.558 | 150 / −0.178 / −1.3 | 506 / −0.140 / −9.6 | 14 / −0.111 / −2.4 | 52 / −0.132 / −4.2 |
  | BNB | 60 | .600/.584 | .543/.541 | 44 / +0.289 / 1.2 | 122 / −0.133 / −4.9 | 39 / −0.174 / −5.1 | 54 / −0.186 / −5.9 |
  | SOL | 60 | .555/.531 | .546/.541 | 17 / +1.548 / 1.0 | 34 / −0.151 / −1.4 | 19 / −0.141 / −2.3 | 39 / −0.157 / −4.1 |
  | XRP | 60 | .570/.557 | .547/.551 | 252 / −0.027 / −0.1 | 958 / −0.145 / −12.5 | 0 | 0 |
  | SUI | 60 | .531/.534 | .544/.552 | 140 / +0.382 / 1.9 | 375 / −0.108 / −3.1 | 91 / −0.089 / −1.9 | 697 / −0.111 / −6.8 |
  | BTC | 120 | .583/.586 | .547/.549 | 71 / −0.040 / −0.4 | 138 / −0.044 / −1.0 | 0 | 286 / −0.115 / −6.6 |
  | ETH | 120 | .552/.551 | .550/.554 | 247 / −0.236 / −2.8 | 744 / −0.153 / −9.6 | 0 | 54 / −0.183 / −4.8 |
  | BNB | 120 | .561/.553 | .534/.541 | 203 / −0.153 / −1.6 | 608 / −0.114 / −5.9 | 2 / −0.086 | 2 / −0.086 |
  | SOL | 120 | .529/.509 | .542/.541 | 84 / +0.651 / 1.8 | 260 / −0.022 / −0.4 | 15 / +0.133 / 0.6 | 37 / −0.059 / −0.7 |
  | XRP | 120 | .552/.536 | .542/.547 | 171 / −0.182 / −1.4 | 781 / −0.160 / −9.3 | 0 | 0 |
  | SUI | 120 | .519/.520 | .551/.542 | 101 / +0.462 / 2.3 | 276 / −0.032 / −0.6 | 112 / −0.111 / −2.4 | 1483 / −0.117 / −7.6 |
  | BTC | 240 | .559/.554 | .542/.547 | 459 / −0.124 / −1.9 | 1218 / −0.114 / −7.7 | 0 | 394 / −0.110 / −5.5 |
  | ETH | 240 | .542/.542 | .546/.548 | 81 / −0.117 / −0.5 | 203 / −0.117 / −2.6 | 0 | 233 / −0.084 / −2.1 |
  | BNB | 240 | .536/.536 | .546/.548 | 537 / −0.135 / −2.0 | 2001 / −0.108 / −7.7 | 0 | 0 |
  | SOL | 240 | .516/.509 | .527/.538 | 646 / −0.102 / −1.4 | 2077 / −0.108 / −5.9 | 0 | 789 / −0.104 / −4.2 |
  | XRP | 240 | .530/.522 | .554/.541 | 67 / −0.440 / −1.2 | 367 / −0.157 / −4.3 | 11 / +0.143 / 0.5 | 107 / −0.078 / −1.4 |
  | SUI | 240 | .519/.509 | .545/.541 | 46 / +0.302 / 0.8 | 153 / −0.052 / −0.6 | 0 | 774 / −0.127 / −4.8 |
  | BTC | 1440 | .512/.536 | .509/.521 | 145 / −0.033 / −0.2 | 357 / −0.094 / −1.9 | 0 | 792 / −0.137 / −4.4 |
  | ETH | 1440 | .499/.523 | .537/.520 | 36 / −0.037 / −0.1 | 139 / −0.105 / −1.2 | 0 | 210 / −0.103 / −1.2 |
  | BNB | 1440 | .500/.512 | .533/.556 | 176 / −0.008 / −0.0 | 859 / −0.111 / −2.6 | 0 | 1138 / −0.108 / −3.5 |
  | SOL | 1440 | .506/.513 | .495/.512 | 102 / −0.294 / −0.7 | 598 / −0.100 / −1.5 | 0 | 201 / −0.038 / −0.4 |
  | XRP | 1440 | .494/.496 | .514/.512 | 74 / +0.519 / 0.7 | 354 / −0.106 / −1.7 | 13 / +0.527 / 0.5 | 15 / +0.377 / 0.5 |
  | SUI | 1440 | .514/.516 | .526/.521 | 114 / −0.171 / −0.4 | 637 / −0.155 / −2.3 | 0 | 1647 / −0.153 / −3.9 |
- Reading (30 cells): no V4 arm is positive with t ≥ 1 anywhere (best: XRP h1440 ARM2 13 trades t 0.5).
  "V3 signal + new exit" is negative in all 30 cells, so the V3 signal does not survive realistic
  SL/trailing exits. AUC falls toward 0.5 as the horizon grows (h1440 ≈ 0.50–0.55, i.e. no ranking).
  Five ARM1_V3 cells (endpoint exit, no SL) show t > 1: SUI h120 2.3, SUI h60 1.9, SOL h120 1.8,
  BTC h60 1.3, BNB h60 1.2. SUI recurs (h15/h60/h120/h240 all positive), but these cells use the
  non-realizable endpoint exit, the same SUI signal loses with stops (all SUI "new exit" cells negative),
  and with 30 cells tried, one or two at t ≈ 2 are expected by chance. Noted as a lead for P4B, not an edge.

## R-014 · 2026-10-07 · Stratified aggTrades resolver — BTCUSDT h15/h60 (30 random dev days)
- 2,343 flagged (intrabar-ambiguous) trades resolved from aggTrades, out of 122,787 flagged in dev. 684 s.
  | Policy / side | resolved | outcome changed | avg net change | conservative understated / overstated |
  |---|---|---|---|---|
  | h15 REFERENCE long / short | 340 / 340 | 14.7% / 12.9% | +0.120% / +0.117% | 66% / 9% · 63% / 14% |
  | h15 VOLATILITY long / short | 600 / 646 | 10.0% / 7.3% | +0.060% / +0.056% | 59% / 12% · 57% / 16% |
  | h60 REFERENCE long / short | 55 / 60 | 21.8% / 20.0% | +0.105% / +0.156% | 38% / 35% · 48% / 22% |
  | h60 VOLATILITY long / short | 135 / 167 | 2.2% / 1.2% | +0.085% / +0.098% | 60% / 12% · 65% / 11% |
- Reading: the conservative same-bar rule is pessimistic on ambiguous trades (it understates net by
  ~0.06–0.16% per ambiguous trade). The 1m evaluation is therefore a lower bound, not a rosy one. This
  bias applies only to the ambiguous subset and is far too small to turn the R-015 / R-017 negatives positive.
  h60 REFERENCE samples are small (55/60).

## R-013 · 2026-10-07 · P3 all coins × horizons (development data only)
- Full table: `D:utofinx-ml-v4
eports\p3\SUMMARY.md` (6 coins × 5 horizons, D-023 code).
- V4 WAIT share 60–87% (highest at h15 and h1440). V3 endpoint "buy+" rows are 2–3× the V4 long
  opportunities. The share where they disagree grows with the horizon (15% at h15 → 41% at h1440 for SOL).
- Always-take baselines on dev_val: negative everywhere from h15 to h240 (−0.05% to −0.12% per trade). At
  h1440 only short positives (SOL +0.045%, XRP +0.090%, SUI +0.299%, 281 trades), which matches a falling
  dev_val market rather than a model signal.
- Same-bar trail 0% everywhere; ambiguous long rows from 31k (BNB h15) down to ~0.9k (SOL h1440).

## R-016 · 2026-10-07 · P5 smoke — BTCUSDT h15 (code check, development data only)
- `p5_run` ran end to end without errors (665 s, CPU shared with the P3 chain), V3 base features
  (no P4B report yet). Windows (dev_val): P5-A 53,766 · P5-B 40,323 · P5-C 40,323 rows.
- SL caps (p90 Layer A adverse-before-profit, dev_train, unambiguous): long 0.200%, short 0.197%.
- Quantile quality gate: all 6 components PASSED (MFE, adverse-before-profit, pullback × 2 sides):
  pinball better than the constant baseline in 3/3 sub-folds, q25–q75 coverage 46–49%, crossing 0%.
- Result: **REJECTED** — 0 trades for all 12 exit policies in P5-A. Reading: at 15m, whenever the
  conservative predicted reward (q25 MFE) clears cost + edge (0.18%), the required stop exceeds the cap,
  so every row is WAIT. Per-side WAIT-reason counts are now recorded in the stage reports to make the
  cause explicit (added after this run).
- Not a verdict on other horizons; BTC h60/h240 smoke started to exercise P5-B/C and the final retrain.

## R-012 · 2026-10-07 · Corrections D-023 — BTCUSDT h15 P4A old vs corrected (dev_val)
- Tests 49/49 (adds worst-case branch tests; resolver returns exit bar).
- Unresolved intrabar rows excluded from V4 labels: 7.97% (long) / 7.77% (short) of dev_train.
- | Item | Before D-023 | After D-023 |
  |---|---|---|
  | V3 AUC long/short | 0.7055 / 0.7090 | 0.7058 / 0.7091 |
  | V4 AUC long/short | 0.6397 / 0.6462 | 0.6527 / 0.6491 |
  | V4 label base rate long | 10.76% | 9.65% (clean rows only) |
  | ARM2_V4 (floor 0.55) | 4 trades, −0.044% | 31 trades, −0.053%, t −1.43 |
  | V3 signal + new exits | 78 trades, −0.128% | 78 trades, −0.135% (worst-case) |
  | EV-only V3 endpoint (diag) | 996 trades, −0.094% | 996 trades, −0.094% |
  | EV-only V4 (diag) | 5 trades, −0.089% | 32 trades, −0.034% |
- Reading: BTC h15 shows no tradable edge on dev_val; more trades (EV-only) lose more. Corrections made
  evaluation stricter, not rosier.
- Wording correction for R-009: read "endpoint label materially misstates what is realizable" as "V3
  endpoint labels are materially misaligned with the new SL/trailing execution policy" (D-023 #2).
- R-009 / R-011 drawdown numbers are additive trade-% diagnostics, not account drawdown (D-023 #5).
- The P3 full run and BTC resolver sample from before D-023 were stopped/ignored and are re-run with the
  corrected code.

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
