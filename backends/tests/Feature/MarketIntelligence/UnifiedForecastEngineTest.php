<?php

namespace Tests\Feature\MarketIntelligence;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\MarketOhlc;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for UnifiedForecastEngine (Unified Forecast Engine, Phase 4b)
 * -- shadow-mode-only computation. These tests exercise evaluate() directly
 * (not through any live trading path -- nothing in this pass wires its
 * output into a real decision).
 */
class UnifiedForecastEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_insufficient_clarity_short_circuits_without_computing_calibration_or_indicators(): void
    {
        // Flat, alternating candles: no window ever clears the composite
        // threshold.
        $time = now()->subMinutes(60);
        $price = 2000.0;
        for ($i = 0; $i < 48; $i++) {
            $open = $price;
            $close = $i % 2 === 0 ? $open + 3 : $open - 3;
            MarketOhlc::create([
                'exchange' => 'binance', 'symbol' => 'CHOPUSDT', 'timeframe' => '1m',
                'open' => $open, 'high' => max($open, $close) + 5, 'low' => min($open, $close) - 5,
                'close' => $close, 'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }

        $result = app(UnifiedForecastEngine::class)->evaluate(
            'quick_trade',
            'binance:CHOPUSDT:1m',
            'binance',
            'CHOPUSDT',
            '1m',
            300,
            24,
            0.9,
        );

        $this->assertSame('NEUTRAL', $result['direction']);
        $this->assertNull($result['confidence']);
        $this->assertFalse($result['calibrated']);
        $this->assertSame('insufficient_clarity', $result['status']);
        $this->assertSame('insufficient_clarity', $result['reason']);
        $this->assertSame(1, $result['wait_ticks']);
        $this->assertNull($result['indicator_groups'], 'indicator scoring must not run when the tick short-circuits on clarity');
        $this->assertSame(['sample_size' => 0, 'hit_rate_pct' => null, 'confidence_bucket' => null], $result['calibration_evidence']);

        // No calibration row was queried/written by this call.
        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 0);
    }

    public function test_max_wait_exceeded_is_reported_with_its_own_reason_after_enough_ticks(): void
    {
        $time = now()->subMinutes(60);
        $price = 2000.0;
        for ($i = 0; $i < 48; $i++) {
            $open = $price;
            $close = $i % 2 === 0 ? $open + 3 : $open - 3;
            MarketOhlc::create([
                'exchange' => 'binance', 'symbol' => 'CHOPUSDT2', 'timeframe' => '1m',
                'open' => $open, 'high' => max($open, $close) + 5, 'low' => min($open, $close) - 5,
                'close' => $close, 'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }

        $engine = app(UnifiedForecastEngine::class);
        $result = null;
        for ($i = 0; $i < 11; $i++) {
            $result = $engine->evaluate('quick_trade', 'binance:CHOPUSDT2:1m', 'binance', 'CHOPUSDT2', '1m', 300, 24, 0.9);
        }

        $this->assertSame('insufficient_clarity_max_wait_exceeded', $result['reason']);
        $this->assertTrue($result['wait_ticks'] >= $result['max_wait_ticks']);
    }

    public function test_calibrated_is_honestly_false_when_no_backtest_row_exists_yet(): void
    {
        $this->seedTrend('binance', 'CLEARUSDT', '1m', 2000.0, 5.0, 48);

        $result = app(UnifiedForecastEngine::class)->evaluate(
            'quick_trade',
            'binance:CLEARUSDT:1m',
            'binance',
            'CLEARUSDT',
            '1m',
            300,
            24,
            0.15,
        );

        $this->assertSame('clear', $result['status']);
        $this->assertSame('BUY', $result['direction']);
        $this->assertFalse($result['calibrated']);
        $this->assertNull($result['calibration_evidence']['hit_rate_pct']);
        $this->assertNotNull($result['confidence'], 'an uncalibrated result still reports the honest trend-implied confidence, never a null one once clear');
        $this->assertIsArray($result['indicator_groups']);
        $this->assertNotEmpty($result['why']);
    }

    public function test_calibrated_true_and_hit_rate_used_once_a_qualifying_backtest_row_exists(): void
    {
        $this->seedTrend('binance', 'CALIBUSDT', '1m', 2000.0, 5.0, 48);

        // Determine which bucket this tick's trend composite implies, then
        // seed a qualifying (>= MIN_BUCKET_SAMPLE_SIZE) calibration row for
        // exactly that bucket/bot/horizon -- proving evaluate() actually
        // reads real calibration data rather than a coincidental match.
        $trend = app(\App\Domain\RatioBot\MultiWindowTrendScoringService::class)
            ->scoreFor('binance', 'CALIBUSDT', '1m', 24, 0.15);
        $impliedConfidencePct = round(min(95.0, max(50.0, 50.0 + (abs((float) $trend['composite_score']) * 45.0))), 2);
        $bucket = ForecastHorizonBacktestService::bucketForConfidence($impliedConfidencePct);

        RatioBotForecastBacktest::create([
            'bot_type' => 'quick_trade',
            'bot_instance_id' => 'binance:CALIBUSDT:1m',
            'horizon_seconds' => 300,
            'confidence_bucket' => $bucket,
            'sample_size' => 50,
            'hits' => 40,
            'hit_rate_pct' => 80.0,
            'avg_confidence_pct' => $impliedConfidencePct,
            'computed_at' => now(),
        ]);

        $result = app(UnifiedForecastEngine::class)->evaluate(
            'quick_trade',
            'binance:CALIBUSDT:1m',
            'binance',
            'CALIBUSDT',
            '1m',
            300,
            24,
            0.15,
        );

        $this->assertTrue($result['calibrated']);
        $this->assertSame(80.0, $result['calibration_evidence']['hit_rate_pct']);
        $this->assertSame(50, $result['calibration_evidence']['sample_size']);
        $this->assertSame(0.3, $result['reliability_weight']);
    }

    private function seedTrend(string $exchange, string $symbol, string $timeframe, float $start, float $step, int $count): void
    {
        $time = now()->subMinutes($count + 1);
        for ($i = 0; $i < $count; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            MarketOhlc::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => max($open, $close) + 1, 'low' => min($open, $close) - 1,
                'close' => $close, 'volume' => 10, 'ts' => $time->copy()->addMinutes($i + 1),
            ]);
        }
    }
}
