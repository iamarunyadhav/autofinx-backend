# AutoFinX V5 Predictive Signal Discovery Report

Date: 2026-10-16  
Status: research-only; no production changes.

## New numerical failure attribution

Source: `D:\autofinx-v5-phase7-row-predictions-20261016.jsonl` (57,240 rows). Analysis artifact:
`D:\autofinx-v5-signal-discovery-analysis-20261016.json`.

At 1h across all five symbols/folds:

| Model | False UP | False DOWN | Correct | SIDEWAYS predictions |
|---|---:|---:|---:|---:|
| AutoFinX | 1,148 | 1,129 | 503 | 400 |
| Logistic | 827 | 646 | 379 | 1,328 |
| Random Forest | 107 | 102 | 62 | 2,909 |
| HistGradientBoosting | 162 | 161 | 88 | 2,769 |
| Persistence | 1,328 | 1,310 | 542 | 0 |
| Naive SIDEWAYS | 0 | 0 | — | 3,180 |

This identifies the principal failure mode: tree models suppress directional calls and appear accurate mainly through
SIDEWAYS predictions; AutoFinX emits many directional calls, but false UP/DOWN errors exceed correct calls. The
artifact also contains per-symbol counts. No directional model shows stable improvement over the class-imbalance
baseline.

## Legitimate data availability

| Source | Verified historical availability in this project | Semantics / limitation |
|---|---|---|
| OHLCV | Binance Vision 15m/1h, five symbols, Jan–Dec 2025 | candle close/open timestamps; validated continuity |
| Funding | Binance Vision monthly funding archives in earlier manifest | event timestamps; join must be backward/as-of |
| Taker buy/sell | no accepted point-in-time archive | not inferred from OHLCV |
| Open interest | no accepted historical archive/hash | unavailable for ablation |
| Long/short ratios | no accepted historical archive | unavailable |
| Order-book/depth | no historical snapshots accepted | live/public snapshots are not historical reconstruction |
| Liquidations | no accepted historical event archive | unavailable |

Only OHLCV-derived causal features and verified funding were eligible. No unavailable microstructure feature was
fabricated.

## Feature evidence

Existing tested groups include return, ATR/volatility, RSI, volume, candle body/range and trend slope. Prior frozen
ablation remains the only valid incremental evidence: candle body test TP rate 34.85% with -0.1041% net mean;
candle range 25.61% / -0.4497%; trend slope 25.76% / -0.4982%. None demonstrated reproducible improvement.

No new order-flow ablation is claimed because the required historical inputs do not exist in the validated manifest.

## Verification gaps

- PHP parity fixture for the Python AutoFinX adapter remains pending; existing PHP suite passes 71/475 but that is
  not parity proof.
- Development-only probability calibration was not fit; ML probability diagnostics remain uncalibrated.
- Chronos-2 bounded inference remains blocked by Hugging Face model access/runtime after bounded retry.
- The row-level artifact lacks persisted feature hashes, so no cryptographic feature-input proof can be added
  retroactively.

## Tests and files

Command:

```powershell
python autofinx-backend\backends\audit\signal_discovery\analyze_rows.py `
  --src D:\autofinx-v5-phase7-row-predictions-20261016.jsonl `
  --out D:\autofinx-v5-signal-discovery-analysis-20261016.json
```

Result: **57,240 rows / 18 model-horizon groups** analyzed. No production files, migrations, scheduler, wallet,
risk, webhook or execution paths changed.

## Decision

**NO_EDGE.** The root-cause evidence points to class imbalance and directional false positives, not an untested
hidden OHLCV feature. Historical microstructure data must be acquired legitimately before claiming incremental
signal discovery. Until then, preserve the existing research-only models and do not build Top-Three or live trading
selection.
