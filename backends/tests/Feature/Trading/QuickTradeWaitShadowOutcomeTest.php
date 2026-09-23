<?php

namespace Tests\Feature\Trading;

use App\Jobs\EvaluateQuickTradeForecastOutcomeJob;
use App\Models\QuickTrade;
use App\Models\QuickTradeResearchSample;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Part A4 -- WAIT outcome tracking (shadow).
 *
 * "If a trade HAD been placed in the Direction at this price, what would net
 * P&L be at expiry after fees?" -- recorded through the EXISTING
 * post-settlement research evaluator (EvaluateQuickTradeForecastOutcomeJob),
 * not a new scheduler.
 */
class QuickTradeWaitShadowOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
        config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
        config()->set('quick_trade.fee_model', 'taker');
    }

    private function trade(string $direction, string $decision, float $stake = 100.0): QuickTrade
    {
        $generatedAt = now()->subMinutes(10);

        $trade = QuickTrade::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory()->create()->id,
            'symbol' => 'BTCUSDT', 'side' => 'buy', 'asset_class' => 'CRYPTO',
            'stake_amount' => $stake, 'quantity' => 1, 'mode' => 'live',
            'source' => 'manual_quick_trade', 'provider_key' => 'binance',
            'quote_currency' => 'USDT', 'timeframe' => '1m', 'expiry_seconds' => 300,
            'payout_ratio' => 0, 'entry_price' => 1000, 'exit_price' => 1002,
            'profit_loss' => -0.05, 'gross_pnl' => 0.2, 'fees_total' => 0.25,
            'fee_source' => 'real', 'status' => 'won',
            'entry_at' => $generatedAt, 'expires_at' => $generatedAt->copy()->addMinutes(5),
            'settled_at' => $generatedAt->copy()->addMinutes(5),
            'signal_payload' => [
                'research' => [
                    'execution_environment' => 'live',
                    'captured_at' => $generatedAt->toIso8601String(),
                    'primary' => [
                        'available' => true,
                        'bias' => $direction,
                        'decision_trend' => $decision,
                        'horizon_seconds' => 300,
                        'generated_at' => $generatedAt->toIso8601String(),
                        'reference_price' => 1000.0,
                        'current_price' => 1000.0,
                        'predicted_expiry_price' => 1000.5,
                        'predicted_low' => 999.0,
                        'predicted_high' => 1002.0,
                        'direction' => ['value' => $direction, 'fee_aware' => false,
                            'expiry_score' => 0.4, 'expected_move_pct' => 0.05],
                        'trade_decision' => ['value' => $decision, 'fee_aware' => true,
                            'required_move_pct' => 0.28, 'max_achievable_move_pct' => 0.141,
                            'cost_pct' => 0.14, 'gate_reason' => null, 'fee_model' => 'taker'],
                    ],
                    'direction_prediction' => ['value' => $direction, 'fee_aware' => false, 'available' => true],
                    'trade_decision_prediction' => ['value' => $decision, 'fee_aware' => true,
                        'available' => true, 'fee_model' => 'taker'],
                ],
            ],
        ]);

        return $trade;
    }

    private function sampleAtExpiry(QuickTrade $trade, float $price): void
    {
        $generatedAt = $trade->entry_at;
        // `id` is an auto-increment bigint on this table (see
        // 2026_09_18_150000_create_quick_trade_research_samples), not a uuid.
        QuickTradeResearchSample::create([
            'quick_trade_id' => $trade->id,
            'quote_time_ms' => $generatedAt->copy()->addSeconds(300)->getTimestampMs() + 200,
            'price' => $price,
            'source' => 'test',
            'recorded_at' => now(),
        ]);
    }

    public function test_a_wait_whose_direction_would_have_lost_money_records_the_saved_amount(): void
    {
        $trade = $this->trade('BUY', 'WAIT');
        // Price FELL, so a BUY would have lost: -0.5% gross, minus 0.14% cost.
        $this->sampleAtExpiry($trade, 995.0);

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $shadow = data_get($trade->fresh()->signal_payload, 'research.wait_shadow');
        $this->assertSame('evaluated', $shadow['status']);
        $this->assertSame('BUY', $shadow['direction']);
        $this->assertSame('WAIT', $shadow['trade_decision']);
        $this->assertEqualsWithDelta(-0.5, $shadow['gross_move_pct'], 1e-6);
        // 100 stake: gross -0.50, cost 0.14 -> net -0.64
        $this->assertEqualsWithDelta(-0.50, $shadow['hypothetical_gross_usdt'], 1e-6);
        $this->assertEqualsWithDelta(0.14, $shadow['hypothetical_cost_usdt'], 1e-6);
        $this->assertEqualsWithDelta(-0.64, $shadow['hypothetical_net_usdt'], 1e-6);
        $this->assertFalse($shadow['would_have_profited']);
        $this->assertEqualsWithDelta(0.64, $shadow['wait_saved_usdt'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $shadow['wait_missed_usdt'], 1e-6);
    }

    public function test_a_wait_whose_direction_would_have_profited_records_the_missed_amount(): void
    {
        $trade = $this->trade('BUY', 'WAIT');
        // Price rose 1%: gross +1.00, cost 0.14 -> net +0.86 missed.
        $this->sampleAtExpiry($trade, 1010.0);

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $shadow = data_get($trade->fresh()->signal_payload, 'research.wait_shadow');
        $this->assertTrue($shadow['would_have_profited']);
        $this->assertEqualsWithDelta(0.86, $shadow['wait_missed_usdt'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $shadow['wait_saved_usdt'], 1e-6);
    }

    public function test_a_sell_direction_is_signed_correctly(): void
    {
        $trade = $this->trade('SELL', 'WAIT');
        // Price FELL 1%: a SELL gains, so gross move is +1%.
        $this->sampleAtExpiry($trade, 990.0);

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $shadow = data_get($trade->fresh()->signal_payload, 'research.wait_shadow');
        $this->assertEqualsWithDelta(1.0, $shadow['gross_move_pct'], 1e-6);
        $this->assertTrue($shadow['would_have_profited']);
    }

    public function test_a_shadow_is_recorded_for_traded_decisions_too_but_without_saved_or_missed(): void
    {
        $trade = $this->trade('BUY', 'BUY');
        $this->sampleAtExpiry($trade, 1010.0);

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $shadow = data_get($trade->fresh()->signal_payload, 'research.wait_shadow');
        $this->assertSame('evaluated', $shadow['status']);
        // Only a WAIT can save or miss money by not trading.
        $this->assertNull($shadow['wait_saved_usdt']);
        $this->assertNull($shadow['wait_missed_usdt']);
    }

    public function test_a_neutral_direction_is_not_applicable_never_a_fabricated_loss(): void
    {
        $trade = $this->trade('NEUTRAL', 'WAIT');
        $this->sampleAtExpiry($trade, 900.0);

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $shadow = data_get($trade->fresh()->signal_payload, 'research.wait_shadow');
        $this->assertSame('not_applicable', $shadow['status']);
        $this->assertNull($shadow['hypothetical_net_usdt']);
        $this->assertNull($shadow['wait_saved_usdt']);
    }

    public function test_a_missing_expiry_sample_is_unavailable_not_zero(): void
    {
        $trade = $this->trade('BUY', 'WAIT');
        // No sample at all.

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $shadow = data_get($trade->fresh()->signal_payload, 'research.wait_shadow');
        $this->assertSame('unavailable', $shadow['status']);
        $this->assertNull($shadow['hypothetical_net_usdt']);
        $this->assertNull($shadow['would_have_profited']);
    }

    public function test_the_existing_outcome_evaluation_block_is_untouched(): void
    {
        $trade = $this->trade('BUY', 'WAIT');
        $this->sampleAtExpiry($trade, 1010.0);

        (new EvaluateQuickTradeForecastOutcomeJob($trade->id))->handle(app(\App\Domain\Trading\ForecastOutcomeEvaluator::class));

        $outcome = data_get($trade->fresh()->signal_payload, 'research.outcome_evaluation');
        $this->assertSame('evaluated', $outcome['primary']['status']);
        $this->assertArrayHasKey('net_pnl', $outcome);
    }
}
