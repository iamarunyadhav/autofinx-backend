# Binance Futures Terminal and Market Pressure — Implementation Report

Date: 2026-10-28

## 1. Project analysis summary

AutoFinx already had separate timed Quick Trade and no-expiry Manual SL/TP flows, a signed `BinanceFuturesClient`, exchange credential vaulting, user streams, exchange-native algo stops, workspace membership authorization, a multi-lane public market feed, Redis live state, and exchange-authoritative position reconciliation. This work extends those paths; it does not replace them or touch TradingView strategy calculations.

## 2. Existing features verified

- Market and reduce-only close execution with idempotent client order IDs.
- Manual long/short, one-way and Hedge Mode `positionSide` handling.
- Pre-entry leverage validation against exchange leverage brackets.
- Exchange-native stop-market, take-profit-market and trailing-stop protection.
- Stop acknowledgement followed by an exchange query before marking protection active.
- Exchange position, mark price, unrealized PNL and liquidation-price reads.
- Manual close preview and exchange reconciliation.
- Symbol tick-size, quantity-step and minimum-notional validation.
- Mainnet remains separately feature-gated and testnet-first.

## 3. Features implemented in this change

- Dedicated Binance USD-M `aggTrade` transport lane.
- Every aggregate trade bypasses quote coalescing so additive volume is not lost.
- Duplicate aggregate-trade ID rejection and bounded out-of-order acceptance.
- Bounded one-second rolling buffers for 1m, 5m and 15m windows.
- Aggressive buy/sell executed-notional percentages (`m=true` is seller-aggressed).
- CVD notional and short-term pressure momentum.
- Top-five visible partial-depth bid/ask imbalance and spread. This is explicitly not represented as a synchronized full order book.
- Redis latest-state keys with TTL:
  - `autofinx:market:binance_futures:{SYMBOL}:pressure:1m`
  - `autofinx:market:binance_futures:{SYMBOL}:pressure:5m`
  - `autofinx:market:binance_futures:{SYMBOL}:pressure:15m`
- Workspace-authorized, rate-limited pressure API.
- Quick Trade pressure panel with 1m/5m/15m switching, CVD, momentum, event age, price, top-five book imbalance, reconnect/offline/stale presentation, and an explicit non-prediction explanation.
- Capability registry that enables only functions the current execution stack actually implements.
- `MARKET_PRESSURE_AUTO_TRADE` is not configurable: auto-trading is hard-disabled in code.

## 4. Unsupported or deliberately disabled controls

| Capability | Status | Reason |
|---|---|---|
| Post-entry SL/TP edit | Disabled | The current USD-M algo-order amendment contract is not exchange-verified. Cancel/replace can leave a protection gap or a ghost close-all order. |
| Position averaging/add | Disabled | Needs an approved entry-ledger schema, protection resizing rules, and testnet verification. Binance's aggregate position must remain authoritative. |
| Margin-mode change while open | Unsupported | Binance rejects margin-mode changes while quantity or open orders exist. |
| Exact cross-margin liquidation preview | Unsupported | It depends on all account positions, collateral, maintenance tiers, fees and funding. The exchange position response remains authoritative. |
| Spot OCO presented as Futures OCO | Forbidden | No substitution is made. |
| Pressure-driven order placement | Disabled | Pressure is informational and stale values are unusable. |
| Full-depth synchronized local book | Not yet implemented | The existing transport is a bounded partial-depth stream, not snapshot + diff-depth reconstruction. |

## 5. Database changes

None. No migration was created or applied. An averaging ledger requires explicit approval before implementation.

## 6. Redis and freshness

Redis is distribution state only. A pressure response is usable only when both exchange event age and backend receive age are within the configured threshold. Missing/expired keys return `offline`; stale snapshots return `stale` and momentum becomes unavailable. Binance remains authoritative for positions and executions.

## 7. Market pressure algorithms

For each aggregate trade:

```text
notional = price × quantity
m = true  -> seller aggressed
m = false -> buyer aggressed

buy pressure %  = aggressive buy notional / total aggressive notional × 100
sell pressure % = aggressive sell notional / total aggressive notional × 100
CVD notional    = aggressive buy notional - aggressive sell notional
```

Momentum compares recent buy-minus-sell notional with the immediately preceding bounded interval. It is descriptive, not a forecast.

Partial-book imbalance:

```text
(top-5 bid notional - top-5 ask notional) / (top-5 bid notional + top-5 ask notional)
```

## 8. Liquidation risk

The existing preview uses position quantity, entry/mark data, available balance, margin type and leverage brackets, and labels liquidation as an estimate. After entry, the UI replaces it with Binance `positionRisk` liquidation data when available. No leverage-only liquidation formula is presented as exact.

## 9. Security review

- Pressure API requires real workspace membership.
- Secrets never reach the browser.
- Pressure has no path to execution services.
- Mutating exchange calls remain signed server-side.
- Existing idempotency, symbol-rule validation, exchange reconciliation and mainnet gates are unchanged.
- TradingView strategies and backend mirrors were not modified.

## 10. Testnet and production status

- Automated calculation and transport tests exist and pass locally.
- The new aggregate-trade server lane is based on Binance's official USD-M stream contract but has not been verified on the owner's long-running production host.
- Algo protection remains marked `UNVERIFIED_ON_EXCHANGE` by the existing module until the owner runs `docs/quick-trade-manual/TESTNET_CHECKLIST.md`.
- Production Supervisor/config changes and restarts are human operations. Deploying code alone does not prove the new lane is running.

## 11. Production readiness

**PARTIAL / TESTNET-FIRST.** The pressure dashboard is safe as read-only observability and fails stale/offline. Existing manual market entry and protection remain behind their established flags. Post-entry protection editing, averaging, a full synchronized book and any pressure auto-trading are not production-ready and are intentionally unavailable.
