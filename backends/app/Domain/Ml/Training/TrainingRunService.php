<?php

namespace App\Domain\Ml\Training;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Jobs\ResumeMlBackfillJob;
use App\Jobs\TrainMlShadowModelJob;
use App\Models\MlBackfillCheckpoint;
use App\Models\MlTrainingRun;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Control-plane writes: start, batch, stop, retry. All of it queues work for
 * the existing ml_training worker; nothing here trains inline and nothing can
 * reach an order path. A run is created only after the per-pair unique job
 * lock is held, so two jobs for the same pair can never be queued.
 */
class TrainingRunService
{
    /** Preset => horizons (minutes). */
    public const PRESETS = ['long' => [240, 1440], 'mid' => [120, 60], 'short' => [15]];

    public function __construct(private readonly FeatureLabelService $features) {}

    public function assertEnabled(): void
    {
        if (! (bool) config('ml.enabled', false)) {
            throw new TrainingRunException(TrainingRunException::ML_DISABLED, 'ML shadow learning is disabled.');
        }
    }

    public function normalizeSymbol(string $raw): string
    {
        $symbol = strtoupper(str_replace(['/', '-', '_', ' '], '', $raw));
        if (! in_array($symbol, (array) config('ml.symbols', []), true)) {
            throw new TrainingRunException(TrainingRunException::UNKNOWN_SYMBOL, "Symbol {$raw} is not configured for ML shadow learning.");
        }

        return $symbol;
    }

    public function assertHorizon(int $horizon): void
    {
        if (! in_array($horizon, $this->features->horizons(), true)) {
            throw new TrainingRunException(TrainingRunException::UNKNOWN_HORIZON, "Horizon {$horizon} is not configured (horizons: ".implode(',', $this->features->horizons()).').');
        }
    }

    public function defaultType(): string
    {
        return MlShadowTrainer::configuredTypes()[0];
    }

    public function activeRunFor(string $symbol, int $horizon): ?MlTrainingRun
    {
        return MlTrainingRun::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->whereIn('status', MlTrainingRun::ACTIVE)->orderByDesc('id')->first();
    }

    /**
     * Queue one run. $force skips only the new-label eligibility check inside
     * the job; the per-pair training lock still applies.
     */
    public function start(string $symbol, int $horizon, ?string $type, string $source, ?string $userId, ?string $batchId = null, ?MlTrainingRun $retryOf = null, ?int $delaySeconds = null, bool $force = true): MlTrainingRun
    {
        $this->assertEnabled();
        $symbol = $this->normalizeSymbol($symbol);
        $this->assertHorizon($horizon);
        $type ??= $this->defaultType();
        if (! in_array($type, MlShadowTrainer::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown candidate type: {$type}");
        }
        if ($this->activeRunFor($symbol, $horizon) !== null) {
            throw new TrainingRunException(TrainingRunException::ALREADY_ACTIVE, "A training run for {$symbol} h{$horizon} is already queued or running.");
        }

        $id = TrainingRunRecorder::newRunId();
        $job = new TrainMlShadowModelJob($symbol, $horizon, $id, $force);
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $job->delay($delaySeconds);
        }
        if (! (new UniqueLock(app(CacheRepository::class)))->acquire($job)) {
            throw new TrainingRunException(TrainingRunException::ALREADY_ACTIVE, "A training job for {$symbol} h{$horizon} is already queued.");
        }

        $now = MlTrainingRun::utcNow();
        $attempt = $retryOf ? $retryOf->attempt + 1 : 1;
        $maxAttempts = max(1, (int) config('ml.training.max_attempts', 3));
        $run = new MlTrainingRun([
            'id' => $id, 'symbol' => $symbol, 'horizon_minutes' => $horizon, 'model_type' => $type,
            'status' => MlTrainingRun::QUEUED, 'phase' => 'queued', 'progress_pct' => 0, 'source' => $source,
            'triggered_by' => $userId, 'batch_id' => $batchId, 'attempt' => $attempt,
            // A manual retry past the limit raises it, so it never triggers further auto-retries.
            'max_attempts' => $retryOf ? max($retryOf->max_attempts, $source === MlTrainingRun::SOURCE_UI ? $attempt : 0) : $maxAttempts,
            'retry_of' => $retryOf?->id, 'queued_at' => $now,
            'next_retry_at' => $delaySeconds ? MlTrainingRun::utcNow($delaySeconds) : null,
        ]);
        $run->save();

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $exception) {
            $this->releaseUniqueLock($symbol, $horizon);
            $run->delete();

            throw $exception;
        }

