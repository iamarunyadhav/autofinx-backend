# Hybrid Trading Module

## Overview

The platform now supports two coordinated trading flows on top of the existing wallet, workspace, ledger, settings, and risk infrastructure:

- `Quick Trade`: mobile-style expiry trading with amount, duration, chart type, indicators, signal preview, active deals, and history.
- `Robot Trade`: end-user friendly bot sessions that evaluate indicator consensus, place quick trades, and stop on configured limits.

Both flows reuse the same shared backend services for market data, indicator math, signal preview, wallet posting, ledger posting, settlement, and risk gating.

## Live / Paper Toggle

`trading.trading_mode`, `trading.live_enabled`, `trading.preferred_provider`, and `trading.preferred_integration_id` are now the single execution control point for the quick-trade and robot-trade surfaces.

- `paper` mode uses the deterministic expiry engine and `PaperWalletService`
- `live` mode uses the selected provider integration and live wallet reservation flow
- default provider is `binance`
- current live implementation maps quick-trade expiry to `timed futures` on Binance Futures: open now, close automatically when the selected duration expires

If the workspace is switched to `live` but no active integration is selected, the UI stays in live mode but blocks order entry until the provider connection is ready.

## Shared Backend Core

Key services under `app/Domain/Trading` are:

- `TimeframeRegistry`: normalizes supported intervals such as `5s`, `15s`, `30s`, `1m`, `5m`, `15m`.
- `TradingModuleSettingsService`: reads defaults and workspace overrides from `settings`.
- `TradingMarketService`: resolves symbols, returns chart candles, returns current quotes, and generates deterministic paper/demo ticks if no live stream is present.
- `SignalPreviewService`: calculates indicator consensus and returns direction, confidence, reasons, and indicator values.
- `PaperWalletService`: resolves the paper wallet, seeds untouched workspaces, and posts wallet + ledger transactions.
- `LiveWalletService`: snapshots provider balances into the existing wallet model, reserves stake, releases stake, and posts live PnL into the shared ledger.
- `LiveTimedTradeService`: opens/closes timed live positions through the provider abstraction and books the underlying `orders` and `trades`.
- `QuickTradeService`: validates and places manual/robot expiry trades, settles expired trades for both paper and live modes, and writes `orders`, `quick_trades`, `trades`, and ledger updates.
- `RobotSessionService`: manages robot session lifecycle, syncs workspace execution mode, enforces session stop conditions, and places trades through `QuickTradeService`.

## Data Model

New tables:

- `quick_trades`
- `robot_sessions`

Additional schema extension:

- `quick_trades` now stores live provider linkage and execution references such as `provider_key`, `integration_id`, `close_order_id`, `entry_trade_id`, `close_trade_id`, `quantity`, `quote_currency`
- `robot_sessions` now stores `provider_key` and `integration_id`

Existing tables reused:

- `orders`
- `trades`
- `wallet_accounts`
- `ledger`
- `ledger_transactions`
- `settings`
- `symbols`
- `market_candles`
- `market_ticks`

## Indicator Support

The indicator engine now supports:

- `SMA`
- `EMA`
- `RSI`
- `Bollinger Bands`
- `MACD`
- `CCI`
- `Stochastic`
- `Heikin Ashi` transform helper

`PredictionChart` was also reframed as a heuristic estimate, not AI.

## API Summary

Quick trade endpoints:

- `GET /api/v1/quick-trade/metadata`
- `GET /api/v1/quick-trade/chart`
- `GET /api/v1/quick-trade/technical-summary`
- `POST /api/v1/quick-trade/place`
- `POST /api/v1/quick-trade/settle-due`
- `GET /api/v1/workspaces/{workspace}/quick-trades/active`
- `GET /api/v1/workspaces/{workspace}/quick-trades/history`

Robot session endpoints:

- `GET /api/v1/workspaces/{workspace}/robot-sessions`
- `POST /api/v1/workspaces/{workspace}/robot-sessions`
- `PATCH /api/v1/robot-sessions/{robotSession}`
- `POST /api/v1/robot-sessions/{robotSession}/start`
- `POST /api/v1/robot-sessions/{robotSession}/pause`
- `POST /api/v1/robot-sessions/{robotSession}/stop`
- `POST /api/v1/robot-sessions/{robotSession}/evaluate`
- `GET /api/v1/robot-sessions/{robotSession}/status`
- `GET /api/v1/robot-sessions/{robotSession}/history`
- `GET /api/v1/robot-sessions/{robotSession}/performance-summary`

## Frontend Surface

New React routes:

- `/trading` for quick trade
- `/deals` for active/history deals
- `/robot-trading` for simplified robot setup
- `/trading/desk` preserves the older trading desk

## Runtime Commands

Apply the schema changes:

```bash
php artisan migrate
```

Run the scheduler for expiry settlement and robot evaluation:

```bash
php artisan schedule:work
```

Run the commands manually when needed:

```bash
php artisan quick-trades:settle --limit=100
php artisan robot-sessions:evaluate --limit=50
```

If the existing live-trading module is enabled, keep the queue worker running as before:

```bash
php artisan queue:work
```

## Frontend Run

From `frontend/sentinel-ai-trader`:

```bash
npm install
npm run dev
```

For production assets:

```bash
npm run build
```

## Focused Tests

The targeted trading tests for this module are:

```bash
php artisan test tests/Unit/Trading/IndicatorsAndSignalPreviewTest.php
php artisan test tests/Feature/Trading/QuickTradeLifecycleTest.php
php artisan test tests/Feature/Trading/RobotSessionStopConditionsTest.php
php artisan test tests/Feature/Trading/QuickTradeLiveLifecycleTest.php
php artisan test tests/Feature/Trading/RobotSessionLiveEvaluationTest.php
```

## Notes

- Paper mode still uses deterministic synthetic data when no upstream feed is present.
- Live mode is currently implemented for `Binance Futures` timed entry/exit. The provider abstraction is in place so other venues can be added later without reworking the quick-trade or robot UI.
- Wallet and ledger writes are shared with the existing platform; there is no separate fake balance store.
