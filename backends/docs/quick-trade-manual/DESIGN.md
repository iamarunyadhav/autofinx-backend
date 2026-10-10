# Quick Trade - Manual SL/TP mode: design (2026-10-10)

Status: BUILT BEHIND TWO FLAGS (both OFF). UNVERIFIED-ON-EXCHANGE until the owner runs `TESTNET_CHECKLIST.md`.
Nothing here was run against Binance. No keys were used, read or printed.

## 1. What exists today (audited, file:line as of 2026-10-10)

Quick Trade Time-Based (must stay byte-for-byte unchanged in behavior):

- `QuickTradeService::place()` (`app/Domain/Trading/QuickTradeService.php:48`) -> `placeLiveTrade()` (`:916`) -> `LiveTimedTradeService::executeEntry` ->
  `BinanceTimedFuturesProvider::openTimedTrade()` (`Providers/BinanceTimedFuturesProvider.php:160`): MARKET order on `POST /fapi/v1/order`, NO `positionSide`
  (so it only works on a ONE-WAY account), `newOrderRespType=RESULT`, `newClientOrderId`. It never sets margin type; it sets leverage only when leverage is enabled.
- Expiry exit: `quick-trades:settle` every 10 s (`routes/console.php:118-124`) -> `QuickTradeService::settleExpired()` (`:503`), which selects ONLY `quick_trades`
  rows with `status='open' AND expires_at <= now()`; close = MARKET `reduceOnly` (`closeTimedTrade`, `:320`).
- Optional SL/TP are SOFTWARE-polled (`CheckQuickTradeStopLossJob`, 1 s loop) and close with a MARKET order. They are not exchange orders.
- Wallet: `LiveWalletService::reserveStake / settleReservation` (local accounting of the stake).
- Gates: `TradingEnvironmentGuard::assertWorkspaceMode` (workspace mode + live switch), `RiskGuardService::enforceOrFail`,
  `WorkspaceLiveRiskService::assertQuickTradeExposureAllowed` (counts only `quick_trades` + `positions`), `LeverageValidationService` (real leverage brackets,
  liquidation ESTIMATE, 3 % minimum distance).
- Auth pattern: `AssertsWorkspaceMembership` trait (verbatim copy of `RatioBotController::assertWorkspaceMembership`); owner/admin gate pattern:
  `FutureTradeBotController::authorizeEnvConfigChange`.
- Future Trade live gate pattern: server flag (`services.live_trading_enabled`) AND per-workspace DB flag. Same shape is used here.

## 2. Binance Algo Order finding (REPORT TO OWNER, NOT FIXED HERE)

`grep -rn "algoOrder|algoId|-4120"` over `app/ config/ routes/ tests/` returns NOTHING. Every conditional order in the repo goes to the legacy endpoint:

| Caller | Where | Call |
|---|---|---|
| Future Trade exchange SL/TP | `FutureTradeBotService::placeExchangeSLTPOrders` (`:11389`) and trailing re-placement (`:11541`) via `FutureTradeOrderRouter::placeReduceOnlyStop` (`FutureTradeOrderRouter.php:80`) | `POST /fapi/v1/order` `type=STOP_MARKET/TAKE_PROFIT_MARKET`, `stopPrice`, `closePosition=true` |
| Ratio Bot exchange stop | `RatioBotExchangeStopOrderService.php:110` -> `BinanceFuturesClient::placeReduceOnlyStopOrder` (`:254`) | same |

If the web source is right (conditional types moved to `POST /fapi/v1/algoOrder`, old endpoint returns -4120), those calls fail on mainnet.
Future Trade swallows the failure: `EXCHANGE_SL_FAILED ... (server loop is backup)` (`FutureTradeBotService.php:11427-11430`), so the position would silently
run without exchange-side protection and rely on a software loop. This is a latent bug IF the reported change is live; it cannot be confirmed from here (no web, no keys).
UPDATE 2026-10-10 (later): Future Trade / Ratio Bot stops were migrated to the Algo endpoint in a separate change (see `docs/binance-algo-migration/CHECKLIST.md`); the route/field names in `BinanceAlgoApi` are now verified against Binance's docs (still not run on an account). The DELETE-as-JSON-body suspicion below was confirmed by code and fixed for the legacy cancel calls.

