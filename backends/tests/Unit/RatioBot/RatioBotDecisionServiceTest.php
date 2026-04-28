<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RatioBotDecisionService();
    }

    // -----------------------------------------------------------------------
    // Sell ratio formula tests
    // -----------------------------------------------------------------------

    /** @test */
    public function sell_ratio_formula_is_correct(): void
    {
        // (current / buy_price) - 1 = 0.50 at exactly threshold → SELL
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50);

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertEqualsWithDelta(0.50, $result['ratio'], 0.000001);
    }

    /** @test */
    public function sell_not_triggered_below_threshold(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50);

        $result = $this->service->evaluate($config, 89_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertLessThan(0.50, $result['ratio']);
    }

    /** @test */
    public function sell_triggered_above_threshold(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.40);

        // price is +50% from buy price, threshold is 40% → should trigger
        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
    }

    /** @test */
    public function sell_ratio_example_from_spec(): void
    {
        // buy at 60,000 → sell at 90,000 with 0.5 threshold
        // ratio = (90000 / 60000) - 1 = 0.5 ✓
        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.5);
        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertEqualsWithDelta(0.5, $result['ratio'], 0.000001);
    }

    // -----------------------------------------------------------------------
    // Buy ratio formula tests
    // -----------------------------------------------------------------------

    /** @test */
    public function buy_ratio_formula_is_correct(): void
    {
        // (sell_price / current) - 1 = 0.25 at exactly threshold → BUY
        [$config, $state] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);

        $result = $this->service->evaluate($config, 72_000.0);

        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $this->assertEqualsWithDelta(0.25, $result['ratio'], 0.000001);
    }

    /** @test */
    public function buy_not_triggered_above_threshold_price(): void
    {
        [$config, $state] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);

        // price has only dropped 10% from sell anchor → ratio 0.111, threshold 0.25
        $result = $this->service->evaluate($config, 81_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertLessThan(0.25, $result['ratio']);
    }

    /** @test */
    public function buy_ratio_example_from_spec(): void
    {
        // last sell = 90,000, current = 72,000
        // buy_ratio = (90000 / 72000) - 1 = 0.25 ✓
        [$config] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);
        $result = $this->service->evaluate($config, 72_000.0);

        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $this->assertEqualsWithDelta(0.25, $result['ratio'], 0.000001);
    }

    // -----------------------------------------------------------------------
    // State transitions
    // -----------------------------------------------------------------------

    /** @test */
    public function idle_bot_always_holds(): void
    {
        [$config] = $this->makeIdleConfig();

        $result = $this->service->evaluate($config, 50_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('idle', $result['reason']);
    }

    /** @test */
    public function disabled_bot_always_holds(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.01); // very low threshold
        $config->update(['enabled' => false]);
        $config->refresh();

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('disabled', $result['reason']);
    }

    /** @test */
    public function awaiting_execution_state_holds(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.01);
        $state->update(['position_state' => RatioBotState::STATE_AWAITING_EXECUTION]);
        $config->refresh();

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
    }

    /** @test */
    public function active_cooldown_returns_cooldown_decision_even_when_state_is_holding(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.01);
        $state->update(['cooldown_until' => now()->addMinute()]);
        $config->refresh();

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_COOLDOWN, $result['decision']);
    }

    /** @test */
    public function paused_state_returns_pause_decision(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.01);
        $state->update([
            'position_state' => RatioBotState::STATE_PAUSED,
            'block_reason_code' => 'operator_pause',
        ]);

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_PAUSE, $result['decision']);
    }

    /** @test */
    public function blocked_state_returns_pause_decision(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.01);
        $state->update([
            'position_state' => RatioBotState::STATE_BLOCKED,
            'block_reason_code' => 'order_failure_streak',
        ]);

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_PAUSE, $result['decision']);
    }

    /** @test */
    public function fee_and_slippage_cost_can_prevent_threshold_trigger(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.01);
        $config->update([
            'fee_bps' => 10,
            'max_slippage_bps' => 100,
            'min_net_profit_bps' => 50,
        ]);
        $config->refresh();

        $result = $this->service->evaluate($config, 101.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertEqualsWithDelta(0.01, $result['ratio'], 0.000001);
        $this->assertSame('sell_threshold', $result['decision_record']->metadata['trigger']);
    }

    /** @test */
    public function trailing_sell_only_executes_when_reversal_stays_above_sell_threshold(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.5);
        $config->update([
            'trigger_mode' => 'trailing_ratio',
            'selling_loss_ratio' => 0.2,
        ]);

        $result = $this->service->evaluate($config->fresh('state'), 150.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);

        $result = $this->service->evaluate($config->fresh('state'), 185.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);

        $result = $this->service->evaluate($config->fresh('state'), 140.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('blocked by boundary', $result['reason']);

        $result = $this->service->evaluate($config->fresh('state'), 165.0);
        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertEqualsWithDelta(0.65, $result['ratio'], 0.000001);
    }

    /** @test */
    public function trailing_buy_only_executes_when_rebound_stays_below_buy_threshold(): void
    {
        [$config] = $this->makeFlatConfig(sellPrice: 100.0, buyThreshold: -0.5);
        $config->update([
            'trigger_mode' => 'trailing_ratio',
            'buying_loss_ratio' => 0.2,
        ]);

        $result = $this->service->evaluate($config->fresh('state'), 50.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);

        $result = $this->service->evaluate($config->fresh('state'), 20.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);

        $result = $this->service->evaluate($config->fresh('state'), 70.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('blocked by boundary', $result['reason']);

        $result = $this->service->evaluate($config->fresh('state'), 40.0);
        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
        $this->assertEqualsWithDelta(-0.6, $result['ratio'], 0.000001);
    }

    /** @test */
    public function trailing_signal_waits_for_required_confirmation_updates(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.5);
        $config->update([
            'trigger_mode' => 'trailing_ratio',
            'selling_loss_ratio' => 0.2,
            'trigger_confirm_updates' => 2,
        ]);

        $this->service->evaluate($config->fresh('state'), 150.0);
        $this->service->evaluate($config->fresh('state'), 185.0);

        $firstTrigger = $this->service->evaluate($config->fresh('state'), 165.0);
        $this->assertSame(RatioBotDecision::DECISION_HOLD, $firstTrigger['decision']);
        $this->assertTrue((bool) $firstTrigger['decision_record']->metadata['confirmation_pending']);
        $this->assertSame(1, (int) $config->fresh('state')->state->pending_trigger_updates);

        $secondTrigger = $this->service->evaluate($config->fresh('state'), 165.0);
        $this->assertSame(RatioBotDecision::DECISION_SELL, $secondTrigger['decision']);
    }

    /** @test */
    public function max_allowed_slippage_guard_blocks_trade_signal(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.01);
        $config->update([
            'max_slippage_bps' => 100,
            'max_allowed_slippage_bps' => 50,
        ]);

        $result = $this->service->evaluate($config->fresh('state'), 103.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('slippage guard', $result['reason']);
        $this->assertSame('max_allowed_slippage', $result['decision_record']->metadata['guard']);
    }

    /** @test */
    public function hourly_trade_limit_blocks_new_trade_signal(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.01);
        $config->update(['max_trades_per_hour' => 1]);
        RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_SELL,
            'execution_status' => RatioBotDecision::EXEC_FILLED,
            'executed_at' => now(),
            'decided_at' => now(),
        ]);

        $result = $this->service->evaluate($config->fresh('state'), 103.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('hourly limit', $result['reason']);
        $this->assertSame('trade_rate_limit', $result['decision_record']->metadata['guard']);
    }

    /** @test */
    public function dynamic_threshold_context_can_delay_trade_signal(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.01);

        $result = $this->service->evaluate($config->fresh('state'), 103.0, [
            'dynamic_thresholds' => [
                'enabled' => true,
                'sell_threshold_ratio' => 0.05,
                'buy_threshold_ratio' => 0.03,
                'minimum_profit_ratio' => 0.005,
            ],
        ]);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertEqualsWithDelta(0.05, (float) $result['decision_record']->threshold_used, 0.000001);
        $this->assertTrue((bool) $result['decision_record']->metadata['dynamic_threshold_enabled']);
        $this->assertSame(0.05, (float) $result['decision_record']->metadata['dynamic_thresholds']['sell_threshold_ratio']);
    }

    /** @test */
    public function single_tick_spike_is_rejected_without_poisoning_last_seen_price(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 100.0, sellThreshold: 0.01);
        $state->update(['last_seen_price' => 100.0]);
        $config->update(['max_spike_deviation_bps' => 500]);

        $result = $this->service->evaluate($config->fresh('state'), 120.0);
        $state->refresh();

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('spike filter', $result['reason']);
        $this->assertEqualsWithDelta(100.0, (float) $state->last_seen_price, 0.000001);
    }

    // -----------------------------------------------------------------------
    // Ratio display / calculation helpers
    // -----------------------------------------------------------------------

    /** @test */
    public function compute_current_ratios_when_holding(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50);

        $display = $this->service->computeCurrentRatios($config, 75_000.0);

        $this->assertEqualsWithDelta(0.25, $display['sell_ratio'], 0.000001);
        $this->assertNull($display['buy_ratio']);
        $this->assertSame('holding', $display['position_state']);
    }

    /** @test */
    public function compute_current_ratios_when_flat(): void
    {
        [$config] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);

        $display = $this->service->computeCurrentRatios($config, 80_000.0);

        $this->assertNull($display['sell_ratio']);
        $this->assertGreaterThan(0, $display['buy_ratio']);
        $this->assertSame('flat', $display['position_state']);
    }

    /** @test */
    public function decisions_are_persisted_to_database(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50);

        $this->service->evaluate($config, 90_000.0);

        $this->assertDatabaseHas('ratio_bot_decisions', [
            'ratio_bot_config_id' => $config->id,
            'decision'            => 'SELL',
        ]);
    }

    /** @test */
    public function state_last_seen_price_updated_on_evaluate(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.99);

        $this->service->evaluate($config, 65_000.0);
        $state->refresh();

        $this->assertEqualsWithDelta(65_000.0, (float) $state->last_seen_price, 0.01);
    }

    /** @test */
    public function invalid_zero_buy_anchor_holds_safely(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50);
        $state->update(['last_buy_price' => 0]);

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('zero', $result['reason']);
    }

    /** @test */
    public function invalid_zero_sell_anchor_holds_safely(): void
    {
        [$config, $state] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25);
        $state->update(['last_sell_price' => 0]);

        $result = $this->service->evaluate($config, 70_000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeIdleConfig(): array
    {
        $config = RatioBotConfig::create([
            'workspace_id'        => '00000000-0000-0000-0000-000000000001',
            'symbol'              => 'BTCUSDT',
            'enabled'             => true,
            'sell_threshold_ratio'=> 0.50,
            'buy_threshold_ratio' => 0.25,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_IDLE,
        ]);
        $config->setRelation('state', $state);
        return [$config, $state];
    }

    private function makeHoldingConfig(float $buyPrice, float $sellThreshold): array
    {
        $config = RatioBotConfig::create([
            'workspace_id'        => '00000000-0000-0000-0000-000000000001',
            'symbol'              => 'BTCUSDT',
            'enabled'             => true,
            'sell_threshold_ratio'=> $sellThreshold,
            'buy_threshold_ratio' => 0.25,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_HOLDING,
            'last_buy_price'      => $buyPrice,
            'last_buy_quantity'   => 1.0,
            'active_anchor_price' => $buyPrice,
            'active_anchor_type'  => 'buy_anchor',
        ]);
        $config->setRelation('state', $state);
        return [$config, $state];
    }

    private function makeFlatConfig(float $sellPrice, float $buyThreshold): array
    {
        $config = RatioBotConfig::create([
            'workspace_id'        => '00000000-0000-0000-0000-000000000001',
            'symbol'              => 'BTCUSDT',
            'enabled'             => true,
            'sell_threshold_ratio'=> 0.50,
            'buy_threshold_ratio' => $buyThreshold,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_FLAT,
            'last_sell_price'     => $sellPrice,
            'last_sell_quantity'  => 1.0,
            'active_anchor_price' => $sellPrice,
            'active_anchor_type'  => 'sell_anchor',
        ]);
        $config->setRelation('state', $state);
        return [$config, $state];
    }
}
