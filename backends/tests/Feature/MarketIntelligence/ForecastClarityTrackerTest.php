<?php

namespace Tests\Feature\MarketIntelligence;

use App\Domain\MarketIntelligence\ForecastClarityTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ForecastClarityTracker (Unified Forecast Engine, Phase 4a)
 * -- the stateful wait/streak bookkeeping wrapper around
 * MultiWindowTrendScoringService::scoreFor()'s own deliberately stateless
 * per-call status. Uses the cache store directly (RefreshDatabase pulled in
 * only for parity with this suite's other tests / a clean array cache per
 * test run).
 */
class ForecastClarityTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_wait_ticks_increment_on_repeated_insufficient_clarity(): void
    {
        $tracker = new ForecastClarityTracker();

        $first = $tracker->track('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);
        $second = $tracker->track('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);
        $third = $tracker->track('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);

        $this->assertSame(1, $first['wait_ticks']);
        $this->assertSame(2, $second['wait_ticks']);
        $this->assertSame(3, $third['wait_ticks']);
        $this->assertFalse($third['max_wait_exceeded']);
    }

    public function test_wait_ticks_reset_to_zero_on_clear(): void
    {
        $tracker = new ForecastClarityTracker();
        $key = ['quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m'];

        $tracker->track(...$key, scoreForResult: ['status' => 'insufficient_clarity']);
        $tracker->track(...$key, scoreForResult: ['status' => 'insufficient_clarity']);
        $this->assertSame(2, $tracker->currentWaitTicks(...$key));

        $cleared = $tracker->track(...$key, scoreForResult: ['status' => 'clear']);

        $this->assertSame(0, $cleared['wait_ticks']);
        $this->assertSame('clear', $cleared['status']);
        $this->assertSame(0, $tracker->currentWaitTicks(...$key));
    }

    public function test_max_wait_ticks_is_respected_and_reported(): void
    {
        $tracker = new ForecastClarityTracker();
        $key = ['quick_trade', 'binance:ADAUSDT:1m', 'ADAUSDT', '1m'];

        $result = null;
        for ($i = 0; $i < 5; $i++) {
            $result = $tracker->track(...$key, scoreForResult: ['status' => 'insufficient_clarity'], maxWaitTicks: 5);
        }

        $this->assertSame(5, $result['wait_ticks']);
        $this->assertTrue($result['max_wait_exceeded']);
        $this->assertSame(5, $result['max_wait_ticks']);
    }

    public function test_two_different_symbols_never_share_wait_streak_state(): void
    {
        $tracker = new ForecastClarityTracker();

        $tracker->track('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);
        $tracker->track('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);
        $tracker->track('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);

        // A different symbol under the same bot type/instance-id family
        // starts completely fresh.
        $other = $tracker->track('quick_trade', 'binance:BTCUSDT:1m', 'BTCUSDT', '1m', ['status' => 'insufficient_clarity']);
        $this->assertSame(1, $other['wait_ticks']);

        // A different bot type for the SAME symbol also starts fresh.
        $otherBotType = $tracker->track('robot_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m', ['status' => 'insufficient_clarity']);
        $this->assertSame(1, $otherBotType['wait_ticks']);

        // The original key's own count is unaffected by either of the above.
        $this->assertSame(3, $tracker->currentWaitTicks('quick_trade', 'binance:ETHUSDT:1m', 'ETHUSDT', '1m'));
    }
}
