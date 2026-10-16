# AutoFinX V5 Phase 7 Final Validation Report

Date: 2026-10-16  
Verdict: **NO-GO / PARTIAL**

## Row integrity

Artifact: `D:\autofinx-v5-phase7-row-predictions-20261016.jsonl`

- Rows: **57,240**
- Unique composite keys `(symbol,timeframe,decision,horizon,model,fold)`: **57,240**
- Duplicates: **0**
- Models: 6, each 9,540 rows
- Folds: 3, each 19,080 rows
- Outcome maturity violations: **0**
- Protected 2026 holdout: not read

Prediction rows contain no feature values from future timestamps. The existing fold metadata has train-end and
outcome-availability timestamps; all outcome availability times are later than decisions. A full per-row feature
hash was not persisted in the original artifact, so cryptographic feature-leakage proof is incomplete.

## Statistical validation artifact

Created: `D:\autofinx-v5-phase7-final-validation-20261016.json`  
Code: `audit/v5_phase7_completion/final_validation.py`

Block bootstrap grouped by symbol/fold/day produced 95% accuracy intervals. Selected results:

| Model/horizon | Accuracy | 95% block CI | Uncalibrated Brier | ECE |
|---|---:|---:|---:|---:|
| Logistic 1h | 40.79% | 38.92–43.03% | 0.6656 | 0.0672 |
| Random Forest 1h | 61.23% | 59.60–62.98% | 0.5456 | 0.0316 |
| HistGB 1h | 59.59% | 57.96–61.40% | 0.5613 | 0.0500 |
| Logistic 24h | 36.26% | 33.67–38.57% | 0.6668 | 0.0168 |
| Random Forest 24h | 42.52% | 40.86–44.35% | 0.6510 | 0.0996 |

These Brier/ECE values are **uncalibrated diagnostics**, not promotion-grade calibration. No calibrator was fit on
test rows. ML probabilities existed, but development-only calibration predictions were not available in the saved
artifact. AutoFinX has no class probabilities; Chronos quantiles were not converted.

## AutoFinX and Chronos

The source-faithful AutoFinX output is present in the row artifact, but a deterministic PHP fixture parity test was
not completed in this increment. The existing PHP V4 suite still passes 71 tests / 475 assertions, including its
research contracts, but this is not proof of Python/PHP parity.

Chronos diagnosis command:

```powershell
D:\autofinx-mob-v4\venv\Scripts\python.exe D:\chronos_smoke.py
```

The environment and package were present, but `Chronos2Pipeline.from_pretrained('amazon/chronos-2')` attempted
Hugging Face access and did not finish during the bounded run. It emitted unauthenticated Hub and Windows cache
symlink warnings. No Chronos prediction was written, and no Chronos comparison is claimed.

## Tests

```powershell
cd C:\xampplast\htdocs\auto_finx\autofinx-backend\backends
php artisan test --compact tests/Unit/MarketOpportunityBrain

cd C:\xampplast\htdocs\auto_finx
$env:PYTHONPATH=(Resolve-Path 'autofinx-backend\backends\audit').Path
D:\autofinx-mob-v4\venv\Scripts\python.exe `
  autofinx-backend\backends\audit\v5_phase7_completion\tests\test_final_validation.py
```

Results:

- PHP V4 research suite: **71 passed / 475 assertions**
- Final validation Python test: **1 passed**
- Row artifact integrity checks: **passed**

## Root causes and decision

The apparent tree-model raw accuracy is mainly SIDEWAYS-class behavior; balanced accuracy remains near the 33.33%
three-class baseline. Uncalibrated probabilities cannot support a directional authorization. Chronos is blocked by
bounded model access/runtime, and PHP parity remains unproven.

**NO-GO.** No model has statistically defensible, calibrated, independently reproduced directional edge. No
trade-feasibility simulation was run because no candidate qualified. No production trading, migration, scheduler,
wallet, risk, webhook or execution code changed.
