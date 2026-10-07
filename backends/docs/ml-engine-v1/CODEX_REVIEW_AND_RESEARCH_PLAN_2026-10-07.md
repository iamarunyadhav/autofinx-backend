# Independent review and proposed next research system

Date: 2026-10-07. Reviewer: Codex. Requested scope: inspect current training, challenge the implementation and results, and propose the strongest next research plan.

This is a proposal, not an approved replacement for locked decisions. No research implementation, process termination/restart, model fitting, holdout evaluation, live execution, deployment, or commit was performed by this review. The workspace and runners were changing during the review; findings refer to the source inspected on this date. Source paths below are relative to `external-predictive-engine/` unless stated otherwise.

## 1. Recommendation

Build an event-conditioned, policy-consistent expected-return system, initially researching 60/120/240-minute opportunities. Keep LightGBM and the existing causal feature infrastructure. First repair evaluation defects, then compare simple executable strategies, then add context and conditional payoff prediction. Add meta-labels and dynamic trailing only if they improve an independently evaluated baseline.

There is no justified promise that this will become profitable. There is a justified way to improve the experiments and distinguish missing signal from restrictive policy design, misleading diagnostics, and execution bugs. Removing REJECT is not an objective; identifying a reproducible profitable subset is.

## 2. Observed training state

Snapshot around 19:39 SLST:

- P4B: 30 per-cell JSON reports; optional layers: six h15 reports.
- New full-run P5 BTC h15: REJECTED, 286.1 seconds, zero executed trades across 12 exit candidates.
- New full-run P5 ETH h15: REJECTED, 376.9 seconds, 0–1 executed trades per candidate.
- P5 BNB h15 started at 19:34:37 and its worker consumed increasing CPU between observations.
- BTC h60 meta experiment started at 19:30:14; its worker also consumed increasing CPU. No completed meta JSON was present at the snapshot.
- Existing BTC h60/h240 P5 reports date from the earlier smoke runs; they are not completed cells of this new full run.
- Two real training workers were active, each with a small Python launcher parent. Four process rows do not mean four separate trainings. Free RAM was about 14.95 GB; free commit about 15.12 GB. Increasing CPU shows activity, not guaranteed forward progress.

The handoff is stale in two respects: meta/maker experiments now exist and are running; the WAIT retention rule has already been revised in source to compare against actual trade retention. The old optional-layer JSON still describes the old verdict regime and needs versioned recomputation before comparison.

### Current BTC h15 rejection, not the older smoke explanation

For `a1.0_b0.8_vol`, the new report records 229 long and 126 short opportunities passing the level gates. Most fail the reward floor (52,430 long; 52,655 short). The remaining opportunities fail the EV gate. Last-subfold diagnostic maximum EV is -0.1161% long and -0.1233% short. The code retains only the last subfold's floating diagnostics, so these are not whole-window extrema.

Thus “all opportunities fail the SL cap” is not the current result. Some pass risk/reward geometry; the estimated payoff still does not justify a trade.

Sources: `D:/autofinx-ml-v4/logs/p4b-p5.log`, `reports/p5/BTCUSDT_h15.json`, `ETHUSDT_h15.json`; `v4/p5_run.py:222` aggregates diagnostics.

## 3. Confirmed implementation defects and evaluation limitations

### A. Maker fill-minute simulation can report impossible pre-entry profit — high priority

`v4/execution.py:22` identifies a fill from a minute's high/low and returns that minute's opening timestamp. `simulate_c1` at line 42 then passes the entire fill minute into `policy.simulate`, with an overridden entry price. Highs/lows before the actual fill can arm the trailing stop. Treating the whole minute as post-entry is not universally conservative.

Reproduced with a long limit at 100, horizon 2 minutes after the fill minute, SL 1%, activation 0.5%, trail 0.2%, C1 cost 0.08%:

| Minute | Open | High | Low | Close |
|---|---:|---:|---:|---:|
| Signal | 100 | 100.1 | 100 | 100.05 |
| Fill minute | 100.05 | 101 | 99.9 | 100.1 |
| Next | 100.1 | 100.15 | 98.8 | 99 |

