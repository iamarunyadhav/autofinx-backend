<?php

namespace Tests\Feature\Ml;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Training\MlDoctor;
use App\Domain\Ml\Training\TrainingPipelineHeartbeats;
use App\Domain\Ml\Training\TrainingQueueStats;
use App\Domain\Ml\Training\TrainingReaper;
use App\Domain\Ml\Training\TrainingRunService;
use App\Domain\Ml\Training\TrainingSystemStats;
use App\Models\MlCandle;
use App\Models\MlFeatureRow;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Ml\Support\MlTrainingTestSupport;
use Tests\TestCase;

final class MlDoctorTest extends TestCase
{
    use MlTrainingTestSupport;

    private string $proc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMlTraining();
        Queue::fake();
        config(['ml.symbols' => ['BTCUSDT'], 'ml.base_timeframe' => '5m']);
        $this->proc = sys_get_temp_dir().'/mldoctor-proc-'.uniqid();
        File::ensureDirectoryExists($this->proc);
        file_put_contents($this->proc.'/loadavg', '0.10 0.10 0.10 1/100 1');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->proc);
        $this->cleanMlTraining();
        parent::tearDown();
    }

    private function fakeProcess(int $pid, int $ppid, string $cmd): void
    {
        File::ensureDirectoryExists("{$this->proc}/{$pid}");
        file_put_contents("{$this->proc}/{$pid}/cmdline", str_replace(' ', "\0", $cmd)."\0");
        file_put_contents("{$this->proc}/{$pid}/stat", "{$pid} (php) S {$ppid} 0 0 0");
    }

    private function doctor(): MlDoctor
    {
        return new MlDoctor(
            new TrainingSystemStats(), app(TrainingQueueStats::class), app(TrainingPipelineHeartbeats::class),
            app(TrainingReaper::class), app(TrainingRunService::class), $this->proc,
        );
    }

    /** @return list<string> "LEVEL [section] message" */
    private function lines(bool $fix = false): array
    {
        return array_map(fn ($f) => "{$f['level']} [{$f['section']}] {$f['message']}", $this->doctor()->run($fix));
    }

    private function has(array $lines, string $needle): bool
    {
        foreach ($lines as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function staleTrainingModel(string $symbol = 'BTCUSDT', int $horizon = 240): MlModel
    {
        $model = MlModel::query()->create(['symbol' => $symbol, 'horizon_minutes' => $horizon, 'timeframe' => '5m',
            'feature_schema_version' => 'v', 'model_type' => 'side_classifier', 'status' => 'TRAINING']);
        MlModel::query()->whereKey($model->id)->update(['created_at' => now()->subMinutes(45)]);

        return $model->refresh();
    }

    public function test_a_training_row_whose_trainer_is_gone_is_reported_and_only_fixed_with_fix(): void
    {
        $model = $this->staleTrainingModel();

        $this->assertTrue($this->has($this->lines(false), 'WARN [Registry] 1 TRAINING model row(s) have no live trainer'));
        $this->assertSame('TRAINING', $model->fresh()->status);

        $lines = $this->lines(true);
        $this->assertTrue($this->has($lines, 'FIXED [Registry] Marked 1 TRAINING model row(s) FAILED'));
        $this->assertSame('FAILED', $model->fresh()->status);
    }

    public function test_a_training_row_is_left_alone_while_a_trainer_process_is_running(): void
    {
        $model = $this->staleTrainingModel();
        $this->fakeProcess(4001, 1, '/usr/local/bin/ea-php82 -d memory_limit=2048M artisan ml:train BTCUSDT 240 --ignore-eligibility');

        $lines = $this->lines(true);

        $this->assertSame('TRAINING', $model->fresh()->status);
        $this->assertTrue($this->has($lines, 'INFO [Registry] 1 TRAINING model row(s) have no run record'));
        $this->assertTrue($this->has($lines, 'OK [Processes] One trainer is running'));
    }

    public function test_a_dead_run_is_reaped_with_fix_and_only_reported_without(): void
    {
        $model = $this->staleTrainingModel();
        $run = $this->makeRun(['status' => MlTrainingRun::RUNNING, 'phase' => 'fitting', 'started_at' => $this->utcAgo(900),
            'heartbeat_at' => $this->utcAgo(600), 'model_id' => $model->id]);

        $this->assertTrue($this->has($this->lines(false), '1 RUNNING run(s) have had no heartbeat'));
        $this->assertSame(MlTrainingRun::RUNNING, $run->fresh()->status);

        $this->assertTrue($this->has($this->lines(true), 'FIXED [Registry] 1 RUNNING run(s) had no heartbeat'));
        $this->assertSame(MlTrainingRun::INTERRUPTED, $run->fresh()->status);
    }

    public function test_two_trainers_and_an_orphan_python_are_flagged_but_never_killed(): void
    {
        $this->fakeProcess(4001, 1, '/usr/local/bin/ea-php82 artisan ml:train ETHUSDT 240 --ignore-eligibility');
        $this->fakeProcess(4002, 1, '/usr/local/bin/ea-php82 artisan ml:train BNBUSDT 240 --ignore-eligibility');
        $this->fakeProcess(4003, 1, '/venv/bin/python /app/external-predictive-engine/train_side_classifier.py --input x');
        $this->fakeProcess(4004, 4001, '/venv/bin/python /app/external-predictive-engine/train_side_classifier.py --input y');

        $lines = $this->lines(true);

        $this->assertTrue($this->has($lines, 'WARN [Processes] 2 ml:train processes are running at once'));
        $this->assertTrue($this->has($lines, 'Orphan python trainer pid 4003'));
        $this->assertFalse($this->has($lines, 'Orphan python trainer pid 4004'));   // has a live ml:train parent
    }

    public function test_a_lock_held_by_nothing_is_released_only_with_fix_and_never_during_training(): void
    {
        $key = MlShadowTrainer::lockKey('BTCUSDT', 240);
        $this->assertTrue(Cache::lock($key, 3600)->get());

        $this->assertTrue($this->has($this->lines(false), 'WARN [Locks] 1 training lock(s) held while nothing is training (BTCUSDT h240)'));
        $this->assertFalse(Cache::lock($key, 5)->get());   // still held

        $this->fakeProcess(4001, 1, '/usr/local/bin/ea-php82 artisan ml:train BTCUSDT 240 --ignore-eligibility');
        $this->assertTrue($this->has($this->lines(true), 'INFO [Locks] A training is active'));
        $this->assertFalse(Cache::lock($key, 5)->get());   // untouched while a trainer runs

        File::deleteDirectory("{$this->proc}/4001");
        $this->assertTrue($this->has($this->lines(true), 'FIXED [Locks] Released 1 orphaned training lock(s)'));
        $this->assertTrue(Cache::lock($key, 5)->get());
    }

    public function test_a_queue_retry_after_that_is_too_short_for_the_job_timeout_is_a_failure(): void
    {
        config(['ml.training.timeout_seconds' => 1800, 'queue.connections.ml_training.retry_after' => 1900]);
        $this->assertTrue($this->has($this->lines(), 'FAIL [Config] queue retry_after (1900s)'));

        config(['queue.connections.ml_training.retry_after' => 2400]);
        $this->assertTrue($this->has($this->lines(), 'OK [Config] queue retry_after 2400s is above the job timeout'));
    }

    public function test_disabled_ml_is_a_failure(): void
    {
        config(['ml.enabled' => false]);
        $this->assertTrue($this->has($this->lines(), 'FAIL [Config] ML shadow learning is disabled'));
    }

    public function test_candle_and_feature_freshness_and_history_gaps_are_reported(): void
    {
        $interval = 300_000;
        $nowMs = now('UTC')->getTimestampMs();
        $this->assertTrue($this->has($this->lines(), 'FAIL [Data] BTCUSDT: no 5m candles at all'));

        $candle = fn (int $open) => MlCandle::query()->create(['provider' => 'binance_futures', 'symbol' => 'BTCUSDT', 'timeframe' => '5m',
            'open_time_ms' => $open, 'close_time_ms' => $open + $interval - 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1, 'source' => 't']);
        $candle($nowMs - 2 * 3_600_000);
        $lines = $this->lines();
        $this->assertTrue($this->has($lines, 'FAIL [Data] BTCUSDT: newest 5m candle closed'));
        $this->assertTrue($this->has($lines, '(stale)'));
        $this->assertTrue($this->has($lines, 'WARN [Data] BTCUSDT: history starts'));         // only one candle: the 5-year target is missed
        $this->assertTrue($this->has($lines, 'FAIL [Data] BTCUSDT: no feature rows'));

        MlCandle::query()->delete();
        $candle(intdiv($nowMs, $interval) * $interval - $interval);
        MlFeatureRow::query()->create(['symbol' => 'BTCUSDT', 'timeframe' => '5m', 'anchor_time_ms' => $nowMs - 3 * 3_600_000, 'anchor_close' => 1,
            'feature_schema_version' => 'v', 'features' => ['a' => 1]]);
        $lines = $this->lines();
        $this->assertTrue($this->has($lines, 'OK [Data] BTCUSDT: newest 5m candle closed'));
        $this->assertTrue($this->has($lines, 'WARN [Data] BTCUSDT: features are'));
    }

    public function test_the_command_prints_a_summary_and_maps_findings_to_the_exit_code(): void
    {
        config(['ml.enabled' => false]);
        $exit = Artisan::call('ml:doctor');
        $output = Artisan::output();

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('ml:doctor:', $output);
        $this->assertStringContainsString('read-only; add --fix to repair', $output);

        $exit = Artisan::call('ml:doctor', ['--fix' => true]);
        $this->assertStringNotContainsString('read-only', Artisan::output());
        $this->assertSame(2, $exit);
    }
}
