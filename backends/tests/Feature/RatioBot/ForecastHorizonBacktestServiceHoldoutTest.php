<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\MarketOhlc;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ForecastHorizonBacktestService::runForBotWithHoldout() --
 * the additive, chronological holdout-validation entry point. Deliberately
 * independent of any RatioBotConfig row, mirroring
 * ForecastHorizonBacktestServiceRunForBotTest's own style for runForBot().
 */
class ForecastHorizonBacktestServiceHoldoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_are_split_chronologically_into_training_and_holdout_partitions(): void
    {
        $this->seedTrend('binance', 'ADAUSDT', '1m', 100.0, 0.2, 500);

        $result = app(ForecastHorizonBacktestService::class)->runForBotWithHoldout(
            'quick_trade',
            'quick_trade:binance:ADAUSDT:1m',
            'binance',
            'ADAUSDT',
            '1m',
            300,
            self::confidenceBuckets(),
            0.6,
        );

        $this->assertFalse($result['insufficient_history']);
        $this->assertGreaterThan(0, $result['training_sample_size']);
        $this->assertGreaterThan(0, $result['holdout_sample_size']);
        // The training partition must be roughly (not necessarily exactly,
        // due to rounding) the larger share for a 0.6 training ratio.
        $this->assertGreaterThan($result['holdout_sample_size'], $result['training_sample_size']);

        $totalFromBuckets = array_sum(array_column($result['training_buckets'], 'sample_size'))
            + array_sum(array_column($result['holdout_buckets'], 'sample_size'));
        $this->assertSame(
            $result['training_sample_size'] + $result['holdout_sample_size'],
            $totalFromBuckets,
            'every scored record must land in exactly one partition/bucket, none dropped or double-counted',
        );
    }

    /**
     * The core leakage-proof test: seed a TRAINING window with a steady
     * uptrend (BUY calls mostly hit) immediately followed by a HOLDOUT
     * window with a steady DOWNTREND (BUY calls mostly miss, since the bot
     * keeps calling BUY on a downtrend only if its bull/bear tally logic
     * itself flips -- what matters here is not the sign but that training
     * and holdout produce MEASURABLY DIFFERENT calibration numbers).
     *
     * If holdout calibration were contaminated by training data (or vice
     * versa), the training-derived 'training_hit_rate_pct' attached to the
     * holdout bucket would drift toward the holdout period's own realized
     * rate. This test asserts the two are computed independently by
     * checking training_hit_rate_pct equals the training partition's own
     * hit_rate_pct exactly (never blended with the holdout figure) even
     * though the holdout period's OWN realized rate ('hit_rate_pct' on the
     * holdout bucket) is measurably different.
     */
    public function test_holdout_calibration_uses_only_training_period_data(): void
    {
        $exchange = 'binance';
        $symbol = 'HOLDOUTLEAK';
        $timeframe = '1m';
        $time = now()->subMinutes(561);
        $price = 1000.0;

        // Training window: 300 candles of a steady uptrend.
        for ($i = 0; $i < 300; $i++) {
            $open = $price;
            $close = $open + 2;
            MarketOhlc::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => $close + 1, 'low' => $open - 1, 'close' => $close,
                'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }

        // Holdout window: 260 candles of a steady downtrend -- a regime
        // reversal relative to training.
        for ($i = 300; $i < 560; $i++) {
            $open = $price;
            $close = $open - 2;
            MarketOhlc::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => $open + 1, 'low' => $close - 1, 'close' => $close,
                'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }

        $result = app(ForecastHorizonBacktestService::class)->runForBotWithHoldout(
            'quick_trade',
            'leak-test-instance',
            $exchange,
            $symbol,
            $timeframe,
            300, // horizon_seconds -> 5 candles at 1m
            ['0-100'],
            0.6,
        );

        $this->assertFalse($result['insufficient_history']);
        $trainingBucket = $result['training_buckets']['0-100'];
        $holdoutBucket = $result['holdout_buckets']['0-100'];

        $this->assertGreaterThanOrEqual(ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE, $trainingBucket['sample_size']);
        $this->assertGreaterThanOrEqual(ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE, $holdoutBucket['sample_size']);
        $this->assertTrue($trainingBucket['calibrated']);
        $this->assertTrue($holdoutBucket['training_calibrated']);

        // The training window's uptrend must have produced a mostly-hit
        // calibration.
        $this->assertGreaterThan(50.0, $trainingBucket['hit_rate_pct']);

        // No-leakage assertion: the calibration attached to the holdout
        // bucket is EXACTLY the training partition's own figure -- not
        // recomputed, not averaged with holdout data.
        $this->assertSame($trainingBucket['hit_rate_pct'], $holdoutBucket['training_hit_rate_pct']);

        // The holdout period's OWN realized rate must be measurably
        // different from the training-derived calibration -- proving the
        // holdout partition was scored independently against the
        // downtrend it actually contains, not against the training
        // uptrend's outcomes.
        $this->assertNotEquals($trainingBucket['hit_rate_pct'], $holdoutBucket['hit_rate_pct']);
        $this->assertNotNull($holdoutBucket['calibration_delta_pct']);
    }

    public function test_insufficient_history_reports_empty_partitions_and_writes_no_rows(): void
    {
        $this->seedTrend('binance', 'XLMUSDT', '1m', 1.0, 0.01, 10);

        $result = app(ForecastHorizonBacktestService::class)->runForBotWithHoldout(
            'robot_trade',
            'robot-holdout-fixture',
            'binance',
            'XLMUSDT',
            '1m',
            300,
        );

        $this->assertTrue($result['insufficient_history']);
        $this->assertSame(0, $result['training_sample_size']);
        $this->assertSame(0, $result['holdout_sample_size']);
        foreach ($result['training_buckets'] as $bucket) {
            $this->assertSame(0, $bucket['sample_size']);
        }
        foreach ($result['holdout_buckets'] as $bucket) {
            $this->assertSame(0, $bucket['sample_size']);
            $this->assertFalse($bucket['training_calibrated']);
            $this->assertNull($bucket['training_hit_rate_pct']);
        }

        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 0);
    }

    public function test_persists_separate_training_and_holdout_rows_without_collision(): void
    {
        $this->seedTrend('binance', 'TRXUSDT', '1m', 2000.0, 4.0, 400);

        app(ForecastHorizonBacktestService::class)->runForBotWithHoldout(
            'future_trade',
            'future-holdout-instance',
            'binance',
            'TRXUSDT',
            '1m',
            300,
            ['0-100'],
        );

        $rows = RatioBotForecastBacktest::query()
            ->where('bot_type', 'future_trade')
            ->where('bot_instance_id', 'future-holdout-instance')
            ->get();

        $this->assertCount(2, $rows, 'exactly one training row and one holdout row per bucket, no collision under the widened unique index');
        $this->assertEqualsCanonicalizing(['training', 'holdout'], $rows->pluck('evaluation_period')->all());
        foreach ($rows as $row) {
            $this->assertNotNull($row->bot_instance_id);
            $this->assertNotNull($row->confidence_bucket);
        }
    }

    public function test_runforbot_behavior_is_unchanged_by_the_new_holdout_method(): void
    {
        $this->seedTrend('binance', 'DOTUSDT', '1m', 2000.0, 4.0, 400);

        $service = app(ForecastHorizonBacktestService::class);
        $result = $service->runForBot(
            'quick_trade',
            'quick_trade:binance:DOTUSDT:1m',
            'binance',
            'DOTUSDT',
            '1m',
            300,
        );

        $this->assertFalse($result['insufficient_history']);
        $populated = collect($result['buckets'])->first(fn (array $b) => $b['sample_size'] > 0);
        $this->assertNotNull($populated);
        $this->assertGreaterThanOrEqual(ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE, $populated['sample_size']);
        $this->assertTrue($populated['calibrated']);
        // runForBot()'s own record shape must still only expose
        // confidence/hit-derived fields -- the additive 'index' key
        // walkForward() now attaches is never surfaced through runForBot().
        $this->assertArrayNotHasKey('index', $populated);
    }

    private static function confidenceBuckets(): array
    {
        return ForecastHorizonBacktestService::DEFAULT_CONFIDENCE_BUCKETS;
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
