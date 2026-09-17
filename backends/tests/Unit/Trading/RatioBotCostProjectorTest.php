<?php

namespace Tests\Unit\Trading;

use App\Domain\RatioBot\RatioBotCostProjector;
use PHPUnit\Framework\TestCase;

class RatioBotCostProjectorTest extends TestCase
{
    private function fill(int $id, int $order, string $side, float $qty, int $time, array $extra = []): array
    {
        return array_merge(['id' => $id, 'orderId' => $order, 'symbol' => 'BTCUSDT', 'side' => $side,
            'price' => 100, 'qty' => $qty, 'time' => $time, 'commission' => 0.1,
            'commissionAsset' => 'USDT', 'realizedPnl' => 0, 'positionSide' => 'BOTH'], $extra);
    }

    private function income(int $id = 1, float $amount = -2, int $time = 15): array
    {
        return ['tranId' => $id, 'incomeType' => 'FUNDING_FEE', 'symbol' => 'BTCUSDT',
            'asset' => 'USDT', 'income' => $amount, 'time' => $time];
    }

    private function project(array $trades, array $funding = [], array $orders = [1 => ['qty' => 1]], ?float $current = 1, bool $complete = true, bool $futures = true): array
    {
        return (new RatioBotCostProjector)->project($trades, $funding, $orders, 'BTCUSDT', 'USDT', $current, $complete, $futures);
    }

    public function test_recovery_topup_partial_fills_and_external_close_are_costed_once(): void
    {
        $trades = [$this->fill(1, 1, 'BUY', 0.4, 10), $this->fill(2, 1, 'BUY', 0.6, 11, ['price' => 110]),
            $this->fill(3, 2, 'BUY', 1, 20), $this->fill(4, 99, 'SELL', 2, 30, ['realizedPnl' => 20])];
        $trades[] = $trades[0]; // repeated exchange page, never duplicate fees
        $funding = [$this->income(), $this->income(), $this->income(2, 1, 25)];
        $result = $this->project($trades, $funding, [1 => ['qty' => 1], 2 => ['qty' => 1]], 0);
        $this->assertSame('real', $result['cost_source']);
        $this->assertSame(0.4, $result['commission_actual_known']);
        $this->assertSame(1.0, $result['funding_actual_known']);
        $this->assertSame(18.6, $result['net_pnl_actual']);
        $this->assertSame(106.0, $result['orders'][0]['fill_price']);
        $this->assertSame('exclusive_position_external_close', $result['orders'][2]['attribution']);
    }

    public function test_short_funding_credit_increases_net_profit(): void
    {
        $result = $this->project([$this->fill(1, 1, 'SELL', 1, 10)], [$this->income(1, 2)], current: -1);
        $this->assertSame(-2.0, $result['funding_actual_known']);
        $this->assertSame(1.9, $result['net_pnl_actual']);
    }

    public function test_foreign_opening_blocks_funding_allocation_and_external_close_guessing(): void
    {
        $result = $this->project([$this->fill(1, 1, 'BUY', 1, 10), $this->fill(2, 90, 'BUY', 1, 12)], [$this->income()], current: 2);
        $this->assertSame('unavailable', $result['funding_source']);
        $this->assertNull($result['net_pnl_actual']);
        $this->assertSame(0.1, $result['commission_actual_known']);
    }

    public function test_preexisting_position_is_not_assigned_to_this_bot(): void
    {
        $result = $this->project([$this->fill(1, 1, 'BUY', 1, 10)], [$this->income()], current: 2);
        $this->assertFalse($result['ownership_verified']);
        $this->assertNull($result['funding_events'][0]['cost']);
    }

    public function test_hedge_mode_missing_history_and_same_millisecond_boundary_fail_closed(): void
    {
        foreach ([['positionSide' => 'LONG'], []] as $extra) {
            $result = $this->project([$this->fill(1, 1, 'BUY', 1, 10, $extra)], [$this->income(time: 10)]);
            $this->assertNull($result['net_pnl_actual']);
        }
        $result = $this->project([$this->fill(1, 1, 'BUY', 1, 10)], complete: false);
        $this->assertNull($result['net_pnl_actual']);
    }

    public function test_bnb_commission_is_preserved_but_not_fabricated_as_usdt(): void
    {
        $result = $this->project([$this->fill(1, 1, 'BUY', 1, 10, ['commissionAsset' => 'BNB'])]);
        $this->assertSame('partial', $result['fee_source']);
        $this->assertSame(['BNB' => 0.1], $result['orders'][0]['commission_by_asset']);
        $this->assertNull($result['net_pnl_actual']);
    }

    public function test_delayed_partial_fill_does_not_claim_complete_commission(): void
    {
        $result = $this->project([$this->fill(1, 1, 'BUY', 0.5, 10)], current: 0.5);
        $this->assertSame('partial', $result['fee_source']);
        $this->assertNull($result['net_pnl_actual']);
    }

    public function test_spot_base_fee_is_valued_at_fill_price_and_slippage_is_not_subtracted_twice(): void
    {
        $result = $this->project([$this->fill(1, 1, 'BUY', 1, 10, ['commissionAsset' => 'BTC', 'commission' => 0.001])],
            orders: [1 => ['qty' => 1, 'reference_price' => 99]], futures: false);
        $this->assertSame(0.1, $result['commission_actual_known']);
        $this->assertSame(1.0, $result['orders'][0]['slippage_quote']);
        $this->assertSame('not_applicable', $result['funding_source']);
    }

    public function test_malformed_evidence_throws_instead_of_zero_fee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->project([$this->fill(1, 1, 'BUY', 1, 10, ['commission' => 'bad'])]);
    }

    public function test_empty_evidence_never_claims_real_costs(): void
    {
        $this->assertSame('unavailable', $this->project([], orders: [], current: 0)['cost_source']);
    }

    public function test_spot_base_fee_inventory_and_net_match_actual_cash_flows(): void
    {
        $result = $this->project([
            $this->fill(1, 1, 'BUY', 1, 10, ['commissionAsset' => 'BTC', 'commission' => 0.001]),
            $this->fill(2, 2, 'SELL', 0.999, 20, ['price' => 110, 'commission' => 0.1]),
        ], orders: [1 => ['qty' => 1], 2 => ['qty' => 0.999]], futures: false);
        $this->assertSame(9.79, $result['net_pnl_actual']); // -100 + 109.89 - 0.10
        $this->assertSame('weighted_average_actual_fills', $result['pnl_basis']);
        $this->assertNull($result['orders'][0]['exchange_realized_pnl']);
    }

    public function test_spot_starting_inventory_without_cost_basis_does_not_invent_profit(): void
    {
        $result = $this->project([$this->fill(1, 1, 'SELL', 1, 10)], futures: false);
        $this->assertSame('real', $result['fee_source']);
        $this->assertNull($result['net_pnl_actual']);
    }
}
