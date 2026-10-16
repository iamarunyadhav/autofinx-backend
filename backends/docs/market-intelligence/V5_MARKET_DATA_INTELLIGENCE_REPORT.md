# AutoFinX V5 Market Data Intelligence Report

Date: 2026-10-16  
Status: **PARTIAL — new valid feature established; no promotion**

## Kline field audit

Binance Futures monthly kline rows contain 12 fields:

`open_time, open, high, low, close, volume, close_time, quote_volume, count, taker_buy_volume, taker_buy_quote_volume, ignore`

Verified directly from `BTCUSDT-15m-2025-01.zip`. Numeric validation confirms taker-buy base and quote volumes do
not exceed total base/quote volume in the tested archives.

Implemented parser: `audit/market_data_intelligence/kline_features.py`.

Derived causal fields:

- `taker_buy_ratio = taker_buy_volume / total_volume`
- `taker_imbalance = 2 * taker_buy_ratio - 1`
- `estimated_taker_sell = total_volume - taker_buy_volume`
- `trade_intensity = trade_count / total_volume`
- relative volume can be computed from prior closed bars only

These represent executed/aggressive volume, not resting order-book liquidity or unique traders.

## Public collector

Implemented: `audit/market_data_intelligence/collector.py`.

`PublicBinanceCollector` is disabled by default, public-data-only, bounded by timeout, and records endpoint,
parameters, exchange URL, receive time, latency and raw-payload SHA-256. It has no order or account capability.

Relevant public endpoint contracts to investigate separately:

- `/fapi/v1/klines`: candles/taker fields
- `/fapi/v1/ticker/bookTicker`: current best bid/ask only; not historical depth
- `/fapi/v1/openInterest`: current open interest; historical endpoint requires separate data source
- `/fapi/v1/fundingRate`: funding history with exchange timestamps and bounded pagination
- Binance data endpoints for taker long/short ratios: historical coverage and retention must be verified per endpoint

No historical order-book snapshots, liquidation archive or point-in-time OI series were fabricated. Current snapshots
cannot reconstruct historical depth.

## Actual feature ablation

Artifact: `D:\autofinx-v5-market-data-ablation-20261016.json`  
Model: Logistic Regression, identical 80/20 chronological split, five symbols, 2025 1h data, 2,172 test rows per horizon.

| Feature set | Horizon | Accuracy | Balanced accuracy |
|---|---|---:|---:|
| OHLCV baseline | 1h | 47.01% | 37.28% |
| + taker ratio/intensity | 1h | 38.95% | 35.56% |
| OHLCV baseline | 4h | 35.08% | 34.81% |
| + taker ratio/intensity | 4h | 34.94% | **35.64%** |
| OHLCV baseline | 24h | 35.73% | 34.57% |
| + taker ratio/intensity | 24h | 38.81% | **37.19%** |

The taker feature group improves balanced accuracy by approximately **+0.83 percentage points at 4h** and **+2.62
points at 24h**, but worsens 1h by approximately **-1.73 points**. No confidence interval or calibration claim is
made from this single split; improvement is not yet independently validated across purged rolling folds.

## Tests

```powershell
cd C:\xampplast\htdocs\auto_finx
$env:PYTHONPATH=(Resolve-Path 'autofinx-backend\backends\audit').Path
D:\autofinx-mob-v4\venv\Scripts\python.exe -m unittest discover `
  -s autofinx-backend\backends\audit\market_data_intelligence\tests -v
```

Result: **2 passed** (real archive taker-field mapping and collector fail-closed behavior).

## Remaining verification gaps

- PHP/Python AutoFinX parity fixture remains pending.
- Development-only calibration and paired bootstrap for the new feature are pending.
- Historical OI, liquidation, order-book and long/short-ratio series are not validated.
- No production scheduler, migration, wallet, risk, webhook or execution path changed.

## Decision

**PARTIAL / NO-GO.** Taker-volume fields are real and a causal feature group is implemented and measured. The
observed 4h/24h improvement is promising but not statistically defensible yet. Continue with purged walk-forward
ablation and calibration; do not build Top-Three selection or live trading from this result.
