# P8 — Final report: AutoFinX ML V4 research (2026-10-07)

Written for: the owner, and any engineer or AI reviewer who continues or audits this work.

Status: **FINAL** for the research family "OHLCV + public positioning data, 15 m – 1 d horizons,
BTC / ETH / BNB / SOL / XRP / SUI". Owner decision: "p8 final thaaa" (2026-10-07).

Nothing was promoted, frozen, installed or connected to live trading. The sealed holdout was **never opened**;
it stays sealed for a future, different experiment.

## 1. Verdict
**No tradable edge was found.**
- Every pre-registered test on development data failed its acceptance rule.
- Costs used: the owner's real Binance fees (maker 0.02 %, taker 0.05 %, verified from the live account,
  D-040), plus a 0.01 % per-leg slippage buffer (round trip 0.12 % taker / 0.08 % maker entry), plus
  actual funding.
- Final status of every symbol × horizon (SPEC §89): **REJECTED (30 / 30)**.
- No cell reached FROZEN_FOR_P6, so P6 (holdout) and P7 (stress) had nothing to evaluate.

This is an honest outcome: the gates did their job and stopped money from being lost on a non-edge.

## 2. Why — root cause (evidence)
1. **Gross edge ≈ 0.**
   - Across ML models, rule setups and event triggers, the average move per trade *before* costs stays
     within about ±0.02 % (R-019, R-023, R-025, R-026).
   - The round-trip cost is 0.12 %, so every arm loses about the cost.
2. **The direction signal exists but is tiny.**
   - The only statistically detectable side-selection lift is BTC h60, about +0.01 % per opportunity
     (R-022), a tenth of the cost.
   - Context data did not enlarge it (R-024).
3. **Ranking improves with horizon, calibration never reaches positive value.**
   - Direct net-return models rank better at 4 h (Spearman up to 0.28) and add up to 0.07 %/trade versus
     always-long (R-025), but every fold stays negative.
   - The calibrated V5 utility model correctly predicts negative after-cost value and waits (R-026).
4. **More information did not help.** None of the following added a usable effect (R-018, R-020, R-024,
   R-026):
   - pattern groups G1–G6 (median AUC gain +0.0005);
   - noise/chop G7-T;
   - Pine v13 components;
   - cross-market returns, funding, open interest, top-trader / global long-short, taker flow,
     premium / basis.
5. **Execution engineering cannot close the gap.**
   - Maker entry saves about 0.04 %, but its fills are adversely selected (R-021).
   - A BNB fee discount would save about 0.01 %.
   - Neither turns a ≈ 0 gross edge positive.

## 3. What was tested (development data only, holdout sealed)
| Stage | What | Result | Ref |
|---|---|---|---|
| P0–P1 | 5 y Binance 1m / 5m / funding, 6 coins, checksum-verified, 100 % coverage | data healthy | R-007 |
| P2 | V3 features (PHP parity) + Layer A path targets | built | R-008, R-010 |
| P3 | V3 endpoint labels vs path reality | V3 labels misaligned with SL / trailing (48 % stop-first at BTC h240) | R-009, R-013 |
| Resolver | aggTrades check of ambiguous minutes | worst-case rule pessimistic on average | R-014 |
| P4A | classifiers + quantiles, 30 cells | no V4 arm positive with t ≥ 1 | R-015 |
| P4B | pattern groups G1–G6 ablation, 30 cells | tiny, inconsistent gains | R-018 |
| P5 smoke + baseline | dynamic SL / activation / trailing selection | REJECTED wherever run | R-016, R-017, table |
| Optional layers | G7-T noise/chop, v13 components as FEATURE / WAIT / RULE | no value | R-020 |
| Strategy Lab | owner Pine v13 standalone, 24 cells | all negative | R-019 |
| TAKE/SKIP + maker | meta-label + maker-entry smoke | meta AUC ≈ 0.52; maker adverse selection | R-021 |
| Independent review | defects A–E fixed + regression tests | P0 done | D-036 |
| P1 | paired side-selection diagnostics | only BTC h60, ≈ +0.01 % | R-022 |
| P2 setups | trend pullback / range reversion, fixed exits | gross ≈ 0 | R-023 |
| P4 context | funding, OI, metrics, cross-market, dispersion | no lift | R-024 |
| P3 economics | direct net-return + q20 | better than always-long, still negative | R-025 |
| V5-lite | regime experts + net-return quantiles, 5 walk-forward folds | calibrated; no positive event | R-026 |

Every rule was written down before its result was seen (DECISIONS D-025 … D-041). Failed experiments are kept,
not deleted, and the number of trials is visible in RESULTS (R-001 … R-026).

## 4. Global table (SPEC §91)
- **V3 arm** = V3 endpoint classifier with the endpoint exit (P4A, dev_val).
- **V4 arm** = V4 path labels with the reference SL / trailing.
- "pending" P5 cells were still running as a pre-P0 baseline record when this was written. They cannot
  change the verdict, because every upstream test in those cells already failed.
