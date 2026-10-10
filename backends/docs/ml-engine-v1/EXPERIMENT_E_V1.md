# EXPERIMENT E v1 - calibrated probability and Quick Trade decision intelligence (pre-registered plan)

Status: FROZEN before any result is computed (sha256 in `D:\autofinx-ml-v4\exp_e_v1\PLAN_SHA256.txt`). Amendments only before real-data results, original sha kept in `PLAN_SHA256_original.txt`.
Owner request 2026-10-10 ("EXPERIMENT E"). Agent: ml-engine-v1. Research only: no orders, no keys, no server, no DB, no Laravel / Ratio Bot / Quick Trade change, no model installed, never git commit.

**Labelling rule for every number this experiment produces: REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT.** 2023-2025 has been looked at by many earlier experiments
(V4 P4-P8, Edge Search v1/v2, M1, Regime v1, Quick Trade Replay v1). The only never-opened data is the sealed holdout (BTC 2026-01-04 onward; global seal 2026-01-03), reads = 0, and it
stays sealed. There is NO fresh independent 2-year holdout besides it, so this study builds the strongest valid chronological design on the reused window and does NOT weaken leakage
controls to mimic a requested "3 years train + 2 years test" split (the usable data is 2021-10 .. 2025-12, 4.2 years; a 3+2 split would leave 1.2 years of training and drops calibration).

## 0. Questions (E0-E13 of the owner spec) and hypotheses

- E0 repo / artifact audit. E1 audit: do genuine out-of-fold (OOF) predictions with provenance exist? E2 if not: regenerate causal walk-forward OOF predictions of the EXISTING V3 / V4 LightGBM recipes.
- H-CAL: a calibrated probability (fit on a later matured calibration window, evaluated on a later year) of the Quick Trade targets A-C beats the base-rate probability out of sample (Brier / log loss).
- H-CONF: higher calibrated confidence buckets have higher observed hit rates AND higher net expectancy.
- H-ECON: a BUY / SELL / WAIT decision framework built on calibrated net-EV produces positive net P&L per trade at the live presets and the verified costs.
- H-75: the model produces calibrated >= 75 % confidence events that are profitable (descriptive study; if the model never reaches 75 % this is stated).
- Null for all: no usable out-of-sample information beyond the base rate. A "no" is a valid, reported result.

## 1. Data and the sealed-holdout guard

- Local Binance USD-M files only (`D:\autofinx-ml-v4`): 5m and 1m klines, funding, V3 features (`features/<SYM>/v3.parquet`, 37 features from the PHP ShadowFeatureBuilder, 5-minute anchors), Layer A targets (`targets/<SYM>/h60.parquet`).
- Window: 2021-10-01 .. 2025-12-31 (`CUT` = 2026-01-01 00:00 UTC, earlier than the 2026-01-03 global seal). The loader (`exp_e_v1/data.py`) opens ONLY monthly kline / funding files named `YYYY-MM` <= `2025-12`, never daily / tail files,
  asserts `max(bar close) <= CUT` and raises on any request for a cut > `CUT`. The V3 feature and target parquet files are single files that physically contain rows after the seal; they are read with a predicate
  `anchor_time_ms < CUT` (rows after the cut are never exposed to any code, asserted) - this is the one place the file itself is not refused, disclosed as such. Holdout market-data reads = 0.
- Coins: live Quick Trade symbols BTC, ETH, BNB, SOL (`BinanceTimedFuturesProvider.php:50-55`). XRP / SUI excluded (not live). BTC / ETH / BNB have data from 2021-10 and carry the verdict (3 test years); **SOL starts 2023-10-04** (listing of the local data), gets
  one test fold (2025) and is report-only.
