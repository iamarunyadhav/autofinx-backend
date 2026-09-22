<?php

namespace Tests\Unit\Market;

use App\Domain\Market\LiveCandleCacheService;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Models\MarketOhlc;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: RealtimeMarketFeedService::ingest() itself is
 * UNCHANGED by the transport split (per the hotfix's own constraints) — these
 * tests prove that fact by feeding it frames tagged with different lane
 * worker/connection context, exactly as the two real market:ws-consume child
 * processes now do, and confirming quote and candle state stay correctly
 * independent per stream family and per timeframe either way.
 */
class RealtimeMarketFeedServiceLaneIngestionTest extends TestCase
{
    protected function tearDown(): void
    {
        Redis::del('live-candles:binance_futures:LANEUSDT:1m');
        Redis::del('live-candles:binance_futures:LANEUSDT:5m');
        MarketOhlc::query()->where('symbol', 'LANEUSDT')->delete();

        parent::tearDown();
    }

    public function test_public_lane_depth_frame_keeps_quote_fresh_and_never_touches_the_candle_cache(): void
    {
        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('setLatestPrice')->once();
        $feeds = new RealtimeMarketFeedService($prices);
        $eventMs = (int) floor(microtime(true) * 1000);

        $accepted = $feeds->ingest([
            'stream' => 'laneusdt@depth5@500ms',
            'data' => [
                'e' => 'depthUpdate',
                'E' => $eventMs,
                's' => 'LANEUSDT',
                'b' => [['80000.00', '1.0']],
                'a' => [['80001.00', '1.0']],
            ],
        ], 'futures', false, ['worker_id' => 'public-lane-worker', 'connection_id' => 'public-conn']);

        $this->assertTrue($accepted);
        $this->assertNull(Redis::get('live-candles:binance_futures:LANEUSDT:1m'));
    }

    public function test_market_lane_1m_and_5m_open_candles_are_maintained_independently(): void
    {
        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('setLatestPrice')->atLeast()->once();
        $feeds = new RealtimeMarketFeedService($prices);
        $candles = app(LiveCandleCacheService::class);
        $eventMs = (int) floor(microtime(true) * 1000);

        $klinePayload = static fn (string $timeframe, string $close, int $eventMs): array => [
            'stream' => "laneusdt@kline_{$timeframe}",
            'data' => [
                'e' => 'kline',
                'E' => $eventMs,
                's' => 'LANEUSDT',
                'k' => [
                    't' => $eventMs - 10_000,
                    'T' => $eventMs + 50_000,
                    'i' => $timeframe,
                    'o' => '80000',
                    'h' => '80100',
                    'l' => '79900',
                    'c' => $close,
                    'v' => '1',
                    'x' => false,
                ],
            ],
        ];

        $this->assertTrue($feeds->ingest($klinePayload('1m', '80050', $eventMs), 'futures', false, ['worker_id' => 'market-lane-worker', 'connection_id' => 'market-conn']));
        $this->assertTrue($feeds->ingest($klinePayload('5m', '81000', $eventMs), 'futures', false, ['worker_id' => 'market-lane-worker', 'connection_id' => 'market-conn']));

        $oneMinute = $candles->stats('binance_futures', 'LANEUSDT', '1m');
        $fiveMinute = $candles->stats('binance_futures', 'LANEUSDT', '5m');

        $this->assertSame(1, $oneMinute['size']);
        $this->assertSame(1, $fiveMinute['size']);
        $this->assertFalse($oneMinute['latest_is_closed']);
        $this->assertFalse($fiveMinute['latest_is_closed']);

        $oneMinuteRows = $candles->candles('binance_futures', 'LANEUSDT', '1m', 1);
        $fiveMinuteRows = $candles->candles('binance_futures', 'LANEUSDT', '5m', 1);
        $this->assertSame(80050.0, (float) $oneMinuteRows[0]['close']);
        $this->assertSame(81000.0, (float) $fiveMinuteRows[0]['close']);
    }

    public function test_closed_kline_persists_to_market_ohlc_exactly_once_even_if_ingested_twice(): void
    {
        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('setLatestPrice')->atLeast()->once();
        $feeds = new RealtimeMarketFeedService($prices);
        $eventMs = (int) floor(microtime(true) * 1000);
        $openMs = $eventMs - 60_000;

        $payload = [
            'stream' => 'laneusdt@kline_1m',
            'data' => [
                'e' => 'kline',
                'E' => $eventMs,
                's' => 'LANEUSDT',
                'k' => [
                    't' => $openMs,
                    'T' => $eventMs,
                    'i' => '1m',
                    'o' => '80000',
                    'h' => '80200',
                    'l' => '79900',
                    'c' => '80100',
                    'v' => '5',
                    'x' => true,
                ],
            ],
        ];

        // A lane child restarting mid-flush, or the coalescer's closed-kline
        // bypass being hit twice for the same event, must never double-write
        // history -- updateOrCreate() on the unique (exchange,symbol,timeframe,ts)
        // key is what guarantees this.
        $this->assertTrue($feeds->ingest($payload, 'futures', false, ['worker_id' => 'market-lane-worker']));
        $this->assertTrue($feeds->ingest($payload, 'futures', false, ['worker_id' => 'market-lane-worker']));

        $rows = MarketOhlc::query()
            ->where('exchange', 'binance_futures')
            ->where('symbol', 'LANEUSDT')
            ->where('timeframe', '1m')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(80100.0, (float) $rows->first()->close);
    }

    public function test_public_lane_rejected_stale_frame_does_not_corrupt_market_lane_candle_state(): void
    {
        config()->set('autofinx.runtime.live_feed_max_event_lag_ms', 10000);
        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('setLatestPrice')->once(); // only the valid kline frame below
        $feeds = new RealtimeMarketFeedService($prices);
        $eventMs = (int) floor(microtime(true) * 1000);

        // A stale/backlog PUBLIC-lane depth frame is rejected outright.
        $staleAccepted = $feeds->ingest([
            'stream' => 'laneusdt@depth5@500ms',
            'data' => [
                'e' => 'depthUpdate',
                'E' => $eventMs - 60_000,
                's' => 'LANEUSDT',
                'b' => [['80000.00', '1.0']],
                'a' => [['80001.00', '1.0']],
            ],
        ], 'futures', false, ['worker_id' => 'public-lane-worker']);
        $this->assertFalse($staleAccepted);

        // A concurrently-running MARKET-lane kline frame is entirely
        // unaffected by the other lane's rejected frame.
        $klineAccepted = $feeds->ingest([
            'stream' => 'laneusdt@kline_1m',
            'data' => [
                'e' => 'kline',
                'E' => $eventMs,
                's' => 'LANEUSDT',
                'k' => [
                    't' => $eventMs - 10_000,
                    'T' => $eventMs + 50_000,
                    'i' => '1m',
                    'o' => '80000',
                    'h' => '80100',
                    'l' => '79900',
                    'c' => '80050',
                    'v' => '1',
                    'x' => false,
                ],
            ],
        ], 'futures', false, ['worker_id' => 'market-lane-worker']);
        $this->assertTrue($klineAccepted);

        $stats = app(LiveCandleCacheService::class)->stats('binance_futures', 'LANEUSDT', '1m');
        $this->assertSame(1, $stats['size']);
    }
}
