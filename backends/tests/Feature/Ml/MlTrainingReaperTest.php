<?php

namespace Tests\Feature\Ml;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Jobs\TrainMlShadowModelJob;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Ml\Support\MlTrainingTestSupport;
use Tests\TestCase;

final class MlTrainingReaperTest extends TestCase
{
    use MlTrainingTestSupport;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        $this->bootMlTraining();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->cleanMlTraining();
        date_default_timezone_set($this->originalTimezone);
        config(['app.timezone' => $this->originalTimezone]);
        parent::tearDown();
    }

    private function deadRun(array $attributes = []): MlTrainingRun
    {
        $model = MlModel::query()->create([
            'symbol' => $attributes['symbol'] ?? 'BTCUSDT', 'horizon_minutes' => $attributes['horizon_minutes'] ?? 60, 'timeframe' => '5m',
            'feature_schema_version' => 'v', 'model_type' => 'regression', 'status' => 'TRAINING',
        ]);

        return $this->makeRun($attributes + [
            'status' => MlTrainingRun::RUNNING, 'phase' => 'fitting', 'started_at' => $this->utcAgo(900), 'heartbeat_at' => $this->utcAgo(600),
            'model_id' => $model->id,
        ]);
    }

    private function uniqueLock(string $symbol = 'BTCUSDT', int $horizon = 60)
    {
        return app(CacheRepository::class)->lock(UniqueLock::getKey(new TrainMlShadowModelJob($symbol, $horizon)), 7200);
    }

    public function test_a_dead_run_is_interrupted_its_locks_released_and_it_is_requeued_with_backoff(): void
    {
        $run = $this->deadRun();
        $this->assertTrue(Cache::lock(MlShadowTrainer::lockKey('BTCUSDT', 60), 3600)->get());
        $this->assertTrue($this->uniqueLock()->get());

        $this->artisan('ml:training:reap')->expectsOutputToContain('interrupted 1')->assertSuccessful();

        $run->refresh();
        $this->assertSame(MlTrainingRun::INTERRUPTED, $run->status);
        $this->assertSame('INTERRUPTED', $run->error_code);
        $this->assertNotNull($run->finished_at);
        $model = MlModel::query()->findOrFail($run->model_id);
        $this->assertSame('FAILED', $model->status);
        $this->assertSame('interrupted', $model->decision_reason);

        // Both the per-pair training lock and the unique-job lock are free again.
        $trainLock = Cache::lock(MlShadowTrainer::lockKey('BTCUSDT', 60), 5);
        $this->assertTrue($trainLock->get());
        $trainLock->release();

        $retry = MlTrainingRun::query()->where('retry_of', $run->id)->sole();
        $this->assertSame(MlTrainingRun::QUEUED, $retry->status);
        $this->assertSame(2, $retry->attempt);
        $this->assertSame('scheduler', $retry->source);
        $this->assertEqualsWithDelta(time() + 120, $retry->next_retry_at->getTimestamp(), 10);
        Queue::assertPushed(TrainMlShadowModelJob::class, fn (TrainMlShadowModelJob $job) => $job->runId === $retry->id && $job->force === true && $job->delay === 120);
    }

    public function test_a_live_heartbeat_is_never_reaped(): void
    {
        $run = $this->deadRun(['heartbeat_at' => $this->utcAgo(20)]);

        $this->artisan('ml:training:reap')->assertSuccessful();

        $this->assertSame(MlTrainingRun::RUNNING, $run->refresh()->status);
        $this->assertSame(0, MlTrainingRun::query()->where('retry_of', $run->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_stall_detection_and_backoff_use_utc_under_asia_colombo(): void
    {
        config(['app.timezone' => 'Asia/Colombo']);
        date_default_timezone_set('Asia/Colombo');
        $live = $this->deadRun(['symbol' => 'ETHUSDT', 'heartbeat_at' => $this->utcAgo(30), 'started_at' => $this->utcAgo(300)]);
        $dead = $this->deadRun(['heartbeat_at' => $this->utcAgo(400)]);

        $this->artisan('ml:training:reap')->assertSuccessful();

        $this->assertSame(MlTrainingRun::RUNNING, $live->refresh()->status);   // 30 s old is not 5.5 h + 30 s old
        $this->assertSame(MlTrainingRun::INTERRUPTED, $dead->refresh()->status);
        $this->assertEqualsWithDelta(time(), $dead->finished_at->getTimestamp(), 10);
        $retry = MlTrainingRun::query()->where('retry_of', $dead->id)->sole();
        $this->assertEqualsWithDelta(time() + 120, $retry->next_retry_at->getTimestamp(), 10);
        $this->assertEqualsWithDelta(time() + 120, strtotime(DB::table('ml_training_runs')->where('id', $retry->id)->value('next_retry_at').' UTC'), 10);
    }

    public function test_a_run_that_never_beat_uses_its_start_time(): void
    {
        $run = $this->makeRun(['status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(900)]);

        $this->artisan('ml:training:reap')->assertSuccessful();

        $this->assertSame(MlTrainingRun::INTERRUPTED, $run->refresh()->status);
    }

    public function test_backoff_grows_and_max_attempts_is_respected(): void
    {
        $first = $this->deadRun();
        $this->artisan('ml:training:reap')->assertSuccessful();
        $second = MlTrainingRun::query()->where('retry_of', $first->id)->sole();
        $this->assertSame(2, $second->attempt);

        // Attempt 2 dies too: backoff 4 minutes, attempt 3.
        $second->forceFill(['status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(900), 'heartbeat_at' => $this->utcAgo(600), 'next_retry_at' => null])->save();
        $this->artisan('ml:training:reap')->assertSuccessful();
        $third = MlTrainingRun::query()->where('retry_of', $second->id)->sole();
        $this->assertSame(3, $third->attempt);
        $this->assertEqualsWithDelta(time() + 240, $third->next_retry_at->getTimestamp(), 10);

        // Attempt 3 of 3 dies: INTERRUPTED, no fourth attempt.
        $third->forceFill(['status' => MlTrainingRun::RUNNING, 'started_at' => $this->utcAgo(900), 'heartbeat_at' => $this->utcAgo(600), 'next_retry_at' => null])->save();
        $this->artisan('ml:training:reap')->assertSuccessful();
        $this->assertSame(MlTrainingRun::INTERRUPTED, $third->refresh()->status);
        $this->assertSame(0, MlTrainingRun::query()->where('retry_of', $third->id)->count());
        $this->assertSame(3, MlTrainingRun::query()->count());
    }

    public function test_a_second_pass_does_not_requeue_twice(): void
    {
        $run = $this->deadRun();

        $this->artisan('ml:training:reap')->assertSuccessful();
        $this->artisan('ml:training:reap')->assertSuccessful();

        $this->assertSame(1, MlTrainingRun::query()->where('retry_of', $run->id)->count());
        Queue::assertPushed(TrainMlShadowModelJob::class, 1);
    }

    public function test_rejected_timeout_stopped_and_other_failures_are_never_retried(): void
    {
        $finished = fn (array $a) => $this->makeRun($a + ['finished_at' => $this->utcAgo(60), 'started_at' => $this->utcAgo(600)]);
        $cases = [
            $finished(['symbol' => 'BTCUSDT', 'status' => MlTrainingRun::FINISHED, 'outcome' => 'REJECTED', 'outcome_reason' => 'mae_not_better_than_zero_return_baseline']),
            $finished(['symbol' => 'ETHUSDT', 'status' => MlTrainingRun::FAILED, 'error_code' => 'TIMEOUT']),
            $finished(['symbol' => 'BTCUSDT', 'horizon_minutes' => 120, 'status' => MlTrainingRun::STOPPED, 'error_code' => 'CANCELLED']),
            $finished(['symbol' => 'ETHUSDT', 'horizon_minutes' => 120, 'status' => MlTrainingRun::FAILED, 'error_code' => 'OTHER']),
            $finished(['symbol' => 'BTCUSDT', 'horizon_minutes' => 240, 'status' => MlTrainingRun::FAILED, 'error_code' => 'EXPORT_FAILED']),
        ];

        $this->artisan('ml:training:reap')->expectsOutputToContain('auto-retried 0')->assertSuccessful();

        $this->assertSame(count($cases), MlTrainingRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_python_crash_is_retried_but_an_old_failure_is_history(): void
    {
        $recent = $this->makeRun(['status' => MlTrainingRun::FAILED, 'error_code' => 'PYTHON_EXIT', 'finished_at' => $this->utcAgo(300), 'started_at' => $this->utcAgo(900)]);
        $old = $this->makeRun(['symbol' => 'ETHUSDT', 'status' => MlTrainingRun::FAILED, 'error_code' => 'PYTHON_EXIT', 'finished_at' => $this->utcAgo(3 * 86400), 'started_at' => $this->utcAgo(3 * 86400 + 600)]);

        $this->artisan('ml:training:reap')->assertSuccessful();

        $this->assertSame(1, MlTrainingRun::query()->where('retry_of', $recent->id)->count());
        $this->assertSame(0, MlTrainingRun::query()->where('retry_of', $old->id)->count());
    }

    public function test_a_cancelled_run_whose_owner_died_is_stopped_not_retried(): void
    {
        $run = $this->deadRun(['cancel_requested_at' => $this->utcAgo(100)]);

        $this->artisan('ml:training:reap')->assertSuccessful();

        $run->refresh();
        $this->assertSame(MlTrainingRun::STOPPED, $run->status);
        $this->assertSame('cancelled_by_operator', MlModel::query()->findOrFail($run->model_id)->decision_reason);
        $this->assertSame(0, MlTrainingRun::query()->where('retry_of', $run->id)->count());
    }

    public function test_auto_retry_can_be_switched_off_and_never_doubles_up_with_an_active_run(): void
    {
        config(['ml.training.auto_retry' => false]);
        $run = $this->deadRun();
        $this->artisan('ml:training:reap')->assertSuccessful();
        $this->assertSame(MlTrainingRun::INTERRUPTED, $run->refresh()->status);
        $this->assertSame(0, MlTrainingRun::query()->where('retry_of', $run->id)->count());

        config(['ml.training.auto_retry' => true]);
        $this->makeRun(['status' => MlTrainingRun::QUEUED]); // someone already re-queued the pair
        $this->artisan('ml:training:reap')->assertSuccessful();
        $this->assertSame(0, MlTrainingRun::query()->where('retry_of', $run->id)->count());
    }

    public function test_the_reaper_does_nothing_when_ml_is_disabled(): void
    {
        config(['ml.enabled' => false]);
        $run = $this->deadRun();

        $this->artisan('ml:training:reap')->assertSuccessful();

        $this->assertSame(MlTrainingRun::RUNNING, $run->refresh()->status);
    }

    public function test_the_reaper_is_scheduled_every_two_minutes_with_a_short_overlap_lock(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString("['ml:training:reap', 'everyTwoMinutes', 3]", $source);
    }
}
