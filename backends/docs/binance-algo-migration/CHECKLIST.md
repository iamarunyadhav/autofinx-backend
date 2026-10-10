# Binance Algo Order migration - owner verification checklist (2026-10-10)

STATUS: CODE DONE + FAKE-TESTED, **NOT VERIFIED ON A LIVE ACCOUNT**. Nothing here was run against Binance. No keys were used.
Server-side steps below are for the owner only; the agent has no server access.

## What changed (short)

Binance USD-S-M Futures moved conditional orders (STOP_MARKET, TAKE_PROFIT_MARKET, STOP, TAKE_PROFIT, TRAILING_STOP_MARKET) to
`POST /fapi/v1/algoOrder` (reported: the old `POST /fapi/v1/order` answers -4120). Future Trade and Ratio Bot placed every exchange-side stop through the
old endpoint. Now `BinanceFuturesClient::placeConditionalOrder()` (called by `placeReduceOnlyStopOrder` and the Future Trade router) decides:

- `BINANCE_CONDITIONAL_ORDER_MODE=auto` (default): algo first; legacy ONLY after a definite "unsupported" rejection of the algo call
  (HTTP 404/405 or code -5000/-1116/-1104, all UNVERIFIED guesses); a legacy -4120 switches to algo; learned mode cached 600 s.
  Timeout / 5xx / unknown outcome is NEVER retried on the other endpoint: the client looks the order up by `clientAlgoId` first.
- `algo`: algo endpoint only, legacy never called. `legacy`: exactly the old behaviour (rollback).
- Cancel / open-order checks route to the right API (algo cancel = `DELETE /fapi/v1/algoOrder`, algo open list = `GET /fapi/v1/openAlgoOrders`).
- Future Trade's exchange snapshot now includes algo stops, so "cancel reduce-only orders before close" still cancels them.
- Legacy `cancelOrder` / `cancelOpenOrders` now send DELETE parameters in the query string (they used a JSON body, which Binance does not read).
- Every failure logs `[BINANCE_CONDITIONAL_ORDER_FAILED]` (critical, exact code/message), Future Trade also `[FT EXCHANGE STOP NOT PLACED]`
  and the existing `EXCHANGE_SL_FAILED` / `EXCHANGE_TP_FAILED` / `TRAILING_SL_PLACE_FAILED` rows now carry `binance_code`, `binance_msg`, `endpoint`, `outcome` in `context`.
  Ratio Bot logs `[RatioBotStopOrder] Failed to place exchange-side stop` at critical with the same fields.
- Bot decisions, thresholds, sizing: untouched.

## 0. Future Trade client-order-id collision: FIXED in code (owner approved 2026-10-10), NOT VERIFIED ON A LIVE ACCOUNT

Before: `deterministicClientOrderId()` mapped every kind except `exit` to `entry`, so entry, SL, TP and trailing-SL ids were all `ftx-entry-<key24>`;
Binance wants client ids unique among OPEN orders, so the TP was (expected to be) rejected as a duplicate (`EXCHANGE_TP_FAILED`; exact duplicate code unverified),
and a user-stream SL fill (id == entry id) was even read as an ENTRY fill. Now (all <= 36 chars, algo-regex valid, derived from the signal key so a retry gives the same ids):

| kind | id | note |
|---|---|---|
| entry | `ftx-entry-<key24>` | UNCHANGED |
| exit | `ftx-exit-<key24>` | unchanged |
| SL | `ftx-sl-<key24>` | new |
| TP | `ftx-tp-<key24>` | new |
| trailing SL, cycle N | `ftx-tsl-<N>-<key>` | new; N = persisted `sl_update_count`+1 (key shortened only if N is huge) |

Trailing invariant: old stop is cancelled BEFORE the next id is placed; a failed cancel aborts the replacement; N is persisted only after a successful placement.
Meta now also stores `sl_algo` / `tp_algo` (which API owns each stop) so the sibling cancel is routed exactly.
Old positions/orders (`ftx-entry-...` as SL id) are NOT renamed: the user stream recognises them (closing-side fill whose id equals meta `sl_client_order_id` => SL; when SL and TP slots
hold the same old id the order type STOP*/TAKE_PROFIT* decides); their first trailing update places a new-format `ftx-tsl-` id.

What to look for after deploy (small position first):
1. Conditional/Algo tab shows BOTH the STOP_MARKET and the TAKE_PROFIT_MARKET, close-position, with ids `ftx-sl-...` and `ftx-tp-...` (a trailing move shows `ftx-tsl-1-...`, old one cancelled).
2. Log rows `EXCHANGE_SL_PLACED` AND `EXCHANGE_TP_PLACED`; `EXCHANGE_TP_FAILED` must disappear (if it stays, read `binance_code`/`binance_msg` in the row context: it is then NOT the id collision).
3. After a SL or TP fill: runtime signal `EXCHANGE_SL_FILLED` / `EXCHANGE_TP_FILLED`, and the OTHER protective order gone from the Conditional tab (sibling cancel; whether Binance also auto-cancels is still UNVERIFIED).
   Warning `[EXCHANGE_SLTP_SIBLING_CANCEL_FAILED]` or a leftover order in the tab = cancel it by hand and tell the developer.
4. After a bot-initiated close (server-side SL/TP/trailing/reverse): no leftover stop in the Conditional tab (`cancelReduceOnlyOrders` runs first). A leftover closePosition TP/SL could fire on the NEXT position of that symbol.
5. If the user-data stream does not emit the algo trigger fill (unverified, section 5.3) nothing is attributed by the stream; the reconcile/external-close path closes the DB row as before, but the sibling is then cancelled only by the next bot close path: check the tab.

