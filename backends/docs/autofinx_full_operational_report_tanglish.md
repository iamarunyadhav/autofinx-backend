# AutoFinX Full Operational Report - Tanglish

Date: 2026-04-17  
Scope: Current repo state in this workspace: Laravel backend + Vite React frontend.

## 1. Short answer

Current realistic readiness:

| Area | Readiness | Notes |
| --- | ---: | --- |
| Paper quick trade / paper robot | 80% | UI + backend flow mostly usable. |
| Direct Binance Spot buy/sell | 65% | `/live-execution` UI + `/live/orders` backend flow irukku; prod config and testnet smoke test required. |
| Auto Bot page live spot automation | 60% | `/auto-bot` uses Bot model, ExchangeConnection, scheduler, queues, risk gates. Needs careful setup. |
| Robot Trade live timed futures | 55% | Binance futures timed-trade provider exists; manual quick live is blocked, robot can call service path. Needs testnet and futures account setup. |
| Other exchanges live | 20% | Coinbase/Bybit/KuCoin catalog placeholders; live provider not implemented. |
| Risk / ops / reconciliation | 70% | Good guards exist, but needs live testing and operator discipline. |
| Websocket / realtime | 55% | User stream and market commands exist; frontend can fallback to REST/SSE. Needs daemon setup. |
| Production deployment readiness | 60% | Your current prod env has critical config gaps like `APP_KEY`. |

Overall: 68% for controlled production pilot, 78% for paper/demo, 55% for unattended live auto trading.

## 2. Your current prod issue

You showed two symptoms:

1. `Unexpected token '<', "<!doctype"... is not valid JSON`
2. Network tab `CORS error` / `Failed to fetch`

Root cause:

- Backend `APP_KEY=` blank irundha Laravel `MissingAppKeyException` HTML error page return pannum.
- Frontend JSON expect pannum. HTML starts with `<`, so JSON parse error varum.
- CORS preflight also fail aagum if backend exception, wrong domain, or allowed origins missing.
- Screenshot la request sometimes `https://autofinx.arunyadhav.dev/api/v1/auth/login` ku poguthu. Correct backend is `https://api.autofinx.arunyadhav.dev/api/v1`. Frontend build/env or old browser localStorage stale base issue irundhirukkum.

Immediate prod fix:

```bash
cd /path/to/backend
php artisan key:generate --force
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

Important: `APP_KEY` once production la set pannina piragu change panna koodathu. Existing encrypted Binance keys, 2FA secrets, secrets vault decrypt aagaama pogum.

Backend prod `.env` minimum:

```env
APP_NAME=AutoFinX
APP_ENV=production
APP_KEY=base64:GENERATED_VALUE
APP_DEBUG=false
APP_URL=https://api.autofinx.arunyadhav.dev
APP_TIMEZONE=Asia/Colombo

FRONTEND_URL=https://autofinx.arunyadhav.dev
CORS_ALLOWED_ORIGINS=https://autofinx.arunyadhav.dev

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=autofinx
DB_USERNAME=autofinx_user
DB_PASSWORD=strong_password

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