Also suspected (unverified, not touched): `BinanceFuturesClient::request()` sends `DELETE` params as a JSON body (Laravel `delete($url, $data)`), while Binance reads
form/query parameters; `cancelOrder`/`cancelOpenOrders` may not work as intended. The new manual-mode client sends `DELETE` parameters in the query string.

### API shapes: verified vs reported

VERIFIED BY EXISTING CODE THAT RUNS AGAINST BINANCE (used by production code paths; tests are mocked, repo has no recorded live responses):
`POST /fapi/v1/order` (MARKET, `reduceOnly`, `newClientOrderId`, `newOrderRespType=RESULT`), `GET /fapi/v1/order`, `GET /fapi/v2/positionRisk`, `GET /fapi/v2/balance`,
`GET /fapi/v1/userTrades`, `GET /fapi/v1/income`, `GET /fapi/v1/leverageBracket`, `GET /fapi/v1/positionSide/dual`, `GET /fapi/v1/exchangeInfo`, `GET /fapi/v1/premiumIndex`,
`POST /fapi/v1/leverage`.

REPORTED BY THE OWNER'S WEB SOURCE ONLY (UNVERIFIED): `POST /fapi/v1/algoOrder`, `algoType=CONDITIONAL`, `closePosition=true` without `quantity`, `positionSide` in hedge mode,
ids are `algoId`, old endpoint rejects with -4120.

FROM MODEL MEMORY OF THE DOCS, EVEN LESS CERTAIN (UNVERIFIED): trigger parameter name `triggerPrice` (not `stopPrice`), `clientAlgoId`, response field `algoStatus`
with `NEW` meaning working, `GET /fapi/v1/algoOrder`, `DELETE /fapi/v1/algoOrder`, `GET /fapi/v1/openAlgoOrders`, `GET /fapi/v1/forceOrders`.
All of these live in ONE class (`BinanceAlgoApi`) so a testnet surprise is a one-file correction. Design is fail-closed: if any shape is wrong, protection is NOT verified,
and the position is emergency-closed (see 4). It can cost fees; it can never leave an unprotected position that the UI calls protected.

## 3. Decisions

1. Separate tables, not `quick_trades`. `quick_trades.expires_at` is mandatory and every reader (settler, SL monitor, reconcile, ML shadow, research, exposure cap, Robot Trade)
   assumes expiry. Manual positions live in `quick_trade_manual_positions` (+ append-only `quick_trade_manual_events`), with an explicit `mode='manual_sl_tp'` column.
   The 10 s settler therefore cannot see them (structural isolation) and a test proves it.
2. One active manual position per (workspace, symbol), enforced by a UNIQUE nullable `active_key` column (set while non-terminal, NULL when terminal). DB-level, race-proof.
3. Stake = margin (same convention as Time-Based): notional = stake x leverage, quantity = notional / price floored to the symbol step.
4. SL % is PRICE-MOVEMENT % (labelled). Margin loss % = SL % x leverage is shown beside it. Trigger from the ACTUAL fill price after entry; the preview uses mark price.
   Trigger price is rounded toward entry to the tick (never farther than requested), and must stay strictly on the loss side of mark.
5. Liquidation safety: refuse unless SL distance <= `sl_liq_ratio` (default 0.5) x estimated liquidation distance (maintenance-margin from real leverage brackets),
   plus a post-fill check against the exchange-reported `liquidationPrice`.
6. Account mode: one-way and hedge are both built (hedge adds `positionSide`, drops `reduceOnly`). Hedge is mock-tested only. Cross margin is allowed but flagged
   (cross liquidation depends on the whole wallet); margin type is never mutated.
7. Protection gate: BLOCK (never fall back to a browser timer or the 1 s software SL) when the algo capability is not usable: if the SL cannot be placed AND verified, the position
   is closed at once. Additionally `allow_mainnet` (default false) keeps the mode testnet-only until the owner attests the checklist.
