# AutoFinx Live Deployment and Auto Bot Runbook

## 1. Scope and honest status

This guide is for the current AutoFinx repo state in this workspace.

If you are deploying specifically to a Linux VPS such as Contabo, also read [`contabo_linux_production_runbook_tanglish.md`](./contabo_linux_production_runbook_tanglish.md).

What is realistically supported now:

- Binance **spot** paper/sandbox flow
- Binance **spot** restricted live flow
- Rule-based / scheduler-based **Auto Bot**
- AI trading with supervised approval / restricted auto controls
- Exit engine with stop-loss / take-profit / trailing / time-stop
- Ops readiness, incidents, runbooks, and health checks

What is **not** ready for unrestricted live use:

- Binance **futures live** unrestricted trading
- "Guaranteed profit" automation
- Fully unattended zero-review live operation

Short version:

- **Spot restricted auto**: yes, if configured correctly
- **AI/manual supervised spot**: yes
- **Futures live**: no
- **Futures testnet groundwork**: yes

---

## 2. Big-picture architecture

AutoFinx is not one single bot process. It is a set of components working together:

1. **Frontend operator console**
   - React app in `frontend/sentinel-ai-trader`
   - used to login, configure workspace, connect Binance, enable bot, monitor trades

2. **Backend API**
   - Laravel app in `backend`
   - stores config, receives commands from UI, validates trades, runs ops logic

3. **Scheduler**
   - runs repeating tasks like bot evaluation, AI decision cycles, order sync, exit checks

4. **Queue workers**
   - process async jobs like signal execution, reconciliation, AI cycles, monitoring

5. **Market data / exchange sync**
   - spot price updates
   - order/fill reconciliation
   - optional Binance user stream websocket consumer

6. **Risk / exit / ops services**
   - kill switch
   - stale feed checks
   - readiness checks
   - stop-loss / take-profit / trailing / time-stop

---

## 3. Important architecture nuance

Current repo has **two Binance credential surfaces**:

- `Exchange Connection`
  - this is what the current spot bot execution path uses
  - create it from the **Connections** UI / API

- `Integration`
  - some newer readiness, ops, and futures groundwork surfaces also look for an active Binance integration
  - create it from the **Integrations** UI / API

For the safest current setup, do **both**:

1. create a Binance `Exchange Connection`
2. create a Binance `Integration`

Until the repo fully unifies those two surfaces, doing both avoids false readiness blockers.

---

## 4. Required infrastructure

Minimum for serious operation:

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL or MariaDB
- Redis recommended
- a process manager for queues / long-running commands
- a real server or VPS with stable time and network

Local development can run with SQLite and no Redis, but live-like operation should use:

- MySQL
- Redis
- persistent queue workers
- cron or scheduler worker

---

## 5. Backend environment variables

Use `backend/.env.example` as the base.

Minimum important variables for spot live preparation:

```env
APP_ENV=production
APP_URL=https://your-api-domain

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=autofinx
DB_USERNAME=autofinx
DB_PASSWORD=strong-password

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

TRADING_MODE=paper
LIVE_TRADING_ENABLED=false
LIVE_TRADING_REQUIRE_2FA=true
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
BINANCE_USER_STREAM_KEEPALIVE_SEC=1800

AI_MODE=ADVISORY
AI_TRADE_PROVIDER=heuristic
OPENAI_API_KEY=
AI_TRADE_OPENAI_API_KEY=

EXCHANGE_SYNC_CRON=*/10 * * * *
LIVE_TRADING_STALE_FEED_THRESHOLD_SEC=60
```

Notes:

- keep `TRADING_MODE=paper` and `LIVE_TRADING_ENABLED=false` until final go-live
- if you do not use OpenAI / Gemini / Anthropic, keep AI on heuristic or advisor only
- do not put raw secrets in docs, screenshots, or logs

---

## 6. Frontend environment

The frontend mainly needs the API base URL.

Practical build-time variable:

```env
VITE_API_BASE_URL=https://your-api-domain/api/v1
```

