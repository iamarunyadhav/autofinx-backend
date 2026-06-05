# TradingView Webhook Alert Format

## What this is

The exact payload format your TradingView alert must send to AutoFinX. The
backend rejects payloads that do not match, and TradingView itself silently
swaps in default text if your alert message is not valid JSON.

## TradingView side

### 1. Alert message field

In the TradingView alert dialog, set the **Message** field to **exactly**:

```text
{{strategy.order.alert_message}}
```

That tells TradingView to use the JSON your Pine script emits in
`strategy.entry(... , alert_message = ...)` / `strategy.exit(... , alert_message = ...)`.

### 2. Webhook URL

```text
https://YOUR_DOMAIN/api/v1/future-trade/pine-webhook/YOUR_WORKSPACE_ID
```

The workspace ID is the same value you see in the URL on the Future Trade Bot
dashboard.

## Pine script side

Your Pine v6 strategy must construct a JSON alert message like this and pass
it to `strategy.entry` / `strategy.exit` via the `alert_message` parameter.
The reference implementation is at
[`docs/tradingview/autofinx_prime_v6_1_7_crypto_stable_default.pine`](./autofinx_prime_v6_1_7_crypto_stable_default.pine).

### Required JSON shape (executable BUY / SELL)

```json
{
  "token": "YOUR_PINE_WEBHOOK_TOKEN",
  "profile": "autofinx_prime_v6_1_7_crypto",
  "source": "pine_webhook",
  "symbol": "{{ticker}}",
  "timeframe": "{{interval}}",
  "signal": "SELL",
  "price": 2096.44,
  "confidence": 83,
  "long_score": 21,
  "short_score": 83,
  "bar_confirmed": true,
  "candle_open_time": "{{time}}",
  "strategy_order_id": "{{strategy.order.id}}",
  "strategy_version": "v6.1.7"
}
```

### Required JSON shape (CLOSE)

```json
{
  "token": "YOUR_PINE_WEBHOOK_TOKEN",
  "profile": "autofinx_prime_v6_1_7_crypto",
  "source": "pine_webhook",
  "symbol": "{{ticker}}",
  "timeframe": "{{interval}}",
  "signal": "CLOSE_LONG",
  "price": 2096.44,
  "bar_confirmed": true,
  "candle_open_time": "{{time}}",
  "strategy_order_id": "{{strategy.order.id}}",
  "strategy_version": "v6.1.7"
}
```

`signal` for close events must be one of: `CLOSE`, `CLOSE_LONG`, `CLOSE_SHORT`,
`EXIT_LONG`, `EXIT_SHORT`, `FLAT_LONG`, `FLAT_SHORT`. `CLOSE` on its own infers
side from the open position.

### Required fields — backend will reject otherwise

| Field | Why |
|---|---|
| `token` | Must match `FUTURE_TRADE_PINE_WEBHOOK_TOKEN` env. |
| `profile` | Must equal `autofinx_prime_v6_1_7_crypto`. |
| `source` | Must equal `pine_webhook`. |
| `symbol` | `XYZUSDT.P` is auto-normalised to `XYZUSDT`. By default, all Binance-style crypto futures symbols ending in `USDT` or `USDC` are accepted; set `restrictToWorkspaceSymbols=true` to force the curated workspace list only. |
| `timeframe` | Must equal `15m` (or `15`). Other timeframes are rejected. |
| `signal` | `BUY` / `SELL` / `LONG` / `SHORT` / `WAIT` / `CLOSE*`. |
| `price` | Must be > 0. |
| `bar_confirmed` | Must be `true` (we do **not** trade unconfirmed bars). |
| `candle_open_time` | ISO-8601 or millisecond timestamp. Used for idempotency. |

### All-symbol Pine mode

For the v9.8 All Symbols Pine script, keep this gate:

```pine
symbolOk = isCrypto and (str.endswith(tickerNorm, "USDT") or str.endswith(tickerNorm, "USDC"))
```

The backend default that matches this mode is:

```text
FUTURE_TRADE_PERMISSIVE_SYMBOL_MODE=true
restrictToWorkspaceSymbols=false
permissiveSymbolMode=true
```

That means examples like `ETHUSDT.P`, `XRPUSDT.P`, `DOGEUSDT.P`,
`1000PEPEUSDT.P`, and `BTCUSDC.P` are accepted after normalization, subject to
the usual bot guards such as max open positions, cooldown, confidence, daily
loss, and live-trading safety.

