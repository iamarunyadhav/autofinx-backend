# Realtime Selected-Symbol Ratio Bot

## What changed

- `ratio_bot_configs` now supports realtime selected-symbol spot and Binance USD-M futures testnet settings.
- `market:ws-consume` is now a true Binance market websocket consumer by default.
- `ratio-bots:evaluate` still exists, but websocket updates are now the primary market source.
- `trading:binance-futures-user-stream` consumes futures execution updates.
- `ratio-bots:reconcile` reconciles spot/futures order and position state.

## Required env/config

Set these values in your environment:

```env
BINANCE_API_URL=https://api.binance.com
BINANCE_WS_URL=wss://stream.binance.com:9443
BINANCE_TESTNET_API_URL=https://testnet.binance.vision
BINANCE_TESTNET_WS_URL=wss://testnet.binance.vision
BINANCE_FUTURES_API_URL=https://fapi.binance.com
BINANCE_FUTURES_WS_URL=wss://fstream.binance.com
BINANCE_FUTURES_TESTNET_API_URL=https://testnet.binancefuture.com
BINANCE_FUTURES_TESTNET_WS_URL=wss://stream.binancefuture.com
BINANCE_USER_STREAM_KEEPALIVE_SEC=1800
BINANCE_FUTURES_USER_STREAM_KEEPALIVE_SEC=1800
```

## Migrate

```bash
php artisan migrate
```

## Realtime market feed

Spot:

```bash
php artisan market:ws-consume --market-type=spot --workspace=<workspace-uuid>
```

Futures testnet:

```bash
php artisan market:ws-consume --market-type=futures --workspace=<workspace-uuid> --testnet
```

REST fallback only:

```bash
php artisan market:ws-consume --market-type=spot --workspace=<workspace-uuid> --once --store-ohlc
```

## User streams

Spot user stream:

```bash
php artisan trading:binance-user-stream --workspace=<workspace-uuid>
```

Futures user stream:

```bash
php artisan trading:binance-futures-user-stream --workspace=<workspace-uuid>
```

## Scheduler / queue

```bash
php artisan queue:work
php artisan schedule:work
```

## Example create payloads

Spot realtime ratio bot:

```json
{
  "name": "BTC Spot Realtime",
  "symbol": "BTCUSDT",
  "market_type": "spot",
  "algorithm": "trailing_ratio",
  "timeframe": "1m",
  "side_mode": "buy_sell",
  "evaluation_mode": "hybrid",
  "mode": "restricted_auto",
  "execution_mode": "live",
  "connection_id": "spot-connection-uuid",
  "base_quote_amount": 100,
  "quote_asset_budget": 100,
  "sell_threshold_ratio": 0.7,
  "buy_threshold_ratio": 0.6,
  "cooldown_seconds_after_trade": 45,
  "max_trades_per_day": 25,
  "stop_loss_pct": 0.02,
  "take_profit_pct": 0.03,
  "trigger_mode": "trailing_ratio",
  "strategy_params": {
    "trailing_pct": 0.4
  }
}
```

Spot realtime trailing bot with dynamic thresholds, indicator confirmation, profit lock, and latency guards:

```json
{
  "name": "BTC Adaptive Trailing",
  "symbol": "BTCUSDT",
  "market_type": "spot",
  "algorithm": "trailing_ratio",
  "timeframe": "1m",
  "side_mode": "buy_sell",
  "evaluation_mode": "hybrid",
  "mode": "restricted_auto",
  "execution_mode": "sandbox",
  "connection_id": "spot-connection-uuid",
  "base_quote_amount": 75,
  "quote_asset_budget": 500,
  "sell_threshold_ratio": 0.006,
  "buy_threshold_ratio": -0.006,
  "selling_loss_ratio": 0.0035,
  "buying_loss_ratio": 0.0035,
  "cooldown_seconds_after_trade": 45,
  "max_trades_per_day": 20,
  "max_allowed_slippage_bps": 35,
  "min_net_profit_bps": 12,
  "trigger_mode": "trailing_ratio",
  "strategy_params": {
    "use_dynamic_thresholds": true,
    "use_indicator_confirmation": true,
    "use_trailing_take_profit": true,
    "profit_lock_enabled": true,
    "manual_trailing_take_profit_ratio": 0.003,
    "manual_pullback_ratio": 0.003,
    "manual_rebound_ratio": 0.003,
    "manual_stop_loss_ratio": 0.0045,
    "min_volume_ratio": 0.75,
    "max_spread_ratio": 0.006,
    "min_liquidity_notional": 15000,
    "require_trend_alignment": true,
    "allow_sideways_market": false,
    "max_api_latency_ms": 900
  }
}
```

