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

class FuturesRecoveryTopupSizingTest extends TestCase
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

    /**
     * @test
     * @dataProvider leverageProvider
     */
    public function sizing_formula_matches_expected_values_at_various_leverages(float $leverage, float $expectedDampening): void
    {
        $config = $this->createConfig(['leverage' => $leverage, 'futures_recovery_topup_pct' => 10.0]);
        $state = $this->positionState($config, qty: 1.0, entryPrice: 50000.0);

        $originalNotional = 1.0 * 50000.0;
        $expected = round($originalNotional * 0.10 * $expectedDampening, 8);

        $this->assertSame($expected, round($config->futuresRecoveryTopupNotional($state), 8));
    }

    public static function leverageProvider(): array
    {
        return [
            '2x -> full dampening (2/2=1.0)' => [2.0, 1.0],
            '4x -> half (2/4=0.5)' => [4.0, 0.5],
            '8x -> quarter (2/8=0.25)' => [8.0, 0.25],
            '16x -> eighth (2/16=0.125)' => [16.0, 0.125],
        ];
    }

    /** @test */
    public function the_hard_thirty_percent_ceiling_is_enforced_even_if_config_requests_more(): void
    {
        $config = $this->createConfig(['leverage' => 2, 'futures_recovery_topup_pct' => 99.0]);
        $state = $this->positionState($config, qty: 1.0, entryPrice: 50000.0);

        // pct is clamped to 30 before dampening (2x leverage => dampening 1.0)
        $expected = round(50000.0 * 0.30, 8);

        $this->assertSame($expected, round($config->futuresRecoveryTopupNotional($state), 8));
    }

    /** @test */
    public function a_topup_that_would_push_the_projected_buffer_into_the_watch_zone_is_refused_with_the_reason_logged(): void
    {
        // High leverage + a large topup pct pushes the projected post-fill
        // buffer well below the watch threshold.
        $config = $this->createConfig([
            'leverage' => 20,
            'futures_recovery_topup_pct' => 30.0,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
        ]);
        $state = $this->positionState($config, qty: 1.0, entryPrice: 50000.0);
        $this->seedTrendCandles($config, bullish: true);

        $review = app(FuturesRecoveryReviewService::class)->review($config, $state, 50500.0, [
            'danger_level' => 'safe',
            'liquidation_price' => 47975.0, // ~5% away at 20x, consistent with an existing isolated position
        ]);

        $this->assertSame(FuturesRecoveryReviewService::OUTCOME_PAUSE, $review['outcome']);
        $this->assertStringContainsString('projected liquidation buffer', $review['reason']);
        $this->assertArrayHasKey('projected_buffer_pct', $review);
    }

    private function positionState(RatioBotConfig $config, float $qty, float $entryPrice): RatioBotState
    {
        $state = $config->state()->first();
        $state->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_position_side' => 'long',
            'last_position_qty' => $qty,
            'entry_price' => $entryPrice,
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
