# SPEC — AutoFinX V4: Path-Aware ML, Dynamic SL / Profit Activation / Trailing, Local Binance Training

Status: **complete — sections 0–101 received and locked (2026-10-06).** The phase order is the
reconciled table in §101 (D-012).
Section numbers follow the owner's original spec so references stay stable.
Corrections C1–C10 (owner-approved review, 2026-10-06) are merged in and marked **[C#]**.

## Mission
Replace the research assumption "T0 close → exact T+H close" with a path-aware system that learns,
per side (LONG/SHORT): whether a profitable opportunity existed, when it appeared, how much adverse
movement came first, how much favorable movement was realistically available, how much pullback
follows once profit starts, the dynamic SL / profit activation / trailing requirements, and when the
correct action is WAIT. The eventual output per anchor is a full trade plan (decision, calibrated
probability, expected favorable/adverse move, expected net edge, hard SL, profit activation,
trailing distance, max hold, risk/reward diagnostics, WAIT/reject reasons).
**V4 is NOT connected to live trading or autopilot.** Research / training / shadow only.

## 0. Non-negotiable safety
- No order placement/closing, no change to Quick Trade or Futures execution, no influence on live
  decisions, no live flags, no automatic champion replacement, no automatic production install.
  All V4 outputs are `SHADOW / RESEARCH / LOCAL TRAINING ONLY`.
- V3 endpoint pipeline is not deleted or rewritten; V4 is a versioned new pipeline. Required
  report: V3 endpoint labels vs V4 path-aware labels + dynamic policy. If V4 is worse, V3 stays.
- No production migration without approval; prefer local Parquet/JSON(L)/SQLite/artifacts. If a
  production table seems necessary: STOP, explain why, schema, migration, rollback.
- No commit/push/merge unless asked. Server artisan only as zcadmin; heavy work local.

## 1. Read-only audit first
Audit actual code (config/ml.php, FeatureLabelService, ShadowFeatureBuilder, MlShadowTrainer,
FundingRateService, MlCandleCollector, MlCandleStore, LearningStatusService, SignalStabilityService,
MlPaperAutopilotService, train_side_classifier.py, shadow_sim.py, ml-offload.ps1, models, commands,
migrations, tests, promotion policies, QuickTradeCostModel). Confirm timeframe, schema, horizons,
label/anchor/entry semantics, costs, fee/slippage source, funding, BUY/SELL targets, floor,
calibration, recency, split, purge/embargo, walk-forward, holdout, non-overlap sim, gates,
paper autopilot. Print the summary before coding. **Done 2026-10-06 → RESULTS.md R-002.**

## 2. Concept
Future path between T0 and H: profit opportunity, adverse move, profit activation, running peak,
trailing reversal, hard stop, time expiry. Future candles only build targets — never features.

## 3. Symbols and history
BTCUSDT, ETHUSDT, BNBUSDT = 60 months. SOLUSDT, XRPUSDT, SUIUSDT = 36 months.
If Binance history is shorter (listing date): never fabricate, never use another exchange, never
interpolate; use maximum genuine history and report requested/actual history, coverage start/end,
coverage %, reason if short.

## 4. Local Binance data only
Download directly to the PC from official Binance futures public data (bulk historical source
allowed; REST for recent data, gaps and tail consistency). Never from the production server. No
third-party reconstructed data. No API credentials needed.

## 5. New local runner
Do not break `ml-offload.ps1`. Create a versioned runner, e.g. `ml-v4-local.ps1`, work dir
`D:\autofinx-ml-v4`. Example:
`powershell -ExecutionPolicy Bypass -File .\ml-v4-local.ps1 -Symbols BTCUSDT,ETHUSDT,BNBUSDT,SOLUSDT,XRPUSDT,SUIUSDT -Horizons 15,60,120,240,1440 -Work D:\autofinx-ml-v4`
History months chosen per symbol automatically. No SSH needed. No server upload/install in the
normal command. (Note: `powershell -File` passes comma lists as one string — split inside.)

## 6. Resume
Stages: CHECK, DOWNLOAD, VERIFY, BUILD_5M_FEATURES, BUILD_PATH_TARGETS, TRAIN, EVALUATE, REPORT.
Persist checkpoints; a crash in one symbol never destroys completed symbols. Options equivalent to
-DownloadOnly, -BuildTargetsOnly, -TrainOnly, -EvaluateOnly, -Refetch, -ForceRebuildTargets, and a
STOP marker file.

## 7. Data resolution [C8]
Features: existing causal 5m V3 feature pipeline, unchanged at first (isolate the effect of the new
labels). Use **native Binance 5m** for exact feature parity. Future path: **Binance 1m** candles —
15m→15 bars, 60m→60, 120m→120, 240m→240, 1440m→1440 — chronological order, never unordered
max/min alone. Historical **funding** from Binance. Verify 1m/5m timestamp alignment. Never
fabricate missing candles.

## 8. Entry semantics (no lookahead)
Features are known only after the anchor 5m candle closes. Default convention: prediction at T0
(anchor close); **entry = next available 1m open after T0**, plus entry slippage. If the live
architecture enters at another deterministic time/price, audit and match it. The same rule must be
used in training, holdout, shadow and future paper. Document it.

## 9. No oracle exits
Never exit at the future high (long) / low (short). Extremes are target information (MFE/MAE) only.

## 10. Raw path targets — Layer A [C4]
Per symbol × anchor × horizon, LONG and SHORT:
`mfe_pct, mae_pct, time_to_mfe_s, time_to_mae_s, max_adverse_before_profit_pct,
time_to_first_positive_net_s, peak_retracement_pct, final_time_exit_gross_pct,
final_time_exit_net_pct` (prefix long_/short_). Definitions documented precisely.
**Layer A is policy-independent truth** and must not depend on any chosen SL/TP policy, so policy
changes never require rebuilding 5 years of raw targets.

## 11. MFE / MAE
LONG: MFE = max favorable above entry, MAE = max adverse below entry. SHORT mirrored. Distinguish
MAE over the whole horizon vs **MAE before profit activation** (key for SL learning). Path order
matters (a collapse after a trailing exit must not count as needed stop room).

## 12. Cost model
All-in: entry fee, exit fee, entry slippage, exit slippage, actual historical funding where
applicable. Use the existing AutoFinX cost source where possible (QuickTradeCostModel: taker
0.05%/side, slippage buffer from settings). Costs are part of the decision, not subtracted at the
end. Store gross_pct and net_pct.

## 13. Profitability definition
Success ≠ net > 0. `minimum_required_net = all_in_cost + safety_margin`. Configurable
`min_net_edge_pct`, audited against the existing required-move model, selected on
training/validation only, frozen before the final holdout.

## 14. Stop loss — what to learn
How much adverse movement normally happens before genuinely profitable moves (MAE-before-profit
distribution of winners) vs loser paths that do not recover under the policy. Estimate expected
adverse excursion and a conservative upper quantile per side.

## 15. Hard loss cap is never learned away [C6]
Configurable absolute max loss independent of the model. If required SL > cap ⇒ **WAIT**. ML may
recommend a tighter stop, never override the cap; confidence never widens it.

## 16. Profit activation, not blind fixed TP
Reaching activation (e.g. +0.45%) arms profit protection; trailing then follows the running
favorable peak. It does not close the trade by itself.

## 17. Dynamic profit target learning
Predict a distribution / conservative range of MFE (e.g. q25/q50/q75 — exact quantiles chosen on
validation), not an exact future top. Investigate LightGBM quantile regression.

## 18. Dynamic SL learning
Predict adverse excursion distribution, especially MAE before activation. SL = f(predicted adverse
excursion, causal volatility, minimum practical stop, hard cap). Candidate formulas compared on
validation; winner frozen before holdout.

## 19. Trailing learning
For paths that reach positive net: track running peak, subsequent pullbacks, whether the trend
continued, largest normal retracement before continuation, retracement before the final reversal.
Trailing = learned retracement behaviour + current short-term volatility, with min/max caps. Never
use the future final maximum to set live trailing.

## 20. Trailing invariants [C7]
LONG: running peak only rises; trailing stop only tightens upward. SHORT: running trough only falls;
trailing stop only tightens downward. Once armed, never loosens. Hard SL may tighten, never widen
after entry. Break-even/protected SL after profit = validation candidate only, not assumed.

## 21. Realizable historical policy simulation — Layer B [C4]
Chronological per trade: hard SL first → loss; profit activation first → arm trailing, update peak,
exit on trailing reversal; neither → exit at max hold. SHORT inverse. The evaluator must know the
first event in time. Layer B = policy simulation (SL, activation, trailing, expiry, fees, slippage,
funding → realized net + exit reason), built on top of Layer A data.

## 22–24. Required examples (must be tests)
- SL first: stop hit at 99,450 ⇒ trade ends at the stop even if price later reaches 102,000.
- Profit first: activation at 100,500, peak 102,000, trailing exit at 101,750 ⇒ exit at the trailing
  level, not at 102,000.
- Time exit: neither triggers ⇒ TIME_EXIT at horizon close; insufficient net ⇒ not an opportunity.

## 25–27. 1m intrabar ambiguity [C9]
If SL and activation/trailing levels are both touched inside one 1m candle, order is unknown ⇒
`AMBIGUOUS_INTRABAR`; never assume favorable ordering. V1: mark conflicts, exclude from success
training or evaluate as the worse first-touch outcome, report the ambiguity rate. Only if material,
add an optional official Binance aggTrade resolver for those windows only. No global tick download.
5m = feature context; 1m = path reconstruction.

## 28. BUY / SELL / WAIT targets
Simulate LONG and SHORT separately (probability/opportunity, reward, risk, realizable net), then
decide BUY / SELL / WAIT. Not a simplistic endpoint direction.

## 29. WAIT is first-class
WAIT = neither side has enough safe, reproducible positive net edge (reward too small, cost too
large, risk too large, SL beyond cap, low probability, high uncertainty, conflict, incomplete or
ambiguous data, out-of-distribution).

## 30. Never force label counts
Do not change thresholds, costs, floor, SL, TP, trailing to reduce WAIT samples. Report sparsity.
Training weights only if statistically justified; validation, holdout and simulation stay natural.

## 31. Both sides can look profitable
Never label by the larger hindsight maximum. Use calibrated probability, conservative reward and
risk, expected net, risk/reward, uncertainty. Unclear difference ⇒ WAIT.

## 32. Model architecture
Keep LightGBM unless evidence says otherwise; no deep learning for novelty. Components:
A/B BUY & SELL success classifiers, C/D LONG & SHORT favorable-excursion estimators, E/F adverse-risk
estimators, G retracement/trailing estimator or a validated volatility-based trailing policy. Do not
add models a simpler validated policy beats.

## 33. Quantile models
LightGBM quantile objectives for MFE, MAE, maybe post-activation retracement. Goal: conservative
plausible ranges and uncertainty, not exact values.

## 34. Uncertainty
Wide predicted ranges (e.g. MFE q25 0.20% vs q75 2.50%) reduce confidence ⇒ toward WAIT. Build an
uncertainty metric.

## 35. Dynamic trade plan object (illustrative, never hard-coded)
`decision, probability, expected_reward_pct, expected_adverse_pct, expected_net_pct, stop_loss_pct,
profit_activation_pct, trailing_distance_pct, max_hold_minutes, risk_reward_ratio, uncertainty,
policy_version: "v4-path-aware", execution: false`.

## 36–38. Generating SL / activation / trailing
- SL = predicted normal adverse movement + risk buffer + causal volatility, bounded by minimum
  practical SL and hard cap; required > cap ⇒ WAIT.
- Activation = all-in cost + minimum net edge + conservative predicted favorable move + volatility
  regime; never below break-even; conservative reward ≤ cost + safety edge ⇒ WAIT.
- Trailing = historical post-activation retracement + current short-term volatility, within min/max
  trail. Formulas tuned on train/validation only.

## 39. Optional hard TP
Default: activation + trailing, no hard TP. A hard TP only if out-of-sample risk-adjusted net
materially improves; optional parameter.

## 40. Policy calibration without oracle optimization
Never optimize SL/activation/trailing per historical row. Small candidate family → fit ML on
training → select policy coefficients on validation/walk-forward → freeze → evaluate once on the
untouched holdout. Candidate parameters: MFE/MAE quantile used, SL risk multiplier, activation
fraction, trailing retracement multiplier, volatility multiplier, probability floor, min risk/reward,
min net edge, uncertainty threshold. Keep the search space small.

## 41. Data splitting
No random shuffle. Chronological training / purged validation & walk-forward / calibration /
untouched newest holdout. Purge ≥ H so no target path crosses split boundaries.

## 42. Holdout untouched [C2]
No inspect-tune-rerun loops on the holdout. Freeze the policy, run the holdout once; failure ⇒
REJECT. A later experiment needs a new temporal test period or nested evaluation.

## 43. Recency
Keep recency weighting (old years add regimes: bull, bear, sideways, crash, recovery) without
overpowering recent behaviour. Compare the existing 180-day half-life with alternatives on
validation only.

## 44. Feature leakage test
Automated: build T0 features, mutate only future candles, rebuild ⇒ T0 features identical, V4
targets change.

## 45. Local storage
Never load 1m history into the production DB. Layout:
`D:\autofinx-ml-v4\data\<SYMBOL>\{1m,5m,funding}\`, `features\`, `targets\`, `models\`,
`reports\`, `logs\`, `cache\`. Parquet preferred (pyarrow allowed in the isolated local env only;
no new production dependencies).

## 46. Data integrity
Per symbol/timeframe: ascending timestamps, no duplicate open_time, correct interval, valid OHLC,
non-negative volume, closed candles only, detect missing 1m intervals. Never interpolate. A label
whose future path has a meaningful gap ⇒ `TARGET_INVALID_DATA_GAP`, excluded; report gap counts.

## 47. Download robustness
Checkpoint, retry, exponential backoff, rate-limit respect, timeout, resume, duplicate protection,
atomic writes. Ctrl+C never corrupts completed history. **[C5]** Show real progress (bytes, rows,
%, ETA) and report actual runtime; no fixed time promises.

## 48. Funding
Local download of actual Binance funding. Apply only when the simulated position crosses a funding
event, with correct side semantics (short gets the inverse). No flat made-up funding charge.

## 49. Cost stress
Evaluate under normal costs, +25%, +50%, higher slippage, optionally 1-minute delayed entry. If tiny
changes destroy the edge: fragile, not promotable.

## 50–57. Risk gates (any failure ⇒ WAIT)
DATA QUALITY (incomplete anchor, missing features, incomplete path, stale input, invalid OHLC,
gaps, artifact/schema mismatch) · PROBABILITY (calibrated, isotonic or justified; report BUY/SELL
calibration separately) · NET EDGE · COST (conservative move must clear fee + slippage + funding +
min edge) · RISK/REWARD (conservative reward / conservative risk ≥ validated minimum) · HARD SL ·
UNCERTAINTY · MODEL CALIBRATION · STALENESS · CONFLICT (close BUY/SELL utilities ⇒ WAIT) ·
OOD/REGIME (persist training feature ranges/distributions; extreme outliers ⇒ WAIT/LOW_CONFIDENCE).

## 58. Signal stability
Keep hysteresis (no BUY/WAIT/SELL flicker), but risk exits always override smoothing.

## 59. Same exit policy in evaluation
Evaluate with the exact causal policy intended for paper/autopilot later (entry, hard SL,
activation, running peak/trough, trailing, expiry, fees, slippage, funding) — never "train
path-aware, evaluate at T+H".

## 60. Non-overlapping execution
One simulated position per symbol × horizon at a time (labels may overlap for training; executed
trades may not).

## 61. Exit reasons
`HARD_STOP, TRAILING_PROFIT, TIME_EXIT_PROFIT, TIME_EXIT_LOSS, TIME_EXIT_FLAT, AMBIGUOUS_INTRABAR,
INVALID_DATA` — persisted and reported.

## 62. Risk metrics (final report)
Trades (long/short), total/avg/median net, win rate, gross profit/loss, profit factor, max
drawdown, max single loss, max consecutive losses, 95% loss percentile, CVaR if enough samples,
t-stat, bootstrap CI of mean net if practical, avg/median hold, SL/trailing/time exit %, avg SL
distance / activation / trailing distance.

## 63. Profit concentration
Report by year, quarter/month, volatility regime, long/short. Flag profit coming from a few trades
or one period (surface it; hard gate optional later).

## 64. Walk-forward robustness
Multiple folds; report positive folds/total, worst, best, median fold.

## 65. Parameter sensitivity
After selection perturb SL ±10%, activation ±10%, trailing ±10%, probability threshold ±small,
cost +25%. Collapse ⇒ FRAGILE.

## 66. Baselines
NEVER TRADE, ALWAYS BUY, ALWAYS SELL, CURRENT V3, simple fixed endpoint strategy, and fixed
SL/TP/trailing vs dynamic V4 policy. Prove dynamic complexity adds value.

## 67. No forced promotion
REJECTED with explicit reasons (negative net, few trades, poor calibration, drawdown, folds, low
confidence, cost fragile, poor risk/reward, high ambiguity, concentration, policy fragile).

## 68. Promotion status
Passing = `LOCAL_RESEARCH_ACCEPTED` only. Never copied into the live champion directory.

## 69. Artifact versioning
Record model_version, policy_version, feature_schema, target_schema, symbol, horizon,
training/validation/holdout from-to, cost & funding assumptions, entry rule, SL / activation /
trailing / expiry policy, calibration, quantile config, git revision.

## 70. Schema names
`feature_schema = qt-shadow-features-v3`, `target_schema = qt-path-targets-v4`,
`policy_version = qt-dynamic-exit-v4`. Features and targets are different concepts.

## 71. Path target files
One partition per symbol × horizon, e.g. `targets/BTCUSDT/h15.parquet`, keyed by `anchor_time_ms`
to join the causal feature row.

## 72. No duplicate feature implementation
Do not rewrite ShadowFeatureBuilder in Python. Reuse the PHP builder locally; if Python needs
features, export the exact existing vectors. One implementation of every indicator.

## 73. Local 5m feature pipeline
The isolated local SQLite approach (as in ml-offload.ps1) is fine for 5m candles, feature rows and
funding metadata. The large 1m path dataset may live outside the Laravel DB. Developer `.env` /
MySQL never overwritten.

## 74. Full local runner — steps
1 check PHP/Python/disk · 2 isolated Python env · 3 download native 5m · 4 download 1m ·
5 download funding · 6 verify all data · 7 build exact current causal features (PHP builder) ·
8 build V4 path targets · 9 V3-vs-V4 label audit · 10 train V4 models · 11 calibrate ·
12 walk-forward evaluation · 13 freeze policy · 14 untouched holdout · 15 cost/risk stress ·
16 final reports.

## 75. Training order (progressive)
BTCUSDT 15m, ETHUSDT 15m first → confirm pipeline correctness → BNB/SOL/XRP/SUI 15m → then
60, 120, 240, 1440. The runner must still support the full matrix automatically.

## 76. Efficient target generation
Never naive O(N × 1440) or materialized N × H windows (e.g. 500,000 × 1,440). Use vectorized NumPy,
indexed slicing, chunk/batch processing, Parquet scanning / memory mapping; Numba only if
profiling justifies it. Sequential policy simulation in efficient batches. Print timings.

## 77. Memory safety
Report RAM estimate, disk estimate, rows, progress, ETA. Process symbol-by-symbol and
horizon-by-horizon; never all coins/horizons in RAM at once.

## 78. Mandatory tests
- A: LONG activation → higher peak → trailing reversal ⇒ success, TRAILING_PROFIT.
- B: LONG SL hit then huge rally ⇒ failure, HARD_STOP (the rally never changes it).
- C: SHORT mirror of A and B.
- D: neither activation nor SL ⇒ TIME_EXIT, net from the time exit.
- E: gross +0.10% with cost 0.12% ⇒ not successful.
- F: SL and activation in the same 1m candle ⇒ AMBIGUOUS_INTRABAR, never an optimistic win.
- G: missing future candle ⇒ INVALID_DATA, never filled.
- H: funding event crossed ⇒ applied exactly once at the right timestamp/rate.
- I: no funding event crossed ⇒ funding 0.
- J: entry timing — feature candle closes first; entry never uses a price unavailable at T0.
- K: leakage — changing candles after T0 never changes X; changes the target if the path changes.
- L: trailing monotonicity (LONG stop only up, SHORT stop only down).
- M: predicted required SL > hard cap ⇒ WAIT.
- N: high probability but poor expected net ⇒ WAIT.
- O: BUY and SELL too close ⇒ WAIT.

## 79. V3 vs V4 label audit (before full training; per symbol × horizon)
anchors; valid / invalid-gap / ambiguous V4 targets; V3 BUY-positive and SELL-positive counts;
V4 LONG / SHORT opportunity and WAIT counts; V3→V4 disagreement count and %; "endpoint misses a
profitable path" count; "endpoint says profit but the realizable path hits SL first" count.
Purpose: show whether the endpoint label was losing information.

## 80. Path distribution report (LONG and SHORT separately)
Percentiles of MFE, MAE, MAE-before-profit, time-to-profit, time-to-MFE, post-activation
retracement. Guides policy validation instead of arbitrary TP/SL values.

## 81. Exit policy report
Counts of HARD_STOP, TRAILING_PROFIT, TIME_EXIT_PROFIT, TIME_EXIT_LOSS, AMBIGUOUS; average
activation, stop distance, trail distance; average capture ratio (realized favorable net / path
MFE) — diagnostic only, never an optimization target.

## 82. Final decision policy (shadow, live-equivalent)
data bad ⇒ WAIT · predict P(BUY), P(SELL) · predict conservative reward and risk per side ·
expected net after costs · apply uncertainty, hard risk cap, risk/reward, probability, conflict ·
no side passes ⇒ WAIT · one passes ⇒ that side · both pass ⇒ compare conservative expected
utility; difference not safely meaningful ⇒ WAIT.

## 83. Expected net
expected_net = expected realized gross − fees − slippage − expected/applicable funding, from
calibrated/probabilistic estimates, never from optimistic MFE.

## 84. Trade plan after side selection + consistency gate
dynamic_sl = f(predicted adverse risk, volatility) clipped by the hard cap; profit_activation =
f(conservative reward, cost, min net edge); trailing_distance = f(retracement behaviour, live
volatility) clipped to safe limits; max_hold = horizon. Consistency gate: activation > costs + edge,
SL ≤ hard cap, reward/risk adequate, trailing sensible relative to activation — otherwise WAIT.

## 85. Future autopilot contract — design only, NOT implemented
Output fields: decision, symbol, horizon, entry_reference, probability, expected_reward,
expected_risk, expected_net, hard_stop_loss, profit_activation, trailing_distance, max_hold,
model_version, policy_version, risk_gate_results, decision_reasons. Autopilot later executes the
policy; it never invents its own SL/TP.

## 86. Future autopilot invariants (document only)
Hard stop always wins; trailing only tightens; SL never widened after entry; an expired position
must exit; stale model/input, unknown costs, invalid artifact, model rejection or WAIT cannot open.
Position sizing / leverage protection is a separate future phase.

## 87. No profit guarantee
Never label anything "guaranteed profitable". Historical BUY/SELL = historically realizable under
the defined policy; prediction = estimated probability/edge; losses remain possible.

## 88. V4 local research gate
Start from the existing classifier gates (min trades, net > 0, avg net/trade, fold share, t-stat) and
add: acceptable calibration, max drawdown, tail loss, cost stress, policy sensitivity, ambiguity
rate, data coverage, no major profit concentration. New thresholds: documented rationale,
validated on train/validation, frozen before the holdout. No arbitrary permissive numbers.

## 89. Result status per symbol × horizon
`ACCEPTED_FOR_SHADOW_RESEARCH`, `REJECTED`, `INSUFFICIENT_DATA`, `INVALID_DATA`, `FRAGILE`.
Never silently pass.

## 90. Final report per symbol × horizon (JSON + Markdown)
History (requested, actual, 5m rows, 1m rows, funding rows) · Targets (valid, ambiguous, invalid,
BUY, SELL, WAIT) · V3/V4 (endpoint disagreement, missed path opportunities) · Model (P(BUY) and
P(SELL) calibration, MFE and MAE error) · Trades (count, long, short, total/avg/median net, win
rate, profit factor, max drawdown, max loss, consecutive losses, t-stat) · Exits (hard stop,
trailing, time) · Stress (normal, +25%, +50%, delayed entry) · Walk-forward (positive folds) ·
Decision + reason.

## 91. Global comparison table
Rows: every symbol × horizon (BTC/ETH/BNB/SOL/XRP/SUI × 15/60/120/240/1440). Columns: V3 result,
V4 result, trades, avg net, total net, max DD, t-stat, positive folds, cost stress, status, reason.

## 92. Local artifacts only
Accepted V4 models stay in `D:\autofinx-ml-v4\models\`. Never copied to the production champion
directory; never call the offload install automatically.

## 93. Python environment
Isolated V4 venv. Existing lightgbm, numpy, scikit-learn; add only what is justified (pyarrow for
Parquet). Pin compatible versions. Never alter production Python.

## 94. Command UX (deliver at completion)
Exact `powershell -ExecutionPolicy Bypass -File .\ml-v4-local.ps1 …` commands for: fresh full run,
resume, one-symbol smoke test, one-horizon test, rebuild targets only, retrain only, evaluate only,
open/read the final report.

## 95. Smoke test before multi-year runs
Small historical window, BTC 15m: verify download, 1m/5m alignment, features, targets, SL-first,
profit-first, trailing, time expiry, costs, funding, training, evaluation, report. All tests pass,
then full history.

## 96. Full data run
After the smoke test: BTC/ETH/BNB 60 months, SOL/XRP/SUI 36 months, horizons 15/60/120/240/1440.
Resume, never restart completed history.

## 97. Never hide failures
Report download failures, missing history, high ambiguity, all-rejected training, V4 not beating
V3. Never change the methodology to make results look positive.

## 98. Priority order
1 correctness · 2 no leakage · 3 realistic executable path · 4 loss prevention · 5 reproducibility
· 6 robustness · 7 performance · 8 trade count (last).

## 99. Deliverables at completion
A audit · B architecture · C every file changed + reason · D data locations · E exact label
semantics · F dynamic SL (training + calculation) · G dynamic profit activation · H dynamic
trailing · I every WAIT reason · J every risk gate + config source · K test commands + results ·
L smoke test result · M full six-coin command · N report paths · O remaining risks (intrabar
ambiguity, data gaps, distribution shift, overfitting, transaction-cost uncertainty).

## 100. Final design principle
The V4 brain must not learn "what was the best price I could have magically exited at", but "from
only the information available at T0, how likely is a safe net-profitable LONG or SHORT
opportunity, what reward/risk behaviour normally follows, and what causal SL / profit-activation /
trailing plan gives a robust realizable outcome". NO SUFFICIENT EDGE ⇒ WAIT, never FORCE BUY/SELL.
Loss prevention beats trade frequency. TP is not an exact future target (MFE distribution + profit
activation + trailing). SL is not an exact future number (MAE / adverse-before-profit distribution
+ hard risk cap).

Loss-prevention chain: prediction → probability ok? → expected NET after all costs positive? →
reward/risk ok? → required SL inside the hard cap? → uncertainty ok? → data/OOD ok? → YES: BUY/SELL
+ dynamic exit plan; any NO: WAIT.

## 101. Phases — reconciled locked order (D-012)
The owner's §101 phase list and review correction C10 merged into one order:

| Phase | Content | Holdout |
|---|---|---|
| P0 | Read-only audit ✅ | — |
| P1 | Local Binance 1m + native 5m + funding downloader, verification, runner skeleton (§3–7, 45–48, 74 steps 1–6, 76–77, 93) | — |
| P2 | Layer A raw path targets + exact PHP features (§10–11, 46, 71–72, 74 steps 7–8; tests G, J, K) | sealed |
| P3 | V3-vs-V4 label audit (§79), path distribution report (§80), policy laboratory: fixed + volatility baselines and V3 signals + new exits (§21–24, 59–61, 81; tests A–F, H, I, L) — **train/validation only**, diagnostic | sealed |
| P4 | BUY/SELL probability classifiers + MFE/MAE quantile models + uncertainty (§28–34) | sealed |
| P5 | Dynamic SL / activation / trailing calibration on validation, decision policy + gates (§35–40, 50–58, 82–84, 88; tests M–O); then **rebuild success outcomes under the frozen policy, retrain + recalibrate the final selected-feature models on development data (D-026 #5)**, then **FREEZE** every value in DECISIONS.md | sealed |
| P6 | Purged walk-forward + ONE untouched final holdout (§41–42, 62–64) | opened once |
| P7 | Cost / risk / parameter / delay stress (§49, 65) | — |
| P8 | Final local reports, global table, statuses, command list, deliverables (§89–91, 94, 99) | — |

The BTC 15m smoke test (§95) runs at the end of P2/P3 before any multi-year run; training order
follows §75. **STOP after P8.** No autopilot, no live execution, no production model install.

## 102. Causal Pattern Intelligence (P4A / P4B) — LOCKED 2026-10-06 (D-014)
Owner proposal: give the direction brain Pine-style market understanding (structure, candles,
momentum, squeeze, multi-timeframe, regime, volume/order flow) as **causal features**, never as
direct signals, and prove each group by ablation. Agent review against code (RESULTS R-005) adds
the adjustments marked **[A#]**.

**Split P4:** P4A = V4 labels/policy with the unchanged V3 features (isolates the label/exit effect).
P4B = candidate pattern feature groups, added only if ablation proves value.

**Candidate groups (bounded list, fixed order):**
- G1 Market structure: confirmed swing highs/lows, HH/HL/LH/LL counts, distance to last swing
  high/low, breakout above/below last swing (strength, age), failed breakout, retest, range position.
- G2 Candle shape sequence: upper/lower wick %, body %, close position in range, last 3 candles,
  consecutive bull/bear count, inside/outside bar, range expansion/contraction.
- G3 Momentum derivatives: RSI slope/acceleration, MACD(12,26,9) histogram + slope, ROC,
  EMA20/EMA50 slopes, EMA spread change.
- G4 Volatility / squeeze: ATR % and change, Bollinger width + position, width percentile,
  volatility percentile.
- G5 Multi-timeframe from closed 5m bars: 15m / 1h / 4h trend slope and structure, alignment score.
- G6 Volume / taker: volume z-score, volume trend, taker imbalance change (extends V2 taker set).
- G7 (optional) Derivatives data: open interest change, long/short ratios, funding level — only
  after a coverage check (Binance daily `metrics` files exist from at least 2021-10-06 for BTC).
- G8 (optional, last) 1m micro features — only if it adds value, because live use would need a
  1m feed the server does not collect today.

**Adjustments:**
- [A1] One implementation (§72): all candidate groups are added to the PHP ShadowFeatureBuilder as a
  new superset schema `qt-shadow-features-v4`; V3 stays untouched. Ablation selects columns at
  training time, so variants never need a rebuild, and any future live shadow uses the same code.
- [A2] No repaint: a swing pivot is only usable after its confirmation bars have closed (lagged by
  the fractal width k); higher-timeframe values use only fully closed bars or rolling windows of
  closed 5m bars. Covered by the leakage test (§44) per group.
- [A3] Regime is causal features + a reporting dimension (§63), not a separately trained brain
  (regime labels are subjective); a regime model only if ablation shows it beats regime features.
- [A4] The direction brain stays two calibrated side classifiers (BUY, SELL). WAIT is a policy
  outcome (§28–31, §82), not a third trained class.
- [A5] Ablation discipline: pre-declared groups and order; validation / walk-forward only; a group
  is kept only if the improvement is consistent across folds (not just the aggregate), feature count
  is capped, and every ablation result — kept or dropped — is logged in RESULTS.md so the number of
  experiments is visible.
- [A6] Pre-registered final comparison: before P6, three arms are frozen — V3 baseline,
  V4 labels + V3 features (P4A), V4 labels + selected groups (P4B). The holdout evaluates all three
  in one run.

The four "brains" map to SPEC §32: Regime (features), Direction (A/B classifiers), Reward/Risk
(C–F quantile models), Execution policy (validated SL / activation / trailing rules) → BUY/SELL/WAIT.

**Status: LOCKED 2026-10-06 (D-014)** with these final constraints:
- [L1] P5 pre-registers the three holdout arms. **PRIMARY = Arm 3 (V4 labels + selected V4
  features)**; Arm 1 (V3 baseline) and Arm 2 (V4 labels + V3 features) are benchmarks only. After the
  holdout no new winner is chosen and nothing is retuned. Arm 3 pass ⇒ candidate for a later shadow
  phase; Arm 3 fail ⇒ REJECT for this holdout.
- [L2] No hard-cap number now. P3 reports MAE-before-profit distributions by symbol, horizon, side
  and volatility regime; the **market SL cap** (max price move against the trade) is chosen and frozen
  in P5 from train/validation evidence only. The **account risk cap** (money per trade, position
  size, leverage) is a separate future autopilot phase, not part of V4.
- [L3] G7 is optional and only after P1 verifies causal timestamp coverage and quality: values known
  at T0 only, no backward fill, no future snapshot, no fake zero. G7 never shortens the G1–G6
  full-history dataset; with incomplete coverage it is a separate common-window ablation.
- [L4] G8 (1m micro features) is excluded from this implementation; 1m is for target paths only.
- [L5] G5 higher-timeframe bar features use only fully CLOSED 15m / 1h / 4h bars aligned to UTC
  (Binance) boundaries — at T0 = 10:35 the 1h feature uses 09:00–10:00, never 10:00–11:00. Mandatory
  leakage test.
- [L6] Swing/pivot features become available only when their confirmation bars have closed (swing at
  t, knowable at t+k). Explicit leakage tests.
- [L7] P5 freezes the stress matrix before the holdout opens: normal costs, +25%, +50%; SL ±10%,
  activation ±10%, trailing ±10%; 1m delayed entry. P7 only classifies ROBUST / FRAGILE / REJECT,
  never retunes or adds scenarios.
- [L8] Raw path targets (Layer A) stay policy-independent; policy simulation is a separate layer.
- [L9] (agent addition) If P4B ablation keeps no feature group, Arm 3 equals Arm 2; then the primary
  candidate is Arm 2, decided by this rule in advance, not after the holdout.
- Ablation keep rule: a group is kept only if it improves the median fold, is consistent across
  folds (one big fold cannot hide worse folds), does not degrade calibration materially, and does
  not explode the feature count.

