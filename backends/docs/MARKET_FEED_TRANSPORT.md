# Market feed transport deployment

The websocket daemon now has a bounded transport layer before
`RealtimeMarketFeedService`:

1. every socket frame is decoded immediately;
2. quotes are retained as one latest value per symbol;
3. open klines are retained as one latest value per symbol/timeframe;
4. the bounded map drains every configured interval (100ms by default);
5. closed klines bypass coalescing and enter the existing persistence path;
6. `RealtimeMarketFeedService::ingest()` still applies event-age validation,
   Redis/cache writes, DB-close persistence, breaker handling, and evaluation.

This deliberately stays inside the single feed-owner daemon. Sending every
frame to Redis or Laravel Queue merely moves the unbounded backlog and adds a
network write to the socket hot path. The in-memory map is bounded by active
symbols/timeframes and cannot grow with event count.

## Environment

```dotenv
AUTOFINX_MARKET_TRANSPORT_FLUSH_INTERVAL_MS=100
AUTOFINX_LIVE_QUOTE_WRITE_INTERVAL_MS=100
AUTOFINX_LIVE_OPEN_CANDLE_WRITE_INTERVAL_MS=1000
AUTOFINX_LIVE_FEED_SIDE_EFFECT_INTERVAL_MS=1000
AUTOFINX_LIVE_FEED_MAX_EVENT_LAG_MS=10000
```

Do not increase the event-lag threshold to conceal transport backlog.

## Pre-deployment checks

```bash
php artisan test --compact tests/Unit/Market/MarketTransportCoalescerTest.php
php artisan market:transport-benchmark --events=100000 --symbols=4 --flush-ms=100
php artisan config:show autofinx
```

## Supervisor deployment

No additional process is required. Keep exactly one `market:ws-consume`
owner for each market type. After deploying code and environment changes:

```bash
php artisan config:clear
php artisan config:cache
sudo supervisorctl restart autofinx-market-spot
sudo supervisorctl restart autofinx-market-futures
```

Use the real Supervisor program names if they differ. Do not enable the
Laravel scheduled market consumer while Supervisor owns these feeds.

## Verification

```bash
php artisan market:feed-diagnostics --market-type=spot --timeframe=1m --json
php artisan market:feed-diagnostics --market-type=futures --timeframe=1m --json
```

Verify `transport.buffered` and `transport.max_buffered` remain bounded,
`transport.coalesced` rises during bursts, quote/event ages remain within the
configured gates, and reconnect count is stable.

## Rollback

Deploy the prior code revision, remove
`AUTOFINX_MARKET_TRANSPORT_FLUSH_INTERVAL_MS`, rebuild Laravel's config cache,
and restart only the two market-feed Supervisor programs. No database rollback
is required because this change has no migration or durable schema state.
