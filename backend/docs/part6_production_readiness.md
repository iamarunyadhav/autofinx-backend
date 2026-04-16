# AutoFinX — Part 6 Production Readiness (Milestone 1)

This milestone delivers foundations for live readiness gating, optimizer stubs, advanced risk/event logging, and monitoring scaffolds. Remaining scope (full optimizer logic, analytics UI, kill-switch automation, reconciliation loops) can be added incrementally next.

## What’s included (milestone 2)
- Migrations for promotion/optimizer/risk/event store/execution attempts/market feed status.
- Models: PromotionRequest, PromotionApproval, OptimizerRun, RiskEvent, EventStore, ExecutionAttempt, MarketFeedStatus.
- Services:
  - LivePromotionService (paper→live gating workflow, 2FA-gated endpoints)
  - PortfolioOptimizerService (equal-weight stub)
  - AdvancedRiskGuard (risk event logging)
  - EventStoreService (append-only dedupe by hash)
  - ForecastService (EMA-based stub)
- Jobs/Commands:
  - RunPortfolioOptimizerJob
  - DetectStaleFeedsJob + `ops:stale-feed-check`
  - ReconcileOrdersJob + `ops:reconcile-orders`
- API:
  - `GET /api/v1/live/promotion/status`
  - `POST /api/v1/live/promotion/request` (2FA middleware)
  - `POST /api/v1/live/promotion/approve` (2FA middleware)
- Docs update: this file.

## Migrations
Run:
```bash
php artisan migrate
```

## Commands (existing/new)
- `php artisan venues:seed-defaults` (previous milestone)
- `php artisan ops:stale-feed-check --once` (or loop)
- `php artisan ops:reconcile-orders --once` (or loop)
- `php artisan analytics:recompute {workspace}`
- `php artisan market:backfill {symbol} [--from=] [--to=]`
- Upcoming (planned): trading:promote-live, optimizer loops, ops health, etc.

## Next steps (planned incremental)
1) Finalize optimizer strategies (risk parity, bounded Kelly, drawdown-constrained) wiring into execution sizing.
2) Kill-switch automation and link stale-feed detector + reconciliation into ops UI and notifications.
3) Analytics dashboards + export and profitability metrics per strategy/venue/symbol.
4) Market replay/event-store visualization.
5) Frontend pages for promotion/ops/analytics/optimizer + Playwright/Vitest coverage.
