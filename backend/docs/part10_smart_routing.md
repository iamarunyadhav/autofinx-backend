## Smart Routing (Part 10)

What it does:
- Chooses venues using policy (best_price, fee-aware, latency-aware, balanced).
- Uses venue fee/slippage/latency fields (see `venues` table).

Key API:
- GET `/api/v1/routing/policy?workspace_id=...`
- PATCH `/api/v1/routing/policy` (2FA required) body: `workspace_id, mode, allow_split_orders, max_venues_per_order, min_balance_usd, enabled`

Run commands:
- Keep queue running: `php artisan queue:work`
- (Planning-only; execution logic will be wired in later steps)