Optional if you use custom streaming endpoints:

```env
VITE_WS_MARKET_URL=
VITE_SSE_MARKET_URL=
```

---

## 7. First-time install commands

### Backend

From `backend`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan optimize:clear
```

Check migration status:

```powershell
php artisan migrate:status
```

Important:

- in this current workspace, recent migrations were still pending when inspected
- do **not** go live until `migrate:status` shows no required pending migrations

### Frontend

From `frontend/sentinel-ai-trader`:

```powershell
npm install
npm run build
```

Local dev run:

```powershell
npm run dev
```

---

## 8. How to start the application

## Local / development start

### Backend API

From `backend`:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

### Queue worker

From `backend`:

```powershell
php artisan queue:work --queue=execution,reconcile,strategies,assistant,monitoring,billing,risk,ops,analytics,copytrading,default --sleep=1 --tries=1 --timeout=120
```

### Scheduler

For local development:

```powershell
php artisan schedule:work
```

### Optional dedicated Binance user stream consumer

Recommended for better live order/fill awareness:

```powershell
php artisan trading:binance-user-stream --workspace=<workspace-uuid>
```

or:

```powershell
php artisan trading:binance-user-stream --connection=<exchange-connection-uuid>
```

### Frontend

From `frontend/sentinel-ai-trader`:

```powershell
npm run dev
```

---

## 9. Production-style process layout

Recommended shape:

1. **Web server**
   - Nginx or Apache serves `backend/public`
   - PHP-FPM or equivalent runs Laravel

2. **Frontend**
   - build React app with `npm run build`
   - serve `frontend/sentinel-ai-trader/dist` from static hosting or reverse proxy

3. **Queue worker process**
   - one or more persistent `queue:work` processes

4. **Scheduler**
   - use cron to call Laravel scheduler every minute

5. **Optional user stream process**
   - dedicated Binance websocket consumer

### Recommended cron

Production cron entry:

```cron
* * * * * cd /path/to/auto_finx/backend && php artisan schedule:run >> /dev/null 2>&1
```

Why this is enough:

- Laravel sub-minute scheduling handles `everySecond`, `everyFiveSeconds`, etc. from the scheduler layer
- for local/manual operation, `schedule:work` is easier

### Recommended worker command

```bash
php artisan queue:work --queue=execution,reconcile,strategies,assistant,monitoring,billing,risk,ops,analytics,copytrading,default --sleep=1 --tries=1 --timeout=120 --max-time=3600
```

After deploy:

```bash
php artisan queue:restart
```

---

## 10. What scheduled tasks actually run

These are the important scheduled jobs currently wired in `routes/console.php` and `app/Console/Kernel.php`.

- `connections:sync`
  - every 10 minutes
  - syncs balances / positions from enabled connections

- `strategy:heal`
  - every 10 minutes
  - heals strategies / retries certain state issues

- `orders:poll-status`
  - every 2 minutes
  - polls provider state for pending orders

- `market:ws-consume --once --store-ohlc`
  - every second
  - refreshes live price cache and OHLC snapshots

- `metals:poll-prices --once`
  - every 5 seconds
  - metals price refresh

- `bots:evaluate`
  - every second
  - checks all running bots, but only dispatches a bot when its own `interval_sec` is due

- `robot-sessions:evaluate`
  - every 5 seconds
  - timed-trade / robot-session path

- `quick-trades:settle`
  - every second
  - settles expiry-style quick trades

- `SyncOrderStatusJob`
  - every 10 seconds
  - REST fallback order status sync / fill repair

- `KeepAliveUserStreamsJob`
  - every 30 minutes
  - keeps Binance listen keys alive

- `DetectStaleFeedsJob`
  - every minute
  - stale feed detection and breaker actions

- `RunWithdrawalSchedulesJob`
  - every minute

- `ExpireAiDecisionsJob`
  - every minute

- `DispatchAiDecisionCycleJob`
  - every minute
  - AI decision loop dispatcher

- `RunExitMonitoringJob`
  - every 10 seconds
  - managed position exit engine

- `ops:readiness-check`
  - every minute
  - readiness snapshots + incidents

- `ops:maturity-cycle`
  - every 5 minutes
  - trend snapshots, drift detections, maintenance confidence

---

## 11. Queue names and what they do

Current queue split in the codebase:

- `execution`
  - actual signal/order execution jobs

- `reconcile`
  - order status sync, fills, wallet/order reconciliation, user stream keepalive

- `strategies`
  - bot evaluation, AI recommendation jobs, strategy evaluation

- `assistant`
  - AI decision cycle jobs

- `monitoring`
  - stale feed checks, exit monitoring

- `billing`
  - withdrawal schedule and payment reconciliation

- `risk`
  - portfolio optimizer / risk-heavy async tasks

- `ops`
  - operational repair / ops actions

- `analytics`
  - analytics computations

- `copytrading`
  - copy-trade replication

- `default`
  - any job without explicit queue assignment

For small deployments, one worker can handle all of them.

For better isolation in serious environments:

- worker 1: `execution,reconcile`
- worker 2: `strategies,assistant,monitoring`
- worker 3: `billing,risk,ops,analytics,copytrading,default`

---

## 12. Binance account setup

This is the safest current approach.

### Recommended Binance-side steps

1. Turn on Binance account 2FA.
2. If possible, create a **sub-account** only for the bot.
3. Keep only small capital in that trading account.
4. Create API keys in Binance API Management.
5. Configure **IP whitelist** before enabling trading permission.
6. Enable only the permissions required for spot trading.
7. **Do not enable withdrawals** for the trading key.

Official Binance guidance that supports this approach:

- Binance Spot Test Network FAQ explains how to generate testnet API keys, which testnet endpoints to use, and that testnet has virtual funds only.
  - Source: https://developers.binance.com/docs/binance-spot-api-docs/faqs/testnet
- Binance Academy also recommends **IP whitelisting** for API keys as a security control.
  - Source: https://www.binance.com/en/academy/articles/what-is-an-api-key-and-how-to-use-it-securely

Practical interpretation for AutoFinx:

- use **Spot Testnet first**
- whitelist only your server IP
- do not use your main full-balance account
- do not give withdrawal permission

---

## 13. How to connect Binance inside AutoFinx

## A. Exchange Connection

This is the current practical spot bot execution credential path.

Go to the app and create a Binance connection with:

- `provider = binance`
- `is_testnet = true` for first testing
- API key
- API secret

Then:

1. test the connection
2. keep it `is_active = true`
3. confirm it appears under workspace connections

API path used by UI:

- `POST /api/v1/workspaces/{workspace}/connections`
- `POST /api/v1/connections/{connection}/test`

## B. Integration

Create an active Binance integration too, because readiness and some newer subsystems check it.

Suggested values:

- `type = exchange`
- `provider_key = binance`
- `display_name = Binance`
- `secrets = { api_key, api_secret }`

Keep it `status = active`.

API path:

- `POST /api/v1/integrations`
- `POST /api/v1/integrations/{integration}/health`

---

## 14. Workspace setup before any live trading

Do this in this order.

1. Create / select workspace.
2. Enable app-side 2FA for your operator account.
3. Create Binance `Exchange Connection`.
4. Create Binance `Integration`.
5. Test connection health.
6. Keep system in `paper` / `sandbox` first.
7. Create or update risk profile.
8. Configure AI trading only if you actually need it.
9. Configure exit policy:
   - stop-loss
   - take-profit
   - trailing stop
   - time-stop
10. Run readiness check.

Do not switch to live before those are done.

---

## 15. Trading mode and environment settings

Current trading mode is resolved by:

- environment variables
- workspace `trading` settings group

Key workspace trading settings:

- `trading_mode`
  - `paper` or `live`
- `live_enabled`
  - master live enable
- `venue_environment`
  - `sandbox` or `live`
- `preferred_provider`
  - usually `binance`
- `auto_trading_enabled`
  - master bot automation switch
- `bot_default_interval_seconds`
  - default cycle for new bots

Practical progression:

1. `paper + sandbox`
2. testnet connection
3. bot dry run / paper
4. readiness check
5. switch workspace carefully to live only when approved

---

## 16. Auto Bot ON/OFF behavior

Current Auto Bot behavior in this repo:

- `Auto Trading OFF`
  - bot start is blocked
  - scheduler will pause running bots
  - signal execution for bot automation is cancelled
  - robot sessions are also blocked

- `Auto Trading ON`
  - running bots can be evaluated
  - scheduler checks each bot continuously
  - a bot only evaluates when its own `interval_sec` is due

Important:

- this switch is currently for **bot / robot automation paths**
- AI trading has its own enable/mode controls in AI Trading config

---

## 17. How the Auto Bot flow actually works

If you create a bot with `interval_sec = 3600`, this is the real flow:

1. scheduler fires `bots:evaluate` every second
2. command checks all `RUNNING` bots
3. if 3600 seconds have not passed since `last_evaluated_at`, it skips
4. if due, `EvaluateBotJob` goes to queue `strategies`
5. bot signal logic evaluates market state
6. if no valid signal, no trade
7. if valid signal, `ExecuteSignalJob` goes to queue `execution`
8. live execution guard checks:
   - workspace live mode
   - kill switch
   - risk rules
   - slippage / spread / min-profit guards
   - automation enabled
9. order is sent
10. reconciliation and/or user stream repair the final state
11. exit monitoring handles stop-loss / take-profit / trailing / time-stop

So even though the scheduler heartbeat is frequent, your bot can still be effectively **hourly** because the per-bot interval controls when evaluation is allowed.

---

## 18. Difference between the major trading modules

Do not mix these mentally.

### Auto Bot

- rule-based / bot-style cycle
- current main path for your requested spot auto trading
- can be hourly
- uses `Exchange Connection`

### AI Trading

- supervised AI decision loop
- advisor / manual approval / restricted auto
- more policy-driven than simple bot cycle

### Robot Sessions / Quick Trades

- timed-entry / expiry-style flow
- separate from the spot auto bot path

If your target is:

- "analyze every hour and buy/sell if rule passes"

use **Auto Bot** first.

---

## 19. Pre-live checklist

Before real money, verify all of these:

1. `php artisan migrate:status`
   - no required migrations pending

2. `php artisan test` for your critical slice
   - at minimum bot control, readiness, live execution, exits

3. connection and integration both active

4. app-side 2FA enabled

5. Binance API key:
   - IP whitelisted
   - no withdrawals
   - limited balance

6. workspace trading settings:
   - correct mode
   - `auto_trading_enabled` intentionally set

7. exit policy configured

8. risk profile configured

9. readiness check passes:

```powershell
php artisan ops:readiness-check --workspace=<workspace-uuid>
```

10. optional but recommended user stream consumer is running

11. paper/testnet behavior already observed and understood

---

## 20. Suggested safe rollout sequence

### Phase A: local paper

1. run backend
2. run queue worker
3. run scheduler
4. run frontend
5. create workspace
6. configure bot in paper mode
7. observe monitor/logs

### Phase B: Binance spot testnet

1. create Binance testnet-style connection
2. keep `TRADING_MODE=paper`
3. keep workspace `venue_environment=sandbox`
4. create bot with very conservative settings
5. run user stream consumer if possible
6. verify reconciliation and exits

### Phase C: tiny real-money restricted live

1. switch only one workspace
2. whitelist only 1-2 symbols
3. `max_open_trades = 1`
4. strict stop-loss / time-stop / trailing
5. very small allocation
6. watch first sessions manually

---

## 21. Commands reference

### Install

```powershell
cd backend
composer install
php artisan key:generate
php artisan migrate --force
php artisan optimize:clear

