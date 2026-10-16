# AutoFinX V5 Phase 5 Recovery — Execution Report

Date: 2026-10-16  
Status: verified ML execution completed in isolated research environment; no production changes.

## Environment recovery

Existing verified Chronos environment: `D:\autofinx-mob-v4\venv` (preserved, not reinstalled).

Bounded install command:

```powershell
D:\autofinx-mob-v4\venv\Scripts\python.exe -m pip install --timeout 20 --retries 1 `
  scikit-learn==1.6.1 scipy==1.15.1 pytest==8.3.4
```

Import validation succeeded:

- Python 3.10.11, 64-bit Windows
- numpy 2.2.6
- pandas 2.3.3
- scipy 1.15.1
- scikit-learn 1.6.1
- pytest 8.3.4
- Existing Chronos environment retains torch 2.14.1, chronos-forecasting 2.3.2 and transformers 5.19.0.

The first separate venv install stalled; it was stopped with a bounded timeout. No production environment was
modified. The working Chronos environment was used for the ML benchmark dependencies only; Chronos weights were
not re-downloaded.

## New Binance dataset

Downloaded from:

`https://data.binance.vision/data/futures/um/monthly/klines/BNBUSDT/1h/BNBUSDT-1h-2025-01.zip`

Checksum URL: same URL plus `.CHECKSUM`  
Expected SHA-256: `d7a3c3240f2214756249042ec7d914008d13457d2fdba41571607e5243a2513d`  
Downloaded file: `D:\autofinx-v5-phase5-data\BNBUSDT-1h-2025-01.zip`  
Download was bounded with `curl --connect-timeout 15 --max-time 60 -fL`.

The checksum matched. The archive contains 744 hourly rows; it is a new validated dataset, but it is not merged
into the protected V4/V5 benchmark because it is a disconnected January window. No missing data was invented.
The broader 12-month/15m download target remains incomplete and is reported as an open limitation.

## ML benchmark execution

Script: `audit/v5_phase5/benchmark.py`  
Command:

```powershell
D:\autofinx-mob-v4\venv\Scripts\python.exe `
  autofinx-backend\backends\audit\v5_phase5\benchmark.py `
  --root D:\autofinx-v4-fresh-research-v2-20261015-213345\raw `
  --out D:\autofinx-v5-phase5-ml-results-20261016.json
```

This benchmark uses the verified BTC/ETH/SOL 1h Oct–Dec 2025 data, causal five-feature vectors (return, ATR,
candle body/range and normalized close slope), chronological 80/20 train/test separation, and 1h/4h/24h labels.
It does not read the protected 2026 holdout. The source-faithful AutoFinX result remains in the Phase 3 artifact;
the new ML models use identical timestamps and labels.

## Actual test results

| Model | Horizon | Train/Test | Accuracy | Balanced accuracy | UP precision/recall | DOWN precision/recall | SIDEWAYS precision/recall |
|---|---|---:|---:|---:|---:|---:|---:|
| Logistic Regression | 1h | 1281/321 | 38.32% | 30.70% | 11.81/37.50% | 7.81/10.00% | 79.23/44.59% |
| Random Forest | 1h | 1281/321 | 68.22% | 34.38% | 4.55/2.50% | 36.36/8.00% | 74.31/92.64% |
| HistGradientBoosting | 1h | 1281/321 | 61.06% | 35.05% | 6.82/7.50% | 27.27/18.00% | 75.41/79.65% |
| Logistic Regression | 4h | 1281/321 | 35.20% | 40.66% | 34.58/42.05% | 32.79/68.97% | 51.61/10.96% |
| Random Forest | 4h | 1281/321 | 33.64% | 32.30% | 22.68/25.00% | 30.77/32.18% | 43.61/39.73% |
| HistGradientBoosting | 4h | 1281/321 | 34.89% | 35.04% | 26.79/34.09% | 34.41/36.78% | 43.10/34.25% |
| Logistic Regression | 24h | 1281/321 | 37.38% | 34.03% | 42.59/18.85% | 42.42/64.12% | 18.84/19.12% |
| Random Forest | 24h | 1281/321 | 45.48% | 38.92% | 46.00/56.56% | 46.58/57.25% | 20.00/2.94% |
| HistGradientBoosting | 24h | 1281/321 | 40.81% | 35.48% | 42.11/52.46% | 40.91/48.09% | 26.67/5.88% |

Full confusion matrices and probability availability are persisted in `D:\autofinx-v5-phase5-ml-results-20261016.json`.
All three scikit-learn models provide probabilities, but they were not calibrated in this recovery run; Brier/ECE
are therefore not presented as validated calibration results.

## Interpretation

- Random Forest's 1h raw accuracy (68.22%) is class-imbalance driven: SIDEWAYS recall is 92.64%, while UP recall is
  2.50% and DOWN recall is 8.00%. It is not a useful directional promotion.
- Logistic Regression has the best 4h balanced accuracy (40.66%) among these runs, but this is a single short
  period and below any predeclared promotion gate.
- Random Forest has the best 24h balanced accuracy (38.92%), with almost no SIDEWAYS recall (2.94%), so coverage
  behavior is unstable and requires calibration/holdout confirmation.
- No profitability or TP/SL claim follows from these classification metrics.
- Statistical significance was not claimed: the sample is one short 2025 period, folds are not multi-year, and no
  serial-dependence bootstrap was run in this recovery.

## Tests and files

Created/modified research-only files:

- `audit/v5_phase5/benchmark.py`
- `audit/v5_phase5/requirements-research-py310.lock`
- `audit/v5_phase5/validate_environment.py`
- `docs/market-intelligence/V5_PHASE5_RECOVERY_EXECUTION_REPORT.md`

The benchmark process completed successfully. Import/version checks passed. Existing Phase 2/3 isolated tests were
not changed. No production migration, scheduler, wallet, risk, webhook or execution path was modified.

## Evidence gate and recommendation

The mandatory environment gate passed. A new BNBUSDT archive was checksum-validated. Logistic Regression, Random
Forest and HistGradientBoosting executed and persisted metrics. However, the data expansion target (12 months,
15m and five-symbol broad coverage), calibration, Chronos multi-horizon rerun and protected long-horizon holdout
remain incomplete.

**Recommendation: NO-GO for Top-Three ranking or trading promotion.** Continue only after acquiring the expanded
validated dataset and running preregistered calibration/walk-forward evaluation.