- Positive V3-arm cells use the endpoint exit with no stop and small samples. That is what chance produces
  across 30 cells, and the same signals lose with realistic stops (R-015).

| Symbol | H | AUC V3 L/S | AUC V4 L/S | V3 arm trades / avg net / t | V4 arm trades / avg net / t | P4B groups (pre-P0) | P5 baseline | Status |
|---|---|---|---|---|---|---|---|---|
| BTC | 15 | 0.71/0.71 | 0.65/0.65 | 57 / +0.033 / +0.2 | 31 / -0.053 / -1.4 | g5 | REJECTED | REJECTED |
| BTC | 60 | 0.62/0.62 | 0.56/0.56 | 25 / +0.558 / +1.3 | 0 / – / – | g3+g5 | REJECTED | REJECTED |
| BTC | 120 | 0.58/0.59 | 0.55/0.55 | 71 / -0.040 / -0.4 | 0 / – / – | g3+g5 | REJECTED | REJECTED |
| BTC | 240 | 0.56/0.55 | 0.54/0.55 | 459 / -0.124 / -1.9 | 0 / – / – | g3+g6 | pending | REJECTED |
| BTC | 1440 | 0.51/0.54 | 0.51/0.52 | 145 / -0.033 / -0.2 | 0 / – / – | none | pending | REJECTED |
| ETH | 15 | 0.64/0.63 | 0.61/0.61 | 130 / -0.049 / -0.4 | 0 / – / – | g4 | REJECTED | REJECTED |
| ETH | 60 | 0.58/0.56 | 0.56/0.56 | 150 / -0.178 / -1.3 | 14 / -0.111 / -2.4 | g5 | REJECTED | REJECTED |
| ETH | 120 | 0.55/0.55 | 0.55/0.55 | 247 / -0.236 / -2.8 | 0 / – / – | g2 | REJECTED | REJECTED |
| ETH | 240 | 0.54/0.54 | 0.55/0.55 | 81 / -0.117 / -0.5 | 0 / – / – | g1 | pending | REJECTED |
| ETH | 1440 | 0.50/0.52 | 0.54/0.52 | 36 / -0.037 / -0.1 | 0 / – / – | g1+g4 | pending | REJECTED |
| BNB | 15 | 0.68/0.67 | 0.62/0.61 | 184 / -0.010 / -0.1 | 0 / – / – | g4 | REJECTED | REJECTED |
| BNB | 60 | 0.60/0.58 | 0.54/0.54 | 44 / +0.289 / +1.2 | 39 / -0.174 / -5.1 | none | REJECTED | REJECTED |
| BNB | 120 | 0.56/0.55 | 0.53/0.54 | 203 / -0.153 / -1.6 | 2 / -0.086 / -0.4 | none | REJECTED | REJECTED |
| BNB | 240 | 0.54/0.54 | 0.55/0.55 | 537 / -0.135 / -2.0 | 0 / – / – | g1 | pending | REJECTED |
| BNB | 1440 | 0.50/0.51 | 0.53/0.56 | 176 / -0.008 / -0.0 | 0 / – / – | g1 | pending | REJECTED |
| SOL | 15 | 0.61/0.60 | 0.57/0.56 | 83 / -0.217 / -1.3 | 0 / – / – | g2 | REJECTED | REJECTED |
| SOL | 60 | 0.55/0.53 | 0.55/0.54 | 17 / +1.548 / +1.0 | 19 / -0.141 / -2.3 | none | REJECTED | REJECTED |
| SOL | 120 | 0.53/0.51 | 0.54/0.54 | 84 / +0.651 / +1.8 | 15 / +0.133 / +0.6 | none | REJECTED | REJECTED |
| SOL | 240 | 0.52/0.51 | 0.53/0.54 | 646 / -0.102 / -1.4 | 0 / – / – | g1 | pending | REJECTED |
| SOL | 1440 | 0.51/0.51 | 0.49/0.51 | 102 / -0.294 / -0.7 | 0 / – / – | none | pending | REJECTED |
| XRP | 15 | 0.64/0.63 | 0.59/0.60 | 125 / +0.116 / +0.5 | 13 / -0.146 / -2.8 | none | REJECTED | REJECTED |
| XRP | 60 | 0.57/0.56 | 0.55/0.55 | 252 / -0.027 / -0.1 | 0 / – / – | none | REJECTED | REJECTED |
| XRP | 120 | 0.55/0.54 | 0.54/0.55 | 171 / -0.182 / -1.4 | 0 / – / – | g1 | REJECTED | REJECTED |
| XRP | 240 | 0.53/0.52 | 0.55/0.54 | 67 / -0.440 / -1.2 | 11 / +0.143 / +0.5 | none | pending | REJECTED |
| XRP | 1440 | 0.49/0.50 | 0.51/0.51 | 74 / +0.519 / +0.7 | 13 / +0.527 / +0.5 | g1+g2+g3 | pending | REJECTED |
| SUI | 15 | 0.58/0.59 | 0.55/0.56 | 52 / +0.063 / +0.1 | 0 / – / – | g5 | REJECTED | REJECTED |
| SUI | 60 | 0.53/0.53 | 0.54/0.55 | 140 / +0.382 / +1.9 | 91 / -0.089 / -1.9 | none | REJECTED | REJECTED |
| SUI | 120 | 0.52/0.52 | 0.55/0.54 | 101 / +0.462 / +2.3 | 112 / -0.111 / -2.4 | none | pending | REJECTED |
| SUI | 240 | 0.52/0.51 | 0.55/0.54 | 46 / +0.302 / +0.8 | 0 / – / – | g3+g5 | pending | REJECTED |
| SUI | 1440 | 0.51/0.52 | 0.53/0.52 | 114 / -0.171 / -0.4 | 0 / – / – | g1+g5 | pending | REJECTED |

