# AutoFinX Future Trade Test Report

**Verdict:** `PASS`  
**Run ID:** `autofinx-ft-20260518-074447`  
**Started:** `2026-05-18T07:44:47.024434+00:00`  
**Finished:** `2026-05-18T07:47:35.311337+00:00`  
**API Base:** `https://api.autofinx.arunyadhav.dev`  
**Workspace:** `26a36b56-4383-4edd-99d1-7622e7c191ef`

## Environment
| Key | Value |
| --- | --- |
| mode | testnet |
| force_testnet | True |
| testnet_configured | True |
| live_configured | True |
| testnet_api_url | https://testnet.binancefuture.com |
| live_api_url | https://fapi.binance.com |

## Bot Status
| Key | Value |
| --- | --- |
| state | COOLDOWN |
| status | Running |
| connection_status | Connected |
| current_symbol | ETHUSDT |
| current_signal | SELL |
| current_price | 2116.63 |
| confidence | 0.35 |

## Counts
| Metric | Value |
| --- | --- |
| before_logs | 1000 |
| after_logs | 1000 |
| new_logs | 59 |
| new_signal_logs | 59 |
| new_blocked_or_skipped_logs | 59 |
| before_trades | 86 |
| after_trades | 86 |
| new_trades | 0 |
| closed_trades_total_seen | 86 |

## Latency
| Metric | Value |
| --- | --- |
| API calls | 23 |
| API average ms | 595.07 |
| API median ms | 402.52 |
| API max ms | 2007.48 |
| Matched execution trades | 0 |
| Execution avg seconds |  |
| Execution median seconds |  |
| Execution max seconds |  |

## PnL Summary From Visible Closed Trades
| Metric | Value |
| --- | --- |
| closed_trades_with_pnl | 74 |
| wins | 10 |
| losses | 64 |
| win_rate_percent | 13.51 |
| net_pnl_sum | -8.96529426 |

## Artisan Commands
| RC | ms | Command |
| --- | --- | --- |
| 0 | 3113.0 | /usr/local/bin/ea-php82 artisan future-trade:diagnostics 26a36b56-4383-4edd-99d1-7622e7c191ef --persist |
| 0 | 61000.0 | /usr/local/bin/ea-php82 artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute |
| 0 | 70579.0 | /usr/local/bin/ea-php82 artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute |
| 0 | 9712.0 | /usr/local/bin/ea-php82 artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=4 --sleep-ms=1000 --no-execute |
| 0 | 4490.0 | /usr/local/bin/ea-php82 artisan future-trade:audit-missed-signals --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --limit=100 |

### Command 1: `/usr/local/bin/ea-php82 artisan future-trade:diagnostics 26a36b56-4383-4edd-99d1-7622e7c191ef --persist`
Return code: `0`

**stdout tail**
```text
[26a36b56-4383-4edd-99d1-7622e7c191ef] BLOCKED | bot blockers=1 | server=degraded | wallet=info

```

### Command 2: `/usr/local/bin/ea-php82 artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute`
Return code: `0`

### Command 3: `/usr/local/bin/ea-php82 artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute`
Return code: `0`

### Command 4: `/usr/local/bin/ea-php82 artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=4 --sleep-ms=1000 --no-execute`
Return code: `0`

### Command 5: `/usr/local/bin/ea-php82 artisan future-trade:audit-missed-signals --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --limit=100`
Return code: `0`

**stdout tail**
```text
Reviewed 0 pending missed-signal audits. Pending remaining: 1040

```

## Next Actions
- Test runner completed without failures. Compare `new_logs.csv` and `new_trades.csv` for detailed signal/order behavior.
