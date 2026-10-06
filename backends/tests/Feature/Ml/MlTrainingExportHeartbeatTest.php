<?php

namespace Tests\Feature\Ml;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Training\TrainingCancelled;
use App\Domain\Ml\Training\TrainingReaper;
use App\Models\MlTrainingRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\Feature\Ml\Support\MlTrainingTestSupport;
use Tests\TestCase;

/** A run that is busy exporting must not be declared dead while it is alive. */
final class MlTrainingExportHeartbeatTest extends TestCase
{
    use MlTrainingTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMlTraining();
        Queue::fake();
        config(['ml.training.export_stall_seconds' => 1800]);
    }

    protected function tearDown(): void
    {
        $this->cleanMlTraining();
        parent::tearDown();
    }

    private function running(string $phase, int $silentSeconds): MlTrainingRun
    {
        return $this->makeRun(['status' => MlTrainingRun::RUNNING, 'phase' => $phase, 'started_at' => $this->utcAgo($silentSeconds + 30),
            'heartbeat_at' => $this->utcAgo($silentSeconds)]);
    }

    public function test_an_exporting_run_survives_ten_silent_minutes_but_a_fitting_run_does_not(): void
    {
        $exporting = $this->running('exporting', 600);
        $fitting = $this->makeRun(['symbol' => 'ETHUSDT', 'status' => MlTrainingRun::RUNNING, 'phase' => 'fitting',
            'started_at' => $this->utcAgo(630), 'heartbeat_at' => $this->utcAgo(600)]);

        $this->assertSame(1, app(TrainingReaper::class)->staleCount());
        app(TrainingReaper::class)->reapStalled();

        $this->assertSame(MlTrainingRun::RUNNING, $exporting->fresh()->status);
        $this->assertSame(MlTrainingRun::INTERRUPTED, $fitting->fresh()->status);
    }

    public function test_an_exporting_run_is_interrupted_after_the_export_stall_limit(): void
    {
        $exporting = $this->running('exporting', 2000);

        app(TrainingReaper::class)->reapStalled();

        $this->assertSame(MlTrainingRun::INTERRUPTED, $exporting->fresh()->status);
    }

    private function trainerWithRun(string $runId): MlShadowTrainer
    {
        $trainer = $this->app->make(MlShadowTrainer::class);
        $property = (new ReflectionClass(MlShadowTrainer::class))->getProperty('activeRunId');
        $property->setValue($trainer, $runId);

        return $trainer;
    }

    private function beat(MlShadowTrainer $trainer, bool $force): void
    {
        (new ReflectionClass(MlShadowTrainer::class))->getMethod('exportBeat')->invoke($trainer, $force);
    }

    public function test_the_export_beat_refreshes_the_heartbeat_and_is_throttled(): void
    {
        $run = $this->running('exporting', 120);
        $trainer = $this->trainerWithRun($run->id);

        $this->beat($trainer, true);
        $first = $run->fresh()->heartbeat_at;
        $this->assertGreaterThan(strtotime($this->utcAgo(60)), strtotime((string) $first));

        MlTrainingRun::query()->whereKey($run->id)->update(['heartbeat_at' => $this->utcAgo(120)]);
        $this->beat($trainer, false);                                  // inside the interval: no write
        $this->assertLessThan(strtotime($this->utcAgo(60)), strtotime((string) $run->fresh()->heartbeat_at));
    }

    public function test_the_export_beat_stops_the_export_when_the_operator_cancelled(): void
    {
        $run = $this->running('exporting', 5);
        MlTrainingRun::query()->whereKey($run->id)->update(['cancel_requested_at' => MlTrainingRun::utcNow()]);

        $this->expectException(TrainingCancelled::class);
        $this->beat($this->trainerWithRun($run->id), true);
    }

    public function test_ml_training_busy_reflects_a_running_run(): void
    {
        $this->assertSame(1, Artisan::call('ml:training:busy'));
        $this->running('fitting', 5);
        $this->assertSame(0, Artisan::call('ml:training:busy'));
        $this->assertStringContainsString('busy: BTCUSDT', Artisan::output());
    }
}
