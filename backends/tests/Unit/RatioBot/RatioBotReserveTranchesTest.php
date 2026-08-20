<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotStateService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotReserveTranchesTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotStateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RatioBotStateService();
    }

    /** @test */
    public function fresh_buy_with_reserve_enabled_spends_initial_pct_and_enters_partial_holding(): void
    {
        $config = $this->makeReserveConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config);

        // Fresh entry: 60 of the 100 base_quote_amount at price 1.0 -> qty 60.
        $this->service->onBuyFilled($config, $decision, executedPrice: 1.0, executedQty: 60.0, orderId: 'o1', clientOrderId: 'c1');
        $state->refresh();

        $this->assertSame(RatioBotState::STATE_PARTIAL_HOLDING, $state->position_state);
        $this->assertEqualsWithDelta(1.0, (float) $state->last_buy_price, 0.0001);
        $this->assertEqualsWithDelta(60.0, (float) $state->last_buy_quantity, 0.0001);
        $this->assertSame(2, (int) $state->reserve_tranches_remaining);
        $this->assertEqualsWithDelta(40.0, (float) $state->reserve_quote_remaining, 0.0001);
    }

    /** @test */
    public function tranche_fill_accumulates_weighted_average_and_decrements_reserve(): void
    {
        $config = $this->makeReserveConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_PARTIAL_HOLDING,
            'last_buy_price' => 1.0,
            'last_buy_quantity' => 60.0,
            'reserve_tranches_remaining' => 2,
            'reserve_quote_remaining' => 40.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config);

        // Tranche buy: spend 20 (40/2) at price 0.5 -> qty 40.
        $this->service->onBuyFilled($config, $decision, executedPrice: 0.5, executedQty: 40.0, orderId: 'o2', clientOrderId: 'c2');
        $state->refresh();

        // weighted avg = (60*1 + 40*0.5) / 100 = 80/100 = 0.8
        $this->assertEqualsWithDelta(0.8, (float) $state->last_buy_price, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $state->last_buy_quantity, 0.0001);
        $this->assertSame(RatioBotState::STATE_PARTIAL_HOLDING, $state->position_state);
        $this->assertSame(1, (int) $state->reserve_tranches_remaining);
        $this->assertEqualsWithDelta(20.0, (float) $state->reserve_quote_remaining, 0.0001);
    }

    /** @test */
    public function last_tranche_fill_promotes_to_full_holding(): void
    {
        $config = $this->makeReserveConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_PARTIAL_HOLDING,
            'last_buy_price' => 0.8,
            'last_buy_quantity' => 100.0,
            'reserve_tranches_remaining' => 1,
            'reserve_quote_remaining' => 20.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config);

        // Final tranche: spend all 20 remaining at price 0.4 -> qty 50.
        $this->service->onBuyFilled($config, $decision, executedPrice: 0.4, executedQty: 50.0, orderId: 'o3', clientOrderId: 'c3');
        $state->refresh();

        // weighted avg = (100*0.8 + 50*0.4) / 150 = (80+20)/150 = 0.6667
        $this->assertEqualsWithDelta(0.66667, (float) $state->last_buy_price, 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $state->last_buy_quantity, 0.0001);
        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertSame(0, (int) $state->reserve_tranches_remaining);
        $this->assertEqualsWithDelta(0.0, (float) $state->reserve_quote_remaining, 0.0001);
    }

    /** @test */
    public function sell_fill_resets_reserve_fields_even_with_unused_tranches(): void
    {
        $config = $this->makeReserveConfig();
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_PARTIAL_HOLDING,
            'last_buy_price' => 1.0,
            'last_buy_quantity' => 100.0,
            'reserve_tranches_remaining' => 1,
            'reserve_quote_remaining' => 20.0,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config, 'SELL');

        $this->service->onSellFilled($config, $decision, executedPrice: 1.5, executedQty: 100.0, orderId: 'o4', clientOrderId: 'c4');
        $state->refresh();

        $this->assertSame(RatioBotState::STATE_FLAT, $state->position_state);
        $this->assertSame(0, (int) $state->reserve_tranches_remaining);
        $this->assertEqualsWithDelta(0.0, (float) $state->reserve_quote_remaining, 0.0001);
    }

    /** @test */
    public function legacy_bot_without_reserve_goes_straight_to_full_holding(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            // initial_entry_pct/reserve_tranche_count left at defaults (1.0 / 0)
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
        ]);
        $config->setRelation('state', $state);
        $decision = $this->makeDecision($config);

        $this->assertFalse($config->reserveEnabled());

        $this->service->onBuyFilled($config, $decision, executedPrice: 900.0, executedQty: 100 / 900, orderId: 'o5', clientOrderId: 'c5');
        $state->refresh();

        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertSame(0, (int) $state->reserve_tranches_remaining);
    }

    /** @test */
    public function resumable_trading_state_routes_to_partial_holding_when_reserve_remains(): void
    {
        $state = new RatioBotState([
            'last_trade_side' => 'BUY',
            'last_buy_price' => 900,
            'reserve_tranches_remaining' => 1,
        ]);

        $this->assertSame(RatioBotState::STATE_PARTIAL_HOLDING, $state->resumableTradingState());
    }

    /** @test */
    public function resumable_trading_state_routes_to_holding_when_no_reserve_remains(): void
    {
        $state = new RatioBotState([
            'last_trade_side' => 'BUY',
            'last_buy_price' => 900,
            'reserve_tranches_remaining' => 0,
        ]);

        $this->assertSame(RatioBotState::STATE_HOLDING, $state->resumableTradingState());
    }

    private function makeReserveConfig(): RatioBotConfig
    {
        return RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            'initial_entry_pct' => 0.6,
            'reserve_tranche_count' => 2,
        ]);
    }

    private function makeDecision(RatioBotConfig $config, string $side = 'BUY'): RatioBotDecision
    {
        return RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => $side,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'decided_at' => now(),
        ]);
    }
}
