<?php

namespace Tests\Unit\Fast2X;

use App\Domain\Fast2X\DuplicateOrderGuard;
use App\Domain\Fast2X\DynamicThresholdService;
use App\Domain\Fast2X\OrderExecutionService;
use App\Domain\Fast2X\PnLTrackingService;
use App\Domain\Fast2X\RiskGuardService;
use App\Domain\Fast2X\RollbackService;
use App\Domain\Fast2X\StopLossService;
use App\Domain\Fast2X\TradeFrequencyService;
use App\Domain\Fast2X\TrailingProfitService;
use App\Models\Fast2XConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Fast2XServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_thresholds_widen_in_high_volatility(): void
    {
        $ticks = [
            ['price' => 100],
            ['price' => 102],
            ['price' => 98],
            ['price' => 103],
        ];

        $result = app(DynamicThresholdService::class)->resolve($ticks, config('fast2x.defaults'));

        $this->assertSame('HIGH', $result['volatilityLevel']);
        $this->assertGreaterThan(0.2, $result['minProfitTargetPercent']);
        $this->assertGreaterThan(0.15, $result['rollbackLossPercent']);
    }

    public function test_pnl_calculation_uses_fees_and_slippage(): void
    {
        $result = app(PnLTrackingService::class)->calculate(100000, 100250, 0.001, config('fast2x.defaults'));

        $this->assertGreaterThan(0, $result['grossPnL']);
        $this->assertGreaterThan(0, $result['estimatedFees']);
        $this->assertLessThan($result['grossPnL'], $result['netPnL']);
        $this->assertEqualsWithDelta(0.13, $result['netPnLPercent'], 0.02);
    }

    public function test_rollback_stop_loss_and_trailing_triggers(): void
    {
        $settings = config('fast2x.defaults');

        $this->assertTrue(app(RollbackService::class)->shouldRollback(-0.2, $settings));
        $this->assertTrue(app(StopLossService::class)->shouldStop(-0.5, $settings));

        $trailing = app(TrailingProfitService::class)->resolve(100000, 100700, 101000, $settings);
        $this->assertTrue($trailing['shouldSell']);
    }

    public function test_duplicate_order_guard_rejects_active_order_and_same_signal(): void
    {
        $config = $this->config();
        $config->state()->create(['order_in_progress' => true, 'last_signal_key' => 'BUY:1']);

        $this->assertFalse(app(DuplicateOrderGuard::class)->validate($config->fresh('state'), 'BUY:2')['allowed']);

        $config->state->update(['order_in_progress' => false]);
        $this->assertFalse(app(DuplicateOrderGuard::class)->validate($config->fresh('state'), 'BUY:1')['allowed']);
    }

    public function test_frequency_guard_rejects_daily_limit(): void
    {
        $config = $this->config();
        $config->state()->create(['status' => 'Running', 'websocket_status' => 'Connected']);
        $config->trades()->create(['symbol' => 'BTCUSDT', 'side' => 'BUY']);

        $result = app(TradeFrequencyService::class)->validate($config->fresh('state'), array_merge(config('fast2x.defaults'), ['maxTradesPerDay' => 1]));

        $this->assertFalse($result['allowed']);
    }

    public function test_risk_guard_rejects_high_latency(): void
    {
        $config = $this->config();
        $config->state()->create(['status' => 'Running', 'websocket_status' => 'Connected']);

        $result = app(RiskGuardService::class)->validateBeforeTrade($config->fresh('state'), [
            'side' => 'BUY',
            'signalKey' => 'BUY:latency',
            'tickAgeMs' => 100,
            'spreadPercent' => 0.01,
            'slippagePercent' => 0.01,
            'latencyMs' => 1000,
            'exchangeHealthy' => true,
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertSame('Latency too high', $result['reason']);
    }

    public function test_paper_buy_execution_records_trade_and_position(): void
    {
        $config = $this->config();
        $config->state()->create(['status' => 'Running', 'websocket_status' => 'Connected']);

        app(OrderExecutionService::class)->executeBuy($config->fresh('state'), [
            'price' => 100000,
            'amount' => 10,
            'signalKey' => 'BUY:test',
        ]);

        $this->assertDatabaseHas('fast2x_trades', ['side' => 'BUY', 'mode' => 'PAPER']);
        $this->assertGreaterThan(0, (float) $config->fresh('state')->state->quantity);
    }

    private function config(): Fast2XConfig
    {
        return Fast2XConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'paper_trading' => true,
            'enable_live_trading' => false,
            'settings' => config('fast2x.defaults'),
        ]);
    }
}
