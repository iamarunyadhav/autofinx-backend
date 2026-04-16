## Hedge & Rebalance (Part 10)

Tables:
- `portfolio_targets` (targets_json, rebalance interval).
- `execution_plans` (intent can be `rebalance`).

API:
- GET `/api/v1/portfolio/targets?workspace_id=...`
- PATCH `/api/v1/portfolio/targets` (2FA) with `workspace_id`, `targets_json`, `rebalance_interval_minutes`, `enabled`.

Run:
- Keep queue: `php artisan queue:work`
- Future commands (to wire): `php artisan portfolio:rebalance --paper`, `php artisan portfolio:hedge-check --paper`.
