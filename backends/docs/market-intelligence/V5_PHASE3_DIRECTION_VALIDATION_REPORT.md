# AutoFinX V5 Phase 3 — Direction Validation Report

Date: 2026-10-16  
Mode: offline research only; no production migration, scheduler, execution or Top-Three scanner.

## Implementation

Created:

- `audit/v5_phase3/experiment.py`: causal multi-horizon label/prediction replay using the source-faithful
  AutoFinX score and naive/persistence baselines.
- `audit/v5_phase3/tests/test_phase3.py`: neutral-label and confusion/coverage tests.
- Artifact: `D:\autofinx-v5-phase3-direction-20261016.json`.

The replay uses the existing 1h Binance Vision archives for BTCUSDT, ETHUSDT and SOLUSDT. It evaluates 1h, 4h
and 24h horizons from 1h closes. A 15m result is not produced because no verified 15m archive exists; resampling
1h data would be invalid for a 15m claim.

The AutoFinX calculation preserves the audited 12/24/48-bar windows, ATR normalization and 0.5/0.3/0.2 weights.
No new trading algorithm was introduced. Chronos-2 probabilities were not invented; Chronos is therefore marked
`not_run_in_this_offline_replay` in the artifact. The prior pinned Chronos-2 V4 experiment remains the only valid
Chronos numerical evidence.

## Dataset and partitions

- Symbols: BTCUSDT, ETHUSDT, SOLUSDT
- Source period: 2025-10-01 through 2025-12-31
- Development: before 2025-11-16
- Validation: 2025-11-16 through 2025-11-30
- Final test: December 2025
- Protected 2026 holdout: not read
- Labels: future close return relative to decision close; neutral threshold `max(0.12%, 0.5 × causal ATR%)`.

## Actual prediction counts and test metrics

Each model/horizon has 540 test predictions (180 per symbol). Percentages below are test results.

| Model | Horizon | Accuracy | Balanced accuracy | Coverage | Brier/ECE |
|---|---|---:|---:|---:|---|
| Naive SIDEWAYS | 1h | 71.11% | 33.33% | 0.00% | unavailable |
| Persistence | 1h | 14.63% | 33.74% | 100% | unavailable |
| AutoFinX existing | 1h | 23.52% | 33.49% | 85.74% | unavailable |
| Naive SIDEWAYS | 4h | 42.96% | 33.33% | 0.00% | unavailable |
| Persistence | 4h | 25.56% | 29.87% | 100% | unavailable |
| AutoFinX existing | 4h | 26.11% | 27.84% | 85.74% | unavailable |
| Naive SIDEWAYS | 24h | 18.89% | 33.33% | 0.00% | unavailable |
| Persistence | 24h | 36.67% | 30.13% | 100% | unavailable |
| AutoFinX existing | 24h | 31.85% | 28.97% | 85.74% | unavailable |

The naive accuracy is misleading because it predicts SIDEWAYS for every row; balanced accuracy and coverage expose
that it has no directional utility. Full confusion matrices are stored in the JSON artifact.

## Probability calibration

No candidate in this replay supplied calibrated class probabilities. Brier score and ECE are therefore **not
available**, rather than being estimated from point forecasts or Chronos quantiles. The Phase 2 calibration helper
returns `None` without probabilities and has tests for this behavior.

## Cost-aware backtesting

This Phase 3 directional artifact deliberately separates classification from execution profitability. The prior
V4 event simulator remains the cost-aware source of truth; its fresh test evidence was negative for AutoFinX and
Chronos-2. No new cost result is claimed here because this replay's purpose was label/prediction alignment, not a
second incompatible trade simulator.

## Failure analysis

- 1h/4h/24h results are below a useful directional standard and balanced accuracy is near or below the three-class
  chance baseline.
- AutoFinX coverage is high but its directional calls do not translate into correct labels.
- Persistence is not stable across horizons; its 24h result is better than 1h but remains below a defensible gate.
- Naive SIDEWAYS inflates raw accuracy in periods dominated by the neutral label and must not be called successful.
- 15m and Chronos multi-horizon metrics are unavailable because the required verified data/model replay was not
  part of this run; no fabricated substitution was made.
- These are direction labels, not TP/SL or profit results. The earlier cost-aware test remains negative.

## Tests

```powershell
cd C:\xampplast\htdocs\auto_finx
$env:PYTHONPATH=(Resolve-Path 'autofinx-backend\backends\audit').Path
python -m unittest discover -s 'autofinx-backend\backends\audit\v5_phase3\tests' -v
```

Result: **2 passed**. Phase 2 tests remain **3 passed**, and Phase 1 PHP contracts remain **4 passed / 5 assertions**.

## Exact files changed

- `audit/v5_phase3/__init__.py`
- `audit/v5_phase3/experiment.py`
- `audit/v5_phase3/tests/test_phase3.py`
- `docs/market-intelligence/V5_PHASE3_DIRECTION_VALIDATION_REPORT.md`

No existing production trading service, execution permission, wallet/risk code, migration state, or scheduler was
changed. The JSON result is an isolated artifact.

## Recommendation

**NO-GO for directional promotion.** Before Phase 4, acquire and validate 15m/4h/1d-compatible historical data,
run the pinned Chronos adapter on identical labels, implement development-only probability calibration, and then
repeat the protected walk-forward evaluation. Do not begin Top-Three ranking or live/shadow scheduler wiring until
those evidence gaps are closed.
