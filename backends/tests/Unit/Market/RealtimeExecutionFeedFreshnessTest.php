<?php

namespace Tests\Unit\Market;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\RealtimeMarketFeedService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RealtimeExecutionFeedFreshnessTest extends TestCase
{
    public function test_fresh_depth_quotes_are_coalesced_without_being_marked_stale(): void
    {
        config()->set('autofinx.runtime.live_quote_write_interval_ms', 60_000);
        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('setLatestPrice')->once();
        $feeds = new RealtimeMarketFeedService($prices);
        $eventMs = (int) floor(microtime(true) * 1000);
        $payload = [
            'stream' => 'btcusdt@depth5@500ms',
            'data' => [
                'e' => 'depthUpdate',
                'E' => $eventMs,
                's' => 'BTCUSDT',
                'b' => [['80000.00', '1.0']],
                'a' => [['80001.00', '1.0']],
            ],
        ];

        $this->assertTrue($feeds->ingest($payload, 'futures', false));
        $this->assertTrue($feeds->ingest($payload, 'futures', false));
    }

    public function test_stale_exchange_frame_never_enters_execution_cache_or_candles(): void
    {
        config()->set('autofinx.runtime.live_feed_max_event_lag_ms', 10000);
        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldNotReceive('setLatestPrice');
        $feeds = new RealtimeMarketFeedService($prices);
        $oldEventMs = (int) floor(microtime(true) * 1000) - 60000;

        $accepted = $feeds->ingest([
            'stream' => 'btcusdt@kline_1m',
            'data' => [
                'e' => 'kline',
                'E' => $oldEventMs,
                's' => 'BTCUSDT',
                'k' => [
                    't' => $oldEventMs,
                    'T' => $oldEventMs,
                    'i' => '1m',
                    'o' => '80000',
                    'h' => '80100',
                    'l' => '79900',
                    'c' => '80050',
                    'v' => '1',
                    'x' => false,
                ],
            ],
        ], 'futures', false);

        $this->assertFalse($accepted);
        $this->assertNull(Redis::get('live-candles:binance_futures:BTCUSDT:1m'));
    }

    public function test_recent_redis_write_is_not_fresh_when_exchange_event_was_stale(): void
    {
        config()->set('autofinx.runtime.live_feed_max_event_lag_ms', 10000);
        $key = 'live-feed:binance_futures:STALECHECKUSDT';
        Redis::setex($key, 120, json_encode([
            'exchange' => 'binance_futures',
            'symbol' => 'STALECHECKUSDT',
            'price' => 100,
            'event_timestamp_ms' => (int) floor(microtime(true) * 1000) - 60000,
            'received_at_ms' => (int) floor(microtime(true) * 1000),
            'received_at' => now()->toIso8601String(),
            'market_type' => 'futures',
            'event_lag_ms' => 60000,
        ], JSON_UNESCAPED_SLASHES));

        try {
            $snapshot = app(MarketPriceCacheService::class)
                ->getLiveFeedSnapshot('binance_futures', 'STALECHECKUSDT', 15);

            $this->assertNotNull($snapshot);
            $this->assertSame(0, $snapshot['age_seconds']);
            $this->assertFalse($snapshot['fresh']);
            $this->assertSame(60000, $snapshot['event_lag_ms']);
        } finally {
            Redis::del($key);
        }
    }
}