8. Flags (all default OFF): `config('quick_trade_manual.enabled')` (env `QUICK_TRADE_MANUAL_ENABLED`) AND workspace `Setting(group=quick_trade_manual,key=enabled)`;
   plus the existing live gates (workspace live mode + `live_ready`). Mainnet also needs `QUICK_TRADE_MANUAL_ALLOW_MAINNET`.
9. SL/TP editing is NOT built (replacement semantics on the algo API are unverified; a cancel-then-place gap would leave a naked position).

## 4. Lifecycle and guarded transitions

`ENTRY_PENDING -> ENTRY_FILLED | PARTIALLY_FILLED | ENTRY_FAILED | EXCHANGE_STATE_UNKNOWN`;
`ENTRY_FILLED | PARTIALLY_FILLED -> PROTECTION_PENDING -> OPEN_PROTECTED | PROTECTION_FAILED -> EMERGENCY_EXIT`;
`EMERGENCY_EXIT -> RECONCILIATION | CLOSE_FAILED`; `OPEN_PROTECTED -> CLOSING | RECONCILIATION | LIQUIDATED | PROTECTION_FAILED | EXCHANGE_STATE_UNKNOWN`;
`CLOSING -> RECONCILIATION | CLOSE_FAILED`; `CLOSE_FAILED -> CLOSING | EMERGENCY_EXIT | OPEN_PROTECTED | RECONCILIATION`; `RECONCILIATION -> CLOSED | LIQUIDATED | EXCHANGE_STATE_UNKNOWN`;
`EXCHANGE_STATE_UNKNOWN -> (any resolving state)`. `ENTRY_FAILED` is added to the owner's list (a rejected entry has no position, so it must not read CLOSED).

Every transition is a compare-and-set `UPDATE ... WHERE id=? AND status IN (allowed from)` inside a transaction; the loser of a race gets 0 rows and does nothing.
Each transition writes one row to `quick_trade_manual_events` (from, to, source, payload). Wallet settlement is guarded by `wallet_settled_at IS NULL` in the same transaction.

Entry: reserve stake -> insert row ENTRY_PENDING (BEFORE the exchange call, with deterministic client order id) -> MARKET entry -> resolve fill (response, then query by
client id, then position read) -> persist ACTUAL executed qty / avg price -> place SL (and TP) as algo orders -> VERIFY by querying the exchange (id, status NEW, symbol, side,
trigger price) -> only then `protection_status=PROTECTED`. Failure at any protection step: emergency reduce-only market close, read the position back, only a flat position
confirmed by the exchange counts as closed; otherwise `CLOSE_FAILED` + critical log + critical audit event + `critical_alert` visible in the API/UI, and the reconciler retries.
TP is optional: a TP that fails does not fail the position (SL is the safety); it is reported as `tp_status=FAILED` with a warning.

Manual close: CAS `OPEN_PROTECTED -> CLOSING` (a second click is a no-op), read the exchange position first (already flat => SL/TP won the race => reconcile, no order), else
reduce-only MARKET with a per-attempt client id; reduce-only guarantees a close can never reverse the position. After the fill: cancel leftover SL/TP, verify cancel, reconcile real fills.

Reconciler (`quick-trade-manual:reconcile`, scheduled every 10 s in `routes/console.php` but deliberately NOT gated on the feature flag, so switching the flag off never stops reconciling a position that is still open; the command is a no-op when the table is missing or nothing is active; idempotent; the exchange is authoritative): resolves stale ENTRY_PENDING, re-runs
protection idempotently (query by client id before any placement), detects flat positions and attributes them (SL / TP / liquidation / external) from algo status and `forceOrders`,
re-places or emergency-closes when protection has vanished, flags quantity drift, finalizes P&L from real fills (commission, realized P&L, funding) and sweeps orphaned algo orders.
A worker restart or browser close changes nothing: protection is an exchange object, state is in the DB, recovery is the reconciler.

## 5. Files

