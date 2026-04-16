# AutoFinX Part 7 — Phase 1 Backend Foundations

What’s included
- New tables: settings, market_regimes, strategy_performance_snapshots, copy_trading_profiles, copy_trade_replications, signals, api_tokens, webhooks, webhook_deliveries.
- Models added for each of the above.
- Services: SettingsService, SignalPublisherService, WebhookDispatcherService, CopyTradingService.
- Regime/strategy perf storage ready (MarketRegime, StrategyPerformanceSnapshot).
- Settings API (GET /api/v1/settings?group=..., PATCH /api/v1/settings) to move configs out of .env.
- Signals API (latest/history/publish).
- Copy trading profiles CRUD + replication log listing.
- Strategy performance endpoint.

Commands / Migrations
```bash
php artisan migrate
```

API summary (auth required unless noted)
- Settings: GET `/api/v1/settings?group=...&workspace_id=...`; PATCH `/api/v1/settings` with `{workspace_id?, settings: {group: {key: value}}}`
- Signals: GET `/api/v1/signals/latest`; GET `/api/v1/signals/history`; POST `/api/v1/signals/publish`
- Copy trading: GET `/api/v1/copy-trading/profiles`; POST `/api/v1/copy-trading/profiles`; PATCH `/api/v1/copy-trading/profiles/{profile}`; DELETE `/api/v1/copy-trading/profiles/{profile}`; GET `/api/v1/copy-trading/replications`
- Strategy performance: GET `/api/v1/strategies/{instance}/performance`

Notes / next steps
- Regime detection and strategy auto-switch logic to be added next phase.
- Copy trade replication currently logs replication intents; follower order placement/job will follow in next phase.
- API tokens/webhooks tables are present; token generation, webhook subscribe/test endpoints to be added in next phase.
- Frontend settings/copy/signals pages to be added in subsequent milestones.
