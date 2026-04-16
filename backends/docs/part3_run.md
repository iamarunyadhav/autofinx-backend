# AutoFinX Part 3 — Billing, Webhooks, Live Gate, Assistant

## Billing / Wallet (demo)
- Topup uses gateway router (default payhere sandbox). In demo, checkout URL is simulated.
- Webhook endpoint: `POST /api/v1/billing/webhook/{gateway}` (payhere/paypal/payoneer/stripe). Idempotent via `webhooks_inbox`.
- Reconcile pending payments:
```bash
php artisan billing:reconcile
```

## Live trading safe mode
- Defaults OFF: set `LIVE_TRADING_ENABLED=true` to allow.
- Allowlist: `LIVE_TRADING_SYMBOL_ALLOWLIST=BTCUSDT,ETHUSDT`
- Max exposure: `LIVE_TRADING_MAX_EXPOSURE_PCT`
- Mode toggle requires 2FA in UI and an enabled exchange connection.

## Assistant tools
- Endpoint: `POST /api/v1/assistant/function-call`
- Allowlist via `ASSISTANT_TOOL_ALLOWLIST`
- Mutations audited; RBAC requires workspace member role admin/owner/trader.

## WebSockets / Reverb
- Env template includes REVERB_* values; broadcasting channels:
  - `workspace.{id}.prices`
  - `workspace.{id}.orders`
  - `workspace.{id}.notifications`
  - `workspace.{id}.bot`

## Simulate PayHere webhook
```bash
curl -X POST http://localhost:8000/api/v1/billing/webhook/payhere \
  -H "Idempotency-Key: demo-1" \
  -d "order_id=ph_demo&status_code=2&payhere_amount=10&payhere_currency=USD&workspace_id=demo-ws"
```

## Tests
```bash
php artisan test
```
