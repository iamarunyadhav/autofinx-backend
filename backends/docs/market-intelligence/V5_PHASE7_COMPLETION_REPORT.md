# AutoFinX V5 Phase 7 Completion — Row-Level and Walk-Forward Evidence

Date: 2026-10-16  
Status: **PARTIAL** — row-level ML/AutoFinX experiments completed; Chronos bounded inference blocked by model download/runtime timeout.

## Files and commands

Added:

- `audit/v5_phase7_completion/walkforward.py`
- `audit/v5_phase7_completion/__init__.py`
- Row artifact: `D:\autofinx-v5-phase7-row-predictions-20261016.jsonl`

Execution:

```powershell
D:\autofinx-mob-v4\venv\Scripts\python.exe `
  autofinx-backend\backends\audit\v5_phase7_completion\walkforward.py `
  --root D:\autofinx-v5-phase6-data `
  --out D:\autofinx-v5-phase7-row-predictions-20261016.jsonl
```

## Row-level persistence

The JSONL contains **57,240 prediction rows**. Each row records symbol, timeframe, decision timestamp, horizon,
model, fold, train-end timestamp, prediction, actual direction, future return, probabilities when supplied,
calibration status, latency, outcome availability and `execution_authorized=false`.

## Walk-forward protocol

Three rolling chronological folds were executed over 2025 data. Each test segment is separated from training by a
24-hour embargo (maximum label horizon). Models are fit only on earlier rows. The protected 2026 holdout was not read.
The artifact stores fold IDs and train-end timestamps. This is a research fold protocol, not a claim of multi-year
robustness.

## New aggregate metrics from row-level predictions

Metrics below are computed from the new row artifact, not copied from earlier reports. Each model/horizon has 3,180
rows across five symbols and three folds.

| Model | 1h accuracy / balanced | 4h accuracy / balanced | 24h accuracy / balanced |
|---|---|---|---|
| Naive SIDEWAYS | 63.58% / 33.33% | 39.25% / 33.33% | 13.84% / 33.33% |
| Persistence | 17.04% / 31.17% | 29.56% / 32.42% | 43.43% / 33.56% |
| AutoFinX source replay | 24.25% / 33.09% | 31.45% / 33.11% | 39.43% / 33.85% |
| Logistic Regression | 40.79% / 36.65% | 33.90% / 35.31% | 36.26% / 36.14% |
| Random Forest | 61.23% / 34.59% | 34.34% / 33.72% | 42.52% / 33.25% |
| HistGradientBoosting | 59.59% / 34.80% | 33.84% / 33.35% | 42.11% / 33.39% |

Raw tree accuracy is dominated by SIDEWAYS predictions. Logistic Regression has the strongest balanced accuracy in
this artifact, but remains only 36.65% at 1h and 36.14% at 24h. No candidate demonstrates robust promotion-level
skill.

## Calibration

ML probabilities are persisted for model rows but remain `uncalibrated`. Development-only calibration, multiclass
Brier, ECE, reliability and log-loss were not yet computed. AutoFinX has no valid probability output. Chronos
quantiles were not converted to probabilities.

## AutoFinX parity

The AutoFinX adapter preserves 12/24/48 windows, ATR normalization and 0.5/0.3/0.2 weights with the original
thresholds. A PHP parity fixture was not added in this increment; parity remains a required review item before
promotion.

## Chronos-2 blocker

Bounded smoke command:

```powershell
D:\autofinx-mob-v4\venv\Scripts\python.exe D:\chronos_smoke.py
```

The existing environment was found, but `Chronos2Pipeline.from_pretrained('amazon/chronos-2', device_map='cpu')`
attempted Hugging Face model access and did not complete within the bounded observation window. It emitted the
unauthenticated HF Hub warning and Windows cache symlink warning before being interrupted. No Chronos prediction row
was written and no Chronos comparison is claimed.

## Tests

The row-level process completed successfully and persisted all rows. A small Phase 7 completion unit suite should be
added in the next increment for exact timestamp alignment, purge boundaries, duplicate prevention and parity
fixtures. Existing Phase 6 data integrity validation passed: 120/120 archive checksums and all 10 streams had zero
cross-month gaps/duplicates.

## Cost feasibility

No new cost simulation was attached to these classification rows. Prior event-simulator evidence remains negative;
direction accuracy is not treated as profitability.

## Decision

**PARTIAL / NO-GO.** New row-level ML and source-faithful AutoFinX predictions exist, with multiple chronological
folds and a protected holdout. Completion criteria are not all met because Chronos did not run, calibration and
serial-dependence confidence intervals are not complete, and a PHP parity fixture is still pending. No live trading,
production migration, scheduler, wallet, risk, webhook or execution path changed.