## Dynamic threshold + indicator runtime notes

- `strategy_params.use_dynamic_thresholds=true`
  This enables ATR / volatility / candle-range / volume / liquidity-aware threshold widening or tightening.
- `strategy_params.use_indicator_confirmation=true`
  This enables RSI / EMA / MACD / ATR / volume-based confirmation before order execution.
- `strategy_params.use_trailing_take_profit=true`
  This exposes trailing profit-lock context to the ratio bot runtime.
- `strategy_params.profit_lock_enabled=true`
  This keeps track of locked profit once trailing logic has armed from a live peak.
- `strategy_params.max_api_latency_ms`
  Blocks fresh entries when REST fallback latency exceeds the allowed threshold.
- `strategy_params.min_volume_ratio`, `max_spread_ratio`, `min_liquidity_notional`
  Control low-volume / spread / liquidity guardrails before execution.

Futures testnet MA crossover bot:

```json
{
  "name": "BTC Futures Testnet",
  "symbol": "BTCUSDT",
  "market_type": "futures",
  "algorithm": "ma_crossover",
  "timeframe": "1m",
  "side_mode": "both",
  "evaluation_mode": "candle_close",
  "mode": "restricted_auto",
  "execution_mode": "live",
  "integration_id": "binance-integration-uuid",
  "quote_asset_budget": 100,
  "leverage": 3,
  "margin_type": "isolated",
  "position_mode": "hedge",
  "reduce_only_exit_orders": true,
  "close_on_opposite_signal": true,
  "allow_reverse_signal": false,
  "max_trades_per_day": 20,
  "max_daily_loss": 50,
  "max_consecutive_losses": 3,
  "strategy_params": {
    "ma_short": 9,
    "ma_long": 21
  }
}
```

## Useful API endpoints

- `GET /api/v1/workspaces/{workspace}/ratio-bots`
- `POST /api/v1/workspaces/{workspace}/ratio-bots`
- `PUT /api/v1/ratio-bots/{ratioBot}`
- `POST /api/v1/ratio-bots/{ratioBot}/start`
- `POST /api/v1/ratio-bots/{ratioBot}/pause`
- `POST /api/v1/ratio-bots/{ratioBot}/resume`
- `POST /api/v1/ratio-bots/{ratioBot}/stop`
- `POST /api/v1/ratio-bots/{ratioBot}/kill-switch`
- `GET /api/v1/ratio-bots/{ratioBot}/status`
- `GET /api/v1/ratio-bots/{ratioBot}/feed-health`
- `GET /api/v1/ratio-bots/{ratioBot}/last-signal`
- `GET /api/v1/ratio-bots/{ratioBot}/last-order`
- `GET /api/v1/ratio-bots/{ratioBot}/position`
- `GET /api/v1/ratio-bots/{ratioBot}/pnl-summary`
- `GET /api/v1/ratio-bots/{ratioBot}/candles`
- `GET /api/v1/ratio-bots/{ratioBot}/snapshot`

## Spot test flow

1. Create a spot bot for `BTCUSDT`.
2. Start `market:ws-consume --market-type=spot`.
3. Start `trading:binance-user-stream`.
4. Start the bot.
5. Confirm `GET /status` returns `feed_status=healthy`.
6. Confirm `GET /candles` returns recent `1m` candles.
7. Confirm `GET /last-signal` updates when the strategy triggers.
8. Confirm `GET /last-order` and `GET /snapshot` reflect execution/fill changes.

## Futures testnet flow

1. Enable workspace futures config in `restricted_testnet_auto` or `review_only`.
2. Create a futures bot for `BTCUSDT`.
3. Start `market:ws-consume --market-type=futures --testnet`.
4. Start `trading:binance-futures-user-stream`.
5. Start the bot.
6. Confirm `GET /status` shows `market_type=futures`.
7. Confirm `GET /position` updates after fills.
8. Confirm reverse/exit signals move through `last-signal`, `last-order`, and `snapshot`.

## Known limitations

- Spot user-stream health still uses the existing connection-based breaker model.
- Futures position state is updated from order events and sync; full hedge-mode multi-leg accounting is still simplified in the bot runtime state.
- `ratio_bot_states.position_state` remains a legacy flat/holding state machine; futures side detail is exposed through `last_position_side` and `last_position_qty`.
