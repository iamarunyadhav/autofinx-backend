# AutoFinX V5 Phase 6 — Robust Direction Validation Report

Date: 2026-10-16  
Mode: research-only; no production or trading changes.

## Dataset acquisition

Bounded download command pattern:

```powershell
curl.exe --connect-timeout 10 --max-time 45 --retry 1 --fail -L <binance-vision-url> -o <archive.zip>
```

Downloaded and checksum-validated from Binance Vision:

- Symbols: BTCUSDT, ETHUSDT, SOLUSDT, BNBUSDT, XRPUSDT
- Period: January–December 2025 (12 consecutive months)
- Timeframes: 15m and 1h
- Archives: **120**
- Checksum results: **120/120 matched Binance `.CHECKSUM` values**
- Manifest: `D:\autofinx-v5-phase6-manifest.json`
- Raw data: `D:\autofinx-v5-phase6-data`

The archives were downloaded with ZIP integrity checks by the client and all source hashes were verified. The
benchmark loader rejected no rows; it used only numeric, timestamped rows and sorted chronologically. A formal
cross-month gap report and symbol listing-boundary audit are still required before promotion; no gaps were silently
filled. 4h/1d were derived only conceptually in this run; no native or aggregated 4h/1d result is claimed.

## Experiment

Script: `audit/v5_phase6/benchmark.py`  
Output: `D:\autofinx-v5-phase6-results-20261016.json`

Features are causal: four-bar return, ATR-like true-range fraction, candle body, candle range and normalized
48-bar close deviation. Labels use `max(0.12%, 0.5 × causal ATR)` as the neutral threshold. For each timeframe,
timestamps are sorted and the final 20% is untouched test data; model fitting uses only the first 80%. No final-test
tuning was performed.

Models executed:

- Logistic Regression (`class_weight=balanced`)
- Random Forest (`class_weight=balanced_subsample`)
- HistGradientBoostingClassifier

## Actual results

Test counts: 2,172 per horizon for 1h inputs; 8,724 per horizon for 15m inputs. Values are accuracy / balanced
accuracy.

| Input | Horizon | Logistic | Random Forest | HistGradientBoosting |
|---|---|---:|---:|---:|
| 1h | 1h | 41.25 / 36.00% | 62.57 / 33.74% | 60.96 / 33.54% |
| 1h | 4h | 37.20 / 37.91% | 35.87 / 35.32% | 36.10 / 35.52% |
| 1h | 24h | 38.12 / 38.28% | 42.17 / 34.38% | 40.88 / 33.48% |
| 15m | 15m | 43.57 / 35.09% | 62.53 / 34.07% | 64.06 / 33.75% |
| 15m | 1h | 35.39 / 35.68% | 36.55 / 35.46% | 39.12 / 36.67% |
| 15m | 4h | 40.59 / 33.33% | 39.51 / 33.52% | 39.52 / 32.91% |
| 15m | 24h | 34.88 / 35.93% | 46.77 / 34.01% | 46.58 / 34.30% |

Raw Random Forest/gradient accuracy is again dominated by SIDEWAYS-class behavior; balanced accuracy remains close
to the three-class baseline. Full confusion matrices, symbol list and probability availability are in the JSON.

## Calibration and uncertainty

All three classifiers expose class probabilities, but this run did not fit a development-only calibrator or compute
Brier/ECE/reliability curves. Therefore calibration is **not claimed**. Serial-dependence-aware bootstrap confidence
intervals and regime/symbol stability tables are also not yet computed.

## AutoFinX and Chronos comparison status

The source-faithful AutoFinX and pinned Chronos-2 results from prior phases were not rerun on this expanded 120-file
manifest. They must not be called head-to-head with the new ML numbers because their timestamps/labels are not yet
identical. Prior AutoFinX/Chronos results remain separate evidence only.

## Opportunity feasibility

No trading profitability is inferred from these classifications. A cost-aware simulation was not run on the
expanded dataset because the unified AutoFinX/Chronos timestamp contract and calibrated outputs are incomplete.

## Completion-gate assessment

1. 12 consecutive months target: **PASS for 15m and 1h archives; 120/120 checksums matched**.
2. Three ML models on expanded data: **PASS**.
3. Walk-forward numerical results persisted: **PASS** (chronological 80/20 test artifact).
4. Calibration/leakage checks: **PARTIAL** — chronological separation and causal features run; calibration,
   purge/embargo and serial-bootstrap not yet implemented.
5. Genuine baseline comparison: **PARTIAL** — new ML models share labels, but AutoFinX/Chronos must be rerun on
   identical expanded timestamps before a head-to-head claim.

## Recommendation

**NO-GO for Top-Three ranking and trading promotion.** The expanded data confirms that high raw accuracy can coexist
with near-chance balanced accuracy. Next safe work is to implement the unified timestamp adapter, purged/embargoed
walk-forward folds, development-only probability calibration, symbol/regime breakdowns, serial-aware confidence
intervals, and then rerun AutoFinX plus pinned Chronos on the same manifest. No live trading path was touched.
