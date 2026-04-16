## Arbitrage (Part 10)

Tables:
- `arbitrage_opportunities` extended with type, legs_json, fee/slippage estimates, captured_at.

API:
- GET `/api/v1/arbitrage/opportunities?workspace_id=...`
- POST `/api/v1/profit/arbitrage/run-once` (paper) remains available.

Notes:
- Profit after fees/slippage should be checked before execution (future wiring).
- Keep module mode set via `modules` API for live gating.
