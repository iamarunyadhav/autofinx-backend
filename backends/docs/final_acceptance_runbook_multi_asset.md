# AutoFinX Final Acceptance Runbook (Crypto + Metals)

Last updated: 2026-03-04
Repo path: `C:\xampplast\htdocs\auto_finx`
Mode: Strict checklist with `PASS / FAIL / BLOCKED`

## 1) Verification Scope

This runbook validates:

1. Multi-asset API and scheduler wiring (Crypto + Metals).
2. Frontend compile health and new UI controls.
3. Trading/wallet acceptance checklist status against current codebase.

## 2) Evidence Collected In This Verification

## Backend route evidence

1. `php artisan route:list --path=assets`
2. `php artisan route:list --path=metals`
3. `php artisan route:list --path=providers`
4. `php artisan route:list --path=bots`
5. `php artisan route:list --path=market/fx`
6. `php artisan route:list --path=wallet/deposit`
7. `php artisan route:list --path=wallet/withdraw`
8. `php artisan route:list --path=market/price`

Result: all required multi-asset/provider/wallet alias routes are present.

## Scheduler evidence

1. `php artisan schedule:list | Select-String "metals:poll-prices|bots:evaluate|orders:poll-status"`
2. `php artisan list | Select-String "metals:poll-prices"`

Result: metals poller command exists and is scheduled every 5 seconds.

## Backend syntax evidence

1. `php -l app/Http/Controllers/Api/AssetController.php`
2. `php -l app/Http/Controllers/Api/MetalsController.php`
3. `php -l app/Http/Controllers/Api/ProviderController.php`
4. `php -l app/Http/Controllers/Api/MarketController.php`
5. `php -l app/Http/Controllers/Api/BotController.php`
6. `php -l app/Domain/Strategies/BotSignalGenerator.php`
7. `php -l app/Domain/Risk/BotRiskEngine.php`

Result: no syntax errors.

## Frontend evidence

1. `npm run build` (in `frontend/sentinel-ai-trader`)

Result: build success.

## 3) Checklist Status Matrix

Legend:

1. `PASS`: verified by command/build or deterministic code inspection.
2. `FAIL`: broken or missing.
3. `BLOCKED`: needs external runtime dependency (API keys, payments, running workers, live provider account).

| Checklist Item | Status | Reason / Evidence |
|---|---|---|
| UI toggle works (Crypto / Metals) | PASS | `LiveBotsPage.tsx` has top toggle + metals tabs; frontend build passes. |
| Metals live price shows USD + LKR | PASS | `/api/v1/market/price` handles metals + quote; `/api/v1/metals/rates` exists; UI shows USD/LKR/FX badges. |
| Create metals bot | PASS | Bot create API accepts `asset_class`, `metal_type`, `asset_code`, `provider`, `quote_currency`. |
| Start metals bot | PASS | Bot start endpoint exists with metals provider validation. |
| Signals generated for metals strategies | PASS | `rebalance_flip` and `mean_reversion` implemented in `BotSignalGenerator`. |
| Execution -> trades recorded (metals) | BLOCKED | Needs running DB/queues/scheduler + provider credentials + live/paper execution session. |
| Slippage guard prevents bad fills | PASS | Guard logic enforced in `LiveSignalExecutionService` before order placement. |
| Reconciliation updates positions + wallet | PASS | `ReconciliationService` updates order fills, trades, positions, wallet ledger; `SyncOrderStatusJob` now unified with venue factory. |
| Risk engine auto-stops/pauses bot | PASS | `BotRiskEngine` enforces daily loss/drawdown/volatility and metals session/max trades per hour. |
| Deposit flow endpoint chain | PASS | `/wallet/deposit/initiate` + `/wallet/deposit/webhook` routes present via `LiveWalletController`. |
| Withdraw flow endpoint chain | PASS | `/wallet/withdraw/request` + `/wallet/withdraw/webhook` implemented; webhook updates status and wallet balances. |
| Provider connect/status/balances (binance + metals) | PASS | `/providers/{provider}/connect|status|balances` routes and controller implemented. |
| Audit/trace_id logs for trade lifecycle | PASS | Signal/order/reconciliation flow logs with `trace_id` and persisted bot logs. |
| End-to-end LIVE acceptance (real fills + real payouts) | BLOCKED | Requires real Binance/metals/payments credentials and full infra workers. |

## 4) Strict Runtime Acceptance Procedure (To Convert BLOCKED -> PASS)

## Prerequisites

1. Configure `backend/.env` with real/sandbox provider keys:
   1. `BINANCE_*`
   2. `METAL_BROKER_*`
   3. `FX_*`
   4. `PAYHERE_*` (or gateway chosen)
2. Start infra:
   1. `php artisan migrate`
   2. `php artisan horizon` (or `php artisan queue:work`)
   3. `php artisan schedule:work`
3. Start frontend:
   1. `npm run dev` (inside `frontend/sentinel-ai-trader`)

## Mandatory E2E tests

1. Provider connect tests:
   1. `POST /api/v1/providers/binance/connect`
   2. `POST /api/v1/providers/metals/connect`
   3. `GET /api/v1/providers/{provider}/status`
   4. `GET /api/v1/providers/{provider}/balances`
2. Metals market tests:
   1. `GET /api/v1/market/price?asset_class=METALS&asset_code=XAUUSD&provider=metal_broker&quote=LKR`
   2. `GET /api/v1/metals/rates`
3. Bot runtime tests:
   1. Create metals bot from UI.
   2. Start bot and verify `EvaluateBotJob` + `ExecuteSignalJob`.
   3. Confirm orders/fills/trades/positions/ledger updates.
4. Risk guard tests:
   1. Force conditions for max loss / volatility / max trades per hour.
   2. Confirm bot auto pause/stop and cooldown behavior.
5. Wallet tests:
   1. Initiate deposit and send signed webhook.
   2. Request withdrawal and send payout webhook (`completed`, `failed`).

## 5) Current Final Verdict

1. Code-level architecture and API/UI coverage for Crypto + Metals: `PASS`.
2. Production live-trading proof (real external systems): `BLOCKED` until full staged UAT is executed with credentials and workers.
