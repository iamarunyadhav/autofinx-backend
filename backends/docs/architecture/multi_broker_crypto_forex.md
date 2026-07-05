# Multi-broker architecture: Crypto (Binance) + Forex/Metals

Status: **Phase A shipped** (2026-07-05) — infrastructure wired, no real forex/metals broker connected yet.
Related docs: [`hybrid-trading.md`](../hybrid-trading.md) (Quick Trade / Robot Trade module this builds on), [`final_acceptance_runbook_multi_asset.md`](../final_acceptance_runbook_multi_asset.md) (earlier crypto+metals acceptance checklist).

## Goal

Crypto (Binance) and forex/metals/commodities (a second broker, not chosen yet) run in the same app, sharing every module that isn't genuinely broker-specific. One Crypto/Forex toggle drives Quick Trade, Robot Trade, and (partially) Future Trade Bot.

## What already existed vs. what Phase A added

The platform was closer to ready than it looked — most of the abstraction already existed, just inconsistently wired:

| Piece | Before | Phase A |
|---|---|---|
| `TradingVenueClientInterface` | Already had a real, generic, config-path-driven implementation for a second broker: `MetalBrokerClient` (`app/Domain/Venue/MetalBrokerClient.php`) — order/status/balances/price paths, spread%, sandbox/live mode. Returns fake data only because `services.metal_broker.api_url` is empty. | Unchanged — **do not rewrite this class**, just point it at a real broker in Phase B. |
| `TradingVenueClientFactory` | `forBot()` and `forProvider()` already branched to `makeMetalBrokerClient()` for `metal_broker`/`metals`. `forIntegration()` was missing that branch and threw for anything but `binance`. | Added the identical branch to `forIntegration()` (`makeMetalBrokerClientFromIntegration()`, decrypts `Integration.secrets_encrypted` via `IntegrationManager`). |
| `LiveTradeProviderRegistry` | `catalog()` was a hardcoded PHP array missing `metal_broker` entirely (so it never appeared in the frontend's data-driven provider list). `for()` was a hardcoded `match` throwing for anything but `binance`. | Catalog now reads `config/live_providers.php` (single source of truth), merging in `BinanceTimedFuturesProvider::capabilities()` for the binance row so live-capability fields stay dynamic. `for()` resolves via the same config map. |
| `config/assets.php` | `classes => [CRYPTO, METALS]`, no FOREX. | Added a `forex` block (EURUSD/GBPUSD/USDJPY placeholders) alongside existing `metals` (XAUUSD/XAGUSD/XCUUSD). |
| `AssetRegistryService` / `AssetController` (`GET /assets`) | Only knew CRYPTO/METALS. `providersFor()` had its own **third** hardcoded provider-to-class map (`'metal_broker'` vs `'binance'`), independent of the registry. | Added FOREX. `providersFor()` now derives from `LiveTradeProviderRegistry::catalog()` — no more parallel hardcoded truth. This endpoint is the frontend's provider catalog source (`useProviderCatalog` hook) — no new route was added. |
| `ConnectionController`/`IntegrationController` | Already accept free-form `provider`/`provider_key` (no allow-list) — no backend validation change needed to add a broker. | Unchanged in Phase A. |
| Frontend provider dropdown | `RobotTradingPage.tsx` already had a working, data-driven Select sourced from `provider_catalog`. `QuickTradePage.tsx` had the same inline pattern duplicated. `AddConnectionModal.tsx` had a **hardcoded local `exchanges` array** (already listing `metal_broker`, but not wired to any catalog). | Extracted into shared `src/components/trading/AssetClassToggle.tsx` + `ProviderSelect.tsx`, backed by `src/hooks/useProviderCatalog.ts` (calls `GET /assets`). `RobotTradingPage`, `QuickTradePage`, and `AddConnectionModal` all now use these instead of their own copies. `FutureTradeBotPage` got the toggle for visual consistency with "Forex" disabled (see below). |

## Why Future Trade Bot's "Forex" option is disabled

`FutureTradeBotService.php` (11k lines) is irreducibly Binance-Futures-specific: leverage, isolated margin, funding-rate assumptions, TradingView Pine webhook payload format, `.P` futures symbol suffix handling. None of that maps onto a forex/CFD broker's execution model without a deliberate rewrite. Rather than fake support or exclude the page from the toggle (breaking UI consistency), it shows the same `AssetClassToggle` as the other two pages with "Forex" disabled and a tooltip: *"Coming soon — futures execution isn't adapted for a forex/CFD broker yet. Use Quick Trade or Robot Trade for Forex."* `CandleDirectionBotService` (Robot Trade) and `TradingMarketService` (Quick Trade) are pure price-math / synthetic-or-live-feed engines already asset-agnostic — they're the real home for a second broker, not Future Trade Bot.

## Known duplication found, not yet consolidated

While wiring this, a **third** parallel "connect a broker" surface was found: `ProviderController` (`app/Http/Controllers/Api/ProviderController.php`, routes `providers/{provider}/connect|status|balances`) creates `ExchangeConnection` rows independently of `ConnectionController`/`IntegrationController`+`Integration`. It already hardcodes an allow-list of `['binance', 'metal_broker']` in `assertSupported()` — a **fourth** copy of the provider-allow-list concept, separate from `LiveTradeProviderRegistry`. It wasn't touched in Phase A (out of scope, and it already works for metal_broker), but it's a real candidate for consolidation into the registry-driven approach — flagged here rather than fixed silently.

## Phase B — once a specific broker + API keys are chosen

1. Populate `services.metal_broker.api_url/api_key/api_secret/mode=live` + real `order_path`/`status_path`/`balances_path`/`price_path` for that broker's REST API in `config/services.php`.
2. **If the chosen broker only offers MetaTrader (MT4/5)** rather than a plain REST API, `MetalBrokerClient` won't fit directly — it needs a bridge/EA-based integration, a different shape entirely. Confirm the broker's API type before starting Phase B.
3. Flip `live_supported: true` in `config/live_providers.php`, populate real symbol lists + pip/price precision in `config/assets.php`.
4. Calibrate SL/TP and drawdown-guard defaults per asset class (pip-based for forex vs. percent-based for crypto — don't reuse Binance-crypto-tuned percentages for a different volatility profile; see the trading-defaults-consistency session's lesson on this).
5. Extend `ConnectionController::syncTradingIntegration()`'s drawdown re-baseline check (currently gated on `capabilities()['live_supported']`) to also fire for the forex provider once it's genuinely live.
6. Consider consolidating `ProviderController` into the same registry-driven pattern (see "Known duplication" above).

## Files touched in Phase A

Backend: `config/live_providers.php` (new), `config/assets.php`, `app/Domain/Trading/Providers/LiveTradeProviderRegistry.php`, `app/Domain/Venue/TradingVenueClientFactory.php`, `app/Domain/Assets/AssetRegistryService.php`, `app/Http/Controllers/Api/AssetController.php`, `tests/Feature/MultiBrokerProviderRegistryTest.php` (new).

Frontend: `src/hooks/useProviderCatalog.ts` (new), `src/components/trading/AssetClassToggle.tsx` (new), `src/components/trading/ProviderSelect.tsx` (new), `src/pages/RobotTradingPage.tsx`, `src/pages/QuickTradePage.tsx`, `src/pages/FutureTradeBotPage.tsx`, `src/components/connections/AddConnectionModal.tsx`, `src/hooks/useQuickTrade.ts` (added `asset_class` to the `provider_catalog` type).