ENFORCE_2FA_LOGIN=false
ENFORCE_2FA_CRITICAL=true
```

Frontend prod `.env`:

```env
VITE_API_BASE_URL=https://api.autofinx.arunyadhav.dev/api/v1
VITE_WS_MARKET_URL=
VITE_ENCRYPTION_PASSPHRASE=change-this-to-a-secure-random-phrase
```

Browser la old API base stuck irundha:

```js
localStorage.removeItem("autofinx.apiBase")
```

I already patched frontend API base resolver so production la stale localStorage API base ignore pannum.

## 3. Login and 2FA

Seeder credentials:

| Role | Email | Password |
| --- | --- | --- |
| owner | `admin@autofinx.local` | `password` |
| trader | `trader@autofinx.local` | `password` |
| viewer | `viewer@autofinx.local` | `password` |

Why "2 factor code required" varudhu:

- `ENFORCE_2FA_LOGIN=true` irundha backend login expects `code`.
- Current frontend login form email/password mattum send pannuthu. OTP input UI illa.
- If user already TOTP enabled and confirmed, backend also real TOTP code expect pannum.

Current easiest login setup:

```env
ENFORCE_2FA_LOGIN=false
ENFORCE_2FA_CRITICAL=true
TEST_OTP=000000
```

Meaning:

- Login simple email/password.
- Critical actions like live order, integration secret save, bot start, kill switch need confirmed 2FA in production or when `ENFORCE_2FA_CRITICAL=true`.

For real production:

1. Login.
2. Go Settings/Security if available.
3. Setup 2FA QR.
4. Confirm code.
5. Then live critical actions pass.

## 4. Main architecture

AutoFinX is not one single bot. It has these parts:

| Part | Tech | Purpose |
| --- | --- | --- |
| Frontend | Vite React | User login, settings, Binance connect, order/bot UI, monitoring. |
| Backend API | Laravel | Auth, workspace, settings, trading, risk, order execution, ops. |
| Database | SQLite local / MySQL prod recommended | Users, workspaces, orders, trades, positions, settings, integrations. |
| Queue worker | Laravel queue | Execute signals, reconciliation, AI cycles, monitoring jobs. |
| Scheduler | Laravel scheduler + cron | Bot evaluation, settle trades, order sync, feed stale checks. |
| Exchange clients | Binance Spot/Futures code | Price, balances, orders, user stream. |
| Ops/risk layer | Laravel services | Kill switch, stale feed, risk profile, exposure, drawdown, failures. |

## 5. UI feature map

| UI route | Feature | Purpose | Output | Live status |
| --- | --- | --- | --- | --- |
| `/` | Dashboard | Summary view | portfolio/status cards | Mostly monitor/demo |
| `/settings` | Settings Center | Global/workspace trading, risk, AI, billing, security settings | saved settings | Important for live gate |
| `/integrations` | Integrations | Newer exchange integration store | active/error health | Used by direct live spot and Robot Trade live |
| `/connections` | Connections | Vaulted exchange connections | balances/test/sync | Used by Auto Bot live spot |
| `/live-execution` | Execution | Direct live spot order console | order status/fills/events | Binance Spot live path |
| `/trading` | Quick Trade | Manual quick trade | quick trade open/history | Paper only from controller |
| `/robot-trading` | Robot Trade | Quick-trade robot sessions | auto quick trades | Paper strong, live timed futures needs testnet |
| `/auto-bot` | Auto Bot | Scheduler-driven Bot model | signals/orders/logs | Live spot automation path |
| `/scheduler` | Scheduler | Time windows / schedule simulation | schedule decisions | Strategy support |
| `/risk` | Risk | Risk profiles / guardrails | max trade/loss/drawdown | Live guard input |
| `/wallet` | Wallet | Paper/live wallet balances, deposits/withdrawals | accounts/transactions | Needed for sizing |
| `/ops` | Ops | Health, readiness, breakers, kill switch, incidents | ops health | Must monitor in live |
| `/ai-trading` | AI Trading | AI trade proposal / decision cycle | advisory/auto decisions | Needs AI keys if enabled |
| `/futures` | Futures | Groundwork/test page | futures state | Not unrestricted live |
| `/signals` | Signals | Signal/webhook management | signal records | Partial |
| `/copy-trading` | Copy Trading | Follower controls | copy configs | Partial |
| `/billing` | Billing | Gateway topup/checkout | payment intents | Sandbox/stub unless configured |
| `/assistant` | AI Assistant | Chat/tool assistant | replies/actions | Optional |

## 6. Global env vs UI settings vs specific config

Use this rule:

- `.env` = deployment-level hard switches and service URLs.
- `/settings` UI = workspace-level trading behavior.
- `/integrations` or `/connections` UI = per-exchange API credentials.
- Risk profile UI/database = per-workspace/per-bot safety rules.

| Config | Where | Global or specific | Required? | Notes |
| --- | --- | --- | --- | --- |
| `APP_KEY` | backend `.env` | Global | Yes | Must generate once. Do not rotate casually. |
| `APP_URL` | backend `.env` | Global | Yes | `https://api.autofinx.arunyadhav.dev` |
| `FRONTEND_URL` / `CORS_ALLOWED_ORIGINS` | backend `.env` | Global | Yes | Must include `https://autofinx.arunyadhav.dev` |
| `VITE_API_BASE_URL` | frontend `.env` | Global frontend | Yes | Must be backend `/api/v1` URL. |
| `TRADING_MODE` | backend `.env` | Global fallback | Yes | Keep `paper` by default. UI can override workspace. |
| `LIVE_TRADING_ENABLED` | backend `.env` | Global hard gate | Yes for live | If false, no live order should pass. |
| `LIVE_TRADING_SYMBOL_ALLOWLIST` | backend `.env` | Global safety | Yes for live | Example `BTCUSDT,ETHUSDT`. |
| `LIVE_TRADING_MAX_EXPOSURE_PCT` | backend `.env` | Global safety | Yes for live | Blocks oversized exposure. |
| `ENFORCE_2FA_LOGIN` | backend `.env` | Global security | Optional | Current frontend login has no code input, so false is practical. |
| `ENFORCE_2FA_CRITICAL` | backend `.env` | Global security | Yes for live | Critical actions need confirmed 2FA. |
| `BINANCE_API_URL`, `BINANCE_WS_URL` | backend `.env` | Global endpoint | Yes | Defaults okay. |
| `BINANCE_API_KEY`, `BINANCE_API_SECRET` | backend `.env` | Global fallback | Usually no | Prefer UI credentials. |
| Binance API key/secret | `/integrations` | Workspace specific | Yes for direct live / robot timed | Encrypted with `APP_KEY`. |
| Binance API key/secret | `/connections` | Workspace specific | Yes for Auto Bot | Encrypted vault. |
| `trading_mode` | `/settings` group `trading` | Workspace specific | Yes | Must be `live` for live order. |
| `live_enabled` | `/settings` group `trading` | Workspace specific | Yes | Must be true for live order. |
| `venue_environment` | `/settings` group `trading` | Workspace specific | Yes | `sandbox` forces paper in guard; `live` allows live mode. |
| `auto_trading_enabled` | `/settings` group `trading` | Workspace specific | Yes for bot | Master switch for bots. |
| Risk profile | `/risk` | Workspace/bot specific | Yes for live | Controls max trade, drawdown, open orders. |
| `REVERB_*` / `VITE_WS_MARKET_URL` | env | Global realtime | Optional | Leave blank to use fallback unless websocket daemon is running. |
| Billing/PayHere/Stripe/PayPal | env/UI | Global | Optional | Only if wallet payment gateway needed. |
| AI provider keys | env | Global | Optional | Only if OpenAI/Gemini/Claude advisor enabled. |
| Metals/FX env | env | Global | Optional | Only if metals trading enabled. |

