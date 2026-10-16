# Independent AutoFinX V4 Audit — 2026-10-15

## Verdict

The V4 Market Opportunity Brain is a research-only scaffold, not a validated trading system.
The shipped `mob-exp-v1` experiment does not compare Chronos-2 with the actual AutoFinX decision
algorithm; its own plan calls Arm A a non-comparable momentum stand-in.

No trading functionality, migrations, live flags, historical records, or production settings were
changed during this audit.

## Source findings

Completed and verified:

- Public Binance adapter is read-only, credential-free, gated, retried, and budget-limited.
- Research contracts force `execution_authorized=false` and reject order fields.
- Candle fixtures cover malformed rows, forming candles, duplicates, gaps, causality, and holdout exclusion.
- Chronos-2 loads through Amazon's `Chronos2Pipeline` API and returns finite ordered quantiles.

Incomplete or incorrect:

- `ResearchDecisionPolicy` can currently produce only WAIT/REJECT. Calibration and the cost model
  are not implemented.
- Historical point-in-time universe membership, delist/list history, depth, spread, OI, and
  historical liquidity validation are not implemented.
- The shipped experiment uses zero funding, fixed fees, and an optimistic close-price fill with no
  latency model.
- `models/chronos2.py` stores only `amazon/chronos-2`, not a fixed model commit, so it is not fully
  reproducible by itself.

## Fresh independent experiment

The isolated harness is `audit/independent_v4_20261015/experiment.py`. It has no database, secret,
order, or trading-service access.

Data was freshly downloaded from official Binance Vision monthly USD-M archives for BTCUSDT,
ETHUSDT, and SOLUSDT, 1-hour candles and funding rates, October–December 2025. All 18 archives
passed Binance `.CHECKSUM` verification. Each symbol had 2,208 valid bars, zero malformed rows,
zero gaps, and 276 funding events.

The test window was 2025-11-01 through 2025-12-31, before the protected 2026-01-04 holdout.
There were 183 non-overlapping windows: 61 per symbol, 256-bar context, 4-bar horizon. No test
threshold or model parameter was tuned from outcomes.

Chronos environment: Python 3.10.11, chronos-forecasting 2.3.2, transformers 5.19.0, CPU Torch
2.14.1. Model snapshot:
`29ec3766d36d6f73f0696f85560a422f50e8498c`.
Repeated inference had maximum absolute difference `0.0`.

## Forecast results

| Model | Relative MAE | Relative RMSE | Direction accuracy | P10–P90 coverage |
|---|---:|---:|---:|---:|
| Naive last | 1.3582% | 2.0449% | N/A (flat) | — |
| Drift-24 | 1.4127% | 2.0918% | 47.54% | — |
| Chronos-2 | 1.4197% | 2.0841% | 47.54% | 71.58% (nominal 80%) |

Chronos-2 did not beat the persistence baseline, and its prediction interval was under-covered.

## Trading replay

All arms used the same windows, 5 bps taker fee per side, 1 bps slippage per side, and actual
Binance funding inside each four-hour holding window.

| Arm | Trades / waits | Net mean at 1x cost | Net mean at 2x cost | 1x bootstrap CI |
|---|---:|---:|---:|---:|
| Buy-and-hold long | 183 / 0 | -0.3375% | -0.4575% | [-0.635%, -0.041%] |
| Momentum-4 | 183 / 0 | +0.2763% | +0.1563% | [-0.0089%, +0.565%] |
| Drift-24 | 140 / 43 | -0.1960% | -0.3160% | [-0.503%, +0.108%] |
| Existing AutoFinX trend score | 158 / 25 | +0.0621% | -0.0579% | [-0.253%, +0.389%] |
| Chronos-2 p50 threshold | 107 / 76 | -0.0938% | -0.2138% | [-0.501%, +0.329%] |
| Naive-last threshold | 0 / 183 | 0 trades | 0 trades | N/A |

Chronos-2 underperformed the source-faithful AutoFinX comparator by 0.1559 percentage points per
trade at the 1x cost profile. Neither has a positive lower confidence bound. This does not support
promoting Chronos-2 or claiming a validated V4 edge.

## Failure attribution

- Data quality: not the primary failure in this replay; checksums, continuity, and OHLC validation passed.
- Model inference: verified and deterministic; no inference failure.
- Prediction accuracy: Chronos-2 was slightly worse than persistence and under-covered intervals.
- Trade decisions: Chronos-2 produced 107 trades with 41.12% net hit rate and negative net mean.
- Execution costs: actual funding plus fees/slippage materially reduced gross results; doubled costs
  made the existing AutoFinX arm negative too.

## Tests

- `php artisan test --compact tests/Unit/MarketOpportunityBrain`: 71 passed, 475 assertions.
- Python contract suite: 8 passed.
- Independent experiment `py_compile`: passed.

Artifacts are in `D:\autofinx-v4-independent-audit-20261015-211901`:
`plan.json`, `PLAN_SHA256.txt`, `data_provenance.json`, `data_validation.json`, `result.json`,
and `rows.jsonl`.

## Recommended corrections

1. Replace the non-comparable Arm A with the real AutoFinX algorithm in the shipped experiment.
2. Pin and record the Chronos model commit in code and result artifacts.
3. Implement fees, slippage, actual funding, and latency as a versioned cost contract.
4. Add calibration and interval-coverage validation before any directional policy.
5. Add historical point-in-time universe and liquidity/depth validation.
6. Keep the module research-only; do not enable live trading.