- Alignment (verified on real data before this plan, re-tested in the suite): V3 feature anchor `a` = close time of the 5m bar that opens at `a - 5m`; `ret_1(a)` equals that bar's close-to-close return. Entry = open of the 5m bar that opens at `a`
  (the replay's `entry_index`). No feature uses a bar that opens at or after `a`.

## 2. Event grid, presets, costs

Presets (verified, `config/quick_trade.php:131-135`): **P1 5m chart / 3600 s (12 bars, primary)**, P2 5m / 1800 s (6 bars), P3 15m / 3600 s (events only at 15-minute anchors, 12 bars). Costs: **0.12 % round trip measured (D-040) = primary**, 0.14 % (code),
0.11 % (code maker entry, report-only), 0.08 % (research maker, report-only), plus actual funding (side-signed). The decision is always made at 0.12 %; other costs are applied to the same decisions (cost sensitivity).
An event = every valid 5m anchor of the coin (P1, P2) or every 15m-boundary anchor (P3). Events overlap heavily; this is handled by day-block bootstrap and by an explicit effective-N (section 8).

## 3. Targets (precise)

For an event with entry `E` (open of the bar at the anchor), side s in {L, S}, horizon H bars of 5m (12 / 6 / 12), range unit `R = min(5, atr%_chart * sqrt(h_chart))` in % (the code's `rangePct`, `arms.range_unit`; ATR 14 of the preset's chart timeframe, signal bar = last closed bar):

- `gX1_s`: gross % of the side at the CLOSE of the last horizon bar (expiry exit, the replay's X1); `net_X1_s = gX1_s - 0.12 - f_s` (f_s = side-signed actual funding between entry and exit).
- **A (terminal direction)**: `yA = 1[gX1_L > 0]`; rows with `gX1_L == 0` are excluded from A only.
- **B_s (barrier, fixed Quick Trade exit policy)**: SL = 1.0 R and TP = 1.0 R from E (the replay's `X2_k1.0_r1`, polled on 5m bars, gap fills at the worse open, same-bar SL + TP = AMBIGUOUS counted as the stop). `yB_s = 1` iff TP is hit first inside the horizon; SL / GAP / AMBIGUOUS / expiry-without-either = 0 (the timeout share is reported; excluding timeouts would condition on the future). `net_B_s = gB_s - 0.12 - f_B_s`.
- **C_s (after-cost trade success, expiry exit)**: `yC_s = 1[net_X1_s > 0]`.
- **D_s (expected net return)**: `net_X1_s` (regression target).
- Events with non-finite R or an incomplete window, or whose exit lies at or after `CUT`, are excluded (labels not mature). B is defined for P1 and P2 only (P3 uses P1 events at 15m anchors for A, C, D; its own-R barrier is not evaluated).

## 4. Models (the EXISTING recipes, fold-local, nothing redesigned)

LightGBM, the V3 tree settings unchanged (`v4/train_v4.py::TREE`: 300 trees, lr 0.03, 31 leaves, min_child_samples 200, subsample 0.8, colsample 0.8, lambda 1, seed 42, deterministic), the same 37 V3 features, the same 180-day half-life time weights (reference = last FIT row of the fold).
**Preprocessing is fold-local**: LightGBM needs no scaling; the only data-dependent steps (half-life reference time, V4 reference stop / activation / trail levels, calibrators, EV averages) use fit / calibration rows only.
- **Layer 1, EXISTING native models** (h60): `V3` = endpoint-label classifiers (`long: gross > 0.18 + fund`, `short: -gross > 0.18 - fund`, long and short), `V4` = path-label classifiers (net >= 0.06 under the fold's REFERENCE exit levels from the fold's FIT rows, worst-case ambiguity, ambiguous rows excluded from fitting, D-023), both on the V3 features
  (= the P4A / P5 "arm2" recipe). Raw output = the booster probability; the recipe's own isotonic step is NOT used (the calibrators below are applied uniformly). The V3 / V4 scores are mapped onto the Quick Trade targets by one-dimensional calibrators fit on the calibration window (A from `s_L - s_S`, B_s and C_s from `s_s`).
- **Layer 2, target-matched `Q`** (same algorithm, params, features, weights; only the label is the Quick Trade target): classifiers for A, B_L, B_S, C_L, C_S and regressors (L2 objective) for D_L, D_S; P1 and P2 (P3 reuses P1 A / C / D). This is the new-label application of the existing recipe, labelled as such; it is not a new model design.
- **Ablations (P1; A, C_L, C_S)**: `VOL` (only vol_12, vol_48, vol_24h, vol_regime, range_1, range_mean_12), `TREND` (only ret_*, ema_gap_*, rsi_14, dist_*, range_pos_72h, vwap_gap_24h), `SOFT` (V3 features + the regime / tradeability soft scores as FEATURES, not gates: R, expected move M = 0.55 R, tradeability T and its 7 components, regime code, breakout dir, ADX, vol / BBW percentile, structure; from `regime_v1.features.compute` on the signal bar, prefix-invariant by its own tests).
- Baseline arms: `BASE` (calibration-window base rate = constant probability), `C50` (constant 0.5), rule arms from the Quick Trade replay (B momentum `tsmom n = h`, C Bollinger fade `bb_z k = 2`, always-long, random side = mean of both sides, the code's volatility gate G1a `0.55 R >= 0.28 %` as a WAIT filter), all on the SAME events and costs.
- **P2 has no existing native model (no h30 V3 / V4 exists and building one is a redesign): Layer 1 is evaluated at h60 / P1 and P3 only; Q covers P1, P2, P3.**

## 5. Walk-forward design (annual test folds, expanding, calibration window before every test fold)

Embargo E = 24 h (>= the 60-minute horizon and the 1,440-minute `MAX_HORIZON_MIN`). A row is MATURE for a boundary T iff `exit_time + E <= T`. Rows that are not mature are excluded (tested).

| test year | FIT rows (anchors) | CALIBRATION window | TEST | embargo |
|---|---|---|---|---|
| 2023 | first valid anchor (2021-10) .. 2022-06-30, mature at 2022-07-01 | 2022-07-01 .. 2022-12-31, mature at 2023-01-01 | 2023-01-01 .. 2023-12-31 | 24 h before each boundary |
| 2024 | first .. 2023-06-30, mature at 2023-07-01 | 2023-07-01 .. 2023-12-31, mature at 2024-01-01 | 2024 | 24 h |
| 2025 | first .. 2024-06-30, mature at 2024-07-01 | 2024-07-01 .. 2024-12-31, mature at 2025-01-01 | 2025 (exit before 2026-01-01) | 24 h |

BTC / ETH / BNB: all three folds. SOL: only 2025 (fit 2023-10-04 .. 2024-06-30). Minimum 20,000 fit rows and 10,000 calibration rows else the fold is skipped and reported. Calibrators and EV averages are fit ONLY on the calibration window; the test year is never used for any fit, selection or threshold.
No fold's test year is re-used for model selection and nothing is tuned after seeing any result (a pre-set list of models, targets, calibrators and thresholds is below). **Calendar reality**: the three test years are years 2023-2025 that earlier experiments already viewed; the design stops the leakage that matters for THIS study (fit / calibration / test chronology, purge, embargo) but cannot restore a never-seen test set.

## 6. Calibration (E4)

Calibrators fit on the calibration window: **raw** (model output), **Platt** (logistic on logit(raw), clipped 1e-6; PRIMARY calibrator), **isotonic** (secondary), **base rate** (calibration-window mean), **constant 0.5**. Regression D: isotonic of D-hat -> realised net on the calibration window (EV calibration).
Metrics on the test rows: Brier, log loss, ECE (10 equal-mass bins; also 10 equal-width bins), reliability table, calibration intercept and slope (unpenalised logistic of y on logit(p); CI by day-block bootstrap, 2,000 replicates for primary items), calibration-in-the-large, AUC, Brier skill vs base rate
(BSS = 1 - Brier / Brier_base), per test year, per coin, per preset (horizon). D: Spearman, decile table, slope of realised on predicted, MSE vs the base-rate (calibration mean) predictor.
- **CAL_CALIBRATED(item)**: ECE (equal-mass) <= 0.02 AND slope CI contains 1 AND intercept CI contains 0. **CAL_INFORMATIVE(item)** = F1 LEAD (section 9). These are reported separately; "calibrated but uninformative" is a valid outcome.

## 7. Confidence buckets, decision framework, economics (E5, E6, E8, E9)

- Buckets of the CONFIDENCE of the chosen side, fixed edges: **[50,60) [60,70) [70,75) [75,80) [80,90) [90,100]**, defined on three confidences: `CONF_A = max(pA, 1 - pA)` (side = sign), `CONF_B = max_s pB_s`, `CONF_C = max_s pC_s` (calibrated Platt probability; the raw-probability version is reported next to it). Tested items use CONF_A with Q.
  Per bucket: n, effective independent n (distinct non-overlapping horizon blocks per coin containing an event; also distinct days), mean predicted, observed rate with day-block bootstrap CI90, gross and net per trade (X1, or X2 for CONF_B), win rate, avg win, avg loss, profit factor and max additive drawdown on the greedy non-overlapping chain per coin, coverage (share of events).
- **Decision (BUY / SELL / WAIT)**: `EV_s = pC_s * Wbar_s - (1 - pC_s) * Lbar_s` with `Wbar_s` / `Lbar_s` = mean net of the positive / negative `net_X1_s` in the calibration window (isotonic pC for EVp). BUY if `EV_L > 0 and EV_L >= EV_S`, SELL if `EV_S > 0 and EV_S > EV_L`, else **WAIT** (no sufficiently attractive net-EV trade; WAIT is a decision outcome, not a probability class, it has no P&L and is shown as coverage). Margin tau = 0 is the only tested threshold; tau in {0.03, 0.06} are
  reported descriptively, never selected. Variant `EVD` takes `EV_s` from the calibrated D regressor instead. Non-overlapping execution (greedy per coin, one position at a time) is used for profit factor, drawdown and the equity view; per-event means (every BUY / SELL event is one trade-signal) are the tested statistic with day-block bootstrap.
- E8: P1 / P2 / P3 x costs 0.12 / 0.14 / 0.11 / 0.08, per coin and year, gross vs net, versus the replay baselines. E9: observed net per trade vs confidence (deciles of EV, of pA confidence), slope of net on confidence.

## 8. Uncertainty (E12)

Day-block bootstrap (whole UTC days of the anchor resampled jointly across the pooled coins, 2,000 replicates, seed = crc32(key)); SE from the bootstrap, one-sided p = 1 - Phi(effect / SE), CI90 = effect +- 1.645 SE, MDE = 2.8 SE (the replay's convention). Effective N = distinct (coin, horizon-block) cells with an event, and distinct days.
Paired comparisons are differences on the same events. Multiple testing: BH-FDR per family (below). Cost sensitivity on every economics item. Per-year and per-coin sign counts.

## 9. Families, tests and decision rules (all fixed now)

- **F1 calibration skill (49 items)**: effect = Brier(base-rate calibrator) - Brier(calibrated model), per event, pooled P1 (Q at P2 added). Items: {V3, V4, Q} x {A, B_L, B_S, C_L, C_S} x {Platt, isotonic} = 30; Q at P2 x 5 targets x 2 = 10; {VOL, TREND, SOFT} x {A, C_L, C_S} x Platt = 9.
  LEAD iff q < 0.05 and CI90 lower > 0 and effect >= 0.0005 and the effect > 0 in >= 2 of 3 test years and in >= 2 of 3 coins (BTC, ETH, BNB); FAIL iff CI90 upper < 0.0005 and MDE <= 0.001; else INCONCLUSIVE. (Log loss delta reported as a companion.)
- **F2 net economics (17 items)**: effect = mean net % per trade at 0.12 % (X1), pooled P1. Items: policies Q-EVp, Q-EVD, V3-EVp, V4-EVp, VOL-EVp, TREND-EVp, SOFT-EVp (7); Q-EVp at P2 (1); Q CONF_A buckets (6); rules B, C, always-long (3).
  LEAD iff q < 0.05, CI90 lower > 0, effect >= 0.03 %, MDE <= 0.06 %, positive in >= 2 of 3 years and >= 2 of 3 coins, and effect at cost 0.14 % >= 0; FAIL iff CI90 upper < 0.03 % and MDE <= 0.06 %; else INCONCLUSIVE (the replay's rule).
- **F3 paired vs baselines (11 items)**: effect = mean of (chosen side net - comparator net) on the policy's trade events. Q-EVp vs {random side, opposite side, always-long, rule B side, rule C side, TREND-EVp side, VOL-EVp side} (7); V3-EVp, V4-EVp, Q-EVD, SOFT-EVp vs random side (4). LEAD iff q < 0.05, CI90 lower > 0 and effect >= 0.03 %; FAIL iff CI90 upper < 0.03 % and MDE <= 0.06 %.
- **ECON_PASS (primary = Q-EVp, P1, BTC + ETH + BNB, 2023-2025)**: F2 LEAD AND F3 (vs random side) LEAD AND >= 1,000 trades AND effective N >= 300 horizon-blocks AND profit factor of the non-overlapping chain >= 1.15 AND no single year > 50 % of the positive profit.
- **Final verdict (one word)**: **PASS** iff ECON_PASS and Q has an F1 LEAD that is CAL_CALIBRATED for A or C. **FAIL** iff the primary F2 item is FAIL (adequately powered) and no secondary policy has an F2 + F3 LEAD. **INCONCLUSIVE** iff neither (including a secondary LEAD, which is reported as LEAD for a pre-registered forward test only, never promoted). **BLOCKED** iff the data / OOF
  generation cannot be completed (the blocker is reported, no result is invented). Quick Trade paper readiness is stated from the verdict: PASS = a pre-registered paper forward test of the policy is justified; anything else = the model probabilities are not usable as a trade or sizing input.
- Calibration and economics are reported as separate verdicts as well (e.g. "calibrated, not profitable").

## 10. The 75 % study (E13, descriptive, no test)

For every (model, target, probability type raw / Platt / isotonic): number of test events with confidence >= 0.75 (max(p, 1-p) for A), by year and coin, whether the observed rate is >= 0.75 (Wilson / bootstrap CI), whether profitable net (X1 / X2), maximum probability reached. If the model never reaches 75 % this is stated plainly and the nearest populated buckets are shown.

## 11. Controls and tests (all in `tests/test_exp_e_v1.py`; the whole engine suite must stay green)

Negative leakage tests: a planted look-ahead feature is caught by the prefix-invariance guard and its walk-forward AUC trips the too-good guard (OOS AUC > 0.70 aborts a job); a calibrator fit on the evaluation window is rejected; the loader refuses any 2026 file and any cut > CUT and drops feature / target rows at or after CUT; immature labels are excluded by `mature_mask`;
a fold whose fit and calibration windows overlap or whose test starts before calibration end + embargo is rejected. Correctness tests: terminal / barrier / net target arithmetic against hand-built windows and against `edge_search.core.outcomes_time` on the same bars, calibration metric formulas, Platt / isotonic on planted miscalibration, bucket edges, effective N, BH-FDR, greedy chain, feature-anchor alignment on the real BTC data, a planted-signal power test (the pipeline must find a planted edge) and a null test (no PASS on noise).

## 12. Runtime and artifacts

Below-normal priority, one heavy process at a time, 2 GB available-commit guard, STOP file `D:\autofinx-ml-v4\exp_e_v1\STOP`, D: free space must stay > 30 GB, atomic writes, resumable markers per coin (events) and per (coin, fold) job. The live collector on `D:\autofinx-collector` is not touched.
Code `external-predictive-engine/exp_e_v1/`, launcher `run_exp_e_v1.py` (`audit | dry | run | analyze`). Results `D:\autofinx-ml-v4\exp_e_v1\results\` (reports A-G: A OOF audit, B calibration, C confidence buckets, D BUY / SELL / WAIT, E walk-forward by year, F economics, G final verdict + 75 % study), OOF predictions with provenance `exp_e_v1\oof\`, per-run `manifest.json` (config, data manifest, model hashes, seeds, window boundaries, costs, code sha).
Result document `docs/ml-engine-v1/EXPERIMENT_E_V1_RESULT.md`; tracking files CHANGELOG / RESULTS (next R id) / DECISIONS (next D id) / PROGRESS.

## 13. What this plan cannot show (stated up front)

No fresh holdout exists; 2023-2025 was reused. Slippage 0.01 % per leg and a market-fill stop are assumptions. Day-block bootstrap treats days as independent, which under-states dependence in volatility clusters. The models are the existing V3 / V4 recipes with their fixed features; a different feature set or model is out of scope (D-046).
A FAIL means these frozen models, targets and decision rules showed no out-of-sample after-cost value on this window; it does not prove no model can.

## AMENDMENT 1 (written before ANY real-data result of the experiment was computed or looked at; only the event table and the fold jobs, i.e. raw predictions, were being generated)

Reason: the primary decision policy takes a trade only when the calibrated net-EV is positive. With a 0.12 % round-trip cost and near-zero gross edge it may trade very rarely, which would make the economics test under-powered
(MDE > 0.06 %) even though the substantive answer ("the model never finds a net-positive trade") is clear. The original rule would then return INCONCLUSIVE for a reason that is not about uncertainty. The following is added; nothing else changes.

1. New policy **Q-SIDE**: at EVERY event take the side with the larger `EV_p` (no WAIT, no sign condition). It measures side-selection skill with full power and is the descriptive companion of the WAIT policy. It joins F2 (F2 = 18 items) and F3 (vs random side; F3 = 12 items). It is not a deployable decision rule (it trades at negative EV by construction); it is a power-independent measurement.
2. Verdict addendum: if the primary policy Q-EVp has fewer than 1,000 trades in the verdict set ("WAIT-dominant"), its F2 / F3 items are reported as under-powered and the economics verdict falls back to Q-SIDE: **FAIL** iff Q-SIDE F2 is FAIL and Q-SIDE F3 (vs random side) is FAIL and no secondary policy has an F2 + F3 LEAD; PASS is impossible while the primary has < 1,000 trades; otherwise INCONCLUSIVE.
3. Bucket items with fewer than 30 events are INCONCLUSIVE "empty bucket" (no p-value), as the effect statistics already do; CONF_B / CONF_C bucket tables are descriptive (only CONF_A buckets are F2 items). Descriptive supplement (not tested): P(TP | barrier resolved) next to the B base rate, because with SL = TP = 1 R most events expire unresolved.
