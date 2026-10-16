# AutoFinX V5 Phase 4 — Direction Intelligence Research Upgrade

Date: 2026-10-16  
Status: offline research only; no promotion or execution integration.

## Scope and evidence

Phase 4 reused the Phase 3 artifact and existing source-faithful AutoFinX replay. No thresholds were loosened,
no protected holdout was read, and no production algorithm was replaced. Current verified data remains BTCUSDT,
ETHUSDT and SOLUSDT, 1h, October–December 2025. Verified 15m/365d/order-book historical archives were not
available in the isolated manifest, so those requested windows are explicitly unavailable rather than approximated.

Artifact generated: `D:\autofinx-v5-phase4-comparison-20261016.json`.

## Models and dependencies

| Model | Status |
|---|---|
| Existing AutoFinX 12/24/48 trend score | evaluated, source-faithful |
| Naive SIDEWAYS baseline | evaluated; raw accuracy is misleading |
| Persistence baseline | evaluated |
| Chronos-2 | prior pinned V4 replay only; not rerun for multi-horizon Phase 4 |
| Logistic regression | unavailable; scikit-learn is not installed in isolated environment |
| Random forest | unavailable; scikit-learn is not installed |
| Gradient boosting | unavailable; scikit-learn is not installed |

No alternative ML package was silently substituted.

## Untouched test results

Each row below has 540 test predictions (180 per symbol). Balanced accuracy is the useful three-class measure;
raw accuracy for the always-SIDEWAYS baseline must not be interpreted as directional skill.

| Model | 1h accuracy / balanced | 4h accuracy / balanced | 24h accuracy / balanced |
|---|---|---|---|
| Naive SIDEWAYS | 71.11% / 33.33% | 42.96% / 33.33% | 18.89% / 33.33% |
| Persistence | 14.63% / 33.74% | 25.56% / 29.87% | 36.67% / 30.13% |
| AutoFinX existing | 23.52% / 33.49% | 26.11% / 27.84% | 31.85% / 28.97% |

AutoFinX coverage was 85.74% at every horizon. Full confusion matrices and symbol-level rows are in the JSON
artifact. No calibrated probabilities were produced, so Brier score, ECE and reliability curves are unavailable.

## Feature ablation evidence

The frozen factor artifact `D:\autofinx-v4-factor-ablation-20261015.json` was reused as the Phase 4 feature
ablation evidence. Test net means after fixed costs were:

| Feature group | Test trades | TP rate | Net mean |
|---|---:|---:|---:|
| Candle body | 66 | 34.85% | -0.1041% |
| Candle range | 82 | 25.61% | -0.4497% |
| Trend slope | 66 | 25.76% | -0.4982% |

These factors do not demonstrate incremental out-of-sample edge. Historical order-flow, taker imbalance,
liquidity and order-book features were not tested because their point-in-time archives were unavailable; they were
not approximated from OHLCV.

## Calibration and leakage controls

- Features use only bars at or before each decision timestamp.
- Labels use future closes only after the forecast horizon.
- Development/validation/test boundaries are chronological; the protected 2026 holdout was not read.
- No overlapping-label correction or multi-year walk-forward claim is made from this short manifest.
- Probabilities were not invented from Chronos quantiles or deterministic scores.

## Failure analysis

1. Existing AutoFinX directional calls remain near the three-class baseline in balanced accuracy.
2. High coverage does not translate to correct direction; the 1h test accuracy is only 23.52%.
3. Naive SIDEWAYS illustrates class-imbalance inflation and is not a successful model.
4. Feature groups tested so far do not overcome costs or produce stable edge.
5. Missing 15m/multi-year/microstructure data prevents claims about short-horizon or regime robustness.
6. Calibrated probability and ML-model comparisons cannot be honestly reported until dependencies and data are
   available in a pinned research environment.

## Tests

```powershell
cd C:\xampplast\htdocs\auto_finx
$env:PYTHONPATH=(Resolve-Path 'autofinx-backend\backends\audit').Path
python -m unittest discover -s 'autofinx-backend\backends\audit\v5_phase4\tests' -v
```

Result: **2 passed**. Existing Phase 1/2/3 tests remain green in their isolated suites.

## Exact files added

- `audit/v5_phase4/__init__.py`
- `audit/v5_phase4/research.py`
- `audit/v5_phase4/tests/test_research.py`
- `docs/market-intelligence/V5_PHASE4_DIRECTION_RESEARCH_REPORT.md`

No production database migration was applied. No scheduler, wallet, risk, execution or Top-Three code changed.

## Recommendation

**NO-GO for direction promotion.** Next research task should provision a pinned ML environment, acquire and
checksum-validate 15m/4h/1d and longer historical manifests plus legitimate point-in-time microstructure data,
then run a preregistered walk-forward comparison with development-only calibration. Do not tune thresholds or begin
Top-Three ranking until those gaps are resolved.