cd ..\frontend\sentinel-ai-trader
npm install
npm run build
```

### Local start

```powershell
cd backend
php artisan serve --host=0.0.0.0 --port=8000
php artisan queue:work --queue=execution,reconcile,strategies,assistant,monitoring,billing,risk,ops,analytics,copytrading,default --sleep=1 --tries=1 --timeout=120
php artisan schedule:work
php artisan trading:binance-user-stream --workspace=<workspace-uuid>
```

```powershell
cd frontend\sentinel-ai-trader
npm run dev
```

### Production deploy

```bash
cd /path/to/auto_finx/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

```bash
cd /path/to/auto_finx/frontend/sentinel-ai-trader
npm ci
npm run build
```

### Manual ops commands

```powershell
php artisan connections:sync --workspace=<workspace-uuid>
php artisan bots:evaluate --bot=<bot-uuid> --limit=1
php artisan ops:readiness-check --workspace=<workspace-uuid>
php artisan ops:maturity-cycle --workspace=<workspace-uuid>
php artisan trading:binance-user-stream --connection=<connection-uuid>
```

---

## 22. Suggested worker layout

### Simple single-worker layout

Use one queue worker:

```bash
php artisan queue:work --queue=execution,reconcile,strategies,assistant,monitoring,billing,risk,ops,analytics,copytrading,default --sleep=1 --tries=1 --timeout=120
```

