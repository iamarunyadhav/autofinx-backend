# AutoFinX — Part 5 (Advanced Profit Engine) — Milestone Plan

This milestone only delivers the foundations (venues schema, router scaffold, seed command, basic API). Next milestones will add arbitrage/staking/defi engines, custom strategy builder, UI, and tests.

## What’s in this drop
- New tables: venues, venue_connections, order_routes.
- Models: Venue, VenueConnection, OrderRoute.
- Services: VenueManager (seed + test), SmartOrderRouter (best-ask fallback).
- Command: `php artisan venues:seed-defaults` (seeds demo/binance/coinbase/bybit/kucoin/broker_stub).
- API: GET /venues (public), venue connections CRUD/test/toggle (auth).

## Migrations
Run:
```bash
php artisan migrate
php artisan venues:seed-defaults
```

## Next milestones (recommended)
1) Add arbitrage configs/cycles tables + engine + job + command.
2) Add staking_vaults/positions + accrual job.
3) Add defi_protocols/positions + simulation job.
4) Extend SmartOrderRouter with live quotes + latency + fees + risk tie-in.
5) Custom strategy builder DSL + paper-mode executor.
6) Frontend pages/components + Playwright/Vitest coverage.
