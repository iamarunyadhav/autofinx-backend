<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\FuturesRecoveryReviewService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FuturesRecoveryReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        Queue::fake();
    }

    /** @test */
    public function futures_recovery_is_never_enabled_without_the_liquidation_guard_regardless_of_other_flags(): void
    {
        $config = $this->createConfig([
            'futures_recovery_enabled' => true,
            'futures_recovery_timeout_minutes' => 60,
            'liquidation_guard_enabled' => false,
        ]);

        $this->assertFalse($config->futuresRecoveryEnabled());
    }

    /** @test */
    public function pauses_when_liquidation_guard_danger_level_is_watch_or_critical_even_with_high_confidence(): void
    {
        $config = $this->createConfig(['leverage' => 2]);
        $state = $this->stuckLongState($config);
        $this->seedTrendCandles($config, bullish: true);

        $review = app(FuturesRecoveryReviewService::class)->review($config, $state, 50000.0, [
            'danger_level' => 'watch',
            'liquidation_price' => 45000.0,
        ]);

        $this->assertSame(FuturesRecoveryReviewService::OUTCOME_PAUSE, $review['outcome']);
        $this->assertStringContainsString('danger_level', $review['reason']);
    }

    /** @test */
    public function pauses_when_signal_confidence_for_the_original_direction_has_dropped_even_with_low_liquidation_risk(): void
    {
        $config = $this->createConfig(['leverage' => 2, 'futures_recovery_min_confidence' => 60]);
        $state = $this->stuckLongState($config);
        // Bearish-aligned candles while the position is LONG: confidence for
        // "long" should be pushed well below the 60% minimum.
        $this->seedTrendCandles($config, bullish: false);

        $review = app(FuturesRecoveryReviewService::class)->review($config, $state, 50000.0, [
            'danger_level' => 'safe',
            'liquidation_price' => 25000.0,
        ]);

        $this->assertSame(FuturesRecoveryReviewService::OUTCOME_PAUSE, $review['outcome']);
        $this->assertStringContainsString('confidence', $review['reason']);
    }

    /** @test */
    public function pauses_instead_of_topping_up_again_when_the_one_time_topup_is_already_used(): void
    {
        $config = $this->createConfig(['leverage' => 2]);
        $state = $this->stuckLongState($config);
        $state->update(['futures_recovery_used' => true]);
        $this->seedTrendCandles($config, bullish: true);

        $review = app(FuturesRecoveryReviewService::class)->review($config->fresh(), $state->fresh(), 50000.0, [
            'danger_level' => 'safe',
            'liquidation_price' => 25000.0,
        ]);

        $this->assertSame(FuturesRecoveryReviewService::OUTCOME_PAUSE, $review['outcome']);
        $this->assertStringContainsString('already used', $review['reason']);
    }

    /** @test */
    public function tops_up_once_only_when_liquidation_is_safe_confidence_is_supportive_and_not_yet_used(): void
    {
        $config = $this->createConfig(['leverage' => 2]);
        $state = $this->stuckLongState($config);
        $this->seedTrendCandles($config, bullish: true);

        $review = app(FuturesRecoveryReviewService::class)->review($config, $state, 50000.0, [
            'danger_level' => 'safe',
            'liquidation_price' => 25000.0,
        ]);

        $this->assertSame(FuturesRecoveryReviewService::OUTCOME_TOP_UP_ONCE, $review['outcome']);
        $this->assertArrayHasKey('topup_notional', $review);
        $this->assertGreaterThan(0, $review['topup_notional']);
    }

    private function stuckLongState(RatioBotConfig $config): RatioBotState
    {
        $state = $config->state()->first();
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_position_side' => 'long',
            'last_position_qty' => 1.0,
            'entry_price' => 55000.0,
            'futures_position_opened_at' => now()->subHours(2),
        ]);

        return $state->fresh();
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'timeframe' => '5m',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'liquidation_guard_enabled' => true,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
            'futures_recovery_enabled' => true,
            'futures_recovery_timeout_minutes' => 60,
            'futures_recovery_topup_pct' => 10.0,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }

    private function seedTrendCandles(RatioBotConfig $config, bool $bullish): void
    {
        $price = 50000.0;
        for ($i = 0; $i < 60; $i++) {
            $step = $bullish ? 40.0 : -40.0;
            $open = $price;
            $close = $price + $step;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'ts' => now()->subMinutes((60 - $i) * 5),
                'open' => $open,
                'high' => max($open, $close) * 1.001,
                'low' => min($open, $close) * 0.999,
                'close' => $close,
                'volume' => 500,
            ]);
            $price = $close;
        }
    }
}
