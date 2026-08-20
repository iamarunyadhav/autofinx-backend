<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotStateService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotStagedSellTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RatioBotStateService();
    }

    /** @test */
    public function first_stage_sell_transitions_to_partial_sold_and_preserves_cost_basis(): void
    {
        $config = $this->makeStagedSellConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 1.0,
            'last_buy_quantity' => 100.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config, 'SELL');

        // Sell 50 of 100 at price 1.2 (first of 2 stages, initial_exit_pct=0.5).
        $this->service->onSellFilled($config, $decision, executedPrice: 1.2, executedQty: 50.0, orderId: 'o1', clientOrderId: 'c1');
        $state->refresh();

        $this->assertSame(RatioBotState::STATE_PARTIAL_SOLD, $state->position_state);
        $this->assertEqualsWithDelta(1.0, (float) $state->last_buy_price, 0.0001, 'cost basis must be untouched by a partial exit');
        $this->assertEqualsWithDelta(50.0, (float) $state->last_buy_quantity, 0.0001);
        $this->assertSame(1, (int) $state->sell_tranches_remaining);
        // realized pnl for this stage = (1.2 - 1.0) * 50 = 10
        $this->assertEqualsWithDelta(10.0, (float) $state->realized_pnl, 0.0001);
    }

    /** @test */
    public function final_stage_sell_transitions_to_flat_and_accumulates_total_realized_pnl(): void
    {
        $config = $this->makeStagedSellConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_PARTIAL_SOLD,
            'last_buy_price' => 1.0,
            'last_buy_quantity' => 50.0,
            'sell_tranches_remaining' => 1,
            'realized_pnl' => 10.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config, 'SELL');

        // Final stage: sell remaining 50 at price 1.4.
        $this->service->onSellFilled($config, $decision, executedPrice: 1.4, executedQty: 50.0, orderId: 'o2', clientOrderId: 'c2');
        $state->refresh();

        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame(0, (int) $state->sell_tranches_remaining);
        $this->assertEqualsWithDelta(0.0, (float) $state->last_buy_quantity, 0.0001);
        // stage 2 pnl = (1.4-1.0)*50 = 20; total = 10 + 20 = 30
        $this->assertEqualsWithDelta(30.0, (float) $state->realized_pnl, 0.0001);
    }

    /** @test */
    public function partial_sell_never_mutates_last_buy_price(): void
    {
        $config = $this->makeStagedSellConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 2.5,
            'last_buy_quantity' => 100.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config, 'SELL');

        $this->service->onSellFilled($config, $decision, executedPrice: 0.5, executedQty: 50.0, orderId: 'o3', clientOrderId: 'c3');
        $state->refresh();

        // Even a sell BELOW cost basis (a "bad" stage) must not touch cost basis.
        $this->assertEqualsWithDelta(2.5, (float) $state->last_buy_price, 0.0001);
        $this->assertSame(RatioBotState::STATE_PARTIAL_SOLD, $state->position_state);
    }

    /** @test */
    public function legacy_bot_without_v2_enabled_keeps_single_shot_sell_behavior(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            'sell_tranche_count' => 2,
            'initial_exit_pct' => 0.5,
            'v2_enabled' => false, // <-- not opted in
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_buy_price' => 1.0,
            'last_buy_quantity' => 100.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config, 'SELL');

        $this->assertFalse($config->sellTranchesEnabled());

        $this->service->onSellFilled($config, $decision, executedPrice: 1.2, executedQty: 100.0, orderId: 'o4', clientOrderId: 'c4');
        $state->refresh();

        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame(0, (int) $state->sell_tranches_remaining);
    }

    /** @test */
    public function sell_tranches_disabled_when_initial_exit_pct_is_one(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            'sell_tranche_count' => 2,
            'initial_exit_pct' => 1.0,
            'v2_enabled' => true,
        ]);

        $this->assertFalse($config->sellTranchesEnabled());
    }

    /** @test */
    public function resumable_trading_state_routes_to_partial_sold_when_sell_tranches_remain(): void
    {
        $state = new RatioBotState([
            'last_trade_side' => 'SELL',
            'last_buy_quantity' => 50,
            'sell_tranches_remaining' => 1,
        ]);

        $this->assertSame(RatioBotState::STATE_PARTIAL_SOLD, $state->resumableTradingState());
    }

    /** @test */
    public function resumable_trading_state_routes_to_flat_when_no_sell_tranches_remain(): void
    {
        $state = new RatioBotState([
            'last_trade_side' => 'SELL',
            'last_buy_quantity' => 0,
            'sell_tranches_remaining' => 0,
        ]);

        $this->assertSame(RatioBotState::STATE_FLAT, $state->resumableTradingState());
    }

    private function makeStagedSellConfig(): RatioBotConfig
    {
        return RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            'sell_tranche_count' => 2,
            'initial_exit_pct' => 0.5,
            'v2_enabled' => true,
        ]);
    }

    private function makeDecision(RatioBotConfig $config, string $side = 'SELL'): RatioBotDecision
    {
        return RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => $side,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'decided_at' => now(),
        ]);
    }
}