### Optional fields

| Field | Purpose |
|---|---|
| `strategy_order_id` | Per-order ID from Pine. Used to dedupe TradingView retries. |
| `confidence` | 0–100 or 0–1; backend normalises. |
| `long_score`, `short_score` | Diagnostics, surfaced in the UI. |
| `tp`, `sl`, `trailing`, `rollback` | Risk overrides — **ignored unless** `useWebhookRiskOverrides` is enabled. |
| `change_percent`, `volume`, `range_percent`, `range_position` | Diagnostics. |
| `reason` | Free-text reason shown in the log. |

## Verifying what TradingView actually sends

If a webhook is failing, the backend has captured the raw payload — open the
**Webhook Command Center** card on the Future Trade Bot page. The "Last
TradingView Webhook Payload" panel shows:

- `Token valid: yes/no` — wrong token? Re-check your alert.
- `JSON valid: yes/no` — TradingView silently inserted default text? Fix the
  alert message field.
- `Bar confirmed: yes/no` — Pine fired pre-confirmation; only confirmed bars
  execute.
- `Rejected reason` — exact backend code (e.g. `timeframe_must_be_15m`).
- Collapsible raw body preview — full sanitized payload, with tokens masked.

You can also query the same payload via API:

```bash
curl -s https://YOUR_DOMAIN/api/v1/workspaces/YOUR_WORKSPACE_ID/future-trade/webhook/last | jq
```

## Manual test (no TradingView needed)

```bash
curl -X POST "https://YOUR_DOMAIN/api/v1/future-trade/pine-webhook/YOUR_WORKSPACE_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "YOUR_PINE_WEBHOOK_TOKEN",
    "profile": "autofinx_prime_v6_1_7_crypto",
    "source": "pine_webhook",
    "symbol": "ETHUSDT",
    "timeframe": "15m",
    "signal": "SELL",
    "price": 2096.44,
    "confidence": 83,
    "bar_confirmed": true,
    "candle_open_time": "2026-05-26T07:30:00Z"
  }'
```

Expected response:

```json
{
  "accepted": true,
  "executed": true,
  "decision": "SELL",
  "source": "pine_webhook",
  "execution": "executed",
  "latency": {
    "totalMs": 320,
    "validatedMs": 8,
    "stateSyncedMs": 290,
    "orderPlacedMs": 320
  }
}
```

If `latency.totalMs` is consistently above 800ms, the backend will log a
`WEBHOOK_LATENCY_BREAKDOWN` event so you can see where time was lost.

## Duplicate protection

The backend uses two layers of duplicate protection — TradingView resending
the same alert (e.g. on network retry) will never place a second order:

1. **Signal-key idempotency.** Derived from `profile + symbol + signal +
   timeframe + candle_open_time + strategy_order_id`. A second arrival in the
   same candle returns `execution: "duplicate_ignored"`.
2. **Deterministic Binance `newClientOrderId`.** Even if the signal-key check
   is somehow bypassed, Binance itself rejects orders with the same
   `clientOrderId`.

## Daily-loss safety tiers

The Webhook Command Center shows the live daily-loss tier:

| Daily loss | Behaviour |
|---|---|
| < 0.30% | All trades allowed. |
| ≥ 0.30% | Soft warning logged (`DAILY_LOSS_WARN`). Trades still allowed. |
| ≥ 0.50% | New entries blocked. Close webhooks still execute. |
| ≥ 0.80% | All webhook-driven trades blocked. Automated SL/trailing/exit-monitor still runs to protect open positions. |

Thresholds are configurable on the bot settings:
`softDailyLossWarnPercent`, `maxDailyLossPercent`, `hardDailyLossBlockPercent`.

## Cooldown bypass on reversal

When a position closes, a cooldown (default 180s) blocks new same-side entries
to prevent stop-out churn. **Opposite-direction Pine webhooks bypass this
cooldown** — TradingView calling a fresh reversal is a legitimate move signal
and we don't want the operator to miss it.

| Position just closed | Next Pine signal | Outcome |
|---|---|---|
| LONG | SELL | Cooldown bypassed — opens SHORT |
| LONG | BUY | Cooldown respected — waits |
| SHORT | BUY | Cooldown bypassed — opens LONG |
| SHORT | SELL | Cooldown respected — waits |