### Better split layout

Worker A:

```bash
php artisan queue:work --queue=execution,reconcile --sleep=1 --tries=1 --timeout=120
```

Worker B:

```bash
php artisan queue:work --queue=strategies,assistant,monitoring --sleep=1 --tries=1 --timeout=120
```

Worker C:

```bash
php artisan queue:work --queue=billing,risk,ops,analytics,copytrading,default --sleep=1 --tries=1 --timeout=120
```

Reason:

- execution/reconcile stay more responsive
- heavy assistant/ops jobs do not starve live order handling

---

## 23. Recommended bot settings for first live run

Do not start aggressive.

Safer first live setup:

- symbols: `BTCUSDT` only or `BTCUSDT,ETHUSDT`
- interval: `3600`
- `Auto Trading`: ON only when ready
- `is_live`: true only after testnet confidence
- `allocation_mode`: `FIXED`
- small notional only
- stop-loss strict
- take-profit moderate
- trailing enabled
- time-stop enabled
- max open trades: 1
- high confidence only

If you want AI:

- start with `advisor` or `manual approval`
- move to `restricted_auto` only after paper/testnet proof

---

## 24. Troubleshooting

### Bot does not start

Check:

- `Auto Trading` master switch may be OFF
- workspace may still be in paper mode while bot is live
- no active Binance connection attached
- 2FA may be required
- kill switch may be active

