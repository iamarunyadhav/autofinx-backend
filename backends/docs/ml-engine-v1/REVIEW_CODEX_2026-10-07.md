# Independent review (Codex, 2026-10-07) — condensed record + agent verification

The owner pasted the full review into the session; this file keeps its substance for traceability.
Owner decision: "ok rendum" → **D-036**: (a) stop the automatic 30-cell meta/maker run, (b) adopt the review's
roadmap (P0 → P1 → P2 …) as the next research plan.

## Confirmed defects (agent re-checked B, C and E in code; A was reproduced by the reviewer)
| ID | Defect | Status |
|---|---|---|
| A | Maker C1: the whole fill minute was treated as after the fill, so a pre-fill high could arm trailing (synthetic case: +0.02 % instead of −1.08 %). Expiry also moved to fill + H. | FIXED (`execution.simulate_c1`): adverse extreme of the fill minute counts, favourable extreme ignored, position from the next minute, expiry signal + H; tests `test_p0_fixes.py::test_a_*`. R-021 C1 numbers are superseded |
| B | P5 quality gate measured crossing on already-sorted quantiles, so it always reported 0 %. | FIXED (`_predict_q_raw` feeds the gate); test `test_b_*` |
| C | P4B reference exit levels came from all dev_train rows (including the folds' test rows); internal fit/cal split had no purge. | FIXED (per-fold reference levels from the fold's train rows; fit/cal purge H); P4B must be replayed |
| D | Meta / optional layers used the full P4B selection, which had seen those folds. | FIXED (fixed V3 base schema: `Context(arm2)`, `USE_P4B_SELECTION=False`) |
| E | Overlap audit: ties got distinct ranks, so a constant column showed ~1.0 correlation. | FIXED (average ranks, constants → NaN); audit must be replayed; the "g3/g4/v13 duplicate" reading is withdrawn until then |
| F | D-029 remaps the reference ordering only; EV payoffs are unconditional constants. | Open: P3 of the roadmap (direct net-return / conditional payoff models) |
| G | Entry gate is structural: activation b·q25(MFE) > 0.18 % ⇒ q25 MFE ≥ 0.225–0.30 %; meta can only re-rank gate survivors. | Open: P2 (simple executable setups with fixed exits, direct net labels) |
| H | P5-C passes with any positive aggregate (even one trade); `_execute` restarts per sub-fold; final retrain artifacts are never evaluated out of sample; reports were overwritten without identity. | Identity FIXED (`runinfo.stamp()` in P4B / P5 / layers / meta reports, frozen code snapshots per batch). Rest open: preregistered P5-C rule, carried position state, outer walk-forward of the full recipe |
| I | Plain t-stats on dependent returns; dev_val already informed design choices. | Open: block bootstrap + effective N + trial ledger; treat dev_val as development evidence |
| J | Overclaims in the handoff: fixed-horizon exit IS executable; resolver pessimism is average, not a per-trade bound; "OHLCV has no information" is not established; v13 is a normalized translation, not TradingView parity. | Wording corrected here; handoff conclusions to be read with these limits |

## Adopted roadmap (D-036)
| Priority | Work | Continue only when |
|---|---|---|
| P0 | Fix A–E, regression tests, immutable snapshots / run identity, replay affected experiments | counterexamples pass; identity reproducible |
| P1 | OOF signal diagnostics: chosen side vs opposite side at the same times and exits; score/EV deciles vs realized gross/net; volatility-stratified lift; never-trade / always-side / momentum / volatility baselines; cost +25 / +50 % | a credible conditional effect exists, or a clearly testable new hypothesis |
| P2 | Two setup families, separately: trend continuation after a pullback (closed 1h/4h context + causal 5m event), range reversion (low trend, breakout invalidation, hard stop); BTC/ETH h60/h240 first, h120 bounded, h15/h1440 as benchmarks; exits: fixed vol stop + time, fixed stop + simple vol trailing | net improvement stable at matched risk across untouched folds |
| P3 | Trade economics: OOF-stacked direct net-return regression + downside quantiles, conditional payoffs with shrinkage, linear baseline; WAIT = 0 as an explicit option | beats the simpler system |
| P4 | New context: BTC/ETH market direction, relative strength, lagged beta, dispersion, funding timing; then audited OI / basis / flow coverage; pooled vs local models with the earliest holdout boundary | incremental benefit on identical folds / costs |
| P5 | Real account fees (Binance commission endpoint), repaired maker simulator per submitted order, rolling retrain replay, finite-capital portfolio | evidence + risk requirements |
| P6 | Preregistered final evaluation of the whole recipe (outer walk-forward), one-shot holdout, forward paper | — |
Not to start with: Transformers, RL, dozens of indicators, large parameter searches, forced BUY/SELL balance.
