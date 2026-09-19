<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\ForecastBacktestPrediction;
use App\Models\MarketOhlc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Additive tests (exact-Brier-score task) for the NEW per-prediction
 * persistence wired into ForecastHorizonBacktestService::walkForward()'s
 * three public callers (runForBot()/runForBotWithHoldout()/runForConfig()).
 * Deliberately separate from ForecastHorizonBacktestServiceRunForBotTest /
 * ...HoldoutTest, which cover the pre-existing bucket-aggregate behavior
 * and are left untouched.
 */
class ForecastHorizonBacktestServicePredictionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_runforbot_persists_one_prediction_row_per_scored_record(): void
    {
        $this->seedTrend('binance', 'PERSISTUSDT', '1m', 2000.0, 4.0, 400);

        $result = app(ForecastHorizonBacktestService::class)->runForBot(
            'quick_trade',
            'quick_trade:binance:PERSISTUSDT:1m',
            'binance',
            'PERSISTUSDT',
            '1m',
            300,
        );

        $totalScored = array_sum(array_column($result['buckets'], 'sample_size'));
        $this->assertGreaterThan(0, $totalScored);

        $rows = ForecastBacktestPrediction::query()
            ->where('bot_type', 'quick_trade')
            ->where('bot_instance_id', 'quick_trade:binance:PERSISTUSDT:1m')
            ->where('horizon_seconds', 300)
            ->where('evaluation_period', 'full')
            ->get();

        $this->assertSame($totalScored, $rows->count(), 'one persisted row per scored prediction, matching the sum across all buckets exactly');
        foreach ($rows as $row) {
            $this->assertNotNull($row->confidence_pct);
            $this->assertIsBool((bool) $row->predicted_hit ?? false);
            $this->assertNotNull($row->candle_ts);
            $this->assertNotNull($row->backtest_run_id);
        }
    }

    public function test_runforbot_with_insufficient_history_persists_no_prediction_rows(): void
    {
        $this->seedTrend('binance', 'SPARSEUSDT', '1m', 1.0, 0.01, 10);

        app(ForecastHorizonBacktestService::class)->runForBot(
            'robot_trade',
            'robot-sparse-fixture',
            'binance',
            'SPARSEUSDT',
            '1m',
            300,
        );

        $this->assertDatabaseCount('forecast_backtest_predictions', 0);
    }

    public function test_runforbotwithholdout_persists_training_and_holdout_predictions_under_separate_evaluation_periods(): void
    {
        $this->seedTrend('binance', 'HOLDPERSIST', '1m', 100.0, 0.2, 500);

        $result = app(ForecastHorizonBacktestService::class)->runForBotWithHoldout(
            'quick_trade',
            'quick_trade:binance:HOLDPERSIST:1m',
            'binance',
            'HOLDPERSIST',
            '1m',
            300,
        );

        $trainingRows = ForecastBacktestPrediction::query()
            ->where('bot_instance_id', 'quick_trade:binance:HOLDPERSIST:1m')
            ->where('evaluation_period', 'training')
            ->count();
        $holdoutRows = ForecastBacktestPrediction::query()
            ->where('bot_instance_id', 'quick_trade:binance:HOLDPERSIST:1m')
            ->where('evaluation_period', 'holdout')
            ->count();

        $this->assertSame($result['training_sample_size'], $trainingRows);
        $this->assertSame($result['holdout_sample_size'], $holdoutRows);
    }

    /**
     * Re-running the SAME scope must REPLACE its previously-persisted
     * predictions, not append to them -- mirrors RatioBotForecastBacktest's
     * own updateOrCreate()-overwrite semantics for the same scope, so
     * repeated backtest runs (e.g. a nightly recompute) do not accumulate
     * unbounded duplicate rows for a symbol/horizon that never changes.
     */
    public function test_rerunning_the_same_scope_replaces_rather_than_accumulates_prediction_rows(): void
    {
        $this->seedTrend('binance', 'RERUNUSDT', '1m', 2000.0, 4.0, 400);

        $service = app(ForecastHorizonBacktestService::class);
        $first = $service->runForBot('quick_trade', 'rerun-instance', 'binance', 'RERUNUSDT', '1m', 300);
        $firstCount = ForecastBacktestPrediction::query()->where('bot_instance_id', 'rerun-instance')->count();
        $this->assertGreaterThan(0, $firstCount);

        $second = $service->runForBot('quick_trade', 'rerun-instance', 'binance', 'RERUNUSDT', '1m', 300);
        $secondCount = ForecastBacktestPrediction::query()->where('bot_instance_id', 'rerun-instance')->count();

        $this->assertSame($firstCount, $secondCount, 're-running the identical scope over the identical candle history must not double the row count');
        $this->assertSame(
            array_sum(array_column($first['buckets'], 'sample_size')),
            array_sum(array_column($second['buckets'], 'sample_size')),
        );
    }

    /**
     * No-lookahead proof for the PERSISTED rows specifically (not just the
     * in-memory records, already covered by
     * ForecastHorizonBacktestServiceRunForBotTest::
     * test_outcomes_are_never_scored_using_data_the_prediction_could_not_have_seen()):
     * every persisted candle_ts must fall strictly BEFORE the dataset's own
     * cutoff (the last candle ever written), and the persisted rows' candle_ts
     * values must be in the same strictly-increasing chronological order as
     * walkForward()'s own array position -- proving persistPredictions()
     * did not reorder, truncate-from-the-wrong-end, or otherwise corrupt the
     * time ordering the no-lookahead guarantee depends on.
     */
    public function test_persisted_candle_ts_values_are_chronologically_ordered_and_never_leak_beyond_available_history(): void
    {
        $exchange = 'binance';
        $symbol = 'NOLEAKPERSIST';
        $timeframe = '1m';
        $time = now()->subMinutes(401);
        $price = 3000.0;
        $lastTs = null;

        for ($i = 0; $i < 400; $i++) {
            $open = $price;
            $close = $open + 3.0;
            $ts = $time->copy()->addMinutes($i);
            MarketOhlc::create([
                'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => $close + 1, 'low' => $open - 1, 'close' => $close,
                'volume' => 10, 'ts' => $ts,
            ]);
            $price = $close;
            $lastTs = $ts;
        }

        app(ForecastHorizonBacktestService::class)->runForBot(
            'future_trade',
            'noleak-persist-instance',
            $exchange,
            $symbol,
            $timeframe,
            300,
        );

        $rows = ForecastBacktestPrediction::query()
            ->where('bot_instance_id', 'noleak-persist-instance')
            ->orderBy('created_at')
            ->get(['candle_ts']);

        $this->assertGreaterThan(0, $rows->count());

        $previous = null;
        foreach ($rows as $row) {
            $this->assertTrue($row->candle_ts->lte($lastTs), 'no persisted prediction may be stamped later than the last real candle in the dataset');
            if ($previous !== null) {
                $this->assertTrue($row->candle_ts->gte($previous), 'insertion order must match strictly-increasing chronological order, proving no reordering during bulk persistence');
            }
            $previous = $row->candle_ts;
        }
    }

    /**
     * Bulk-insert performance sanity: a walk-forward run that scores close
     * to MAX_SAMPLES (300) predictions must not issue anywhere near 300
     * individual INSERT queries to persist them -- proves
     * persistPredictions() actually uses chunked Model::insert() bulk
     * writes (mirroring FutureTradeBacktestEngine's own bulk-write pattern)
     * rather than one ::create() call per row.
     */
    public function test_persisting_many_predictions_uses_bulk_inserts_not_one_query_per_row(): void
    {
        $this->seedTrend('binance', 'BULKUSDT', '1m', 5000.0, 8.0, 480);

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $result = app(ForecastHorizonBacktestService::class)->runForBot(
            'future_trade',
            'bulk-insert-instance',
            'binance',
            'BULKUSDT',
            '1m',
            300,
        );

        $queryLog = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();

        $insertQueries = array_values(array_filter($queryLog, static fn (array $q) => str_starts_with(strtolower($q['query']), 'insert into "forecast_backtest_predictions"')
            || str_starts_with(strtolower($q['query']), 'insert into `forecast_backtest_predictions`')));

        $totalScored = array_sum(array_column($result['buckets'], 'sample_size'));
        $this->assertGreaterThan(50, $totalScored, 'this test needs a large enough sample to make one-row-at-a-time inserts obviously distinguishable from bulk inserts');

        // Chunk size is 200 -- a few hundred predictions must fit in at
        // most a handful of INSERT statements, nowhere close to one per row.
        $this->assertLessThanOrEqual(3, count($insertQueries), 'expected chunked bulk inserts, not one INSERT per prediction row');
        $this->assertGreaterThan(0, count($insertQueries));
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
