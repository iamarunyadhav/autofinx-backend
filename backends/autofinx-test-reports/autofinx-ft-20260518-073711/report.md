# AutoFinX Future Trade Test Report

**Verdict:** `WARN`  
**Run ID:** `autofinx-ft-20260518-073711`  
**Started:** `2026-05-18T07:37:11.417234+00:00`  
**Finished:** `2026-05-18T07:37:26.622071+00:00`  
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
| current_price | 2120.59 |
| confidence | 0.196 |

## Counts
| Metric | Value |
| --- | --- |
| before_logs | 1000 |
| after_logs | 1000 |
| new_logs | 5 |
| new_signal_logs | 5 |
| new_blocked_or_skipped_logs | 5 |
| before_trades | 86 |
| after_trades | 86 |
| new_trades | 0 |
| closed_trades_total_seen | 86 |

## Latency
| Metric | Value |
| --- | --- |
| API calls | 23 |
| API average ms | 556.28 |
| API median ms | 393.27 |
| API max ms | 1905.88 |
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
- --run-artisan not provided; report is API snapshot only. Add --run-artisan to actually run diagnostics/tick/audit on the server.

## Next Actions
- Review warnings first. Most common causes are bot disabled, 2FA blocking write endpoints, no entry signal during the test window, or missing testnet credentials.
