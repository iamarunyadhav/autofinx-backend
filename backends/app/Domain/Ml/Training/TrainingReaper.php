<?php

namespace App\Domain\Ml\Training;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Models\MlModel;
use App\Models\MlTrainingRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ends training runs whose owner is gone and re-queues the transient ones.
 *
 *  - RUNNING with no heartbeat for ml.training.stall_seconds: INTERRUPTED
 *    (STOPPED when the operator had already asked it to stop), its TRAINING
 *    model row FAILED, the per-pair lock and unique-job lock released.
 *  - INTERRUPTED and python-crash FAILED runs are re-queued with exponential
 *    backoff, at most max_attempts, source=scheduler. REJECTED (a valid
 *    result), TIMEOUT and STOPPED are never retried automatically.
 *
 * Cheap by design: indexed status/heartbeat scans of a small table, no
 * COUNT over the big ml tables.
 */
class TrainingReaper
{
    /** error_code values that are transient enough to retry on their own. */
    private const RETRIABLE_CODES = ['INTERRUPTED', 'PYTHON_EXIT'];

    /** Failures older than this are history, not something to re-queue after a deploy. */
    private const RETRY_WINDOW_HOURS = 24;

    public function __construct(private readonly TrainingRunService $service) {}

    /** @return array{interrupted:int, stopped:int, retried:int} */
    public function run(): array
    {
        $result = $this->reapStalled();
        $result['retried'] = (bool) config('ml.training.auto_retry', true) ? $this->autoRetry() : 0;

        return $result;
    }

    /** RUNNING runs that currently count as dead. */
    public function staleCount(): int
    {
        return MlTrainingRun::query()->where('status', MlTrainingRun::RUNNING)->where($this->staleCondition())->count();
    }

    /**
     * No heartbeat for stall_seconds, except in the export phase, which gets
     * export_stall_seconds: it can sit inside one long database query.
     */
    private function staleCondition(): \Closure
    {
        $stall = max(30, (int) config('ml.training.stall_seconds', 180));
        $cutoff = MlTrainingRun::utcNow(-$stall);
        $exportCutoff = MlTrainingRun::utcNow(-max($stall, (int) config('ml.training.export_stall_seconds', 1800)));
        $silent = static fn ($q, string $limit) => $q->where(function ($w) use ($limit): void {
            $w->where('heartbeat_at', '<', $limit)
                ->orWhere(fn ($n) => $n->whereNull('heartbeat_at')->where(fn ($s) => $s->whereNull('started_at')->orWhere('started_at', '<', $limit)));
        });

        return static fn ($q) => $q->where(function ($any) use ($silent, $cutoff, $exportCutoff): void {
            $any->where(fn ($e) => $silent($e->where('phase', 'exporting'), $exportCutoff))
                ->orWhere(fn ($o) => $silent($o->where(fn ($p) => $p->whereNull('phase')->orWhere('phase', '!=', 'exporting')), $cutoff));
        });
    }

    /** @return array{interrupted:int, stopped:int} */
    public function reapStalled(): array
    {
        $stale = $this->staleCondition();

        $interrupted = 0;
        $stopped = 0;
        $runs = MlTrainingRun::query()->where('status', MlTrainingRun::RUNNING)->where($stale)->orderBy('id')->get();
        foreach ($runs as $run) {
            $now = MlTrainingRun::utcNow();
            $cancelled = $run->cancel_requested_at !== null;
            // Conditional on the heartbeat still being stale: a live owner wins the race.
            $affected = MlTrainingRun::query()->whereKey($run->id)->where('status', MlTrainingRun::RUNNING)->where($stale)->update($cancelled ? [
                'status' => MlTrainingRun::STOPPED, 'error_code' => 'CANCELLED', 'error_message' => 'Stopped by the operator (the owning process had already ended).',
                'finished_at' => $now, 'updated_at' => $now,
            ] : [
                'status' => MlTrainingRun::INTERRUPTED, 'error_code' => 'INTERRUPTED',
                'error_message' => 'No heartbeat for over '.(int) config('ml.training.stall_seconds', 180).' s: the worker or host most likely restarted while this run was in progress.',
                'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($affected !== 1) {
                continue;
            }
            $cancelled ? $stopped++ : $interrupted++;

            if ($run->model_id) {
                MlModel::query()->whereKey($run->model_id)->where('status', 'TRAINING')
                    ->update(['status' => 'FAILED', 'decision_reason' => $cancelled ? 'cancelled_by_operator' : 'interrupted', 'updated_at' => now('UTC')]);
            }
            $this->releaseLocks($run);
            Log::warning('ml.shadow.training_run_reaped', ['run_id' => $run->id, 'symbol' => $run->symbol, 'horizon_minutes' => $run->horizon_minutes, 'status' => $cancelled ? 'STOPPED' : 'INTERRUPTED']);
        }

        return ['interrupted' => $interrupted, 'stopped' => $stopped];
    }

    public function autoRetry(): int
    {
        $since = MlTrainingRun::utcNow(-self::RETRY_WINDOW_HOURS * 3600);
        $candidates = MlTrainingRun::query()
            ->where(fn ($q) => $q->where('status', MlTrainingRun::INTERRUPTED)
                ->orWhere(fn ($f) => $f->where('status', MlTrainingRun::FAILED)->whereIn('error_code', self::RETRIABLE_CODES)))
            ->where('finished_at', '>=', $since)
            ->whereColumn('attempt', '<', 'max_attempts')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ml_training_runs as child')->whereColumn('child.retry_of', 'ml_training_runs.id'))
            ->orderBy('id')->get();

        $retried = 0;
        foreach ($candidates as $run) {
            if ($this->service->activeRunFor($run->symbol, (int) $run->horizon_minutes) !== null) {
                continue; // someone (or an earlier pass) already re-queued this pair
            }
            try {
                $this->service->retry($run, null, MlTrainingRun::SOURCE_SCHEDULER, $this->backoffSeconds((int) $run->attempt));
                $retried++;
            } catch (TrainingRunException $exception) {
                Log::info('ml.shadow.training_auto_retry_skipped', ['run_id' => $run->id, 'code' => $exception->errorCode]);
            }
        }

        return $retried;
    }

    /** Backoff before the retry that follows attempt $attempt (2, 4, 8 minutes by default). */
    public function backoffSeconds(int $attempt): int
    {
        $minutes = array_values((array) config('ml.training.retry_backoff_minutes', [2, 4, 8])) ?: [2];

        return (int) $minutes[min(max(1, $attempt) - 1, count($minutes) - 1)] * 60;
    }

    private function releaseLocks(MlTrainingRun $run): void
    {
        $symbol = $run->symbol;
        $horizon = (int) $run->horizon_minutes;
        // Another live run on the same pair owns the lock now: leave it alone.
        $otherLive = MlTrainingRun::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->where('status', MlTrainingRun::RUNNING)->whereKeyNot($run->id)->exists();
        if ($otherLive) {
            return;
        }
        try {
            Cache::lock(MlShadowTrainer::lockKey($symbol, $horizon))->forceRelease();
        } catch (Throwable $exception) {
            Log::warning('ml.shadow.training_lock_release_failed', ['run_id' => $run->id, 'error' => $exception->getMessage()]);
        }
        $this->service->releaseUniqueLock($symbol, $horizon);
    }
}
