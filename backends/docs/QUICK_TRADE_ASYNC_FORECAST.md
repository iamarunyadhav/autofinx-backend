# Quick Trade background forecasts

The browser requests forecasts with `async: true`. HTTP validates up to 500
candles and returns 202 while Redis queue `predictions` computes the result.
Identical actor/input requests share one pending job. Symbol, exchange,
timeframe, horizon, stake, integration context and candle inputs participate
in the result key. Results expire 20 seconds after request admission, including
queue delay. The browser hides expired results and polls at bounded intervals;
price changes no longer create a new forecast query for every tick.

The original calculation algorithms and synchronous API compatibility remain.
Forecast results are advisory: order submission still performs server-side
price freshness and execution risk checks. Browser candle inputs are not an
authoritative order price. Historical chart candles continue to merge with
live updates by candle open time. Older pages are loaded through the separate
`quick-trade/candle-history` endpoint and remain chart/research data; they are
never used as the execution freshness source.

The current Quick Trade Binance execution path is explicitly Futures. Spot
must remain a separate market context until its own quote, candle, and order
adapter are wired; do not reuse Futures Redis keys for Spot.

## Production worker (install before deploying the frontend)

Run a dedicated Supervisor process under the application user. Example:

```ini
[program:autofinx-predictions]
directory=/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends
command=/usr/local/bin/ea-php82 artisan queue:work redis --queue=predictions --sleep=1 --tries=1 --timeout=20 --max-jobs=500 --max-time=3600 --memory=192
user=zcadmin
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/var/log/autofinx/predictions.log
```

Verify the Redis connection's `retry_after` is greater than 20 seconds.
Apply with `supervisorctl reread`, `supervisorctl update`, then confirm
`supervisorctl status autofinx-predictions`. Restart this worker when deploying
backend calculation changes. No migration or live-feed reset is needed.

Check the browser's forecast request returns 202 followed by 200 and includes
`calculation.symbol`, `exchange`, `timeframe`, `valid_until`. Switch symbols
and timeframes during a calculation: previous results must not render under
the new selection. If workers are unavailable, the UI waits/hides expired
forecasts; it does not fall back to calculating inside the HTTP request.

This change does not prove the Binance transport is low latency. Socket
ingestion lag and live quote freshness must still be measured independently.
