# AutoFinX Future Trade Test Report

**Verdict:** `FAIL`  
**Run ID:** `autofinx-ft-20260518-073616`  
**Started:** `2026-05-18T07:36:16.813905+00:00`  
**Finished:** `2026-05-18T07:36:28.208817+00:00`  
**API Base:** `https://api.autofinx.arunyadhav.dev`  
**Workspace:** `26a36b56-4383-4edd-99d1-7622e7c191ef`

## Environment
| Key | Value |
| --- | --- |
| mode |  |
| force_testnet |  |
| testnet_configured |  |
| live_configured |  |
| testnet_api_url |  |
| live_api_url |  |

## Bot Status
| Key | Value |
| --- | --- |
| state |  |
| status |  |
| connection_status |  |
| current_symbol |  |
| current_signal |  |
| current_price |  |
| confidence |  |

## Counts
| Metric | Value |
| --- | --- |
| before_logs | 0 |
| after_logs | 0 |
| new_logs | 0 |
| new_signal_logs | 0 |
| new_blocked_or_skipped_logs | 0 |
| before_trades | 0 |
| after_trades | 0 |
| new_trades | 0 |
| closed_trades_total_seen | 0 |

## Latency
| Metric | Value |
| --- | --- |
| API calls | 0 |
| API average ms |  |
| API median ms |  |
| API max ms |  |
| Matched execution trades | 0 |
| Execution avg seconds |  |
| Execution median seconds |  |
| Execution max seconds |  |

## PnL Summary From Visible Closed Trades
| Metric | Value |
| --- | --- |
| closed_trades_with_pnl | 0 |
| wins | 0 |
| losses | 0 |
| win_rate_percent |  |
| net_pnl_sum |  |

## Warnings
- --run-artisan not provided; report is API snapshot only. Add --run-artisan to actually run diagnostics/tick/audit on the server.
- 23 API calls failed. See failed_endpoints in JSON report.

## Failures
- Login failed. Check API base URL, email/password, OTP, and backend auth config.
- Could not read Future Trade status endpoint.

## Failed API Endpoints
- `POST /api/v1/auth/login -> 422 HTTP Error 422: Unprocessable Content`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/env-config -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/config -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/status -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/dashboard -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/live-status -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/scanner-snapshot -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/live-feed -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/user-stream -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/logs -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/trades -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/chart -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/env-config -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/config -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/status -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/dashboard -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/live-status -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/scanner-snapshot -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/live-feed -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/user-stream -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/logs -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/trades -> 401 HTTP Error 401: Unauthorized`
- `GET /api/v1/workspaces/26a36b56-4383-4edd-99d1-7622e7c191ef/future-trade/chart -> 401 HTTP Error 401: Unauthorized`

## Next Actions
- Fix failures before trusting testnet execution. Do not run with `--execute` until env mode is confirmed as `testnet` and credentials are configured.
