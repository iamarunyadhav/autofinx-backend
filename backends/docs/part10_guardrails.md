## Guardrails (Part 10)

Table:
- `guardrails` (per workspace thresholds).

API:
- GET `/api/v1/guardrails?workspace_id=...`
- PATCH `/api/v1/guardrails` (2FA) with fields:
  - max_slippage_pct
  - max_spread_pct
  - max_open_orders
  - loss_streak_cooldown_minutes
  - max_daily_loss_pct
  - trailing_stop_pct
  - take_profit_pct
  - volatility_block_pct
  - circuit_breaker_failures

Recommended starting defaults:
- max_slippage_pct: 1
- max_spread_pct: 1
- trailing_stop_pct: 3
- take_profit_pct: 5
- max_daily_loss_pct: 5
- circuit_breaker_failures: 3