New backend: `config/quick_trade_manual.php`; `app/Domain/QuickTradeManual/*` (ManualTradeMath, ManualFeatureGate, ManualPositionStateMachine, ManualPreviewService,
ManualProtectionService, ManualExitService, ManualPositionService, ManualPositionReconciler, ManualPositionPresenter, ManualPositionGuard, and `Exchange/`:
ManualExchangeGateway interface, BinanceManualGateway, BinanceAlgoApi, ManualExchangeGatewayFactory, ManualExchangeException);
`app/Models/QuickTradeManualPosition.php`, `QuickTradeManualEvent.php`; `app/Http/Controllers/Api/QuickTradeManualController.php`;
`app/Console/Commands/QuickTradeManualReconcileCommand.php` (auto-discovered, `Kernel` untouched); migrations `2026_10_14_000000_create_quick_trade_manual_positions_table`
and `2026_10_14_000100_create_quick_trade_manual_events_table` (PENDING, not run); tests under `tests/Feature/QuickTradeManual/`, `tests/Unit/QuickTradeManual/`, helpers in `tests/Support/`.

Existing backend files touched (all purely additive, 0 deleted lines, inert while the flags are off):
`app/Domain/Exchange/BinanceFuturesClient.php` (+ one public `signedApiCall()`, no existing method changed), `app/Domain/Trading/QuickTradeService.php` (+ one guard in `place()`),
`routes/api.php` (+ 9 routes), `routes/console.php` (+ 1 schedule entry), `.env.example` (+ 2 commented keys).

New frontend: `src/lib/manualTrade.ts`, `src/lib/chartStructure.ts`, `src/hooks/useQuickTradeManual.ts`, `src/hooks/useManualChartCandles.ts`, `src/components/trading/manual/*`,
`tests/manualTrade.test.mjs`, `tests/chartStructure.test.mjs`. Existing frontend file touched: `src/pages/QuickTradePage.tsx` (+26 lines: mode switch, early return to the manual section;
the Time-Based JSX is untouched; its hooks still run while Manual is shown).

## 6. Risks and known limitations

- Other engines on the SAME Binance account and symbol (Future Trade webhooks, Ratio Bot) act on the exchange directly and are NOT guarded by this work (touching them was out of scope).
  In one-way mode their orders merge with a manual position. Mitigation built: entry refused unless the symbol is flat on the exchange and no Time-Based/Robot position is open
  (guard in `QuickTradeService::place`); the reconciler flags quantity drift as a critical event. Recommended: a dedicated Binance sub-account/integration for manual mode.
- SL on a MARK_PRICE trigger is a MARKET order after trigger: gap/slippage can make the loss larger than planned. SL does not guarantee a maximum loss.
- Funding is charged every 8 h while open and is not capped by the SL.
- Polling reconciler (10 s) rather than user-data-stream push; exit attribution can lag up to one tick. Exchange protection itself does not depend on it.
- Exchange maintenance / rate limits: the shared client circuit breaker applies; failures leave state unchanged and surface as `EXCHANGE_STATE_UNKNOWN` after repeated failures.
- No in-app push alert channel exists in the repo; critical events are `Log::critical`, a CRITICAL `AuditEvent`, and a `critical_alert` field the UI shows.

## 7. Deployment checklist (owner / server; nothing below was done by the agent)
1. Review and run the two migrations on the target DB (`php artisan migrate:status` first). Without them the feature cannot be enabled and Time-Based is unaffected.
2. Deploy code. No supervisor change is needed (the reconciler rides the existing `schedule:work`). Restart queue workers as usual after deploy.
3. Leave `QUICK_TRADE_MANUAL_ENABLED=false` in production until `TESTNET_CHECKLIST.md` is signed off on a TESTNET workspace first.
4. Enable order: server flag (testnet environment) -> workspace flag -> verify checklist -> only then `QUICK_TRADE_MANUAL_ALLOW_MAINNET=true`, smallest stake, dedicated sub-account.
5. Decide how Future Trade / Ratio Bot symbols are separated from manual-mode symbols (see limitations).