Current simulator returns gross +0.10%, net +0.02%. A compatible tick sequence is 100.05 -> 101 BEFORE entry -> 100 fill -> 99.9 -> 100.1, then the next minute hits the hard stop at 99. That sequence yields gross -1%, net -1.08%. This is a synthetic counterexample, not a measured dataset-wide bias.

Required repair: simulate the pending order and post-fill position together, respecting event order; use aggTrades on selected windows or explicit conservative branching over fill timing. Also model post-only admissibility against bid/ask, latency, cancellation, partial fills/size assumptions and invalid data. Decide whether expiry is signal+H or fill+H; currently C1 shifts it to fill+H, so it changes the opportunity window too. Existing 1m trade-through results are exploratory, not verified maker execution.

### B. Quantile crossing gate is bypassed by preprocessing — reproduced

`v4/p5_run.py:49` sorts predictions in `_predict_q`. At lines 151–154, already-sorted predictions go into `p5.quality_gate`, which calculates crossing again. Synthetic raw q25=0.9, q50=0.5, q75=0.1 gives 100% raw crossings but reported crossing rate 0.0.

Measure raw crossing first; retain it alongside repaired predictions. Existing zero-crossing reports do not establish model coherence. This does not prove that the trained models actually cross frequently.

### C. P4B inner-fold validation is not fully isolated

`v4/ablation.py:109` derives reference exit levels using ALL dev_train rows before constructing the folds. Later internal test outcomes therefore influence the policy that defines earlier training labels. At lines 75–76, fit/calibration is split without an H-length purge even though the outer fold boundary is purged.

Fit reference policy, calibration inputs and every learned preprocessing step inside the preceding training window. Add purge to the internal fit/calibration boundary. This is inner-development contamination; it is not evidence that the sealed final holdout was used for selection.

### D. Meta/layer evaluation reuses selected features from its own test periods

`p5_run.Context` selects features using the complete P4B report. `meta_label.run` uses that context and evaluates the D-025 folds again. Optional layers similarly load the final P4B selection via `_baseline`. Base-model row splits can be forward-only while the feature selection has already seen those test outcomes.

Use a fixed preregistered base schema for the first clean meta test, or repeat feature selection inside each outer training window. Do not present reused folds as a fully independent confirmation of the selected pipeline.

### E. Feature-overlap audit mishandles ties — reproduced

`v4/p4b_audit.py:26` assigns distinct chronological ranks to equal values rather than average ranks. A constant column versus an increasing column produces approximately 1.0 correlation. Correct Spearman correlation with a constant is undefined.

Use tie-aware ranking and explicitly exclude/report constants. Recompute the overlap report before concluding that G3/G4/v13 are redundant. High pairwise correlation alone also does not prove zero incremental predictive value.

### F. D-029 fixes the policy identity, not the whole conditional EV problem

`v4/p5_run.py:166` recalibrates the reference classifier score against candidate-policy success. This is a useful correction. However, isotonic mapping cannot learn a new ordering of trades for a different exit policy. It can flatten or remap the old ordering.

At lines 185–186 the average successful and other payoffs are constants for a policy/side. The true conditional expectation is:

`E[net | x, policy] = p(success | x, policy) * E[net | success,x,policy] + (1-p) * E[net | other,x,policy]`.

Both payoff terms can depend on volatility, stop distance and market state. Current EV substitutes unconditional calibration means. Compare this baseline against regularized direct net-return prediction and conditional payoff models on out-of-sample policy outcomes. A positive model prediction is still not proof of positive realized EV.

Candidate calibration at lines 173–184 does not exclude unresolved ambiguity, whereas initial/final classifier fitting does. OOD is disabled for candidate calibration and enabled for selection. Align the target population and explicitly choose/document the ambiguity treatment. These mismatches need measurement, not an assumption that their repair creates profits.

### G. The entry gate is structurally restrictive; meta cannot rescue excluded opportunities

