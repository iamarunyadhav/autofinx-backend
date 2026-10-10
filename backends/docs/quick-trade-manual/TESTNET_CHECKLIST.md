# Manual SL/TP - owner-run Binance TESTNET verification checklist

STATUS OF THE FEATURE UNTIL THIS IS SIGNED OFF: **UNVERIFIED-ON-EXCHANGE**.
All automated tests use a mocked exchange (`tests/Support/FakeManualExchange.php`). They prove our logic, not what Binance accepts.
The agent that built this had no keys and never called Binance. Do NOT use the old leaked testnet key (pending rotation); create a fresh testnet key for this.

What is already proven by existing production code (low risk): MARKET orders, `reduceOnly` closes, `positionRisk`, `userTrades`, `income`, `leverageBracket`, `positionSide/dual`.
What has NEVER been called by this repo and is only reported by a web source / model memory (HIGH risk, this checklist exists for it):
`POST /fapi/v1/algoOrder` (`algoType=CONDITIONAL`, `closePosition=true`, `triggerPrice`, `clientAlgoId`, `positionSide` in hedge), `GET /fapi/v1/algoOrder`,
`DELETE /fapi/v1/algoOrder`, `GET /fapi/v1/openAlgoOrders`, `GET /fapi/v1/forceOrders`, and the `algoStatus` vocabulary (`NEW` working, triggered/cancelled names).
All of them live in `app/Domain/QuickTradeManual/Exchange/BinanceAlgoApi.php` - if testnet disagrees, that is the only file to change (plus `tests/Unit/QuickTradeManual/BinanceAlgoApiTest.php`).

## 0. Preconditions (owner)
- [ ] Fresh Binance Futures TESTNET key stored through the app's Connections page as a Binance integration with `config_json.environment = testnet` (never paste keys anywhere else).
- [ ] The testnet account is in **one-way** mode (`GET /fapi/v1/positionSide/dual` -> `dualSidePosition:false`); funded with testnet USDT.
- [ ] A dedicated testnet account/symbol not used by Future Trade / Ratio Bot at the same time (they act on the same exchange position and are not guarded).
- [ ] Local/test environment only. Backend branch contains the migrations `2026_10_14_000000` / `2026_10_14_000100`.
- [ ] `php artisan migrate:status` reviewed, then YOU run `php artisan migrate` against the test database (the agent did not).
- [ ] `.env`: `QUICK_TRADE_MANUAL_ENABLED=true`, `QUICK_TRADE_MANUAL_ALLOW_MAINNET=false`; `php artisan config:clear`.
- [ ] Workspace flag on (owner/admin): Quick Trade page -> Manual SL/TP -> "Enable for this workspace" (or `PUT /workspaces/{id}/quick-trade-manual/enabled {"enabled":true}`).
- [ ] Scheduler is running (`quick-trade-manual:reconcile` every 10 s appears in `php artisan schedule:list`) or run it by hand: `php artisan quick-trade-manual:reconcile`.
- [ ] Keep a terminal on `storage/logs/laravel.log` and Binance testnet web UI (positions, open orders / conditional orders, trade history, income) side by side.

For every step below also copy the raw request/response of any call that behaves differently from what is written here into a note (redact keys/signatures). The position's event log (View Order Details) shows what the system believed.

## 1. Entry + exchange stop (the critical one)
Use BTCUSDT, stake 30 USDT, leverage 5, SL 1 %, no TP (notional 150 USDT clears the 100 USDT minimum).
- [ ] Preview shows SL trigger = mark x 0.99 (long), quantity, notional, fees, liquidation estimate, warnings.
- [ ] "Review & Open Position" -> separate final confirmation with the checkbox -> Confirm.
- [ ] Result: status **OPEN_PROTECTED**, "Exchange stop-loss verified". Event log order: `entry_intent_recorded`, `entry_filled`, `protection_started`, `protection_verified`.
- [ ] Binance testnet UI shows exactly ONE conditional STOP_MARKET, close-position, trigger = the displayed SL trigger (computed from the ACTUAL fill price), side opposite to the position, working type MARK_PRICE.
- [ ] DB `quick_trade_manual_positions.sl_algo_id` equals the algo id Binance shows; `sl_verified_at` is set.
- [ ] RECORD: the raw POST `/fapi/v1/algoOrder` answer and the raw GET `/fapi/v1/algoOrder` answer. Confirm field names `algoId`, `clientAlgoId`, `algoStatus`, `triggerPrice`, `symbol`, `side`, `orderType`/`type`, `closePosition`.
  If the system emergency-closed instead (`protection_failed`), the event payload holds the reason; copy it and fix `BinanceAlgoApi` accordingly. That outcome is SAFE behaviour, not a bug in the safety logic.
- Pass only if the stop is visible on the exchange AND the app shows it as verified. Fail otherwise (mode stays off).

