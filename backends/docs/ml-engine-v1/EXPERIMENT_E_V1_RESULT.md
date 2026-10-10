# Experiment E v1 - result (2026-10-10)

Written for: the owner. Plan `EXPERIMENT_E_V1.md` (frozen sha256 `fbd5ddfe...556637`; AMENDMENT 1 written before any real-data result, final sha256 `0c910d7bec85fb9b348cf494ca7b737b2509b0406e8bc017a668889a8c75fed0`, both in `D:\autofinx-ml-v4\exp_e_v1\`). R-031, D-049.
Machine results: `D:\autofinx-ml-v4\exp_e_v1\results\` (reports A-G, CSV tables, `summary.json`, `manifest.json`); OOF predictions with provenance `D:\autofinx-ml-v4\exp_e_v1\oof\`.

**Every number below is a REUSED HISTORICAL EVALUATION - NOT A FRESH FINAL HOLDOUT.** 2023-2025 was viewed by many earlier experiments. The sealed 2026 holdout was not read; no fresh independent 2-year holdout exists besides it.

## Verdict (frozen rules): **INCONCLUSIVE** - substantively: NO

- **No profitable use of the model probabilities was found.** The primary policy (Q-EVp: trade only when the calibrated net EV is positive) lost **-0.060 % per trade** net (CI90 [-0.110, -0.009], 8,366 trades, effective N 2,575 blocks; gross +0.060 %, i.e. half the 0.12 % cost). Every other policy is net negative or statistically zero; 0 F2 LEAD, 0 secondary policy with F2 + F3 LEAD.
- The formal verdict is INCONCLUSIVE and not FAIL only because the frozen FAIL rule also demands MDE <= 0.06 % and the primary's MDE is 0.085 % (8,366 trades is a thin sample when the signal is selective). The full-power companion Q-SIDE (better side at every event, 946,677 events) is FAIL: net -0.117 %, +0.003 % versus a random side (CI [-0.0003, +0.006]).
- **Calibration: raw probabilities are NOT calibrated (overconfident); Platt / isotonic calibration on a later matured window repairs most of it and shows a small but real out-of-sample information gain over the base rate, but it fails the strict CAL_CALIBRATED rule (slope CI excludes 1) and the information is mostly volatility.** Verdict: INFORMATIVE_NOT_CALIBRATED.
- **The 75 % study: the calibrated (Platt) probability of direction never reaches 75 %** (max 0.7075, one event in 945,297; observed hit rate where it is >= 0.60: 58.0 %). Raw model output does reach 75-90 % (3,270 events >= 0.75) but the observed rate there is 59.7 % and the net is -0.076 % per trade. No profitable calibrated >= 75 % population exists.
- Quick Trade paper readiness: **the model probabilities / confidence are not ready to be used as a trade, sizing or "confidence %" input.** Nothing in this study supports a paper forward test of an ML decision policy; the replay's conclusion (R-030, D-048) stands.

## 1. Facts verified by this agent (not trusted) and what was not verified

Verified: (1) no genuine OOF predictions exist (report A: scan of 326 data files + 656 JSON reports; the six files with prediction-like columns are the `features/*/v13.parquet` Pine scores, not model output; every model directory holds boosters + metadata only; the P1 / P4A / P5 / V5-lite JSON reports hold aggregated numbers, 0 numeric lists > 5,000 values); (2) the fitted models have ONE training cutoff (BTC / ETH / BNB dev_train ends 2024-09-23, SOL / XRP / SUI 2025-07-15; `edge_search_v1/results/segments.json`) so they are out-of-sample only afterwards and never walk-forward; (3) `GLOBAL_TABLE.json` = 30 / 30 REJECTED (P8); (4) Quick Trade Replay result files: 1,071 items, 4,176 by-coin rows, 0 of 2,337 non-random rows with n >= 1,000 net-positive, random-arm net -0.1197 / -0.1202 % (BTC / ETH, P1) - and this study independently reproduces the replay's baselines on a larger set (always-long net -0.1139 % vs replay -0.114 %, always-short -0.1261 vs -0.126, random -0.1200 vs -0.120); (5) the live presets (`config/quick_trade.php:131-135`), live symbols BTC / ETH / BNB / SOL (`app/Domain/Trading/Providers/BinanceTimedFuturesProvider.php:51-54`), cost-model constants taker 0.0005, slippage buffer 0.04, multiplier 2.0, displacement 0.55 (`app/Domain/Trading/QuickTradeCostModel.php:15-17,36`), paper BTC payout 0.86 (`TradingModuleSettingsService.php:63`; the file paths in the replay doc say `app/Services/...`, the real ones are `app/Domain/Trading/...`); (6) the V3 feature anchor is the close of the bar that opened 5 minutes earlier (offset-0 match in the suite; neighbours 20x worse); (7) holdout seal dates (BTC 2026-01-04, SOL / XRP / SUI 2026-04-24, global 2026-01-03).
Not verified: production workspace settings / server `.env`, the collector pilot state, anything in the PHP test suite (backend `php artisan test` was not run: no PHP file was touched), the previous agents' claims about earlier tests counts (the engine suite is now 254 passed).

## 2. E0 / E1 / E2 - the OOF predictions

- E1 answer: **did not exist** (see section 1). E2 was therefore required.
- E2 how: the existing recipes (LightGBM, `train_v4.TREE`, 37 V3 features, 180-day half-life weights, V3 endpoint labels and V4 path labels with the fold's reference levels) were re-fit per walk-forward fold; every raw prediction for the calibration window and the test year is persisted with provenance (booster sha256, fit window, calibration window, test window, horizon, label definition, fold, code sha).
- Folds (annual test, expanding, 6-month calibration window, 24 h embargo; labels must be mature for the next boundary; fit / cal / test chronology is asserted per fold): test 2023 = fit 2021-10-04 .. 2022-06-29, calibration 2022-07-01 .. 2022-12-31; test 2024 = fit .. 2023-06-29, calibration 2023-07..12; test 2025 = fit .. 2024-06-29, calibration 2024-07..12. BTC / ETH / BNB: 3 folds each; SOL (data starts 2023-10-04): 2025 only. 10 (coin, year) jobs x 27 models. The three test years were already viewed by earlier experiments; **there is no fresh test year**.
- Test AUCs (any model, any fold) stayed <= 0.639; the "too good" guard (AUC > 0.70 aborts) never fired.

## 3. Calibration (E4) - P1 (5m / 3600 s), BTC + ETH + BNB, 2023-2025 pooled

| target (base rate) | raw ECE | raw slope | raw BSS | Platt ECE | Platt slope [CI90] | Platt intercept | Platt BSS | AUC |
|---|---|---|---|---|---|---|---|---|
| A terminal up (0.511) | 0.0419 | 0.368 | -0.74 % | 0.0041 | 0.881 [0.807, 0.955] | 0.015 | +0.41 % | 0.537 |
| B_L TP before SL (0.138) | 0.0255 | 0.600 | +0.28 % | 0.0038 | 0.947 [0.912, 0.985] | -0.107 | +2.04 % | 0.619 |
| B_S (0.153) | 0.0197 | 0.666 | +1.25 % | 0.0058 | 0.936 [0.900, 0.977] | -0.091 | +2.16 % | 0.616 |
| C_L after-cost success (0.361) | 0.0307 | 0.600 | +1.83 % | 0.0072 | 0.875 [0.832, 0.916] | -0.080 | +2.45 % | 0.575 |
| C_S (0.342) | 0.0337 | 0.568 | +1.27 % | 0.0073 | 0.916 [0.860, 0.971] | -0.082 | +2.06 % | 0.568 |

- Raw model probabilities are overconfident (slope 0.37-0.67) and, for A, WORSE than the base rate (Brier skill -0.74 %). The existing V4 raw probability is worse than the base rate for its own label too (native slope 0.35 / 0.43, BSS -0.9 / -0.5 %); raw V3 is milder (slope 0.71 / 0.67, BSS +3.8 / +2.9 %).
- After Platt calibration on the matured window before each test year: ECE 0.4-0.7 %, slope 0.88-0.95, positive skill vs the base rate in all three years for every target (A: +0.46 / +0.44 / +0.34 %; C_L +2.0 / +3.4 / +1.9 %). F1: **41 of 49 items LEAD** (Q, V3 / V4 mapped, Q at P2, ablations; the 8 non-leads are isotonic B for V3 / V4, V3 / V4 B_S Platt and VOL-A). CAL_CALIBRATED (ECE <= 0.02 and slope CI contains 1 and intercept CI contains 0) holds for 0 of 10 Q items (the slopes are consistently a little below 1: the signal weakens from the calibration window to the test year) and for two V3 / V4 B_L Platt items.
- **The information is small and mostly volatility.** Direction (A) Brier gain is 0.0010 absolute (0.41 % relative, AUC 0.537); a trend-only model gets 78 % of it, a volatility-only model none (VOL-A FAIL). For the after-cost success targets a volatility-only model gets 83 % of the gain (C_L: 0.0047 of 0.0057), trend-only 95 %, soft scores 104 %; i.e. the model mostly knows WHEN a move is big, rarely which way. D (expected net return): the raw regressor has Spearman 0.010 and a worse MSE than the sample mean (0.354 vs 0.346); the calibrated EVD policy is no better than EVp.
- P2 (1800 s) behaves the same (A Platt BSS +0.39 %; all P2 items are F1 LEADs, see report B). P3 (15-minute events) tables are in report B.

## 4. Confidence buckets (E5) - Q, CONF_A = max(p, 1 - p), cost 0.12 %, net % per trade

| bucket | Platt n | Platt pred / observed | Platt net [CI90] | raw n | raw pred / observed | raw net [CI90] |
|---|---|---|---|---|---|---|
| 50-60 | 936,500 | 0.529 / 0.527 | -0.1177 [-0.1209, -0.1144] | 718,047 | 0.545 / 0.519 | -0.1194 |
| 60-70 | 8,796 | 0.615 / 0.580 | -0.1005 [-0.1312, -0.0698] | 209,378 | 0.634 / 0.552 | -0.1122 |
| 70-75 | 1 | - | - | 14,602 | 0.719 / 0.565 | -0.1095 |
| 75-80 | 0 | - | - | 2,824 | 0.768 / 0.594 | -0.0812 [-0.1171, -0.0452] |
| 80-90 | 0 | - | - | 445 | 0.821 / 0.614 | -0.0477 [-0.1665, 0.0711] |
| 90-100 | 0 | - | - | 1 | - | - |

Effective independent N (distinct coin x 60-minute blocks) is 78,863 for 50-60, 2,778 for the Platt 60-70 bucket and 983 / 143 for the raw 75-80 / 80-90 buckets; day counts are in report C. Higher calibrated confidence does carry a higher hit rate (52.7 -> 58.0 %) but the gross edge it buys (+0.002 -> +0.020 %) is far below the 0.12 % cost: net does not turn positive in any bucket. The same table for CONF_B (barrier) and CONF_C (after-cost success), isotonic, by year, and the EV-decile view (slope of realised net on predicted EV; E9) are in report C. Isotonic produces tail plateaus (130 events at 0.99 predicted, 67 % observed) - an artefact of a small calibration tail, not confidence.

## 5. The 75 % study (E13)

41 of 72 (model, target, probability type) combinations have at least one event >= 0.75, but only because of raw or isotonic tails. The primary calibrator (Platt) reaches 0.7075 at most (1 event). Q direction: isotonic 251 events >= 0.75, observed hit 68.9 %, mean net +0.027 %; raw 3,270 events, observed 59.7 %, net -0.076 % (CI [-0.119, -0.034], effective 989 blocks). The only positive-looking pocket, raw C_L >= 0.75 (50 events, 17 effective blocks, 10 days, observed 74 %, net +1.06 % with CI [-0.004, +2.13]), is a forking-path sample of 50, not a finding. A calibrated >= 75 % population that is also profitable: **none**. Per year / coin counts are in `summary.json` -> `study75`.

## 6. BUY / SELL / WAIT and economics (E6, E8, E9, E10, E12)

P1, BTC + ETH + BNB, 2023-2025, % per trade, cost 0.12 % (net at 0.14 / 0.08 in brackets), trades = BUY + SELL events:

| policy | trades (coverage) | gross | net 0.12 [0.14 / 0.08] | CI90 | verdict | chain PF |
|---|---|---|---|---|---|---|
| Q-EVp (primary) | 8,366 (0.88 %) | +0.060 | -0.0595 [-0.080 / -0.020] | [-0.110, -0.009] | INCONCLUSIVE (MDE 0.085) | 0.83 |
| Q-EVD | 6,649 (0.70 %) | +0.062 | -0.0578 | [-0.131, +0.015] | INCONCLUSIVE | 0.91 |
| V3-EVp | 9,493 (1.0 %) | +0.082 | -0.0371 [-0.057 / +0.003] | [-0.081, +0.007] | INCONCLUSIVE | 0.85 |
| V4-EVp | 554 (0.06 %) | +0.024 | -0.0963 | [-0.148, -0.044] | INCONCLUSIVE | 0.42 |
| VOL-EVp / TREND-EVp / SOFT-EVp | 1,892 / 16,387 / 8,617 | +0.057 / +0.068 / +0.053 | -0.063 / -0.052 / -0.067 | | INCONC. / FAIL / INCONC. | 1.03 / 0.81 / 0.82 |
| Q-SIDE (no WAIT) | 946,677 (100 %) | +0.003 | -0.1170 | [-0.1202, -0.1139] | FAIL | 0.53 |
| RULE-B / RULE-C / always-long (replay baselines) | 945,351 / 109,938 / 946,677 | +0.000 / +0.005 / +0.007 | -0.120 / -0.115 / -0.114 | | FAIL | 0.52 / 0.55 / 0.54 |
| random side (null) | - | 0 | -0.1200 | | | |

- WAIT is the usual answer (BUY / SELL on 0.9 % of events at P1), and the trades it does take are the high-volatility events (mean |gross| 0.76 % vs 0.36 % overall) with a small positive gross (+0.06 %, i.e. half the cost). Paired vs a random side the primary gains +0.0605 % (CI [+0.010, +0.111], q 0.061, not significant after BH-FDR), V3-EVp +0.083 % (q 0.017; LEAD in F3 but its net is still -0.037 %); vs RULE-B +0.144 %. None of this reaches the cost.
- Cost sensitivity: at the research maker cost 0.08 % V3-EVp is +0.003 % and Q-EVp at P2 (1800 s) is -0.0002 % at 0.12 % and +0.040 % at 0.08 % (CI [-0.041, +0.040] at 0.12 %); maker rows ignore missed fills and the code refuses maker entry in live, so they are report-only.
- Per year (pooled coins, Q-EVp): -0.060 / -0.061 / -0.058 % (2023 / 2024 / 2025); by coin BTC -0.008, ETH -0.120, BNB +0.115 (BNB: 10 trades in 2023 and 892 in 2025; sign counts: 0 of 3 years, 1 of 3 coins positive). SOL (2025 only, report-only): -0.022 % on 3,247 trades. Calibration skill by year is stable (section 3); economics is stably negative.
- P3 (15-minute events) and P2 tables: report F.

## 7. What was tested and what was not

Tested: the existing V3 / V4 recipes and the same recipe on Quick Trade targets (A, B_L, B_S, C_L, C_S, D), raw vs Platt vs isotonic vs base rate vs constant 0.5, three live presets, four coins, three annual walk-forward folds with embargo, soft scoring as features (volatility-only, trend-only, soft-score ablations), BUY / SELL / WAIT on calibrated net EV (two EV constructions) plus the no-WAIT side-selection companion, the replay's rule baselines, four cost levels, day-block bootstrap, BH-FDR in three families (F1 49, F2 18, F3 12).
Not tested: any feature set beyond V3 (+ the soft scores), any model class other than LightGBM, an h30 native V3 / V4 model (none exists; P2 uses the same recipe on the P2 label, Layer 1 is P1-only), leverage / liquidation, real stop-order latency, maker fill rates, stake sizing, the barrier target for P3 (own-R barrier), the exits of the replay (only expiry and the 1 R / 1 R barrier), the 2026 holdout (sealed), any threshold other than EV > 0. A different, richer information source (order book, liquidations, fills; the collector pilot) is untested here by design (D-046).

## 8. Deviations and disclosures

- AMENDMENT 1 (before any real-data result): added policy Q-SIDE (+1 F2, +1 F3 item) and a WAIT-dominant fallback in the verdict; it was not needed (the primary has 8,366 trades).
- One plan-vs-code bug found while reading the first result and fixed before anything was recorded: the F3 verdict used the stricter F2 conditions (year / coin signs, MDE); corrected to the plan's F3 rule (q < 0.05, CI lower > 0, effect >= 0.03 %), re-run, unit-tested. It changed two F3 labels (Q-EVp vs RULE-B and V3-EVp vs random became LEAD) and nothing else (no F2 LEAD, so no secondary policy qualifies).
- The constant-0.5 predictor was added to the pooled calibration tables after the first run (it is exactly Brier 0.25); no number changed.
- The slope / intercept / ECE confidence intervals come from a day-block bootstrap of 100 equal-mass bins per day (grouped logistic fit, 2,000 replicates), not 2,000 refits of the event-level logistic; point estimates are event-level.
- Operations: the PC has ~3 GB free commit; OpenBLAS reserved 1 GB at import (launcher now sets 2 threads), the first run hit out-of-memory at ETH and restarted from the markers (BTC folds ran with an earlier revision of `data.py` / `runner.py`: only a column-limited loader and a retry loop were added since, results unaffected); analysis arrays are float32 with float64 accumulation. The final `manifest.json` carries the code sha of the revision that produced the reports.
- The D regressors for L and S are exact mirrors of each other (same trees, mirrored targets), so their diagnostics are identical.
- The OOF arrays are in `.npz` (not parquet): per job `idx_*`, `cal|P|model`, `test|P|model` raw predictions plus `yV3_*` / `yV4_*` native labels; provenance in the adjacent `.json`.

## 9. Files

Plan `docs/ml-engine-v1/EXPERIMENT_E_V1.md`; code `external-predictive-engine/exp_e_v1/` (data, leakage, targets, metrics, job, analysis, report, audit, runner), launcher `run_exp_e_v1.py`, tests `tests/test_exp_e_v1.py` (27; whole engine suite 254 passed); results `D:\autofinx-ml-v4\exp_e_v1\results\` (`report_A_oof_audit.md`, `report_B_calibration.md`, `report_C_confidence_buckets.md`, `report_D_buy_sell_wait.md`, `report_E_walk_forward.md`, `report_F_economics.md`, `report_G_final_verdict.md`, `summary.json`, `manifest.json`, CSVs, `supplement_descriptive.json`); OOF `D:\autofinx-ml-v4\exp_e_v1\oof\`; events `D:\autofinx-ml-v4\exp_e_v1\events\`; boosters of V3 / V4 `...\models\`; logs `D:\autofinx-ml-v4\logs\exp_e_v1.log`, `exp_e_v1\run_stdout*.log`.
