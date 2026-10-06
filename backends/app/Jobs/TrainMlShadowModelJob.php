<?php

namespace App\Jobs;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\RetrainEligibility;
use App\Domain\Ml\Training\TrainingCancelled;
use App\Domain\Ml\Training\TrainingRunNotStartable;
use App\Domain\Ml\Training\TrainingRunRecorder;
use App\Models\MlTrainingRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queue-only shadow model training for one symbol × horizon. It has no
 * live-trading dependency: it can only produce a CANDIDATE and, if it beats
 * the champion out of sample, swap the SHADOW champion. A failure leaves the
 * champion untouched.
 *
 * With $runId the job serves one control-plane run (ml_training_runs) of that
 * run's candidate type; $force skips ONLY the new-label eligibility check,
 * never the per-pair training lock. The unique id is the per-pair lock key,
 * so two jobs for the same pair can never be queued either way.
 */
final class TrainMlShadowModelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 7200;

    /** Not constructor-promoted/readonly on purpose: jobs serialized before runId/force existed must still unserialize with these defaults. */
    public ?string $runId = null;

    public bool $force = false;

    public function __construct(
        public readonly string $symbol,
        public readonly int $horizonMinutes,
        ?string $runId = null,
        bool $force = false,
    ) {
        $this->runId = $runId;
        $this->force = $force;
        $this->timeout = (int) config('ml.training.timeout_seconds', 1800) + 120;
        $this->onConnection((string) config('ml.training.connection', 'ml_training'));
        $this->onQueue((string) config('ml.training.queue', 'ml_training'));
    }

    public function uniqueId(): string
    {
        return MlShadowTrainer::lockKey($this->symbol, $this->horizonMinutes);
    }

    public function handle(RetrainEligibility $eligibility, MlShadowTrainer $trainer): void
    {
        $recorder = app(TrainingRunRecorder::class);
        $run = null;
        if ($this->runId !== null) {
            $run = MlTrainingRun::query()->find($this->runId);
            // Cancelled while queued, or already picked up/ended: nothing to do.
            if ($run === null || $run->status !== MlTrainingRun::QUEUED) {
                Log::info('ml.shadow.training_run_not_queued', ['run_id' => $this->runId, 'status' => $run?->status]);

                return;
            }
        }
        if (! (bool) config('ml.enabled', false)) {
            if ($run) {
                $recorder->failQueued($run->id, 'OTHER', 'ML shadow learning is disabled.');
            }

            return;
        }
        // Re-check at run time: the queue may have sat for a while. A manual
        // run ($force) does not need new labels.
        if (! $this->force) {
            $check = $eligibility->check($this->symbol, $this->horizonMinutes);
            if (! $check['eligible']) {
                Log::info('ml.shadow.training_skipped', ['symbol' => $this->symbol, 'horizon_minutes' => $this->horizonMinutes] + $check);
                if ($run) {
                    $this->skipRun($run, "Skipped: not eligible ({$check['reason']}).");
                }

                return;
            }
        }

        // Each configured candidate type is its own attempt; one type failing
        // (recorded as a FAILED row) does not stop the others. A run is one type.
        $types = $run ? [$run->model_type] : MlShadowTrainer::configuredTypes();
        $source = MlTrainingRun::SOURCE_SCHEDULER;
        $failure = null;
        foreach ($types as $type) {
            try {
                $trainer->train($this->symbol, $this->horizonMinutes, $type, $run?->id, $source);
            } catch (TrainingCancelled) {
                // Operator stop: recorded as STOPPED, not a job failure.
            } catch (TrainingRunNotStartable) {
                // Lost the race to a stop; nothing ran.
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
        }
        if ($failure) {
            // Already recorded as a FAILED registry row; do not retry-loop.
            $this->fail($failure);
        }
    }

    private function skipRun(MlTrainingRun $run, string $message): void
    {
        $now = MlTrainingRun::utcNow();
        MlTrainingRun::query()->whereKey($run->id)->where('status', MlTrainingRun::QUEUED)->update([
            'status' => MlTrainingRun::STOPPED, 'error_code' => 'OTHER', 'error_message' => $message, 'finished_at' => $now, 'next_retry_at' => null, 'updated_at' => $now,
        ]);
    }
}
