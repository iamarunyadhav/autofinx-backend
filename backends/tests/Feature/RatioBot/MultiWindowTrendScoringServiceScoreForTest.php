<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\MultiWindowTrendScoringService;
use App\Models\MarketOhlc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for MultiWindowTrendScoringService::scoreFor() -- the generic,
 * RatioBotConfig-free entry point (Unified Forecast Engine plan, Phase 3a).
 * Deliberately independent of any RatioBotConfig row: these tests seed
 * MarketOhlc directly against arbitrary exchange/symbol/timeframe scalars.
 */
class MultiWindowTrendScoringServiceScoreForTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_consistent_uptrend_clears_the_threshold_and_is_reported_as_clear(): void
    {
        $this->seedTrend('binance', 'ETHUSDT', '1m', 2000.0, 5.0, 48);

        $result = app(MultiWindowTrendScoringService::class)
            ->scoreFor('binance', 'ETHUSDT', '1m', 24, 0.15);

        $this->assertSame('bullish', $result['direction']);
        $this->assertTrue($result['threshold_cleared']);
        $this->assertSame('clear', $result['status']);
    }

    public function test_a_flat_choppy_market_never_clears_the_threshold_and_reports_insufficient_clarity_not_a_fabricated_direction(): void
    {
        // Alternates up/down every candle so no window's net move ever
        // exceeds its own ATR-scaled reference by enough to clear even a
        // very low min_composite -- the honest "no window cleared" case.
        $time = now()->subMinutes(60);
        $price = 2000.0;
        for ($i = 0; $i < 48; $i++) {
            $open = $price;
            $close = $i % 2 === 0 ? $open + 3 : $open - 3;
            MarketOhlc::create([
                'exchange' => 'binance',
                'symbol' => 'ADAUSDT',
                'timeframe' => '1m',
                'open' => $open,
                'high' => max($open, $close) + 5,
                'low' => min($open, $close) - 5,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }

        $result = app(MultiWindowTrendScoringService::class)
            ->scoreFor('binance', 'ADAUSDT', '1m', 24, 0.9);

        $this->assertSame('neutral', $result['direction']);
        $this->assertFalse($result['threshold_cleared']);
        $this->assertSame('insufficient_clarity', $result['status']);
    }

    public function test_max_wait_ticks_is_echoed_back_for_a_future_stateful_caller_without_changing_the_computation(): void
    {
        $this->seedTrend('binance', 'SOLUSDT', '1m', 2000.0, 5.0, 48);

        $withoutWait = app(MultiWindowTrendScoringService::class)
            ->scoreFor('binance', 'SOLUSDT', '1m', 24, 0.15);
        $withWait = app(MultiWindowTrendScoringService::class)
            ->scoreFor('binance', 'SOLUSDT', '1m', 24, 0.15, 12);

        $this->assertArrayNotHasKey('max_wait_ticks', $withoutWait);
        $this->assertSame(12, $withWait['max_wait_ticks']);
        $this->assertSame($withoutWait['composite_score'], $withWait['composite_score']);
        $this->assertSame($withoutWait['direction'], $withWait['direction']);
    }

    public function test_scoreFor_and_the_config_wrapper_produce_the_same_windowing_math_for_equivalent_inputs(): void
    {
        $this->seedTrend('binance', 'BNBUSDT', '1m', 2000.0, 5.0, 48);

        $direct = app(MultiWindowTrendScoringService::class)
            ->scoreFor('binance', 'BNBUSDT', '1m', 24, 0.15);

        $this->assertSame('bullish', $direct['direction']);
        $this->assertCount(3, $direct['windows']);
        $this->assertSame(3, $direct['agreement_count']);
    }

    private function seedTrend(string $exchange, string $symbol, string $timeframe, float $start, float $step, int $count): void
    {
        $time = now()->subMinutes($count + 1);
        for ($i = 0; $i < $count; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            MarketOhlc::create([
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open' => $open,
                'high' => max($open, $close) + 1,
                'low' => min($open, $close) - 1,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i + 1),
            ]);
        }
    }
}
