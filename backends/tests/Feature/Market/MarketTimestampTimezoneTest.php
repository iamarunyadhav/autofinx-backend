<?php

namespace Tests\Feature\Market;

use App\Domain\Market\RealtimeMarketFeedService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Ops\SafeLiveGate;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production runs with APP_TIMEZONE=Asia/Colombo; the suite otherwise runs in
 * UTC, where none of these 5.5h shifts are visible.
 */
class MarketTimestampTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTz = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Colombo']);
        date_default_timezone_set('Asia/Colombo');
        $this->travelTo(now()->startOfSecond());
        $this->clearRedis();
    }

    protected function tearDown(): void
    {
        $this->clearRedis();
        date_default_timezone_set($this->previousTz);
        parent::tearDown();
    }

    public function test_a_fresh_websocket_tick_does_not_trip_the_live_trading_stale_feed_gate(): void
    {
        $workspace = Workspace::factory()->create();
        $bot = RatioBotConfig::create(['workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'name' => 'ETH',
            'sell_threshold_ratio' => 0.02, 'buy_threshold_ratio' => 0.01, 'execution_mode' => 'live', 'enabled' => true]);
        RatioBotState::create(['ratio_bot_config_id' => $bot->id, 'position_state' => RatioBotState::STATE_FLAT]);

        app(RealtimeMarketFeedService::class)->ingest([
            'stream' => 'ethusdt@trade',
            'data' => ['e' => 'trade', 's' => 'ETHUSDT', 'p' => '3200.25', 'E' => now()->getTimestampMs()],
        ], 'spot', false);

        $row = MarketFeedStatus::query()->where('workspace_id', $workspace->id)->where('symbol', 'ETHUSDT')->firstOrFail();
        $this->assertLessThan(5, $row->last_tick_at->diffInSeconds(now(), true));

        $gate = new SafeLiveGate(app(OperationalBreakerService::class));
        $stale = (new \ReflectionMethod($gate, 'hasStaleSelectedSymbolFeed'))->invoke($gate, (string) $workspace->id, 'ETHUSDT', 'binance', 60);
        $this->assertFalse($stale);
    }

    public function test_a_stale_synthetic_paper_tick_does_not_block_live_trading_but_a_stale_real_feed_does(): void
    {
        config(['services.live_trading_enabled' => true]);
        $workspace = Workspace::factory()->create();
        $symbol = \App\Models\Symbol::firstOrCreate(['symbol' => 'ETHUSDT'], ['id' => (string) Str::uuid(), 'type' => 'crypto']);
        \App\Models\MarketTick::create(['id' => (string) Str::uuid(), 'symbol_id' => $symbol->id, 'price' => 3200, 'bid' => 3199,
            'ask' => 3201, 'volume' => 1, 'occurred_at' => now()->subHours(2), 'metadata' => ['synthetic' => true]]);
        $feed = MarketFeedStatus::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'symbol_id' => $symbol->id,
            'symbol' => 'ETHUSDT', 'exchange' => 'binance', 'market_type' => 'spot', 'last_tick_at' => now()->subSeconds(2),
            'last_price' => 3200, 'source' => 'test', 'status' => 'healthy']);
        $gate = new SafeLiveGate(app(OperationalBreakerService::class));

        $gate->ensureLiveAllowed((string) $workspace->id, 'ETHUSDT', 0);   // no exception

        $feed->update(['last_tick_at' => now()->subMinutes(10)]);
        $this->expectExceptionMessage('Stale feed breaker active for live trading.');
        $gate->ensureLiveAllowed((string) $workspace->id, 'ETHUSDT', 0);
    }

    public function test_market_ohlc_ts_is_stored_as_utc_wall_time_and_read_back_as_the_true_instant(): void
    {
        $openMs = now()->startOfMinute()->getTimestampMs();
        $candle = MarketOhlc::create(['id' => (string) Str::uuid(), 'exchange' => 'binance', 'symbol' => 'BTCUSDT',
            'timeframe' => '1m', 'ts' => Carbon::createFromTimestampMs($openMs), 'open' => 1, 'high' => 1, 'low' => 1,
            'close' => 1, 'volume' => 1]);

        $fresh = $candle->fresh();
        $this->assertSame(Carbon::createFromTimestampMs($openMs, 'UTC')->format('Y-m-d H:i:s'), $fresh->getRawOriginal('ts'));
        $this->assertSame($openMs, $fresh->ts->getTimestampMs());
        $this->assertSame(Carbon::createFromTimestampMs($openMs)->toJSON(), $fresh->toArray()['ts']);

        // An app-timezone Carbon for the same instant lands on the same row key.
        $local = Carbon::createFromTimestampMs($openMs)->setTimezone('Asia/Colombo');
        $candle->update(['ts' => $local]);
        $this->assertSame($openMs, $candle->fresh()->ts->getTimestampMs());
    }

    private function clearRedis(): void
    {
        foreach (['ETHUSDT', 'BTCUSDT'] as $symbol) {
            foreach (["autofinx:market:binance:{$symbol}:quote", "autofinx:market:binance:{$symbol}:health:QUOTE",
                "market:live:{$symbol}", "live-feed:binance:{$symbol}", "price:binance:{$symbol}", "ts:binance:{$symbol}"] as $key) {
                try {
                    Redis::del($key);
                } catch (\Throwable) {
                }
            }
        }
    }
}
