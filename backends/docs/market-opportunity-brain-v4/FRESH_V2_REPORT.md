# AutoFinX V4 Fresh Research V2 Verification

Date: 2026-10-15  
Verdict: **NO-GO for model promotion or live use**

## Scope and safety

The prior `INDEPENDENT_AUDIT_2026-10-15.md`, existing V4 research files, and the protected
2026-01-04 holdout were preserved. No trading execution, wallet, risk, webhook, production config,
migration, or historical database record was changed. The only new source is the isolated
research harness:

`audit/independent_v4_20261015_v2/experiment_v2.py`

Artifacts are in `D:\autofinx-v4-fresh-research-v2-20261015-213345`.

## Reproducible command

```powershell
$env:HF_HOME='D:\autofinx-mob-v4\hf-cache'
$env:HUGGINGFACE_HUB_CACHE='D:\autofinx-mob-v4\hf-cache\hub'
$env:TEMP='D:\autofinx-mob-v4\tmp'; $env:TMP='D:\autofinx-mob-v4\tmp'
& 'D:\autofinx-mob-v4\venv\Scripts\python.exe' `
  'autofinx-backend\backends\audit\independent_v4_20261015_v2\experiment_v2.py' `
  --out 'D:\autofinx-v4-fresh-research-v2-20261015-213345' `
  --model-snapshot 'D:\autofinx-mob-v4\hf-cache\hub\models--amazon--chronos-2\snapshots\29ec3766d36d6f73f0696f85560a422f50e8498c' `
  --batch-size 16
```

Plan SHA-256: `c4ce7b6ece08899d8379d3bf0c7cbaa14516bf494807edbc7446a7e732b90de8`  
Dataset manifest SHA-256: `469921204210da2965ff3eaba5e1087f1ea971414aa06044b008f07c1f55a628`

## Data provenance and validation

The harness freshly downloaded Binance Vision official USD-M monthly archives for BTCUSDT, ETHUSDT,
and SOLUSDT, 1h klines and monthly funding rates, October–December 2025. For every archive it
recorded URL, fetch timestamp, byte count, and SHA-256 from the corresponding `.CHECKSUM` file in
`data_provenance.json`.

Validation results in `data_validation.json`:

- 2,208 candles per symbol; 6,624 total.
- 0 malformed rows, 0 continuity gaps, and duplicate open times removed only by deterministic key.
- 276 funding events per symbol.
- OHLC positivity, high/low containment, one-hour close interval, and monotonic timestamps passed.

The protected holdout boundary was never reached. Decisions used 256 closed bars and a four-bar
horizon. One full bar of execution latency was applied uniformly: signal after the decision close,
entry at the next bar open, exit after the four-bar horizon. Development was Nov 1–15, validation
Nov 16–30, and untouched test Dec 1–31 2025. Windows are spaced 24 bars apart and are non-overlapping.

## Exact AutoFinX algorithm reproduction

The comparator reproduces `MultiWindowTrendScoringService::scoreFor()` from source:

- short/medium/long windows are 12/24/48 candles from the default base 24;
- each move is `(last close / first open - 1) * 100`;
- ATR reference is exactly `TradeForecastService::computeAtrAvgPct()`: latest 21 candles,
  20 true ranges, divided by the latest close, with a 0.05% floor;
- each score is clamped to [-1, 1]; weights are 0.5/0.3/0.2;
- direction threshold is ±0.15 composite.

This is not the old momentum approximation. Remaining non-reproducible production behaviors are
outside the algorithm math: production reads the latest `MarketOhlc` rows from its database, uses
runtime feed timing and state, and has execution/position guards not represented in this pure replay.

## Chronos-2 verification

Inference used the official `Chronos2Pipeline` API from `chronos-forecasting 2.3.2`, CPU Torch
2.14.1, Transformers 5.19.0, Python 3.10.11. The exact local model snapshot was pinned to:

`29ec3766d36d6f73f0696f85560a422f50e8498c`

The model produced finite ordered p10/p50/p90 quantiles for all 183 windows. A repeated inference
on the same context had maximum absolute delta `0.0`. Total batched inference time was approximately
5.20 seconds.

## Forecast metrics

