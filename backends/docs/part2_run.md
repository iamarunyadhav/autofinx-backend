# AutoFinX Part 2 — Strategies, Risk, Execution

## What’s new
- Real MA+RSI, Bollinger, Grid strategies
- Risk engine (trade/position limits, daily loss, drawdown, slippage, volatility breaker)
- Paper execution upgrade with fees + idempotency
- Binance/Coinbase adapter skeletons (disabled by default)
- Equity snapshots + kill-switch hook

## Env (backend/.env.example already updated)
```
TRADING_MODE=paper
LIVE_TRADING_ENABLED=false
EXCHANGE_DEFAULT=binance
BINANCE_ENABLED=false
BINANCE_BASE_URL=https://api.binance.com
COINBASE_ENABLED=false
COINBASE_BASE_URL=https://api.coinbase.com
RISK_DEFAULT_MAX_TRADE_PCT=2
RISK_DEFAULT_MAX_POSITION_PCT=10
RISK_DEFAULT_DAILY_LOSS_PCT=5
RISK_DEFAULT_DRAWDOWN_PCT=10
RISK_DEFAULT_SLIPPAGE_BPS=50
RISK_DEFAULT_VOLATILITY_BPS=250
PAPER_FEE_BPS=10
```

## Run loops
```bash
php artisan queue:work
php artisan market:demo-start
php artisan trading:loop-start
```

## Test Binance/Coinbase connectivity (safe)
Only `testConnection`/`fetchTicker` are enabled. Live orders stay disabled unless `LIVE_TRADING_ENABLED=true`.

## Switch mode
- Paper (default): `TRADING_MODE=paper`
- Live: set `TRADING_MODE=live` and `LIVE_TRADING_ENABLED=true` (adapters still enforce disable if false)

## Tests
```bash
php artisan test
```
