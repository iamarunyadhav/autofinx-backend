# AutoFinX Part 1 — Scheduler + Demo Trading Loop

## Setup
1) Install deps/migrate/seed:
```bash
cd backend
composer install
php artisan migrate --seed
```
2) Set env defaults (already in `.env.example`):
```
APP_TIMEZONE=Asia/Colombo
DEMO_MODE=true
DEMO_MARKET_ENABLED=true
DEMO_MARKET_SYMBOLS=BTCUSDT,ETHUSDT,AAPL
DEMO_MARKET_TICK_INTERVAL_SECONDS=2
TRADING_LOOP_ENABLED=true
TRADING_LOOP_INTERVAL_SECONDS=300
SCHEDULER_DEFAULT_TIMEZONE=Asia/Colombo
```

## Run loops
```bash
php artisan queue:work      # process jobs
php artisan market:demo-start          # continuous demo ticks
# or one burst:
php artisan market:demo-start --once

php artisan trading:loop-start         # 5m evaluation loop
# or single cycle:
php artisan trading:loop-start --once
```
Stop with Ctrl+C.

## Tests
Run scheduler gating tests:
```bash
php artisan test --filter=ScheduleGateTest
```
Run all tests:
```bash
php artisan test
```
