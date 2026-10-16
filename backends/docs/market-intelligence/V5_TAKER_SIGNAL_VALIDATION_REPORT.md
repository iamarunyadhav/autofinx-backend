# AutoFinX V5 Taker-Volume Signal Validation

Date: 2026-10-16  
Verdict: **INCONCLUSIVE** (not promoted)

## Scope and provenance

This was a research-only rerun. No production code, scheduler, database, wallet,
risk, execution or live-trading path was changed. Inputs were the preserved
Binance Futures Vision archives in `D:/autofinx-v5-phase6-data`, five symbols
(`BTCUSDT`, `ETHUSDT`, `SOLUSDT`, `BNBUSDT`, `XRPUSDT`), 1-hour candles covering
2025. The prior manifest reports 120/120 archive checksums valid. A raw archive
was independently inspected: its 12 columns are Binance's open time, OHLCV,
close time, quote volume, trade count, taker-buy base/quote volume and ignore.

## Reproduction command and artifact

```powershell
D:/autofinx-mob-v4/venv/Scripts/python.exe audit/taker_signal_validation/validate.py `
  --root D:/autofinx-v5-phase6-data `
  --out D:/autofinx-v5-taker-signal-validation-20261016.json
D:/autofinx-mob-v4/venv/Scripts/python.exe -m pytest audit/taker_signal_validation/tests -q
```

The row-level artifact contains 65,350 rows (13,070 per model), with model,
symbol, timestamp, fold, label, prediction and class probabilities. The five
feature specifications are persisted in the artifact metadata. Features use
only completed candles; the 24-hour label is `close[t+24]/close[t]-1`, with
UP/DOWN thresholds of `0.5 * ATR24`, clipped to 0.12% minimum. Three
chronological folds use a 24-hour train embargo. Ratio and imbalance are
exactly linearly equivalent (`imbalance = 2 * (ratio)`), so they are not
independent evidence.

## Results (three-fold pooled out-of-sample rows)

| Model | N | Accuracy | Balanced accuracy |
|---|---:|---:|---:|
| Baseline | 13,070 | 41.20% | 32.53% |
| Taker ratio only | 13,070 | 41.29% | 32.60% |
| Taker imbalance only | 13,070 | 41.29% | 32.60% |
| Trade intensity only | 13,070 | 42.28% | 33.94% |
| Taker combined | 13,070 | 42.44% | 34.05% |

The earlier single-split figures (37.28→35.56%, 34.81→35.64%,
34.57→37.19%) are recorded in `V5_MARKET_DATA_INTELLIGENCE_REPORT.md`; this
rerun does not reproduce those exact values because it uses a new three-fold,
purged protocol. They must not be presented as interchangeable estimates.

Per-symbol balanced accuracy, baseline → combined: BNB 32.32→32.80%, BTC
32.15→36.05%, ETH 30.97→33.07%, SOL 32.10→32.79%, XRP 32.14→32.09%.
The apparent gain is concentrated in BTC/ETH and is absent for XRP.

## Statistical interpretation

A paired comparison on identical `(symbol,timestamp)` rows gives a raw
correctness improvement of 1.24 percentage points for the combined model.
Serial/block resampling was used as a diagnostic; the resulting interval is
not consistently positive under reasonable block definitions (the conservative
run included zero). Therefore the lower confidence bound is not established
above zero. No claim of statistical significance or profitability is made.

The pipeline does not fit a calibrator on test rows. Probabilities are native
LogisticRegression probabilities only; calibrated Brier/ECE results are
therefore **not available** and no probability promotion is justified.

## Data and failure attribution

Raw data quality and Binance field mapping passed the focused tests. Closed
candles are selected by close time; zero-volume denominators become missing,
not fabricated zero pressure. The principal limitation is predictive rather
than ingestion: pooled balanced accuracy remains near/below 35%, performance
is symbol- and period-dependent, and the improvement is small relative to
serial dependence. This experiment contains no historical depth, liquidation,
or open-interest data and cannot attribute the result to order-book liquidity.
Direction accuracy is not a cost-aware trading result; no trade simulation is
used to imply net profitability.

## Tests and changed files

Passed: `2 passed` in the isolated Python test package. Tests cover raw-column
mapping/derived ranges, timestamp uniqueness/ordering, completed-candle data,
and forward-label alignment. New research-only files:

* `audit/taker_signal_validation/validate.py`
* `audit/taker_signal_validation/tests/test_validate.py`
* this report

## Decision

**INCONCLUSIVE.** Taker-volume fields are real and the combined feature set is
directionally better in this rerun, but the gain is not demonstrated as a
stable, statistically defensible edge across symbols/folds. Keep the signal in
research/shadow mode. A follow-up may add a preregistered block-bootstrap
implementation and development-only calibration, but must not tune on the
protected holdout or enable trading.
