<?php

namespace Tests\Feature\RatioBot;

use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\SchedulerRun;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Runs under Asia/Colombo like production, so a 5.5h shift would show up as FAILs. */
class RatioBotsVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $previousTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTz = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Colombo', 'services.live_trading_enabled' => true]);
        date_default_timezone_set('Asia/Colombo');
        $this->travelTo(now()->startOfSecond());

        foreach (['ratio-bots:evaluate', 'ratio-bots:reconcile'] as $command) {
            SchedulerRun::create(['id' => (string) Str::uuid(), 'command' => $command, 'started_at' => now()->subSeconds(30), 'status' => 'completed']);
        }
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
        parent::tearDown();
    }

    private function healthyLiveBot(): RatioBotConfig
    {
        $workspace = Workspace::factory()->create();
        $bot = RatioBotConfig::create(['workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'name' => 'ETH live',
            'market_type' => 'spot', 'execution_mode' => 'live', 'mode' => 'restricted_auto', 'enabled' => true,
            'timeframe' => '1m', 'sell_threshold_ratio' => 0.02, 'buy_threshold_ratio' => 0.01]);
        RatioBotState::create(['ratio_bot_config_id' => $bot->id, 'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 3200, 'last_price_timestamp' => now()->subSeconds(3), 'last_evaluated_at' => now()->subSeconds(3)]);
        $symbol = Symbol::firstOrCreate(['symbol' => 'ETHUSDT'], ['id' => (string) Str::uuid(), 'type' => 'crypto']);
        MarketFeedStatus::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'symbol_id' => $symbol->id,
            'symbol' => 'ETHUSDT', 'exchange' => 'binance', 'market_type' => 'spot', 'last_tick_at' => now()->subSeconds(2),
            'last_price' => 3200, 'source' => 'test', 'status' => 'healthy']);
        $openMs = now()->startOfMinute()->getTimestampMs();
        for ($i = 0; $i < 40; $i++) {
            // Written exactly like the websocket feed: a UTC Carbon from epoch ms.
            MarketOhlc::create(['id' => (string) Str::uuid(), 'exchange' => 'binance', 'symbol' => 'ETHUSDT', 'timeframe' => '1m',
                'ts' => Carbon::createFromTimestampMs($openMs - $i * 60_000), 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
        }

        return $bot;
    }

    public function test_a_healthy_live_bot_passes_every_check(): void
    {
        $bot = $this->healthyLiveBot();

        $exit = Artisan::call('ratio-bots:verify', ['--bot' => $bot->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Live trading gate: an order would pass', $output);
        $this->assertStringContainsString('Stored 1m candles (binance): 40 rows', $output);
        $this->assertStringContainsString('0 FAIL', $output);
    }

    public function test_candles_with_a_future_open_time_fail_and_are_not_taken_as_newest(): void
    {
        $bot = $this->healthyLiveBot();
        // What MySQL ON UPDATE CURRENT_TIMESTAMP did on the +08 server: an
        // updated candle's open time rewritten to the server wall clock.
        MarketOhlc::create(['id' => (string) Str::uuid(), 'exchange' => 'binance', 'symbol' => 'ETHUSDT', 'timeframe' => '1m',
            'ts' => now('UTC')->addHours(8)->setSecond(4), 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);

        $exit = Artisan::call('ratio-bots:verify', ['--bot' => $bot->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Candles with a future open time: 1 corrupted rows', $output);
        $this->assertMatchesRegularExpression('/41 rows, newest opened (\d+)s ago/', $output);
        preg_match('/41 rows, newest opened (\d+)s ago/', $output, $match);
        $this->assertLessThan(120, (int) $match[1]);   // the real latest candle, not the corrupted one 8h ahead
    }

    public function test_a_blocked_bot_with_a_stale_feed_fails(): void
    {
        $bot = $this->healthyLiveBot();
        $bot->state->update(['position_state' => RatioBotState::STATE_BLOCKED, 'block_reason_code' => 'stale_feed',
            'block_reason_detail' => 'Latest market price is stale.', 'last_price_timestamp' => now()->subHours(6)]);
        MarketFeedStatus::query()->update(['last_tick_at' => now()->subMinutes(10)]);

        $exit = Artisan::call('ratio-bots:verify', ['--bot' => $bot->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringContainsString('blocked (stale_feed', $output);
        $this->assertStringContainsString('BLOCKED: Stale feed breaker active for live trading.', $output);
    }
}
