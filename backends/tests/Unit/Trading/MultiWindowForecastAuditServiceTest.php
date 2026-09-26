<?php

namespace Tests\Unit\Trading;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\MultiWindowForecastAuditService;
use App\Models\MultiWindowForecastAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * VNext Dynamic Decision Engine, Phase A -- MultiWindowForecastAuditService
 * (capture + hindsight evaluateDue()), mirroring the established
 * QuickTradeWaitShadowService pattern.
 */
class MultiWindowForecastAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private function forecastResult(string $direction, string $decision, float $referencePrice = 1000.0): array
    {
        $generatedAt = now()->toIso8601String();

        return [
            'available' => true,
            'bias' => $direction,
            'decision_trend' => $decision,
            'generated_at' => $generatedAt,
            'reference_price' => $referencePrice,
            'current_price' => $referencePrice,
            'direction' => ['value' => $direction, 'fee_aware' => false, 'expiry_score' => 0.4, 'expected_move_pct' => 0.3],
            'trade_decision' => ['value' => $decision, 'fee_aware' => true, 'expected_move_pct' => 0.3,
                'required_move_pct' => 0.28, 'cost_pct' => 0.14, 'gate_reason' => null, 'fee_model' => 'taker'],
            'cost_model' => ['fee_model' => 'taker', 'cost_pct' => 0.14],
        ];
    }

    public function test_capture_marks_live_binance_futures_rows_pending_with_a_target(): void
    {
        $service = app(MultiWindowForecastAuditService::class);
        $requestData = ['symbol' => 'btcusdt', 'exchange' => 'binance_futures', 'timeframe' => '15m',
            'expiry_seconds' => 900, 'mode' => 'live', 'workspace_id' => null];

        $service->capture('11111111-1111-1111-1111-111111111111', 'short', $requestData, $this->forecastResult('BUY', 'BUY'));

        $row = MultiWindowForecastAudit::query()->where('correlation_id', '11111111-1111-1111-1111-111111111111')->first();
        $this->assertNotNull($row);
        $this->assertSame('short', $row->window_label);
        $this->assertSame('BTCUSDT', $row->symbol);
        $this->assertSame('binance_futures', $row->exchange);
        $this->assertSame('BUY', $row->direction);
        $this->assertSame('BUY', $row->trade_decision);
        $this->assertSame('pending', $row->status);
        $this->assertNotNull($row->target_ms);
    }

    public function test_capture_marks_paper_mode_rows_not_applicable_never_scored(): void
    {
        $service = app(MultiWindowForecastAuditService::class);
        $requestData = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'timeframe' => '1h',
            'expiry_seconds' => 3600, 'mode' => 'paper'];

        $service->capture('22222222-2222-2222-2222-222222222222', 'medium', $requestData, $this->forecastResult('SELL', 'WAIT'));

        $row = MultiWindowForecastAudit::query()->where('correlation_id', '22222222-2222-2222-2222-222222222222')->first();
        $this->assertSame('not_applicable', $row->status);
        $this->assertNull($row->target_ms);
    }

    public function test_capture_is_idempotent_per_correlation_and_window(): void
    {
        $service = app(MultiWindowForecastAuditService::class);
        $requestData = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'timeframe' => '4h',
            'expiry_seconds' => 14400, 'mode' => 'paper'];
        $correlationId = '33333333-3333-3333-3333-333333333333';

        $service->capture($correlationId, 'long', $requestData, $this->forecastResult('BUY', 'WAIT'));
        $service->capture($correlationId, 'long', $requestData, $this->forecastResult('SELL', 'SELL'));

        $this->assertSame(1, MultiWindowForecastAudit::query()->where('correlation_id', $correlationId)->count());
    }

    public function test_capture_skips_unavailable_forecasts(): void
    {
        $service = app(MultiWindowForecastAuditService::class);
        $service->capture('44444444-4444-4444-4444-444444444444', 'short',
            ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'timeframe' => '15m', 'expiry_seconds' => 900, 'mode' => 'live'],
            ['available' => false]);

        $this->assertSame(0, MultiWindowForecastAudit::query()->where('correlation_id', '44444444-4444-4444-4444-444444444444')->count());
    }

    public function test_evaluate_due_scores_a_correct_buy_and_computes_hypothetical_net_pnl(): void
    {
        config(['trading.binance_futures_taker_fee_rate' => 0.0005]);
        config(['robot_trade.live_slippage_buffer_pct' => 0.04]);

        $service = app(MultiWindowForecastAuditService::class);
        $generatedAt = now()->subSeconds(902); // target = generatedAt + 900s = ~2s ago, already due.
        MultiWindowForecastAudit::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'window_label' => 'short',
            'timeframe' => '15m', 'expiry_seconds' => 900, 'direction' => 'BUY', 'trade_decision' => 'BUY',
            'cost_model' => ['fee_model' => 'taker', 'cost_pct' => 0.14],
            'generated_at' => $generatedAt, 'reference_price' => 1000.0, 'mode' => 'live',
            'target_ms' => $generatedAt->copy()->addSeconds(900)->getTimestampMs(), 'status' => 'pending',
        ]);

        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('getLiveFeedSnapshot')->once()->with('binance_futures', 'BTCUSDT', 5)->andReturn([
            'fresh' => true, 'market_type' => 'futures', 'price' => 1010.0,
            'event_timestamp_ms' => now()->getTimestampMs(),
        ]);

        $result = $service->evaluateDue($prices);

        $this->assertSame(1, $result['evaluated']);
        $row = MultiWindowForecastAudit::query()->first();
        $this->assertSame('evaluated', $row->status);
        $this->assertEqualsWithDelta(1010.0, $row->actual_price, 1e-6);
        $this->assertTrue($row->direction_correct);
        // BUY, +1% move, 0.14% cost -> net +0.86% of 100 reference notional = 0.86.
        $this->assertEqualsWithDelta(0.86, $row->hypothetical_net_usdt, 1e-6);
    }

    public function test_evaluate_due_scores_an_incorrect_sell_as_false(): void
    {
        config(['trading.binance_futures_taker_fee_rate' => 0.0005]);
        config(['robot_trade.live_slippage_buffer_pct' => 0.04]);

        $service = app(MultiWindowForecastAuditService::class);
        $generatedAt = now()->subSeconds(902);
        MultiWindowForecastAudit::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'window_label' => 'short',
            'timeframe' => '15m', 'expiry_seconds' => 900, 'direction' => 'SELL', 'trade_decision' => 'SELL',
            'cost_model' => ['fee_model' => 'taker', 'cost_pct' => 0.14],
            'generated_at' => $generatedAt, 'reference_price' => 1000.0, 'mode' => 'live',
            'target_ms' => $generatedAt->copy()->addSeconds(900)->getTimestampMs(), 'status' => 'pending',
        ]);

        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('getLiveFeedSnapshot')->once()->andReturn([
            'fresh' => true, 'market_type' => 'futures', 'price' => 1010.0,
            'event_timestamp_ms' => now()->getTimestampMs(),
        ]);

        $service->evaluateDue($prices);

        $row = MultiWindowForecastAudit::query()->first();
        $this->assertFalse($row->direction_correct);
    }

    public function test_evaluate_due_marks_missing_horizon_sample_once_the_acceptance_window_passes(): void
    {
        config(['quick_trade_research.max_outcome_delay_seconds' => 10]);

        $service = app(MultiWindowForecastAuditService::class);
        $generatedAt = now()->subSeconds(1000); // target well in the past, past the acceptance window too.
        MultiWindowForecastAudit::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'window_label' => 'short',
            'timeframe' => '15m', 'expiry_seconds' => 900, 'direction' => 'BUY', 'trade_decision' => 'BUY',
            'cost_model' => ['fee_model' => 'taker'],
            'generated_at' => $generatedAt, 'reference_price' => 1000.0, 'mode' => 'live',
            'target_ms' => $generatedAt->copy()->addSeconds(900)->getTimestampMs(), 'status' => 'pending',
        ]);

        $prices = Mockery::mock(MarketPriceCacheService::class);
        $prices->shouldReceive('getLiveFeedSnapshot')->andReturn(null);

        $result = $service->evaluateDue($prices);

        $this->assertSame(1, $result['missing']);
        $this->assertSame('missing_horizon_sample', MultiWindowForecastAudit::query()->first()->status);
    }

    public function test_enabled_is_false_when_capture_flag_is_off(): void
    {
        config(['multi_window_forecast.capture_audit' => false]);
        $this->assertFalse(app(MultiWindowForecastAuditService::class)->enabled());
    }
}
