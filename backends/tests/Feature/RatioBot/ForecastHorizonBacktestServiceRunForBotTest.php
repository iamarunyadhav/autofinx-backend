<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\MarketOhlc;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ForecastHorizonBacktestService::runForBot() -- the generic,
 * confidence-bucketed entry point (Unified Forecast Engine plan, Phase 3b).
 * Deliberately independent of any RatioBotConfig row.
 */
class ForecastHorizonBacktestServiceRunForBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bucket_with_enough_samples_reports_a_real_calibrated_hit_rate_and_a_sparse_bucket_reports_uncalibrated(): void
    {
        // A very long, consistent, steady uptrend: every walk-forward
        // prediction sees essentially the same bull/bear tally and drift
        // sign, so its confidence lands in the SAME bucket every time
        // (>= 30 samples there), while every other bucket stays empty.
        $this->seedTrend('binance', 'DOTUSDT', '1m', 2000.0, 4.0, 400);

        $result = app(ForecastHorizonBacktestService::class)->runForBot(
            'quick_trade',
            'quick_trade:binance:DOTUSDT:1m',
            'binance',
            'DOTUSDT',
            '1m',
            300, // horizon_seconds -> 5 candles at 1m
        );

        $this->assertFalse($result['insufficient_history']);
        $this->assertSame('quick_trade', $result['bot_type']);
        $this->assertSame('quick_trade:binance:DOTUSDT:1m', $result['bot_instance_id']);

        $populated = collect($result['buckets'])->first(fn (array $b) => $b['sample_size'] > 0);
        $this->assertNotNull($populated, 'expected at least one confidence bucket to receive samples');
        $this->assertGreaterThanOrEqual(ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE, $populated['sample_size']);
        $this->assertTrue($populated['calibrated']);
        $this->assertNotNull($populated['hit_rate_pct']);
        $this->assertGreaterThan(50.0, $populated['hit_rate_pct'], 'a consistent uptrend should mostly hit its own BUY calls');
        // Correctly computed: hits/sample_size, matching the exposed hit_rate_pct.
        $this->assertEquals(
            round(($populated['hits'] / $populated['sample_size']) * 100, 2),
            $populated['hit_rate_pct'],
        );

        $sparse = collect($result['buckets'])->first(fn (array $b) => $b['sample_size'] < ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE);
        $this->assertNotNull($sparse, 'expected at least one confidence bucket to stay under the sample floor');
        $this->assertFalse($sparse['calibrated']);
        $this->assertNull($sparse['hit_rate_pct'], 'a sub-floor bucket must never present a hit-rate as trustworthy');
    }

    public function test_insufficient_history_reports_every_bucket_as_uncalibrated_and_does_not_write_rows(): void
    {
        $this->seedTrend('binance', 'XRPUSDT', '1m', 1.0, 0.01, 10);

        $result = app(ForecastHorizonBacktestService::class)->runForBot(
            'robot_trade',
            'robot-session-fixture',
            'binance',
            'XRPUSDT',
            '1m',
            300,
        );

        $this->assertTrue($result['insufficient_history']);
        foreach ($result['buckets'] as $bucket) {
            $this->assertSame(0, $bucket['sample_size']);
            $this->assertFalse($bucket['calibrated']);
            $this->assertNull($bucket['hit_rate_pct']);
        }

        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 0);
    }

    public function test_bot_instance_id_and_bot_type_are_never_null_in_persisted_rows(): void
    {
        $this->seedTrend('binance', 'LTCUSDT', '1m', 100.0, 0.5, 400);

        app(ForecastHorizonBacktestService::class)->runForBot(
            'future_trade',
            'future-bot-config-id',
            'binance',
            'LTCUSDT',
            '1m',
            300,
        );

        $rows = RatioBotForecastBacktest::query()->where('bot_type', 'future_trade')->get();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $this->assertNotNull($row->bot_instance_id);
            $this->assertSame('future-bot-config-id', $row->bot_instance_id);
            $this->assertNotNull($row->confidence_bucket);
        }
    }

    /**
     * No-lookahead proof: the ENTIRE visible history for every scored
     * prediction is a steady downtrend (so forecast() can only ever read a
     * bearish tally/drift from the slice it's actually given), but the
     * dataset is deliberately sized so every prediction's real future
     * outcome (horizonCandles later) lands inside a separate, sharply
     * RISING segment that a leaking implementation would have "seen" (were
     * it allowed to look past index $i) and used to flip its call to BUY.
     * A correct, non-leaking walk-forward must keep calling SELL from the
     * downtrend-only data it's actually given and therefore mostly MISS
     * against the real (rising) outcome -- a low hit-rate here is exactly
     * the proof that no future data leaked into the prediction.
     */
    public function test_outcomes_are_never_scored_using_data_the_prediction_could_not_have_seen(): void
    {
        $exchange = 'binance';
        $symbol = 'LEAKTEST';
        $timeframe = '1m';

        $time = now()->subMinutes(101);
        $price = 5000.0;

        // Segment A: 60 candles of steady decline -- the only data any
        // in-range prediction in this test will ever see.
        for ($i = 0; $i < 60; $i++) {
            $open = $price;
            $close = $open - 10;
            MarketOhlc::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => $open + 1, 'low' => $close - 1, 'close' => $close,
                'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }

        // Segment B: exactly horizonCandles (40) candles of a sharp rise --
        // exists ONLY to serve as the "future" outcome window. Because the
        // dataset ends here, no prediction is ever made FROM inside this
        // segment (there aren't horizonCandles more candles after any of
        // them), so every scored prediction's visible slice is 100% Segment A.
        for ($i = 0; $i < 40; $i++) {
            $open = $price;
            $close = $open + 40;
            MarketOhlc::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => $close + 1, 'low' => $open - 1, 'close' => $close,
                'volume' => 10, 'ts' => $time->copy()->addMinutes(60 + $i),
            ]);
            $price = $close;
        }

        $result = app(ForecastHorizonBacktestService::class)->runForBot(
            'fast2x',
            'leak-test-instance',
            $exchange,
            $symbol,
            $timeframe,
            2400, // 40 candles at 1m
            ['0-100'],
        );

        $this->assertFalse($result['insufficient_history']);
        $bucket = $result['buckets']['0-100'];
        $this->assertGreaterThan(0, $bucket['sample_size'], 'expected the downtrend segment to produce scored SELL predictions');
        $this->assertLessThan(
            50.0,
            $bucket['raw_hit_rate_pct'],
            'a non-leaking backtest must mostly MISS here -- a high hit-rate would mean the future uptrend leaked into the prediction',
        );
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
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }
}
