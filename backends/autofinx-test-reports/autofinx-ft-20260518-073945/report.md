# AutoFinX Future Trade Test Report

**Verdict:** `WARN`  
**Run ID:** `autofinx-ft-20260518-073945`  
**Started:** `2026-05-18T07:39:45.910439+00:00`  
**Finished:** `2026-05-18T07:40:16.020353+00:00`  
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
| current_signal | BUY |
| current_price | 2120.85 |
| confidence | 0.196 |

## Counts
| Metric | Value |
| --- | --- |
| before_logs | 1000 |
| after_logs | 1000 |
| new_logs | 8 |
| new_signal_logs | 8 |
| new_blocked_or_skipped_logs | 8 |
| before_trades | 86 |
| after_trades | 86 |
| new_trades | 0 |
| closed_trades_total_seen | 86 |

## Latency
| Metric | Value |
| --- | --- |
| API calls | 23 |
| API average ms | 862.24 |
| API median ms | 491.61 |
| API max ms | 3209.5 |
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

## Warnings
- One or more artisan commands returned non-zero status. See command output in report.

## Artisan Commands
| RC | ms | Command |
| --- | --- | --- |
| 255 | 1214.0 | /usr/local/bin/php artisan future-trade:diagnostics 26a36b56-4383-4edd-99d1-7622e7c191ef --persist |
| 255 | 707.0 | /usr/local/bin/php artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute |
| 255 | 800.0 | /usr/local/bin/php artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute |
| 255 | 999.0 | /usr/local/bin/php artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=4 --sleep-ms=1000 --no-execute |
| 255 | 719.0 | /usr/local/bin/php artisan future-trade:audit-missed-signals --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --limit=100 |

### Command 1: `/usr/local/bin/php artisan future-trade:diagnostics 26a36b56-4383-4edd-99d1-7622e7c191ef --persist`
Return code: `255`

**stderr tail**
```text
Composer detected issues in your platform:

Your Composer dependencies require a PHP version ">= 8.2.0". You are running 8.0.30.


```

### Command 2: `/usr/local/bin/php artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute`
Return code: `255`

**stderr tail**
```text
Composer detected issues in your platform:

Your Composer dependencies require a PHP version ">= 8.2.0". You are running 8.0.30.


```

### Command 3: `/usr/local/bin/php artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=58 --sleep-ms=1000 --no-execute`
Return code: `255`

**stderr tail**
```text
Composer detected issues in your platform:

Your Composer dependencies require a PHP version ">= 8.2.0". You are running 8.0.30.


```

### Command 4: `/usr/local/bin/php artisan future-trade:tick --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --duration=4 --sleep-ms=1000 --no-execute`
Return code: `255`

**stderr tail**
```text
Composer detected issues in your platform:

Your Composer dependencies require a PHP version ">= 8.2.0". You are running 8.0.30.


```

### Command 5: `/usr/local/bin/php artisan future-trade:audit-missed-signals --workspace=26a36b56-4383-4edd-99d1-7622e7c191ef --limit=100`
Return code: `255`

**stderr tail**
```text
Composer detected issues in your platform:

Your Composer dependencies require a PHP version ">= 8.2.0". You are running 8.0.30.


```

## Next Actions
- Review warnings first. Most common causes are bot disabled, 2FA blocking write endpoints, no entry signal during the test window, or missing testnet credentials.
