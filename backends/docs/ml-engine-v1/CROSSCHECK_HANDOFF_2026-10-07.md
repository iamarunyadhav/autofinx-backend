# AutoFinX ML V4 + Strategy Lab — cross-check handoff (2026-10-07, ~19:30 SLST)

Audience: an independent coding agent (e.g. Codex) asked by the owner to **verify** this work: correctness,
leakage, honesty of results, and what remains. Everything below can be checked against the code and the
artifacts listed; where a claim is a judgement, it says so. Source-of-truth docs live next to this file:
`AGENT.md`, `SPEC.md` (§0–105), `DECISIONS.md` (D-001..D-035), `RESULTS.md` (R-001..R-020), `PROGRESS.md`,
`CHANGELOG.md`, `PLAN_PROFITABILITY_2026-10-07.md`, `strategy-lab/`.

Paths: backend repo `autofinx-backend/backends` (Laravel 12 / PHP 8.2); Python research package
`backends/external-predictive-engine/v4/`; data + artifacts on the owner PC `D:\autofinx-ml-v4\`
(`data/`, `features/`, `targets/`, `reports/`, `models/`, `logs/`). Frontend repo `autofinx-front/frontends`.

---

## 1. Goal and hard constraints
- **Goal:** find BUY / SELL / WAIT decisions for Binance USDⓈ-M futures that stay profitable **after
  fees, funding and realistic execution**, using path-aware labels (V4) instead of the old endpoint
  labels (V3).
- **Constraints (locked in SPEC / DECISIONS):**
  - Research / shadow only. No live trading, no autopilot, no production model install.
  - Chronological splits.
  - The newest 15 % is a **sealed holdout**, opened once in P6 for frozen candidates only. No retuning
    after it is opened.
  - Owner approval between phases.
  - Win rate / accuracy is never an acceptance criterion; only after-cost economics plus robustness are.

## 2. Data and splits
- **Coverage:** 6 symbols, Binance futures from data.binance.vision, sha256-verified, plus a REST tail.
  - BTC / ETH / BNB: 60 months. SOL / XRP / SUI: 36 months.
  - 1m + 5m klines and funding.
  - Coverage 100 %, 0 gaps.
  - A few 1m-vs-5m archive conflicts are marked SOURCE_CONFLICT and excluded (R-007).
- **Anchors:** every 5m close, T0. The entry is the open of the 1m bar at T0.
- **Horizons:** 15 / 60 / 120 / 240 / 1440 minutes, giving 6 × 5 = 30 cells.
- **Splits** (`v4/splits.py`):
  - holdout = newest 15 % of anchors;
  - dev ends at holdout_start − 1440 min (purge);
  - dev_train / dev_val = 70 / 30 by time, with a 1440-min purge between them.
  - Every P3+ module asserts that `max(anchor) < holdout_start`.
- **Training location:** the owner PC (`ml-v4-local.ps1`, venv `D:\autofinx-ml-v4\venv`: lightgbm 4.7.0,
  numpy 2.2.6, scikit-learn 1.7.2, pyarrow). The production server is too loaded; server ML is paused (D-001).

## 3. Features
- **V3 base:** 37 features, built by the **PHP** `ShadowFeatureBuilder` for exact parity with production
  (`ml-v4-local.ps1` runs artisan against an isolated sqlite), exported to `features/<SYM>/v3.parquet`.
- **V4 pattern groups G1–G6:** 57 columns (`app/Domain/Ml/Shadow/PatternFeatures.php`, manifest in
  `FEATURES_V4.md`), exported to `v4.parquet` (94 columns). The groups are G1 structure (swings confirmed
  with lag K=3), G2 candles, G3 momentum, G4 volatility/squeeze, G5 MTF (complete UTC buckets only),
  G6 volume/taker.
- **G7-T tradeability / noise:** 15 columns, Python (`v4/optional_layers.py::tradeability_features`) →
  `g7t.parquet`. They cover efficiency 12/48, chop 14/48, flip rate 24/48, range overlap, failed breakout,
  EMA compression, body/wick means, path ratio, 48-bar range position, volume confirmation, persistence.
- **v13:** 22 columns from the owner's Pine strategy (`v4/strategy_v13.py`) → `v13.parquet`.

**Cross-check:**
- causality tests: `tests/v4/test_optional_layers.py::test_g7t_is_causal`,
  `test_strategy_v13.py::test_compute_is_causal`, `tests/Unit/Ml/PatternFeaturesTest.php`;
- that G5 uses only closed buckets.

## 4. Labels and execution simulation
- **Layer A** (`v4/targets.py`): policy-independent path truth per anchor and side.
  - MFE, MAE, max_adverse_before_profit, pullback_before_continuation, timings.
  - Statuses: VALID / GAP / OUT_OF_RANGE / SOURCE_CONFLICT.
- **Layer B** (`v4/policy.py::simulate` + `apply_worst_case`): hard SL, profit activation, trailing
  `stop = max(-sl, peak - trail)` (never loosens), max hold = H.
  - The same 1m bar touching both stop and target counts as **stop first** (worst case).
  - Gaps fill at the open.
  - Funding is charged to the exit.
  - Cost **0.12 % round trip** (`train_v4.COST`); MIN_EDGE = 0.06 %; the V3 label needs 0.18 % (`REQUIRED`).
- **V4 label:** net ≥ MIN_EDGE under the P3 REFERENCE policy (SL = p75 adverse-before-profit, activation
  = cost + edge, trail = p50 retracement, all from dev_train). Ambiguous rows are excluded from fitting
  only (D-023).
- **Resolver check (R-014):** 2,343 ambiguous BTC trades were re-resolved with aggTrades. The worst-case
  rule **understates** net by 0.06–0.16 % on those trades, so results are pessimistic, not optimistic.

## 5. Models and selection procedure
- **Models:** LightGBM (`TREE`: 300 trees, lr 0.03, 31 leaves, min_child 200, subsample 0.8) + isotonic
  calibration, with recency weights (half-life 180 d). There are separate BUY and SELL classifiers, and
  quantile regressors q25/q50/q75 for MFE, adverse-before-profit and pullback.
- **P4A** (`v4/train_v4.py`): arms on dev_val.
  - ARM1_V3 = V3 labels + endpoint exit.
  - V3 signal + new exits.
  - ARM2_V4 = V4 labels + V3 features.
  - Diagnostics EV-only.
  - Baselines always-long / always-short.
- **P4B** (`v4/ablation.py`, D-025): sequential G1→G6 ablation on 3 folds INSIDE dev_train (55–70 /
  70–85 / 85–100 %, purge H). A group is kept if the median fold AUC gain ≥ 0.002, the gain is > 0 in
  ≥ 2/3 folds, Brier is not worse by > 0.0005, and the feature cap is 80.
- **P5** (`v4/p5.py` rules, `v4/p5_run.py` orchestration, D-027/D-028/D-029):
  - Market SL cap = p90 adverse-before-profit.
  - Quantile quality gate (pinball vs constant, q25–q75 coverage 40–60 %, crossing ≤ 5 %).
  - 12 exit policies (SL a ∈ {1.0, 1.25} × q75 adverse; activation b ∈ {0.8, 0.6} × q25 MFE; trail
    ∈ {0.5σ, 1.0 / 1.5 × q50 pullback}).
  - 12 gates (lift × margin).
  - Nested P5-A (exit policy) / P5-B (gate) / P5-C (confirmation) on dev_val windows 40 / 30 / 30 %.
  - Selection: min trades, positive in 2/3 sub-folds, PF ≥ 1.05, t ≥ 1.
  - Final forward-chaining retrain, then freeze.
  - **D-029:** probabilities are re-calibrated per candidate exit policy, so p and avg win/loss refer
    to the same policy.
  - New today: the freeze writes `models/<SYM>/h<H>/p5_final/decision.json` (isotonic map, EV params,
    base rate, OOD mean/std, caps) so P6 only *applies* frozen inputs. `--arm arm2` gives the V3-feature
    benchmark.

## 6. Results so far (numbers from RESULTS.md)
**P3 (R-013):**
- V3 endpoint labels are materially misaligned with SL/trailing execution: BTC h240 "profit" labels hit
  the stop first 48 % of the time.
- Always-take baselines lose −0.05 to −0.12 %/trade at h15–h240.
- At h1440 only shorts are positive (falling-market period).

**P4A (R-015), dev_val, 30 cells:**
- AUC: V3 labels 0.50–0.71, V4 labels 0.50–0.65. AUC falls toward 0.5 as the horizon grows.
- No V4 arm has positive avg net with t ≥ 1 in any cell.
- "V3 signal + new exits" is negative in 30/30 cells.
- A few ARM1_V3 cells (endpoint exit, no SL) show t 1.3–2.3 (SUI h60/h120, SOL h120, BTC h60). This is
  consistent with chance across 30 tests.

**P4B (R-018), 30 cells, plus Stage B audit:**
- Kept groups: g1 8/30, g5 7/30, g3 5/30, g2 3/30, g4 3/30, g6 1/30.
- Median fold gain is +0.0002–0.0006 AUC.
- No group is consistent across coins or horizons.
- h1440 baselines ≈ 0.50–0.54, so selections there are likely noise.
- Overlap (median max |Spearman| vs other groups): g3 0.91, g4 0.89, g6 0.84, base 0.80, v13 0.74,
  g1 0.70, g5 0.64, g2 0.49, **g7t 0.40** (most novel). Report: `D:\autofinx-ml-v4\reports\p4b_audit\AUDIT.md`.

**P5 smoke (R-016 / R-017):** BTC h15 / h60 / h240 all REJECTED in P5-A.
- h15: whenever q25 MFE > cost + edge, the required SL exceeds the cap (0.20 %), so every row is WAIT.
- h240: 34k rows pass the level gates, but avg win +0.31 %, avg loss −0.27 % and win rate 24 % mean EV
  needs p ≈ 0.46, while the calibrated p peaks around 0.25. Max EV is −0.12 %. 0 trades.

**Optional layers (R-020):**
- G7-T and v13 as FEATURE: NO_VALUE except XRP G7-T (USEFUL, tiny).
- As WAIT filter: tradeability AUC 0.51–0.59; executed net improves by ≤ 0.007 %/trade and stays ≈ −0.12 %.
- v13 rules (chop ≤ 62, TREND): no value.

**v13 Pine standalone (R-019):**
- Negative in 24/24 cells (6 coins × 5m/15m × dev_train/dev_val).
- BTC 5m: 14,322 trades, −0.117 %/trade, PF 0.52, gross +0.003 %/trade. Still negative at TradingView's
  0.08 % cost.

## 7. Why everything is REJECTED (agent's diagnosis — please challenge it)
1. **Cost wall.** Gross directional edge at 5–15m is ≈ 0 (e.g. v13 gross +0.003 %/trade), so 0.12 %
   round-trip cost decides the outcome.
2. **Weak, poorly separable signal.** AUC 0.55–0.65, and calibrated probabilities rarely exceed the base
   rate by much. EV needs p ≈ 2× base at h240.
3. **Short-horizon geometry.** At h15 the reward needed to clear cost forces a stop wider than the
   market SL cap.
4. **Long horizons have no ranking power.** h1440 AUC ≈ 0.50.
5. **Public OHLCV / indicator features.** P4B / G7-T / v13 add almost no information beyond the V3 base.
6. Filtering an always-take stream with ≈ 0 gross edge (WAIT / tradeability) can only remove trades; it
   cannot create a positive mean.

## 8. Issues found and fixed during the work
- D-023 review fixes:
  - same-bar trailing worst-case;
  - unresolved intrabar rows excluded from labels;
  - sklearn AUC (ties);
  - resolver funding recomputed to the resolved exit;
  - drawdown labelled "additive".
- D-029: reference-policy probability vs candidate-policy EV mismatch, fixed by per-candidate isotonic
  re-calibration. The verdict did not change.
- P5 freeze did not save the decision inputs; fixed today (`decision.json`).
- Orchestration:
  - several crashes from out-of-memory (Chrome ~10 GB + VS Code on a 32 GB PC), a Windows power-off and
    bash fork failures;
  - fixed with one python process per symbol × horizon, a ≥ 6 GB free-commit guard and a detached
    runner (`D:\autofinx-ml-v4\run-p4b-p5.sh`, log `logs\p4b-p5.log`).
  - No result was edited; failed cells were re-run with identical code.
- **Open design flaw (R-020):** the WAIT verdict uses a fixed 50 % profit-retention floor even for
  keep-share 30 %, so OVER_FILTERED is near-automatic there. The proposed fix compares retention to the
  keep share. The conclusion is unaffected (gains ≤ 0.007 %).

## 9. Strategy Lab (D-031) — built for testing external strategies safely
- **Backend:**
  - Code: `app/Domain/StrategyLab/{PineAuditor, StrategyLabService, StrategyLabRunner,
    StrategyLabException}.php`, `app/Jobs/RunStrategyLabTestJob.php`,
    `app/Http/Controllers/Api/StrategyLabController.php`, routes `api/v1/workspaces/{ws}/strategy-lab/*` (11).
  - 4 tables: candidates, immutable versions, runs, immutable approvals (migration
    `2026_10_13_000000_create_strategy_lab_tables.php`, **applied on production**, flag on).
  - Mutations need owner/admin; reads need membership.
  - Tests: `tests/Feature/StrategyLab/StrategyLabApiTest.php` (11).
- **Safety:**
  - Uploaded source is text only and never executed.
  - Quick/Deep tests run only for a version whose normalized sha256 matches a reviewed translation
    (`config/strategy_lab.php` adapters ↔ `v4/lab_runner.py::ADAPTERS`); otherwise NEEDS_TRANSLATION.
  - No order path (static test).
  - dev_val / holdout are not used.
- **Python runner** `v4/lab_runner.py`:
  - QUICK_V1 (BTC+ETH 15m, last 25 % of dev_train) and DEEP_V1 (6 coins, 5m+15m, all dev_train, cost
    0.12 / 0.18).
  - Fixed verdict rule (no win rate).
  - Exit codes 3 DATA_UNAVAILABLE / 4 ADAPTER_UNKNOWN.
- **Frontend:** `src/pages/strategy-lab/*`, `src/components/strategy-lab/*`, `src/hooks/useStrategyLab.ts`,
  `src/lib/strategyLab.ts`. Build + eslint clean; **not deployed, not clicked through live**.
- **Production:** has no research data, so tests return RUNNER_UNAVAILABLE by design. Tests run on the PC
  once `AUTOFINX_STRATEGY_LAB_WORK_DIR` / `_PYTHON_BIN` are set and a worker runs
  `queue:work ml_training --queue=strategy_lab`.

## 10. Progress
| Phase | State |
|---|---|
| P0–P3 | done |
| P4A | done (30 cells, no edge) |
| P4B | done (30 cells) + Stage B audit |
| Optional layers (D-030) | done for h15 (R-020): no value |
| P5 T0 baseline (arm3, 30 cells) | **running now** (`logs\p4b-p5.log`, ~10 min / cell) |
| D-033 TAKE/SKIP meta-label | PROPOSED, awaiting owner |
| D-034 continuation / exhaustion trailing | planned |
| D-035 maker-entry cost scenario (C1, 0.08 %, trade-through fills) | PROPOSED, awaiting owner |
| D-032 P6 pass rule + P7 classification | to be proposed **before** any holdout opening |
| P6 / P7 / P8 code | not written |

Rough completion of the research plan: data / labels / features / P4 ≈ 100 %; P5 baseline in progress;
new layers (TAKE/SKIP, continuation, cost) 0–20 %; P6–P8 0 %. If P5 freezes nothing, the holdout stays
sealed and P8 becomes an honest "no edge with current data / features" report.

## 11. Specific things to cross-check (highest value first)
1. **Leakage:**
   - `splits.py` purge logic;
   - every P3+ entry point asserts the holdout boundary (`grep "holdout_start" v4/*.py`);
   - `ablation.folds` purge = H;
   - P5 `nested_windows` purge;
   - `forward_blocks` past-only;
   - isotonic / EV params fitted on calibration slices that are not used for selection.
2. **Execution realism:** `policy.simulate` / `apply_worst_case` stop-first rule, gap fills, funding sign
   (longs pay positive funding), cost applied once per round trip, trailing never loosens; the
   `test_policy.py` hand cases.
3. **D-029 implementation** in `p5_run.ev_params` / `evaluate`: the isotonic is fit on the dev_train
   calibration slice with candidate-policy success; the lift gate uses the candidate base rate.
4. **P4B rule:** with 30 cells × 6 groups = 180 tests and MIN_GAIN 0.002, how many keeps would chance
   produce? (Suggest a permutation / shuffled-feature null.)
5. **v13 translation fidelity** vs Pine semantics: `ta.supertrend`, `ta.dmi`, session VWAP,
   `request.security(f()[1], lookahead_off)` read as "last complete HTF bar at the chart bar's open".
6. **Optional-layer WAIT verdict flaw** (section 8) and whether evaluating on the always-take reference
   stream is the right primary stream.
7. **Cost assumptions:** 0.12 % vs the owner's real Binance fee tier; the proposed C1 fill model
   (adverse selection).
8. **Statistical honesty:** t-stats on overlapping vs non-overlapping trades. Executed trades are
   non-overlapping (`policy.non_overlapping`); diagnostic per-opportunity metrics overlap.

## 12. How to reproduce / verify
```powershell
cd C:\xampplast\htdocs\auto_finx\autofinx-backend\backends\external-predictive-engine
D:\autofinx-ml-v4\venv\Scripts\python.exe -m pytest tests/v4 -q      # 86 tests
# single cell examples (dev data only; the holdout is never read)
D:\autofinx-ml-v4\venv\Scripts\python.exe -m v4.cli p4a --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 15
D:\autofinx-ml-v4\venv\Scripts\python.exe -m v4.cli p4b --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 15
D:\autofinx-ml-v4\venv\Scripts\python.exe -m v4.cli p5  --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 240
D:\autofinx-ml-v4\venv\Scripts\python.exe -m v4.cli layers --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 15
D:\autofinx-ml-v4\venv\Scripts\python.exe -m v4.cli lab-v13 --work D:\autofinx-ml-v4 --symbols BTCUSDT
D:\autofinx-ml-v4\venv\Scripts\python.exe -m v4.cli p4b-audit --work D:\autofinx-ml-v4
cd ..; php artisan test tests/Feature/StrategyLab        # 11 tests; full suite last run: 2068 passed
```
One heavy job at a time (each needs ~4 GB RAM). Reports land in `D:\autofinx-ml-v4\reports\<phase>\`.

## 13. Pending owner actions (not code)
- Approve D-033 / D-035.
- Confirm the Binance fee tier.
- Deploy the Strategy Lab frontend.
- Set up the PC `.env` and worker.
- Check whether the v13 Pine script currently drives live Future Trade orders (its evidence is negative).
- Rotate the leaked tokens and disable SSH password auth.
- Commit the uncommitted work.
