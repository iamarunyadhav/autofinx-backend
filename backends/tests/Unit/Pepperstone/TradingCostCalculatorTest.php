<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\TradingCostCalculator;
use Tests\TestCase;

class TradingCostCalculatorTest extends TestCase
{
    private const DELTA = 0.0000001;

    /** @test */
    public function estimates_total_cost_as_the_sum_of_spread_slippage_and_commission(): void
    {
        $calculator = new TradingCostCalculator();

        // 1 lot, contract_size 100, spread 0.50 price delta, slippage 0.10,
        // commission $7/lot.
        $cost = $calculator->estimateCost(1.0, 100.0, 0.50, 0.10, 7.0);

        // spread: 0.50 * 1 * 100 = 50; slippage: 0.10 * 1 * 100 = 10;
        // commission: 7 * 1 = 7. Total = 67.
        $this->assertEqualsWithDelta(67.0, $cost, self::DELTA);
    }

    /** @test */
    public function cost_scales_linearly_with_lots(): void
    {
        $calculator = new TradingCostCalculator();

        $oneLot = $calculator->estimateCost(1.0, 100.0, 0.50, 0.10, 7.0);
        $twoLots = $calculator->estimateCost(2.0, 100.0, 0.50, 0.10, 7.0);

        $this->assertEqualsWithDelta($oneLot * 2, $twoLots, self::DELTA);
    }

    /** @test */
    public function negative_inputs_never_reduce_cost_below_zero_contribution(): void
    {
        $calculator = new TradingCostCalculator();

        // A negative spread/slippage/commission (shouldn't happen in
        // practice, but must never make the estimate negative or subsidize
        // the trade) contributes zero, not a negative cost.
        $cost = $calculator->estimateCost(1.0, 100.0, -0.5, -0.1, -7.0);

        $this->assertEqualsWithDelta(0.0, $cost, self::DELTA);
    }

    /** @test */
    public function expected_profit_scales_with_lots_and_contract_size(): void
    {
        $calculator = new TradingCostCalculator();

        $profit = $calculator->expectedProfit(2.0, 100.0, 5.0);

        $this->assertEqualsWithDelta(1000.0, $profit, self::DELTA);
    }

    /** @test */
    public function a_trade_only_clears_cost_when_profit_strictly_exceeds_it(): void
    {
        $calculator = new TradingCostCalculator();

        $this->assertTrue($calculator->clearsCost(100.0, 99.99));
        $this->assertFalse($calculator->clearsCost(100.0, 100.0), 'Break-even must not be authorized');
        $this->assertFalse($calculator->clearsCost(50.0, 100.0));
    }
}
