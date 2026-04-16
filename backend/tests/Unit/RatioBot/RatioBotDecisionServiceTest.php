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