### Readiness says blocked

Common reasons:

- active Binance integration missing
- 2FA not confirmed
- AI provider secret missing
- kill switch active
- live/sandbox mismatch

### Orders not executing

Common reasons:

- slippage guard failed
- spread gate failed
- min-profit-after-fees gate failed
- stale data or provider breaker
- execution guard blocked due to risk cap

### Scheduler seems dead

Check:

- cron or `schedule:work` not running
- queue worker not running
- `ops/scheduler-runs` screen in UI

### User stream not updating

Check:

- run dedicated `trading:binance-user-stream`
- connection must be active Binance connection
- `USER_STREAM_INGEST_TOKEN` only matters if you relay events via ingest endpoint

---

## 25. What I would do in your place

For the cleanest live rollout:

1. finish all pending migrations
2. keep futures disabled
3. use Binance **spot** only
4. create both `Exchange Connection` and `Integration`
5. run paper first
6. run spot testnet next
7. only then do tiny real-money restricted live
8. keep user stream consumer running
9. review readiness and incidents daily

---

## 26. Final honest conclusion

AutoFinx can be deployed for **supervised Binance spot automation**.

It is **not** a "switch on and forget profit machine."

It is a guarded trading platform. If you deploy it correctly, the system can:

- analyze on schedule
- generate buy/sell decisions
- execute only when rules and guards pass
- manage exits automatically
- pause when unsafe

That is the correct target for real-money trust.
