# AutoFinX V5 Phase 7 — Unified Validation Report

Date: 2026-10-16  
Status: research-only; no model promotion.

## Unified integrity result

Script: `audit/v5_phase7/unified.py`  
Artifact: `D:\autofinx-v5-phase7-unified-20261016.json`

The existing 120 archives were re-read across month boundaries:

| Symbols | Timeframes | Rows/symbol/timeframe | Duplicates | Gaps |
|---|---|---:|---:|---:|
| BTC, ETH, SOL, BNB, XRP | 15m | 35,040 | 0 each | 0 each |
| BTC, ETH, SOL, BNB, XRP | 1h | 8,760 | 0 each | 0 each |

All ten symbol/timeframe streams passed exact 15m/1h interval continuity. Hash provenance remains in
`D:\autofinx-v5-phase6-manifest.json`.

## Unified result status

The framework consolidated 21 Phase 6 ML result rows (three models × seven horizon/input combinations) under one
schema. They remain chronological 80/20 results with identical labels per input timeframe. A row-level bootstrap
confidence interval was not claimed because the Phase 6 artifact stores aggregate confusion matrices, not individual
predictions.

The existing AutoFinX and Chronos-2 models were **not rerun on these identical 120-archive timestamps**. The report
therefore does not make a false head-to-head claim. Their earlier results remain separate evidence only.

## Calibration

No calibrated probability artifact exists for this unified run. ML probability availability is recorded, but no
development-only calibration, Brier score, ECE, reliability curves or log-loss result is claimed. AutoFinX and
Chronos probabilities remain unavailable; Chronos quantiles were not converted.

## Walk-forward and leakage status

- Phase 6 chronological 80/20 split was preserved.
- Features are causal relative to the decision timestamp.
- The 2026 protected holdout was not read.
- Purged/embargoed rolling folds and overlapping-label corrections are not yet implemented in this framework.
- Symbol/regime breakdown and serial-dependence uncertainty require row-level prediction persistence.

## Test

```powershell
cd C:\xampplast\htdocs\auto_finx
$env:PYTHONPATH=(Resolve-Path 'autofinx-backend\backends\audit').Path
python -m unittest discover -s 'autofinx-backend\backends\audit\v5_phase7\tests' -v
```

Result: **1 passed**.

## Acceptance decision

No candidate qualifies for promotion. Near-baseline balanced accuracy, absent calibration, absent row-level
uncertainty, and missing identical AutoFinX/Chronos replay prevent a defensible ensemble or Top-Three selection.

## Next useful experiment

Persist row-level predictions for every model on the same 120-archive timestamps, add purged rolling folds and an
embargo, fit calibration only on development folds, and rerun source-faithful AutoFinX plus pinned Chronos-2 on the
same labels. Only then calculate paired model-vs-baseline confidence intervals and cost-aware feasibility.

No live trading, production database, scheduler, wallet, risk, webhook or execution code was modified.