Machine-readable: `D:/autofinx-ml-v4/reports/final/GLOBAL_TABLE.json`.

## 5. What is reusable (built, tested, kept)
- **Data pipeline:** checksum-verified Binance archives (klines, funding, metrics, premium index), with gap and
  conflict detection.
- **Labels and simulation:**
  - Layer A path targets and the Layer B execution simulator: worst-case same-minute ordering, gap fills,
    funding.
  - Repaired maker-entry model.
  - Real-fee cost module `v4/costs.py`.
- **Leakage discipline:** chronological splits with purge, forward chaining, causal feature tests, and run
  identity stamps (code sha256, library versions, fees).
- **Research tools:** P1 paired diagnostics, P2 setup engine, P3 direct economics, V5-lite walk-forward with a
  pre-registered PASS rule. Each re-runs on any new data source with one command.
- **Strategy Lab:** backend deployed and migrated on production; frontend built. Any Pine / strategy idea →
  static audit → honest test.
- **Tests:** V4 Python 109 / 109; backend 2068 passed (last full run); Strategy Lab 11 / 11.

## 6. What this report does NOT claim
- It does not claim BTC / ETH are unpredictable in general. Only the tested family (these data, these
  horizons, these execution assumptions) shows no after-cost edge.
- It does not certify TradingView parity (v13 is a normalised translation).
- Slippage of 0.01 %/leg is an assumption; real fills were not available.
- The open P5 items (P5-C rule strength, outer walk-forward of the final recipe) were never reached, because
  nothing upstream passed.

## 7. Recommendations (owner decides)
1. **Do not trade these models or the v13 Pine strategy with real money.** If v13 drives Future Trade through
   the pine-webhook today, the evidence (R-019) says it loses after costs.
2. **Keep the holdout sealed** for a future, genuinely different hypothesis.
3. **If research continues, change the information, not the model.** In order:
   - (a) Start recording order-book depth / spread and the liquidation stream live. Reliable history does not
     exist for these, so months of collection come before any test.
   - (b) Record real execution data (fills, slippage, latency) from paper or shadow orders.
   - (c) Try strategy families that do not need a direction edge: funding-rate arbitrage, market making with
     inventory control, or ML as a risk filter for the existing bots. Test them with the same Strategy Lab
     discipline.
4. Server ML stays paused (D-001). Nothing from V4 is installed.

## 8. Command list (SPEC §94)
```powershell
cd C:\xampplast\htdocs\auto_finx\autofinx-backend\backends\external-predictive-engine
$py = "D:\autofinx-ml-v4\venv\Scripts\python.exe"
& $py -m pytest tests/v4 -q                                                        # 109 tests
& $py -m v4.cli p1       --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 60 --extra ctx
& $py -m v4.cli p2       --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 60,240
& $py -m v4.cli p3econ   --work D:\autofinx-ml-v4 --symbols BTCUSDT --horizons 240 --extra ctx
& $py -m v4.cli v5lite   --work D:\autofinx-ml-v4 --horizons 60,120,240
& $py -m v4.cli p4-build --work D:\autofinx-ml-v4 --symbols ETHUSDT
& $py -m v4.cli lab-v13  --work D:\autofinx-ml-v4 --symbols BTCUSDT
```
- Reports: `D:/autofinx-ml-v4/reports/<stage>/`.
- Docs (this folder): SPEC, DECISIONS, RESULTS, PROGRESS, CHANGELOG, REVIEW_CODEX_2026-10-07,
  CROSSCHECK_HANDOFF_2026-10-07, strategy-lab/.

## 9. Owner actions still open
- Commit the uncommitted work (backend docs / Python, frontend Strategy Lab).
- Deploy the Strategy Lab frontend if the page should be live.
- Rotate the leaked Bitbucket token, GitHub PAT and testnet key; set SSH `PasswordAuthentication no`.
- Optional: run `ml-server-pause.sh --free-disk` on the server (~16 GB).
