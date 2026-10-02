<?php

namespace App\Jobs;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\RetrainEligibility;
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
 */
final class TrainMlShadowModelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 7200;

    public function __construct(public readonly string $symbol, public readonly int $horizonMinutes)
    {
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
        if (! (bool) config('ml.enabled', false)) {
            return;
        }
        // Re-check at run time: the queue may have sat for a while.
        $check = $eligibility->check($this->symbol, $this->horizonMinutes);
        if (! $check['eligible']) {
            Log::info('ml.shadow.training_skipped', ['symbol' => $this->symbol, 'horizon_minutes' => $this->horizonMinutes] + $check);

            return;
        }

        // Each configured candidate type is its own attempt; one type failing
        // (recorded as a FAILED row) does not stop the others.
        $failure = null;
        foreach (MlShadowTrainer::configuredTypes() as $type) {
            try {
                $trainer->train($this->symbol, $this->horizonMinutes, $type);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
        }
        if ($failure) {
            // Already recorded as a FAILED registry row; do not retry-loop.
            $this->fail($failure);
        }
    }
}
