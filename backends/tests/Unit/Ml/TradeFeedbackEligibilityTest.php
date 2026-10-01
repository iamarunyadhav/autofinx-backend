<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\TradeFeedbackEligibility;
use App\Domain\Trading\QuickTradeResearchService;
use App\Models\MlTradeFeedbackTier;
use App\Models\QuickTrade;
use App\Models\Workspace;
use Tests\TestCase;

final class TradeFeedbackEligibilityTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ml.trade_feedback_min_entry_at' => '2026-09-25T13:35:00+05:30']);
        $this->workspace = Workspace::factory()->create();
    }

    private function trade(array $research = [], array $overrides = []): QuickTrade
    {
        return QuickTrade::create(array_merge([
            'workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'side' => 'buy', 'stake_amount' => 100,
            'asset_class' => 'CRYPTO', 'mode' => 'live', 'source' => 'manual_quick_trade', 'timeframe' => '5m',
            'expiry_seconds' => 3600, 'payout_ratio' => 0, 'entry_price' => 100, 'exit_price' => 101, 'profit_loss' => 0.5,
            'status' => 'won', 'fee_source' => 'real', 'entry_at' => '2026-09-26 10:00:00', 'expires_at' => '2026-09-26 11:00:00',
            'settled_at' => '2026-09-26 11:00:00',
            'signal_payload' => ['research' => array_merge([
                'execution_environment' => 'live',
                'input_candles_include_forming_bar' => false,
                'decision_candle_close_at' => '2026-09-26T09:55:00+00:00',
                'trade_decision_prediction' => ['value' => 'BUY', 'available' => true],
            ], $research)],
        ], $overrides));
    }

    private function tier(QuickTrade $trade): array
    {
        return app(TradeFeedbackEligibility::class)->classify($trade);
    }

    public function test_clean_trade_is_full_and_training_eligible(): void
    {
        $trade = $this->trade();
        $this->assertSame(TradeFeedbackEligibility::TIER_FULL, $this->tier($trade)['tier']);
        $report = app(QuickTradeResearchService::class)->report($trade);
        $this->assertTrue($report['data_quality']['training_eligible']);
        $this->assertSame('TIER_FULL', $report['data_quality']['feedback_tier']);
    }

    public function test_forming_bar_trade_can_never_be_eligible(): void
    {
        foreach ([true, null] as $forming) {
            $trade = $this->trade(['input_candles_include_forming_bar' => $forming]);
            $result = $this->tier($trade);
            $this->assertSame(TradeFeedbackEligibility::TIER_COST_ONLY, $result['tier']);
            $report = app(QuickTradeResearchService::class)->report($trade);
            $this->assertFalse($report['data_quality']['training_eligible'], 'forming='.var_export($forming, true));
        }
        // Even if every other rule passes and someone stored a "true" flag elsewhere.
        $trade = $this->trade(['input_candles_include_forming_bar' => true, 'data_quality' => ['training_eligible' => true]]);
        $this->assertFalse(app(QuickTradeResearchService::class)->report($trade)['data_quality']['training_eligible']);
    }

    public function test_excluded_tiers(): void
    {
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade([], ['fee_source' => 'estimated']))['tier']);
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade([], ['fee_source' => 'partial']))['tier']);
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade(['execution_environment' => null]))['tier']);
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade(['execution_environment' => 'testnet']))['tier']);
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade(['decision_candle_close_at' => null]))['tier']);
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade(['trade_decision_prediction' => ['available' => false]]))['tier']);
        $this->assertSame(TradeFeedbackEligibility::TIER_EXCLUDED, $this->tier($this->trade([], ['mode' => 'paper']))['tier']);
    }

    public function test_trade_before_cutoff_is_cost_only(): void
    {
        $result = $this->tier($this->trade([], ['entry_at' => '2026-09-25 07:00:00'])); // 12:30 +05:30, before 13:35
        $this->assertSame(TradeFeedbackEligibility::TIER_COST_ONLY, $result['tier']);
        $this->assertContains('entry_before_trade_feedback_min_entry_at', $result['reasons']);
        $this->assertSame(TradeFeedbackEligibility::TIER_FULL, $this->tier($this->trade([], ['entry_at' => '2026-09-25 08:05:00']))['tier']);
    }

    public function test_classify_command_stores_tier_per_trade_and_reports_counts(): void
    {
        $this->trade();
        $this->trade(['input_candles_include_forming_bar' => true]);
        $this->trade([], ['fee_source' => 'estimated']);

        $this->artisan('ml:trade-feedback:classify')
            ->expectsOutputToContain('Quick Trades classified: 3')
            ->assertSuccessful();

        $this->assertSame(1, MlTradeFeedbackTier::query()->where('tier', 'TIER_FULL')->count());
        $this->assertSame(1, MlTradeFeedbackTier::query()->where('tier', 'TIER_COST_ONLY')->count());
        $this->assertSame(1, MlTradeFeedbackTier::query()->where('tier', 'TIER_EXCLUDED')->count());
        $this->assertSame('manual_quick_trade', MlTradeFeedbackTier::query()->value('decision_source'));
    }
}