| Model | Relative MAE | Relative RMSE | Direction accuracy | P10–P90 coverage |
|---|---:|---:|---:|---:|
| Naive-last | 1.3952% | 2.0956% | N/A (flat) | — |
| Directional persistence / drift-24 | 1.4368% | 2.1428% | 51.91% | — |
| Chronos-2 | 1.4671% | 2.1466% | 45.36% | 68.31% (nominal 80%) |

Chronos-2 was worse than naive-last on both error measures and below directional chance in this
replay. Its interval was materially under-covered. Chronos-2 exposes quantiles, not calibrated
direction probabilities; directional Brier/log-loss was therefore correctly recorded as
`NOT_AVAILABLE_UNCALIBRATED_MODEL_OUTPUT`, not fabricated.

## Cost-aware strategy results

Costs were applied identically to every arm: 5 bps taker fee per side, 1 bps slippage per side,
actual Binance funding events during the holding interval, and one-bar execution latency. Net means
are per trade; confidence intervals are seeded bootstrap 95% intervals.

| Arm | Trades / waits | Net mean 1x | Net mean 2x | Max drawdown 1x |
|---|---:|---:|---:|---:|
| Naive-last | 0 / 183 | 0 | 0 | 0 |
| Directional persistence | 183 / 0 | +0.1713% | +0.0513% | see artifact |
| Existing AutoFinX | 158 / 25 | +0.0345% | -0.0855% | -26.74% cumulative-return units |
| Chronos-2 p50 | 107 / 76 | -0.2499% | -0.3698% | -39.11% cumulative-return units |

The most important result is the untouched test split:

| Test arm (93 windows) | Trades | Net mean | Bootstrap 95% CI | Max drawdown |
|---|---:|---:|---:|---:|
| Existing AutoFinX | 83 | -0.4667% | [-0.8616%, -0.1061%] | -39.49% |
| Chronos-2 | 47 | -0.8810% | [-1.3752%, -0.4132%] | -43.00% |

Both are negative on the untouched test period. Chronos-2 is worse by 0.4143 percentage points per
trade. The development split was favorable to several directional arms, but that advantage did not
survive the untouched December test, demonstrating regime sensitivity rather than robust evidence.

Interval coverage by split was development 71.11%, validation 71.11%, and test 65.59% against the
nominal 80%. Cost doubling made both Chronos-2 and AutoFinX more negative.

## Decision engine readiness audit

`ResearchDecisionPolicy` remains intentionally incomplete for directional use:

- no `CalibrationValidator` exists;
- all real forecasts have `probabilityCalibrated=false`;
- the calibrated branch still returns `WAIT: COST_MODEL_NOT_IMPLEMENTED`;
- `ForecastRecord` has no execution-price/cost lineage sufficient for a net-EV gate.

I did not invent a probability calibration method or activate a directional policy. The V2 harness
adds only versioned, offline diagnostics and tests; it does not modify the policy or any execution
path. A future calibration component needs an owner-approved interface, chronological validation,
coverage/Brier tests, and a frozen cost contract before implementation.

## Failure attribution

- **Data:** not the primary failure in this run; checksums and candle validation passed. Historical
  point-in-time universe/liquidity quality remains unimplemented.
- **Inference:** verified, deterministic, and correctly pinned locally.
- **Prediction:** Chronos-2 lost to naive-last and had 65.59% test interval coverage.
- **Decision:** Chronos-2 thresholding produced fewer trades but substantially negative test expectancy.
- **Execution costs:** one-bar latency, actual funding, fees, and slippage worsened all arms; cost
  doubling removed the small full-sample AutoFinX surplus.

## Tests and isolation evidence

- `php artisan test --compact tests/Unit/MarketOpportunityBrain`: **71 passed, 475 assertions**.
- Python contract suite via `python -m unittest discover -s external-predictive-engine/market_opportunity_brain/tests -v`:
  **8 passed**.
- V2 harness `py_compile`: passed.
- Existing `app/Domain/Trading`, wallet, risk, webhook, route, and production configuration files
  were not modified by this verification. Existing unrelated working-tree changes were preserved.

## Recommendation

**NO-GO.** Do not promote Chronos-2, enable V4 directional decisions, or connect this module to
live execution. The next safe research step is to build and freeze a real calibration/cost contract,
then repeat on additional pre-sealed chronological periods with the same source-faithful AutoFinX
comparator and latency convention.