## 7. Binance setup - safest flow

Do this order.

### Step 1 - Fix production env

Backend:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://api.autofinx.arunyadhav.dev
FRONTEND_URL=https://autofinx.arunyadhav.dev
CORS_ALLOWED_ORIGINS=https://autofinx.arunyadhav.dev
QUEUE_CONNECTION=database
TRADING_MODE=paper
LIVE_TRADING_ENABLED=false
ENFORCE_2FA_LOGIN=false
ENFORCE_2FA_CRITICAL=true
```

Frontend:

```env
VITE_API_BASE_URL=https://api.autofinx.arunyadhav.dev/api/v1
VITE_WS_MARKET_URL=
```

### Step 2 - Migrate and seed

```bash
php artisan migrate --force
php artisan db:seed --force
```

### Step 3 - Login

Go:

```text
https://autofinx.arunyadhav.dev/auth
```

Use:

```text
admin@autofinx.local
password
```

### Step 4 - Setup 2FA

For live critical actions, owner user should have confirmed 2FA.

### Step 5 - Create Binance credentials in both places

Current repo has two credential surfaces:

1. `/integrations`
   - Used by `/live-execution` direct live spot order.
   - Used by `/robot-trading` live timed futures path.

2. `/connections`
   - Used by `/auto-bot` Bot model live spot automation.
   - Used by Binance user stream and older exchange connection flows.

For current app, create both.

Binance API permissions:

- Spot direct orders need Spot Trading permission.
- Futures timed robot needs Futures permission and futures account enabled.
- Disable withdrawal permission.
- Use IP restriction if possible.

### Step 6 - Health check

Use `/integrations` Ping and `/connections` Test.

Expected:

- status `active`
- balance fetch succeeds
- no `Binance API credentials are missing`
- no timestamp/IP permission error

### Step 7 - Enable live only after test

Backend `.env`:

```env
LIVE_TRADING_ENABLED=true
LIVE_TRADING_SYMBOL_ALLOWLIST=BTCUSDT,ETHUSDT
LIVE_TRADING_MAX_EXPOSURE_PCT=20
```

UI `/settings` -> Trading Core:

```text
Trading mode: Live
Enable live trading: ON
Venue environment: Live
Auto trading: OFF first
```

Then test one tiny direct order in `/live-execution`.

## 8. Direct Binance Spot buy/sell flow

UI:

```text
/live-execution
```

Flow:

1. User selects active Binance integration.
2. User enters symbol, side, order type, quantity, limit price if needed.
3. Frontend posts `POST /api/v1/live/orders`.
4. Backend `RequireTwoFactor` checks critical action.
5. `LiveOrderIntentService` resolves integration.
6. Binance latest price is fetched for reference.
7. `LiveExecutionGuard` runs:
   - workspace mode must be live
   - live switch must be enabled
   - kill switch must be off
   - stale feed breaker must be off
   - symbol allowlist must pass
   - exposure and risk profile must pass
8. Binance symbol rules validate qty/price.
9. Order and execution attempt records are created.
10. `BinanceSpotClient` signs and sends order to Binance.
11. Response is reconciled into order/trade/fill state.
12. UI shows order status, fills, events, anomalies.

Supported direct order types:

- Market
- Limit IOC
- OCO exists in venue client, but current simple UI focuses market/limit.

Failure behavior:

- Stable idempotency key prevents duplicate intent.
- If submit fails after possible exchange acceptance, service fetches by client order id.
- If result cannot be verified, order becomes `UNCERTAIN`.
- Uncertain orders must be manually reconciled before retry.

Important:

- Spot sell requires you already hold the asset.
- Market buy/sell can slip.
- Binance can reject for min qty, min notional, insufficient balance, disabled API permission, IP restriction, or timestamp drift.

## 9. Quick Trade and Robot Trade flow

There are two "quick/robot" concepts.

### Manual Quick Trade - `/trading`

Backend controller blocks live quick trades:

```text
Live quick trades are disabled during Phase 1 execution hardening.
```

So manual Quick Trade is currently paper-focused.

Paper flow:

1. User chooses symbol/timeframe/duration/stake.
2. Backend debits paper wallet stake.
3. Order/quick trade opens.
4. `quick-trades:settle` closes expired trades.
5. Win/loss/draw calculated by entry vs exit price.

### Robot Trade - `/robot-trading`

This uses `RobotSessionService`.

Flow:

1. Create robot session with symbol, timeframe, expiry, stake, confidence threshold.
2. Start session.
3. `robot-sessions:evaluate` runs every 5 sec.
4. It fetches candles and builds signal preview from RSI/MACD/etc.
5. If signal neutral or confidence below threshold, skip.
6. If signal passes, it calls `QuickTradeService`.
7. Paper mode opens paper quick trade.
8. Live mode goes to Binance timed futures provider if integration is active and live gate passes.

Live Robot Trade warning:

- It uses Binance Futures market orders.
- It opens a futures position, then closes at expiry.
- It does not create binary-option payout; PnL is real futures price delta.
- Binance futures account, permissions, margin settings, and account risk must be correct.
- Testnet first is mandatory.

## 10. Auto Bot page flow - `/auto-bot`

This is different from Robot Trade.

Auto Bot uses:

- `Bot` model
- `bots:evaluate` command
- `EvaluateBotJob`
- `TradeSignal`
- `ExecuteSignalJob`
- `LiveSignalExecutionService`
- `ExchangeConnection`

Flow:

1. `/connections` la Binance connection create/test.
2. `/settings` la `auto_trading_enabled=true`.
3. `/auto-bot` la bot create:
   - asset `BTCUSDT`
   - provider `binance`
   - connection id selected
   - `is_live=true`
   - interval seconds, example `3600`
   - strategy params JSON
4. Start bot.
5. `bots:evaluate` scheduler checks interval.
6. `EvaluateBotJob` fetches price.
7. `BotSignalGenerator` evaluates strategy:
   - threshold
   - moving average crossover
   - mean reversion
   - rebalance flip
   - SOTR style exits/reentries
8. If buy/sell signal generated, `ExecuteSignalJob` runs.
9. `LiveSignalExecutionService` checks:
   - bot still running
   - bot is live
   - TTL not expired
   - quantity/budget
   - live execution guard
   - slippage guard
   - spread/profit gate
   - optional AI advisor gate
   - bot risk engine
   - preflight quote revalidation
10. Places order through Binance Spot connection.
11. Reconciles result.
12. Monitor panel shows price, signals, orders, trades, logs.

Auto Bot important limitation:

- If `is_live=false`, current ExecuteSignalJob cancels execution. So `/auto-bot` is mainly live signal execution, not the same as paper quick robot.
- For paper practice, use `/trading` and `/robot-trading`.

## 11. Scheduler, cron, and queue setup

Laravel scheduler must run continuously.

Production cron:

```cron
* * * * * cd /path/to/autofinx-backend/backends && php artisan schedule:run >> /dev/null 2>&1
```

Local/dev alternative:

```bash
php artisan schedule:work
```

Queue worker must also run because many jobs are queued:

```bash
php artisan queue:work --queue=default,strategies,execution,reconciliation,risk,intelligence,notifications,monitoring --tries=3 --timeout=120
```

Recommended Supervisor/systemd in production.

Important scheduled work in current code:

| Job/command | Frequency | Purpose |
| --- | --- | --- |
| `connections:sync` | `EXCHANGE_SYNC_CRON`, default 10 min | Sync exchange connection balances/status. |
| `strategy:heal` | 10 min | Heal strategy state. |
| `orders:poll-status` | 2 min | Poll order status. |
| `market:ws-consume --once --store-ohlc` | every second | Update market ticks/OHLC. |
| `metals:poll-prices --once` | every 5 sec | Update metals prices. |
| `bots:evaluate` | every second | Dispatch due Auto Bot evaluations. |
| `robot-sessions:evaluate` | every 5 sec | Evaluate Robot Trade sessions. |
| `quick-trades:settle` | every second | Settle expired quick trades. |
| `SyncOrderStatusJob` | every 10 sec | Reconcile live orders. |
| `KeepAliveUserStreamsJob` | every 30 min | Keep Binance listen keys alive. |
| `DetectStaleFeedsJob` | every minute | Activate stale feed breaker. |
| `RunExitMonitoringJob` | every 10 sec | Stop-loss/take-profit/trailing/time-stop monitoring. |
| `ops:readiness-check` | every minute | Ops readiness snapshots. |
| `ops:maturity-cycle` | every 5 min | Ops maturity/trends/drift. |

Without scheduler:

- bot will not auto evaluate
- quick trades will not settle
- orders may not reconcile
- stale feed detection may not work
- exit monitor may not protect positions

Without queue worker:

- signals may be created but not executed
- AI/ops/reconciliation jobs may wait forever

## 12. Websocket and realtime

Frontend:

```env
VITE_WS_MARKET_URL=
```

Blank means use fallback path. That is acceptable for now.

Backend has:

- Reverb env placeholders
- Binance user stream start/keepalive/stop routes
- user stream keepalive job
- market consume command
- order polling fallback

If websocket live use panna:

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

Then run Reverb/daemon stack. If not running, keep frontend websocket URL blank and rely on REST polling/order sync.

## 13. Risk handling

Live order is blocked when:

- `LIVE_TRADING_ENABLED=false`
- workspace mode is not live
- workspace live switch is off
- kill switch active
- provider outage breaker active
- stale feed breaker active
- symbol not in allowlist
- latest market tick stale
- exposure exceeds env max
- risk profile max trade/max position/open order limits fail
- duplicate pending order exists
- daily loss/drawdown exceeded
- bot cooldown active
- slippage/spread/profit preflight fails
- signal TTL expired
- 2FA not confirmed for critical action

Risk surfaces:

| Surface | Purpose |
| --- | --- |
| `RiskGuardService` | kill switch, daily loss, drawdown. |
| `SafeLiveGate` | global live switch, stale feed, provider breaker, allowlist, exposure. |
| `WorkspaceLiveRiskService` | max order, duplicate pending, max open positions, notional limits. |
| `BotRiskEngine` | bot daily loss, drawdown, volatility breaker, cooldown, session rules. |
| `ExitMonitoringService` | stop-loss, take-profit, trailing, time-stop orchestration. |
| Ops kill switch | Manual emergency block. |

Important: direct manual spot order records risk defaults but does not magically guarantee stop-loss order unless exit/position state is created and monitored. For protected live positions, ensure exit policy/risk profile is configured and `RunExitMonitoringJob` is running.

## 14. Failure handling

Direct live spot:

- Idempotency key reuse returns existing order.
- ExecutionAttempt record tracks request/response/latency/error.
- Successful exchange ack is reconciled immediately.
- On exception, backend tries to fetch remote order by client order id.
- If remote order found, it repairs local order.
- If not found, marks order `UNCERTAIN` and records anomaly.
- Operator should not retry blindly if uncertain.

Auto Bot execution:

- Signal has TTL.
- Slippage guard checks decision price vs current price.
- Preflight validates price again just before order.
- Order retries with requote based on `max_retries`.
- If not filled within fill timeout, order is synced/cancelled and retried.
- If retries exhausted, signal is cancelled and bot log records error.

Scheduler/ops:

- Order sync job reconciles stale order states.
- Provider health and breakers can stop new live orders.
- Stale feed detector prevents trading on old market data.
- Kill switch can stop all trading.

## 15. Latency and Binance practicality

This app is not HFT.

Current latency characteristics:

- REST signed order path to Binance.
- Binance recvWindow is 5000 ms.
- Signal TTL defaults around 7000 to 8000 ms in bot params.
- Fill timeout default around 3000 ms.
- Requote/retry sleeps around 250 ms.
- Market/OHLC update command scheduled every second.
- Order sync job every 10 sec.
- Direct live order records `latency_ms`.

This is okay for:

- normal spot buy/sell
- low frequency bot cycles
- hourly or minute-level strategies
- supervised live automation

Not okay for:

- sub-second scalping
- high frequency market making
- guaranteed fill assumptions
- high leverage futures automation without deeper risk controls

Binance can handle normal REST buy/sell, but correctness depends on:

- API key permission
- IP whitelist
- server time sync
- symbol filters
- min notional
- account balance/margin
- futures account settings
- network latency
- app scheduler and queue running

## 16. Production checklist

Before real live money:

1. Generate `APP_KEY` once and lock it.
2. Set `APP_DEBUG=false`.
3. Set correct `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`.
4. Deploy frontend with correct `VITE_API_BASE_URL`.
5. Use MySQL/MariaDB in production, not local SQLite for serious live use.
6. Run migrations and seed.
7. Confirm admin login.
8. Setup and confirm 2FA for owner.
9. Create Binance Integration.
10. Create Binance Connection.
11. Ping/test both.
12. Start scheduler.
13. Start queue worker.
14. Confirm `/ops/health` and workspace readiness.
15. Set `TRADING_MODE=paper` first.
16. Test paper Quick Trade and Robot Trade.
17. Use Binance testnet where possible.
18. Enable `LIVE_TRADING_ENABLED=true`.
19. UI Settings: trading mode live, live enabled true.
20. Set allowlist only `BTCUSDT,ETHUSDT` initially.
21. Set small exposure/trade limits.
22. Place tiny direct spot order.
23. Confirm reconciliation and Binance account side.
24. Test cancel/reconcile.
25. Only then start Auto Bot with tiny allocation.

## 17. Which env vars from your pasted file are really needed?

Must fix now:

```env
APP_KEY=base64:...
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.autofinx.arunyadhav.dev
FRONTEND_URL=https://autofinx.arunyadhav.dev
CORS_ALLOWED_ORIGINS=https://autofinx.arunyadhav.dev
QUEUE_CONNECTION=database
TRADING_MODE=paper
LIVE_TRADING_ENABLED=false
ENFORCE_2FA_LOGIN=false
ENFORCE_2FA_CRITICAL=true
```

Needed for live Binance:

```env
LIVE_TRADING_ENABLED=true
LIVE_TRADING_SYMBOL_ALLOWLIST=BTCUSDT,ETHUSDT
LIVE_TRADING_MAX_EXPOSURE_PCT=20
LIVE_TRADING_MAX_TRADE_PCT=2
BINANCE_ENABLED=true
BINANCE_API_URL=https://api.binance.com
BINANCE_WS_URL=wss://stream.binance.com:9443
BINANCE_TESTNET_API_URL=https://testnet.binance.vision
BINANCE_TESTNET_WS_URL=wss://testnet.binance.vision
BINANCE_FUTURES_API_URL=https://fapi.binance.com
BINANCE_FUTURES_TESTNET_API_URL=https://testnet.binancefuture.com
```

Usually leave blank because UI stores per-workspace keys:

```env
BINANCE_API_KEY=
BINANCE_API_SECRET=
```

Optional unless feature used:

- Stripe/PayPal/Payoneer/PayHere vars: only billing.
- AI provider keys: only AI advisor.
- Reverb vars: only websocket daemon.
- Metals vars: only metals broker.
- FX vars: only live FX rates.
- AWS vars: only S3/file storage.

## 18. Best current live strategy

Recommended safe pilot:

1. Use `/live-execution` first for manual Binance Spot tiny orders.
2. Keep `/auto-bot` disabled until direct spot orders reconcile correctly.
3. Start `/auto-bot` only with:
   - one symbol
   - tiny allocation
   - long interval, example 3600 sec
   - max retries 1 or 2
   - strict slippage
   - kill switch tested
4. Avoid Robot Trade live futures until futures testnet fully passes.
5. Do not enable unsupported exchanges for live.

## 19. Can the last auto bot concept work?

Yes, but in restricted supervised form.

What can work:

- Auto bot checks market periodically.
- It generates buy/sell signal.
- It applies risk and slippage gates.
- It places Binance Spot live order through connection.
- It logs and reconciles result.
- It can be stopped via pause/stop/emergency stop/kill switch.

What is not guaranteed:

- Profit.
- Fill at exact price.
- Zero latency.
- Safe unattended futures.
- Correct behavior if scheduler/queue stops.
- Live execution if Binance account/API permissions are wrong.

Best answer: auto bot concept is technically present, but run it like a supervised production pilot, not like a fully autonomous money machine.

## 20. Final current status

The app has enough structure for a controlled Binance Spot pilot after configuration. The biggest blockers in your current prod are not "feature missing" only. They are deployment/config blockers:

- blank `APP_KEY`
- CORS/API domain mismatch
- frontend auth missing 2FA code input while `ENFORCE_2FA_LOGIN=true`
- live credentials split between `/integrations` and `/connections`
- scheduler and queue must run
- live/testnet mode needs careful separation

After those are fixed, direct live spot buy/sell can be tested. Auto Bot can be tested after direct order path is proven.
