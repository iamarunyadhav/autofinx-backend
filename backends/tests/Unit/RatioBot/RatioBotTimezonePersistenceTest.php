<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Production runs with APP_TIMEZONE=Asia/Colombo. A price timestamp built
 * from epoch milliseconds is a UTC Carbon; persisting it unconverted stored
 * UTC wall time that read back 5.5h old, so every BUY/SELL was blocked as
 * stale_feed and the bot disabled itself.
 */
class RatioBotTimezonePersistenceTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
        parent::tearDown();
    }

    public static function freshTimestamps(): array
    {
        return [
            'epoch milliseconds' => [fn () => now()->getTimestampMs()],
            'UTC carbon (canonical quote / polling)' => [fn () => Carbon::createFromTimestampMs(now()->getTimestampMs())],
            'ISO string with Z' => [fn () => now()->utc()->format('Y-m-d\TH:i:s\Z')],
        ];
    }

    /** @dataProvider freshTimestamps */
    public function test_a_fresh_price_is_not_blocked_as_stale_under_a_non_utc_app_timezone(callable $timestamp): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001', 'symbol' => 'BTCUSDT', 'enabled' => true,
            'sell_threshold_ratio' => 0.02, 'buy_threshold_ratio' => 0.01, 'pause_on_stale_feed' => true,
        ]);
        RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 100, 'last_buy_quantity' => 1, 'active_anchor_price' => 100, 'feed_status' => 'healthy']);

        app(RatioBotStateService::class)->markMarketSnapshot($config, 103, 'test', $timestamp());

        $config = $config->fresh('state');
        $this->assertLessThan(5, abs($config->state->last_price_timestamp->diffInSeconds(now())));

        $decision = RatioBotDecision::create(['ratio_bot_config_id' => $config->id, 'decision' => RatioBotDecision::DECISION_SELL,
            'evaluated_price' => 103, 'anchor_price' => 100, 'computed_ratio' => 0.03, 'threshold_used' => 0.02,
            'position_state_before' => RatioBotState::STATE_HOLDING, 'position_state_after' => RatioBotState::STATE_HOLDING,
            'execution_status' => RatioBotDecision::EXEC_PENDING, 'metadata' => [], 'decided_at' => now()]);
        $block = (new RatioBotRiskService())->preTradeBlockReason($config, $decision, [
            'market_health' => ['latency_ms' => 10, 'price_age_seconds' => 0],
        ]);

        $this->assertNotSame('stale_feed', $block['code'] ?? null);
    }
}
