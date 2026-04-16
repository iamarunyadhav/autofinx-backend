# AutoFinX Production Runbook (Strict PASS/FAIL)

This runbook validates the live path end-to-end for Crypto + Metals with failover guard, reconciliation, sweep bridge, and withdrawals.

## 1) Prerequisites

- `APP_ENV=production` (or staging-like env for rehearsal)
- Trading env guard configured:
  - `TRADING_MODE=paper|live`
  - `LIVE_TRADING_ENABLED=true` for live
- Queue + scheduler up:
  - `php artisan horizon`
  - `php artisan schedule:work`
- Exchange/provider credentials configured in settings center.

## 2) Bootstrap Commands

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan horizon
php artisan schedule:work
php artisan trading:health-check --workspace=<workspace_uuid>
```

Start user stream consumer (Binance):

```bash
php artisan trading:binance-user-stream --workspace=<workspace_uuid>
```

## 3) PASS/FAIL Checklist

1. Deposit webhook credits cash wallet.
2. Bot can start in selected mode (paper/live) without guard mismatch.
3. Signal generated and `trace_id` logged.
4. Preflight revalidation blocks stale signal when trigger/slippage/spread invalid.
5. Valid signal places order and reconciliation updates order/fill/trade/position.
6. SELL fill dispatches provider-to-cash sweep run (if active rule exists).
7. Cash wallet increases after sweep and ledger has `SWEEP_IN/SWEEP_OUT`.
8. Withdraw request can be completed from cash wallet.
9. Auto-withdraw schedule runs and payout record is created.
10. Metals endpoint shows USD + LKR price and FX snapshot.

## 4) API Smoke Steps

### Deposit / Withdraw

- `POST /api/v1/wallet/deposit/intent`
- `POST /api/v1/wallet/deposit/webhook`
- `POST /api/v1/wallet/withdraw/request`
- `POST /api/v1/wallet/withdraw/webhook`

### Bot Control

- `POST /api/v1/workspaces/{workspace}/bots`
- `POST /api/v1/bots/{id}/start`
- `GET  /api/v1/bots/{id}/monitor`
- `POST /api/v1/bots/{id}/emergency-stop`

### Sweep Bridge

- `POST /api/v1/wallet/sweep/rules`
- `GET  /api/v1/wallet/sweep/rules`
- `POST /api/v1/wallet/sweep/run`
- `GET  /api/v1/wallet/sweep/runs`

### AI Recommendation

- `GET  /api/v1/bots/{id}/ai/recommendations`
- `POST /api/v1/bots/{id}/ai/apply-recommendation`

### Metals / FX

- `GET /api/v1/metals/rates?quote=LKR`
- `GET /api/v1/market/fx?base=USD&quote=LKR`

## 5) Strict Script

Use:

```bash
php scripts/strict_runbook.php
```

Interpretation:

- `PASS` item: validated end-to-end.
- `FAIL` item: inspect `details` and bot logs by `trace_id`.

## 6) Common Failure Causes

- IOC unsupported for metals provider (`METAL_BROKER_SUPPORTS_IOC=false`) and no synthetic fill.
- Mode mismatch (`TRADING_MODE` vs workspace settings).
- Missing active provider connection for live bot.
- No active sweep rules for provider/currency after SELL fill.
- Webhook signature/token mismatch.