`v4/p5.py:106` uses activation = b*q25(MFE), with b in {0.8,0.6}. Activation must exceed cost+edge=0.18%. Therefore q25(MFE) must exceed 0.225% or 0.30%, respectively, even though the first reward check says 0.18%.

`meta_label.py:121` creates proposals only after these P5 level gates and a valid calibration are available. A TAKE/SKIP model cannot rescue opportunities absent from its training/proposal population. Increasing model complexity behind an almost-empty proposal gate is unlikely to answer whether another executable setup has edge.

Do not silently weaken this gate. Compare a separately preregistered small family of causal event setups with fixed risk exits and direct net labels. Distinguish independent hard risk limits from learned “room before profitability.” Layer A adverse-before-profit is measured before the 0.12% threshold, not before each candidate's activation; its cap/target is not automatically calibrated for every exit policy.

### H. Selection and final model lifecycle have untested gaps

- P5-A uses one default EV margin (0.03) before P5-B considers other margins. A policy viable only with a different gate is never considered. A bounded joint search inside proper nested training is a proposed design alternative, not permission to search until something passes.
- `p5_run.py:275` only requires nonempty positive aggregate P5-C net. It does not apply A/B's minimum trades/PF/t gates there; one positive trade can pass this check. Preregister an independent confirmation rule with sample sufficiency and uncertainty.
- Final retraining changes classifier targets and fits new quantiles, while retaining earlier cap/uncertainty/OOD references. Forward labels also reuse globally selected policy/features. This is acceptable as development fitting only if the ENTIRE retraining recipe is independently evaluated in an outer walk-forward loop. Current P5-C does not evaluate those final artifacts.
- `_execute` is restarted for each P5-A/B subfold; a position near a boundary can overlap a newly started position in the next subfold. Carry state or purge boundary positions before aggregating results.
- Reports are overwritten per symbol/horizon and do not provide a complete immutable experiment identity. Freeze source/environment/config/data hashes and run IDs before the next batch. A changing working tree can give successive processes different code.

### I. T-statistics and validation reuse need stronger treatment

Non-overlapping positions are not necessarily independent returns. Regime clustering remains. Current t-stat uses the ordinary standard-error formula. Use block-bootstrap intervals on chronological portfolio returns, sensitivity to block length, and report effective sample size and concentration. Record every trial; repeating dozens of policies/features/horizons consumes the same evidence budget.

P4A already reported on dev_val, and those results informed subsequent designs. The A/B/C row split does not erase researcher reuse of dev_val. Treat it as development evidence; preserve the sealed holdout and add genuinely forward paper observation after a freeze.

### J. Several handoff conclusions are stronger than their evidence

- Zero mean across all opportunities does not imply every conditional subset has zero mean. A predictive filter can improve conditional returns; the tested filters have not demonstrated sufficient improvement.
- Poor current results do not prove that all OHLCV features contain no useful information.
- A fixed-horizon exit is executable. It is not an oracle exit merely because it lacks a stop. Compare it with a predeclared disaster stop/account risk limit; do not call it inherently non-realizable.
- The resolver table itself reports some overstated outcomes. Average pessimism is not a guaranteed lower bound for each trade. Benchmark event ordering and funding before making that claim.
- `strategy_v13.py:1` explicitly describes normalized AutoFinX execution and realtime-style HTF interpretation, not exact TradingView historical parity. The 24 negative cases concern that translation/configuration. Pine parity needs exported indicator/signal/trade fixtures. Trailing distance changes at chart closes also deserve a separate stop-monotonicity review.

## 4. Proposed next system

### Step 0 — establish a trustworthy baseline

Preserve existing results with hashes, fix A–E and target-population inconsistencies, add the reproduced counterexamples as regression tests, and replay affected development experiments. Do not modify running processes' imported source mid-batch. Use an immutable code snapshot and one bounded training worker; record progress per model/fold rather than only per cell.

Use a single frozen timestamp manifest, not a newly recomputed 15% boundary every time data is appended. Physical readers should restrict feature/target columns and time ranges before loading data. Current Context reads whole target/feature files before masking; this is logically excluded from fitting but is not a physical access seal.