## 2. Optional take-profit
- [ ] Open a SHORT with TP 1 %: both a STOP_MARKET (above) and a TAKE_PROFIT_MARKET (below) appear on Binance; `tp_status = WORKING`.
- [ ] Open without TP: only the stop exists.

## 3. Browser independence + restart recovery
- [ ] With a position open, close the browser tab completely and stop the queue worker and the scheduler. Binance still shows the stop.
- [ ] Restart the scheduler / run `php artisan quick-trade-manual:reconcile`: `last_reconciled_at` moves, nothing is re-placed (still ONE stop), no duplicate entry.
- [ ] Let a tight stop fire with the browser closed (SL 0.1 % on a volatile minute) -> reconciler books **CLOSED**, `exit_reason = stop_loss`, `closed_by = exchange`, real exit price/fees from the exchange, any TP leg cancelled, no conditional order left on Binance.
  RECORD the algo status string Binance gives for a triggered order (the code assumes one of TRIGGERED/TRIGGERING/FINISHED/EXECUTED/FILLED) and for a cancelled one (CANCELED/CANCELLED/EXPIRED/REJECTED).
- [ ] Compare `net_pnl`, `entry_fee`, `exit_fee` with Binance trade history (commission, realized PnL).

## 4. Manual close + races
- [ ] Manual Close -> dialog shows symbol, direction, quantity, mark, est. gross/net, fees, slippage warning -> confirm: status CLOSED, `exit_reason = manual`, position flat on Binance, SL (and TP) gone from Binance, P&L matches Binance history.
- [ ] Double-click the confirm button / press close twice quickly: exactly ONE close order in Binance history.
- [ ] Cancel the stop by hand in the Binance UI, wait <= 10 s: `protection_lost` then `protection_restored` (new algo id) or, if it cannot be re-placed, emergency close. Never a silent unprotected position.
- [ ] Close the position by hand in the Binance UI: reconciler detects it (`external`), books it, cancels the leftover stop.

## 5. Failure drills (safe because TESTNET)
- [ ] Force a protection failure: on a scratch branch change `BinanceAlgoApi::TRIGGER_PARAM` to `bogusPrice` and open a position. Expected: entry fills, stop rejected, **emergency reduce-only close**, status CLOSED, `emergency_close_confirmed = true`, `critical_alert = true`, critical event in the log, position flat on Binance. Revert the scratch change.
- [ ] Stop the scheduler, open a position, kill the PHP worker mid-request if you can (or `kill` after the entry): on restart the reconciler resolves the entry/protection by querying, without a second entry order.
- [ ] Disconnect the network / block the host for a minute with a position open: the position goes to `EXCHANGE_STATE_UNKNOWN` (not assumed lost, stop untouched on the exchange) and returns to OPEN_PROTECTED when reads work again.

## 6. Account-mode and cost checks
- [ ] (Optional) Switch the testnet account to **hedge** mode, repeat step 1 and a manual close: entry/stop/close carry `positionSide`; record any rejection text. (Hedge is mock-tested only.)
- [ ] Hold a position across a funding time (00:00/08:00/16:00 UTC): `funding_fee` equals the `FUNDING_FEE` income on Binance (sign: positive = cost).
- [ ] Margin type: with the symbol on CROSS the preview must warn; liquidation price shown after the fill should equal Binance's.
- [ ] Leverage shown equals the leverage Binance reports for the symbol after the open.

## 7. Sign-off
| Item | Result | Date | Notes |
|---|---|---|---|
| Algo order placement + verification shapes | | | |
| Triggered / cancelled status vocabulary | | | |
| Stop survives browser/worker restart | | | |
| Manual close / sibling cancel / duplicate close | | | |
| Emergency close drill | | | |
| Funding and P&L reconciliation | | | |
| Hedge mode (optional) | | | |

Only after every required row is PASS: consider `QUICK_TRADE_MANUAL_ALLOW_MAINNET=true`, with the smallest stake, on a dedicated sub-account, watching the first positions manually.
If algo orders are not supported on testnet, the result is **BLOCKED (cannot verify)**, not pass: keep the feature off.

## Rollback
1. Close every open manual position from the UI (Manual Close) and confirm flat on Binance.
2. `QUICK_TRADE_MANUAL_ENABLED=false` (+ `config:clear`/restart workers). With the flag off no new manual position can open; Manual Close and the 10 s reconciler still work, so an open position keeps its exchange stop and keeps being reconciled.
3. Optional: `PUT .../quick-trade-manual/enabled {"enabled":false}` per workspace.
4. Full removal (only with no active rows): `php artisan migrate:rollback --path=database/migrations/2026_10_14_000100_create_quick_trade_manual_events_table.php` then `..._000000_create_quick_trade_manual_positions_table.php`. Time-Based Quick Trade does not depend on either table.