Rollback of the id change: revert `FutureTradeSignalStabilityService::deterministicClientOrderId`, `FutureTradeUserStreamService` and the `sl_algo/tp_algo` meta lines via git (no flag was added: a flag cannot
safely recreate the collision, and `BINANCE_CONDITIONAL_ORDER_MODE=legacy` does not undo it). Cheapest operational "no exchange-side TP" is NOT available without code; stops already placed stay on the exchange until cancelled.

## 1. Deploy (OWNER, server)

1. Pull/deploy the backend. No migration. Optional `.env`: `BINANCE_CONDITIONAL_ORDER_MODE=auto` (this is already the default; see `.env.example`).
2. `php artisan config:clear` (and `config:cache` again if you cache config) so `config/binance.php` is picked up.
3. Restart every long-running PHP process that loads the code: `supervisorctl restart` the queue workers (`ratio_bot`, default, etc.) and the Future Trade
   supervisor processes (live-feed / user-stream / scanner). Without a restart, old code keeps running.
4. Start with ONE small position on the account/testnet you are testing, not the whole fleet.

## 2. Confirm on the real account (small position)

1. Set the mode you want to test (`auto` first). Open a minimal Future Trade (or Ratio Bot futures) position.
2. Binance app/web -> Futures -> Open Orders -> **Conditional / Algo orders tab** (not the normal "Open Orders" tab): the STOP_MARKET (and TAKE_PROFIT_MARKET) must be listed,
   close-position, trigger price = expected SL, "Mark price" trigger.
3. Query by client id: `GET /fapi/v1/algoOrder?clientAlgoId=<id>` (or the Binance UI order id = `algoId`). The id is in the Future Trade log row `EXCHANGE_SL_PLACED`
   ("clientOrderId: ...", `orderId` = algoId) or the Ratio Bot event `exchange_stop_order_placed`.
4. Trailing/replace: Future Trade trailing SL (`TRAILING_SL_UPDATED`) or a Ratio Bot cost-basis change (`exchange_stop_order_replaced`) must show the old algo order CANCELLED and one new one.
5. Close the position by the bot/manually: the algo stop must not be left behind in the Conditional tab (see open item "sibling cancel").
6. Optional trigger test: place the stop very close to mark price on a tiny position and watch it fire.

## 3. Logs to watch (storage/logs or your log sink)

- GOOD: `EXCHANGE_SL_PLACED` (Future Trade logs), `exchange_stop_order_placed` (Ratio Bot events). `EXCHANGE_SL_FAILED` must disappear.
- BAD (alert on these): `[BINANCE_CONDITIONAL_ORDER_FAILED]`, `[FT EXCHANGE STOP NOT PLACED]`, `[RatioBotStopOrder] Failed to place exchange-side stop`. Each has `binance_code`/`binance_msg`.
- Mode changes: `[BINANCE_CONDITIONAL_MODE_SWITCH]` (warning). If you see it flip-flopping, set the mode explicitly.
- `[BINANCE_ALGO_ORDER_RECOVERED]`: an unknown/duplicate outcome was settled by lookup (no duplicate order was placed).
- `[FT EXCHANGE SNAPSHOT] fetchOpenAlgoOrders failed`: the algo open list could not be read; the snapshot is flagged unavailable (not "no stops").

## 4. Roll back

`BINANCE_CONDITIONAL_ORDER_MODE=legacy`, `php artisan config:clear`, restart workers/Future Trade processes. This restores the exact old placement behaviour
(the DELETE query-string fix for legacy cancel stays; it only makes cancel work). Algo stops already on the exchange are NOT removed by a rollback: cancel them in the
Conditional tab or with the cancel-all endpoint (`DELETE /fapi/v1/algoOpenOrders?symbol=...`).

## 5. UNVERIFIED items (could not be confirmed here)

1. Testnet support of the algo endpoints (and whether it answers 404/-5000 - the auto-fallback trigger codes are guesses).
2. Whether Binance auto-cancels the sibling algo order (and any remaining algo stops) after a `closePosition` order fills / the position is closed otherwise.
   Code defence: Future Trade cancels every reduce-only/algo order it sees before a bot-initiated close and cancels the sibling after a detected exchange SL/TP fill;
   Ratio Bot cancels its stop when it closes. Not covered: a stop left behind after an external/manual close. Check the Conditional tab after your first close.
3. The user-data stream event name/payload for algo orders (reported to be new). Future Trade's exchange-fill detection (`EXCHANGE_SL_FILLED` via `ORDER_TRADE_UPDATE`
   matching our client id) may NOT fire for algo triggers; the position is then picked up by the existing reconcile/external-close path. Not changed, not relied on.
4. `reduceOnly` rules for algo orders in one-way mode (we always use `closePosition=true`, never `reduceOnly`/`quantity`). Hedge mode: `positionSide` is sent only when a caller passes it;
   Future Trade / Ratio Bot are one-way (`positionSide=BOTH` default), as before.
5. Body shape of `GET /fapi/v1/algoOrder` (third-party sourced) and of `GET /fapi/v1/openAlgoOrders` (assumed a JSON list; any other shape raises, it never means "no stops").
6. algoStatus vocabulary beyond `NEW` (Quick Trade Manual guesses TRIGGERED/FINISHED/...).
7. Whether `MARK_PRICE` workingType and `closePosition` are accepted on STOP_MARKET exactly as before (documented as yes).
8. `FuturesPositionSyncService` still mirrors only `/fapi/v1/openOrders` into the `orders` table: algo stops are not mirrored there (mirror only; no decision reads it for stops).
9. Ratio Bot `newClientOrderId` is truncated to 36 chars (`rbstop-<29 hex>`), so it is identical for every placement of the same bot (cancel-then-replace makes this work; a failed cancel makes the next placement a duplicate-id rejection).
