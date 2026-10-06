<?php

namespace Tests\Feature\Ml;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\RetrainEligibility;
use App\Domain\Ml\Training\TrainerProcessFailed;
use App\Domain\Ml\Training\TrainingRunRecorder;
use App\Jobs\TrainMlShadowModelJob;
use App\Models\MlChampion;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Feature\Ml\Support\HookedProcess;
use Tests\Feature\Ml\Support\MlTrainingTestSupport;
use Tests\TestCase;

final class MlTrainingRunRecordingTest extends TestCase
{
    use MlTrainingTestSupport;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->bootMlTraining();
    }

    protected function tearDown(): void
    {
        $this->cleanMlTraining();
        date_default_timezone_set($this->originalTimezone);
        config(['app.timezone' => $this->originalTimezone]);
        parent::tearDown();
    }

    public function test_cli_start_records_a_finished_run(): void
    {
        $this->succeedWith();

        $this->artisan('ml:train', ['symbol' => 'BTCUSDT', 'horizon' => 60, '--type' => 'regression', '--ignore-eligibility' => true])->assertSuccessful();

        $run = MlTrainingRun::query()->sole();
        $model = MlModel::query()->sole();
        $this->assertSame('cli', $run->source);
        $this->assertSame(MlTrainingRun::FINISHED, $run->status);
        $this->assertSame('CHAMPION', $run->outcome);
        $this->assertSame('first_champion_passed_absolute_checks', $run->outcome_reason);
        $this->assertSame('done', $run->phase);
        $this->assertEquals(100, $run->progress_pct);
        $this->assertSame(1234, $run->rows_exported);
        $this->assertSame($model->id, $run->model_id);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->heartbeat_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(getmypid(), $run->worker_pid);
        $this->assertNotSame('', (string) $run->host);
    }

    public function test_queue_job_without_a_run_records_a_scheduler_run(): void
    {
        $this->succeedWith();

        (new TrainMlShadowModelJob('BTCUSDT', 60))->handle(app(RetrainEligibility::class), $this->trainer);

        $run = MlTrainingRun::query()->sole();
        $this->assertSame('scheduler', $run->source);
        $this->assertSame(MlTrainingRun::FINISHED, $run->status);
    }

    public function test_queue_job_attaches_to_the_ui_run_and_force_skips_only_the_eligibility_check(): void
    {
        $this->succeedWith();
        config(['ml.training.min_total_mature_labels' => 1_000_000]); // nothing is eligible
        $run = $this->makeRun();

        (new TrainMlShadowModelJob('BTCUSDT', 60, $run->id, force: true))->handle(app(RetrainEligibility::class), $this->trainer);

        $run->refresh();
        $this->assertSame(MlTrainingRun::FINISHED, $run->status);
        $this->assertSame('ui', $run->source);
        $this->assertSame(1, MlTrainingRun::query()->count()); // attached, not duplicated

        // Without force the same ineligible request is skipped, recorded, and never trains.
        $other = $this->makeRun(['symbol' => 'ETHUSDT']);
        (new TrainMlShadowModelJob('ETHUSDT', 60, $other->id, force: false))->handle(app(RetrainEligibility::class), $this->trainer);
        $other->refresh();
        $this->assertSame(MlTrainingRun::STOPPED, $other->status);
        $this->assertStringContainsString('not eligible', (string) $other->error_message);
        $this->assertSame(1, MlModel::query()->count());
    }

    public function test_force_never_skips_the_training_lock(): void
    {
        $this->succeedWith();
        $run = $this->makeRun();
        $lock = Cache::lock(MlShadowTrainer::lockKey('BTCUSDT', 60), 600);
        $this->assertTrue($lock->get());

        try {
            // The job swallows the refusal into $this->fail() (no queue job attached here); the run records it.
            (new TrainMlShadowModelJob('BTCUSDT', 60, $run->id, force: true))->handle(app(RetrainEligibility::class), $this->trainer);
        } finally {
            $lock->release();
        }

        $run->refresh();
        $this->assertSame(MlTrainingRun::FAILED, $run->status);
        $this->assertSame('OTHER', $run->error_code);
        $this->assertStringContainsString('already running', (string) $run->error_message);
        $this->assertSame(0, MlModel::query()->count());
    }

    public function test_the_unique_job_id_is_the_pair_lock_whatever_the_run(): void
    {
        $a = new TrainMlShadowModelJob('BTCUSDT', 60, 'run-a', true);
        $b = new TrainMlShadowModelJob('BTCUSDT', 60);
        $c = new TrainMlShadowModelJob('BTCUSDT', 120);
        $this->assertSame($a->uniqueId(), $b->uniqueId());
        $this->assertNotSame($a->uniqueId(), $c->uniqueId());
        $this->assertSame('ml_training', $a->connection);
    }

    public function test_a_stopped_queued_run_is_not_trained_by_its_job(): void
    {
        $run = $this->makeRun(['status' => MlTrainingRun::STOPPED, 'cancel_requested_at' => MlTrainingRun::utcNow()]);

        (new TrainMlShadowModelJob('BTCUSDT', 60, $run->id, true))->handle(app(RetrainEligibility::class), $this->trainer);

        $this->assertSame(0, MlModel::query()->count());
        $this->assertSame(MlTrainingRun::STOPPED, $run->refresh()->status);
    }

    public function test_rejected_model_is_a_finished_run_not_a_failure(): void
    {
        $this->succeedWith(mae: 0.6); // worse than the zero-return baseline 0.5

        $model = $this->trainer->train('BTCUSDT', 60);

        $run = MlTrainingRun::query()->sole();
        $this->assertSame('REJECTED', $model->status);
        $this->assertSame(MlTrainingRun::FINISHED, $run->status);
        $this->assertSame('REJECTED', $run->outcome);
        $this->assertSame('mae_not_better_than_zero_return_baseline', $run->outcome_reason);
        $this->assertNull($run->error_code);
    }

    public function test_failures_are_classified_and_leave_the_champion_untouched(): void
    {
        $this->succeedWith();
        $champion = $this->trainer->train('BTCUSDT', 60);
        $this->assertSame('CHAMPION', $champion->status);

        $timedOut = new Process(['x'], null, null, null, 5);
        $cases = [
            'PYTHON_EXIT' => fn () => throw new TrainerProcessFailed('ML shadow training failed: boom', 1, false),
            'INTERRUPTED' => fn () => throw new TrainerProcessFailed('ML shadow training failed: killed', null, true),
            'TIMEOUT' => fn () => throw new ProcessTimedOutException($timedOut, ProcessTimedOutException::TYPE_GENERAL),
            'OTHER' => fn () => throw new RuntimeException('metadata exploded'),
        ];
        foreach ($cases as $code => $throw) {
            $this->trainer->trainerResult = fn () => $throw();
            try {
                $this->trainer->train('BTCUSDT', 60);
                $this->fail('expected failure for '.$code);
            } catch (RuntimeException) {
            }
            $run = MlTrainingRun::query()->orderByDesc('id')->first();
            $this->assertSame($code, $run->error_code, $code);
            $this->assertSame($code === 'INTERRUPTED' ? MlTrainingRun::INTERRUPTED : MlTrainingRun::FAILED, $run->status, $code);
            $this->assertNotNull($run->finished_at);
            $this->assertSame('FAILED', MlModel::query()->findOrFail($run->model_id)->status);
        }
        $this->assertStringContainsString('AUTOFINX_ML_TRAINING_TIMEOUT_SECONDS', (string) MlTrainingRun::query()->where('error_code', 'TIMEOUT')->value('error_message'));

        $this->trainer->trainerResult = null;
        $this->trainer->exportHook = fn () => throw new RuntimeException('export broke');
        try {
            $this->trainer->train('BTCUSDT', 60);
            $this->fail('expected export failure');
        } catch (RuntimeException) {
        }
        $this->assertSame('EXPORT_FAILED', MlTrainingRun::query()->orderByDesc('id')->value('error_code'));

        $this->assertSame($champion->id, MlChampion::query()->where('symbol', 'BTCUSDT')->where('horizon_minutes', 60)->value('model_id'));
        $this->assertSame('CHAMPION', $champion->refresh()->status);
    }

    public function test_heartbeat_is_written_while_the_child_runs_and_the_log_tail_is_kept(): void
    {
        $this->trainer->processFactory = fn () => $this->phpChild('echo "fitting started\n"; fflush(STDOUT); sleep(4);');
        config(['ml.training.python_bin' => PHP_BINARY, 'ml.training.script' => __FILE__]);

        try {
            $this->trainer->train('BTCUSDT', 60);
            $this->fail('the child writes no metadata, so the run must fail');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no metadata', $exception->getMessage());
        }

        $run = MlTrainingRun::query()->sole();
        $this->assertSame(MlTrainingRun::FAILED, $run->status);
        $this->assertSame('OTHER', $run->error_code);
        // Started, then beat at least twice more while the child slept 4 s (1 s heartbeat setting).
        $this->assertGreaterThanOrEqual(2, $run->heartbeat_at->getTimestamp() - $run->started_at->getTimestamp());
        $this->assertStringContainsString('fitting started', (string) $run->log_tail);
    }

    public function test_cooperative_stop_ends_a_long_running_child_and_marks_the_run_stopped(): void
    {
        config(['ml.training.python_bin' => PHP_BINARY, 'ml.training.script' => __FILE__]);
        $child = new HookedProcess([PHP_BINARY, '-r', 'echo "working\n"; sleep(120);'], null, null, null, 300);
        $child->onPoll = fn () => MlTrainingRun::query()->update(['cancel_requested_at' => MlTrainingRun::utcNow()]);
        $this->trainer->processFactory = fn () => $child;
        $started = microtime(true);

        try {
            $this->trainer->train('BTCUSDT', 60);
            $this->fail('expected the stop to surface');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(\App\Domain\Ml\Training\TrainingCancelled::class, $exception);
        }

        $this->assertLessThan(30, microtime(true) - $started, 'a 120 s child must be stopped within seconds');
        $this->assertFalse($child->isRunning());
        $run = MlTrainingRun::query()->sole();
        $this->assertSame(MlTrainingRun::STOPPED, $run->status);
        $this->assertSame('CANCELLED', $run->error_code);
        $model = MlModel::query()->sole();
        $this->assertSame('FAILED', $model->status);
        $this->assertSame('cancelled_by_operator', $model->decision_reason);
        $this->assertNull(app(\App\Domain\Ml\Shadow\MlModelRegistry::class)->champion('BTCUSDT', 60));
        $lock = Cache::lock(MlShadowTrainer::lockKey('BTCUSDT', 60), 5);
        $this->assertTrue($lock->get(), 'the per-pair lock must be released after a stop');
        $lock->release();
    }

    public function test_a_stop_requested_before_the_child_starts_never_spawns_it(): void
    {
        $run = $this->makeRun(['cancel_requested_at' => MlTrainingRun::utcNow()]);
        $spawned = false;
        $this->trainer->processFactory = function () use (&$spawned) {
            $spawned = true;

            return $this->phpChild('sleep(30);');
        };
        config(['ml.training.python_bin' => PHP_BINARY, 'ml.training.script' => __FILE__]);

        try {
            $this->trainer->train('BTCUSDT', 60, 'regression', $run->id);
            $this->fail('expected cancel');
        } catch (RuntimeException) {
        }

        $this->assertFalse($spawned);
        $this->assertSame(MlTrainingRun::STOPPED, $run->refresh()->status);
    }

    public function test_a_run_ended_by_the_reaper_stops_its_child_and_is_not_overwritten(): void
    {
        config(['ml.training.python_bin' => PHP_BINARY, 'ml.training.script' => __FILE__]);
        $child = new HookedProcess([PHP_BINARY, '-r', 'sleep(120);'], null, null, null, 300);
        $child->onPoll = fn () => MlTrainingRun::query()->update(['status' => MlTrainingRun::INTERRUPTED, 'error_code' => 'INTERRUPTED']);
        $this->trainer->processFactory = fn () => $child;

        try {
            $this->trainer->train('BTCUSDT', 60);
            $this->fail('expected lost run');
        } catch (RuntimeException) {
        }

        $this->assertFalse($child->isRunning());
        $run = MlTrainingRun::query()->sole();
        $this->assertSame(MlTrainingRun::INTERRUPTED, $run->status);
        $this->assertSame('interrupted', MlModel::query()->sole()->decision_reason);
    }

    public function test_real_child_end_to_end_promotes_and_redacts_the_log(): void
    {
        config(['ml.training.python_bin' => PHP_BINARY, 'ml.training.script' => __FILE__]);
        $this->trainer->processFactory = function (array $command) {
            $directory = $command[array_search('--output-dir', $command, true) + 1];
            $metadata = base64_encode((string) json_encode($this->metadata(0.40)));
            $code = '$d='.var_export($directory, true).'; @mkdir($d, 0777, true);'
                .'foreach (["model.txt","q10.txt","q90.txt"] as $f) { file_put_contents("$d/$f", $f.uniqid()); }'
                .'file_put_contents("$d/metadata.json", base64_decode("'.$metadata.'"));'
                .'echo "epoch 1 done api_key=SUPERSECRETVALUE123\n"; echo "Authorization: Bearer abc.def.ghi\n";';

            return $this->phpChild($code);
        };

        $model = $this->trainer->train('BTCUSDT', 60);

        $this->assertSame('CHAMPION', $model->status);
        $run = MlTrainingRun::query()->sole();
        $this->assertSame(MlTrainingRun::FINISHED, $run->status);
        $this->assertSame('CHAMPION', $run->outcome);
        $this->assertStringContainsString('epoch 1 done', (string) $run->log_tail);
        $this->assertStringNotContainsString('SUPERSECRETVALUE123', (string) $run->log_tail);
        $this->assertStringNotContainsString('abc.def.ghi', (string) $run->log_tail);
    }

    public function test_redaction(): void
    {
        $recorder = app(TrainingRunRecorder::class);
        $this->assertStringNotContainsString('hunter2', $recorder->redact('password: hunter2'));
        $this->assertStringNotContainsString('s3cr3t', $recorder->redact('postgres://user:s3cr3t@host/db'));
        $this->assertStringNotContainsString(str_repeat('A', 50), $recorder->redact('blob '.str_repeat('A', 50)));
        $this->assertSame('plain line 12', $recorder->redact("\e[31mplain line 12\e[0m"));
        $this->assertNull($recorder->cleanLine("   \r\n"));
    }

    public function test_run_timestamps_are_utc_under_asia_colombo(): void
    {
        config(['app.timezone' => 'Asia/Colombo']);
        date_default_timezone_set('Asia/Colombo');
        $this->succeedWith();

        $this->trainer->train('BTCUSDT', 60);

        $raw = DB::table('ml_training_runs')->first();
        $utcNow = time();
        foreach (['queued_at', 'started_at', 'heartbeat_at', 'finished_at', 'created_at', 'updated_at'] as $column) {
            $stored = strtotime($raw->{$column}.' UTC');
            $this->assertEqualsWithDelta($utcNow, $stored, 10, "{$column} must be stored as UTC wall time, got {$raw->{$column}}");
        }
        $run = MlTrainingRun::query()->sole();
        $this->assertStringEndsWith('+00:00', $run->started_at->toIso8601String());
        $this->assertEqualsWithDelta($utcNow, $run->started_at->getTimestamp(), 10);

        // Eloquent writes (created_at/updated_at through the cast) are UTC too.
        $manual = $this->makeRun(['symbol' => 'ETHUSDT']);
        $this->assertEqualsWithDelta($utcNow, strtotime(DB::table('ml_training_runs')->where('id', $manual->id)->value('created_at').' UTC'), 10);
    }
}