### Step 1 — measure whether there is a direction signal

For each fixed policy on purged development folds, persist OOF predictions and actual long/short outcomes. Compare:

1. Never trade; always long/short; volatility-only and simple momentum baselines.
2. Signal-selected side versus the opposite side at the SAME eligible times and exits.
3. Calibrated score/EV deciles versus realized gross/net, trade count and tail loss.
4. Volatility-stratified direction lift. Predicting a large move on both sides can yield useful-looking AUC without reliable direction.
5. Economic threshold at actual cost, then cost +25/+50%; immutable candidate set.

This is the cheapest decisive diagnostic. If there is no side-selection or conditional payoff lift, defer complex meta/trailing work and investigate new inputs or strategy hypotheses.

### Step 2 — test two simple strategy hypotheses

Primary research hypothesis: trend continuation after a pullback. Use only completed 1h/4h context, a causal 5m pullback/resumption event, volatility-scaled geometry and a fixed maximum holding time. These are candidate signals, not established profitable rules.

Secondary hypothesis: range reversion under low-trend conditions, with an explicit breakout invalidation and hard stop. Keep separate reports/models; do not blend it into trend logic until either survives its own test.

Start with BTC/ETH at h60 and h240 for pipeline validation, add h120 as a bounded comparison, then expand the fixed recipe to the other four symbols. This priority is an economic/design hypothesis, not proof that these horizons beat h15. Keep h15 and h1440 as benchmarks rather than repeatedly optimizing all 30 cells at once.

For each setup compare only a small declared exit set: fixed volatility stop + time exit, and fixed stop + simple volatility trailing. Keep risk caps independent. Dynamic learned exits must beat these at matched risk and costs before being adopted.

### Step 3 — train the trade's economics

Use out-of-fold base predictions for every stacked/meta input. Within every chronological outer fold: fit earlier base models -> produce later OOF proposals -> fit meta/payoff models -> calibrate on a further purged slice -> evaluate the untouched outer interval.

Proposed model comparison:

- Existing policy-calibrated LightGBM classifier as the reference.
- LightGBM direct net-return regression under a fixed executable policy, plus downside quantiles.
- If enough samples exist, probability plus conditional win/other payoff estimates, with shrinkage toward broad baselines.
- A simple regularized linear/logistic model as a complexity check.

Choose the action using validated conditional net return and downside risk, including WAIT=0. Distinguish a return-distribution quantile from a confidence interval on the estimated mean. A lower confidence bound requires an explicit uncertainty procedure. Calibrate on untouched observations, not in-sample score quantiles. Do not market raw LightGBM or meta scores as calibrated confidence percentages.

### Step 4 — add genuinely different market context

First use available causal inputs: BTC/ETH market direction, each coin's return relative to the market, lagged beta/correlation, cross-coin dispersion, last published funding and time to the next funding event. Existing own-symbol taker features must remain in the baseline so duplicates are not called new information.

Then audit official historical coverage for open-interest changes, premium/basis and trade-flow data. Keep publish/receive timestamps and missingness. Never use future realized funding as a decision feature. Add spread/depth/queue inputs only where real historical or forward-collected data exists.

Compare a pooled model with symbol/context features against local models. For pooled/cross-coin experiments use synchronized UTC folds and respect the EARLIEST existing holdout boundary across participating symbols; otherwise training on one coin can overlap another coin's sealed period. Six selected surviving coins do not establish broad-universe performance.

### Step 5 — execution, portfolio and retraining

Actual account commissions, spread, slippage, latency and fill risk determine costs. Keep the current 0.12% as a baseline scenario; do not replace it with 0.08% merely to obtain a pass. Maker comparisons require the repaired pending-order simulator, missed fills and outcome-per-submitted-order/day as well as outcome-per-fill. A lost average of -0.12% cannot be rescued by a 0.04 percentage-point fee reduction alone if gross outcomes stay unchanged.