        return $run->refresh();
    }

    /**
     * @param  list<array{symbol:string, horizon_minutes:int}>  $pairs
     * @return array{batch_id:string, runs:list<MlTrainingRun>, skipped:list<array<string, mixed>>}
     */
    public function startBatch(array $pairs, ?string $type, ?string $userId): array
    {
        $this->assertEnabled();
        // Validate everything first: an unknown pair rejects the whole batch.
        $clean = [];
        foreach ($pairs as $pair) {
            $symbol = $this->normalizeSymbol((string) $pair['symbol']);
            $horizon = (int) $pair['horizon_minutes'];
            $this->assertHorizon($horizon);
            $clean[$symbol.':'.$horizon] = [$symbol, $horizon];
        }

        $batchId = (string) Str::uuid7();
        $runs = [];
        $skipped = [];
        foreach ($clean as [$symbol, $horizon]) {
            try {
                $runs[] = $this->start($symbol, $horizon, $type, MlTrainingRun::SOURCE_UI, $userId, $batchId);
            } catch (TrainingRunException $exception) {
                if ($exception->errorCode !== TrainingRunException::ALREADY_ACTIVE) {
                    throw $exception;
                }
                $skipped[] = ['symbol' => $symbol, 'horizon_minutes' => $horizon, 'reason' => TrainingRunException::ALREADY_ACTIVE];
            }
        }

        return ['batch_id' => $batchId, 'runs' => $runs, 'skipped' => $skipped];
    }

    /**
     * Expand a preset into pairs. Horizons that are not configured are left
     * out (a preset is a convenience, not a way to train an unknown horizon).
     *
     * @param  list<string>|null  $symbols
     * @return list<array{symbol:string, horizon_minutes:int}>
     */
    public function presetPairs(string $preset, ?array $symbols): array
    {
        $configured = $this->features->horizons();
        $symbols = $symbols === null || $symbols === [] ? array_values((array) config('ml.symbols', [])) : array_map(fn ($s) => $this->normalizeSymbol((string) $s), $symbols);
        $pairs = [];
        foreach (array_values(array_unique($symbols)) as $symbol) {
            foreach (self::PRESETS[$preset] ?? [] as $horizon) {
                if (in_array($horizon, $configured, true)) {
                    $pairs[] = ['symbol' => $symbol, 'horizon_minutes' => $horizon];
                }
            }
        }

        return $pairs;
    }

    /** QUEUED: cancelled at once. RUNNING: cooperative flag read by the owning process. */
    public function stop(MlTrainingRun $run): MlTrainingRun
    {
        if (! $run->isActive() || $run->cancel_requested_at !== null) {
            throw new TrainingRunException(TrainingRunException::NOT_STOPPABLE, 'Only a queued or running run that has not already been asked to stop can be stopped.');
        }
        if ($this->stopOne($run) === null) {
            throw new TrainingRunException(TrainingRunException::NOT_STOPPABLE, 'The run is no longer stoppable.');
        }

        return $run->refresh();
    }

    /** @return array{stopped:int, cancelled:int} stopped = RUNNING flagged, cancelled = QUEUED ended now */
    public function stopBatch(string $batchId): array
    {
        $result = ['stopped' => 0, 'cancelled' => 0];
        MlTrainingRun::query()->where('batch_id', $batchId)->whereIn('status', MlTrainingRun::ACTIVE)->whereNull('cancel_requested_at')
            ->orderBy('id')->get()->each(function (MlTrainingRun $run) use (&$result): void {
                $outcome = $this->stopOne($run);
                if ($outcome === MlTrainingRun::QUEUED) {
                    $result['cancelled']++;
                } elseif ($outcome === MlTrainingRun::RUNNING) {
                    $result['stopped']++;
                }
            });

        return $result;
    }

    /** @return string|null the status the run was in when stopped, null when it was no longer stoppable */
    private function stopOne(MlTrainingRun $run): ?string
    {
        $now = MlTrainingRun::utcNow();
        $cancelled = MlTrainingRun::query()->whereKey($run->id)->where('status', MlTrainingRun::QUEUED)->update([
            'status' => MlTrainingRun::STOPPED, 'cancel_requested_at' => $now, 'finished_at' => $now, 'next_retry_at' => null,
            'error_code' => 'CANCELLED', 'error_message' => 'Cancelled before it started.', 'updated_at' => $now,
        ]);
        if ($cancelled === 1) {
            // The queued job becomes a no-op; free the pair so a new run can be queued now.
            $this->releaseUniqueLock($run->symbol, (int) $run->horizon_minutes);

            return MlTrainingRun::QUEUED;
        }
        $flagged = MlTrainingRun::query()->whereKey($run->id)->where('status', MlTrainingRun::RUNNING)->whereNull('cancel_requested_at')
            ->update(['cancel_requested_at' => $now, 'updated_at' => $now]);

        return $flagged === 1 ? MlTrainingRun::RUNNING : null;
    }

    /** New run for the same pair: attempt + 1, retry_of = this run. */
    public function retry(MlTrainingRun $run, ?string $userId, string $source = MlTrainingRun::SOURCE_UI, ?int $delaySeconds = null): MlTrainingRun
    {
        $this->assertEnabled();
        if (! $this->canRetry($run)) {
            throw new TrainingRunException(TrainingRunException::NOT_RETRYABLE, 'Only a failed, stopped or interrupted run that has not been retried yet can be retried.');
        }

        return $this->start($run->symbol, (int) $run->horizon_minutes, $run->model_type, $source, $userId, $run->batch_id, $run, $delaySeconds);
    }

    public function canRetry(MlTrainingRun $run): bool
    {
        if (! in_array($run->status, [MlTrainingRun::FAILED, MlTrainingRun::STOPPED, MlTrainingRun::INTERRUPTED], true)) {
            return false;
        }

        return ! MlTrainingRun::query()->where('retry_of', $run->id)->exists();
    }

    /**
     * Queue a resumable `ml:candles:backfill --no-build` for one symbol (unique
     * per symbol). A checkpoint that is RUNNING and still moving means a
     * backfill is live; one with no progress for 10 minutes is dead, so its
     * leftover lock is released and the job resumes from the checkpoint.
     *
     * @return array<string, mixed>
     */
    public function resumeBackfill(string $symbol): array
    {
        $this->assertEnabled();
        $symbol = $this->normalizeSymbol($symbol);
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $checkpoint = MlBackfillCheckpoint::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->orderByDesc('updated_at')->first();
        if ($checkpoint !== null && $checkpoint->status === 'RUNNING') {
            if ($checkpoint->updated_at !== null && $checkpoint->updated_at->gte(now()->subMinutes(10))) {
                throw new TrainingRunException(TrainingRunException::ALREADY_ACTIVE, "A backfill for {$symbol} is already running.");
            }
            Cache::lock('ml:shadow:backfill:'.$symbol.':'.$timeframe)->forceRelease();
        }

        $job = new ResumeMlBackfillJob($symbol);
        if (! (new UniqueLock(app(CacheRepository::class)))->acquire($job)) {
            throw new TrainingRunException(TrainingRunException::ALREADY_ACTIVE, "A backfill job for {$symbol} is already queued.");
        }
        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $exception) {
            app(CacheRepository::class)->lock(UniqueLock::getKey($job))->forceRelease();

            throw $exception;
        }

        return ['name' => 'ml:candles:backfill --no-build', 'symbol' => $symbol, 'status' => 'queued', 'queue' => (string) config('ml.training.queue', 'ml_training'), 'queued_at' => now('UTC')->toIso8601String()];
    }

    public function releaseUniqueLock(string $symbol, int $horizon): void
    {
        try {
            app(CacheRepository::class)->lock(UniqueLock::getKey(new TrainMlShadowModelJob($symbol, $horizon)))->forceRelease();
        } catch (Throwable) {
            // Best effort: the lock also expires on its own (uniqueFor).
        }
    }
}