Compare one preregistered rolling retraining schedule to the current fixed model. BTC's existing P3 split records dev_train ending 2024-09-23 and dev_val starting 2024-09-24 through early 2026. A frozen fit/calibration evaluated across that period is different from a periodically refreshed deployment. Replay the refresh algorithm using only fully matured earlier labels; do not move the holdout. Compare bounded window/recency alternatives only inside training. The current 180-day weighting sharply discounts old years; “five years downloaded” is not “five years weighted equally.”

Finally simulate one portfolio with finite capital, correlated exposures, position sizing and mark-to-market drawdown. Summing independent symbol/horizon percentages is not portfolio profit. Add risk sizing after signal evidence; leverage and loss-recovery sizing do not repair negative expectancy.

### Step 6 — prove before promotion

Pre-register the primary candidate, all trial counts, sample sufficiency, acceptable drawdown, block-bootstrap method, positive-fold requirement and cost/latency sensitivity before final evaluation. Require incremental performance versus the simple baseline, not just positive average net. Report dependence on a few trades, one coin or one regime.

Evaluate the complete feature-selection/training/calibration/retraining procedure in outer walk-forward folds. Freeze code, inputs and policy; use the existing one-shot holdout only under its approved protocol. A failure remains a failure. Follow with timestamped forward shadow/paper signals and realized execution evidence; calendar duration alone is not sufficient if too few independent opportunities occur. No live integration is part of this proposal.

## 5. Priority and stop rules

| Priority | Work | Continue only when |
|---|---|---|
| P0 | Simulator, quantile, fold and correlation fixes | Counterexamples pass; source/data identity reproducible |
| P1 | OOF signal/economics and paired-side diagnostics | A credible conditional effect or a clearly testable new hypothesis exists |
| P2 | Two setup families + simple exits + payoff models | Net improvement is stable at matched risk across untouched folds |
| P3 | Cross-market/flow inputs and pooled comparison | Incremental benefit survives identical folds and costs |
| P4 | Meta/continuation and realistic maker study | Each addition beats the simpler system independently |
| P5 | Portfolio, full retraining recipe, freeze and final evaluation | Preregistered evidence and risk requirements pass |

Do not begin with Transformers, reinforcement learning, dozens of indicators, a huge parameter search, or forced BUY/SELL balance. They do not address the demonstrated defects. Preserve experiments that fail; use an evidence budget and a declared stop condition instead of repeating until a lucky pass appears.

## 6. Verification performed and limits

- Ran `D:/autofinx-ml-v4/venv/Scripts/python.exe -B -m pytest tests/v4 -q -p no:cacheprovider`: **90 passed in 4.47s**.
- Ran three small synthetic checks: maker pre-fill counterexample, crossing-rate masking, tied-rank correlation. All reproduced the defects described above. No market dataset or holdout values were needed for these checks.
- Read current code, local development reports, decisions, spec and runner/process state. No new heavy experiment launched.
- Full PHP/frontend suites were not run because no application implementation changed. Feature parity, full Pine parity, complete data provenance and every execution branch have not been exhaustively certified by this review.
- Fixing these issues can change measurements either direction. No profitability estimate for the proposed system is claimed.

## 7. External primary references

- [Binance account commission endpoint](https://developers.binance.com/docs/derivatives/usds-margined-futures/account/rest-api/User-Commission-Rate): obtain account/symbol maker and taker rates; documentation examples are not the owner's actual rate.
- [Binance open-interest history](https://developers.binance.com/docs/derivatives/usds-margined-futures/market-data/rest-api/Open-Interest-Statistics): REST states only the latest month is available. Audit official archives separately; do not assume five-year OI or reconstruct it from OHLCV.
- [Binance public archive](https://github.com/binance/binance-public-data): checksums and archive revisions support preserving input hashes.
- [TradingView higher-timeframe semantics](https://www.tradingview.com/pine-script-docs/concepts/other-timeframes-and-data/): confirmed-HTF offset/lookahead behavior needs explicit parity testing.
- [Bailey et al., Probability of Backtest Overfitting](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf): selecting among many tested alternatives requires accounting for research selection; a favorable backtest alone is insufficient.
