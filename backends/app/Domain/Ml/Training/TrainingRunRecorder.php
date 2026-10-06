<?php

namespace App\Domain\Ml\Training;

use App\Models\MlTrainingRun;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Writes the state of a training run from inside the process that owns it
 * (MlShadowTrainer). Every write after begin() is conditional on the run still
 * being RUNNING, so a run the reaper already ended is never resurrected: the
 * owner learns that through TrainingRunLost and stops its child.
 */
class TrainingRunRecorder
{
    private const PROGRESS = ['queued' => 0, 'exporting' => 10, 'fitting' => 20, 'evaluating' => 90, 'promoting' => 95, 'done' => 100];

    /** Attach to a QUEUED run, or create one (CLI / scheduler), and set it RUNNING. */
    public function begin(?string $runId, string $symbol, int $horizon, string $type, string $source): MlTrainingRun
    {
        $now = MlTrainingRun::utcNow();
        $running = [
            'status' => MlTrainingRun::RUNNING, 'phase' => 'exporting', 'progress_pct' => self::PROGRESS['exporting'],
            'started_at' => $now, 'heartbeat_at' => $now, 'worker_pid' => getmypid() ?: null,
            'host' => mb_substr((string) gethostname(), 0, 120), 'updated_at' => $now,
        ];

        if ($runId !== null) {
            $affected = MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::QUEUED)->update($running + ['next_retry_at' => null]);
            if ($affected !== 1) {
                throw new TrainingRunNotStartable("Run {$runId} is not QUEUED any more.");
            }

            return MlTrainingRun::query()->findOrFail($runId);
        }

        $run = new MlTrainingRun([
            'symbol' => $symbol, 'horizon_minutes' => $horizon, 'model_type' => $type, 'source' => $source,
            'attempt' => 1, 'max_attempts' => max(1, (int) config('ml.training.max_attempts', 3)),
            'queued_at' => $now,
        ] + $running);
        $run->save();

        return $run;
    }

    /** Run could not even start (e.g. the per-pair lock is held by another trainer). */
    public function failQueued(string $runId, string $code, string $message): void
    {
        $now = MlTrainingRun::utcNow();
        MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::QUEUED)->update([
            'status' => MlTrainingRun::FAILED, 'phase' => 'queued', 'error_code' => $code, 'error_message' => mb_substr($this->redact($message), 0, 2000),
            'finished_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function linkModel(string $runId, string $modelId): void
    {
        MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update(['model_id' => $modelId, 'updated_at' => MlTrainingRun::utcNow()]);
    }

    public function exported(string $runId, int $rows): void
    {
        MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update(['rows_exported' => $rows, 'updated_at' => MlTrainingRun::utcNow()]);
    }

    /**
     * Move to a phase (also a heartbeat). With $checkCancel a pending operator
     * stop is honoured before the next expensive step starts.
     *
     * @throws TrainingRunLost
     * @throws TrainingCancelled
     */
    public function phase(string $runId, string $phase, bool $checkCancel = false): void
    {
        $now = MlTrainingRun::utcNow();
        $affected = MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update([
            'phase' => $phase, 'progress_pct' => self::PROGRESS[$phase] ?? null, 'heartbeat_at' => $now, 'updated_at' => $now,
        ]);
        if ($affected !== 1) {
            throw new TrainingRunLost("Run {$runId} is no longer RUNNING.");
        }
        if ($checkCancel && $this->cancelRequested($runId)) {
            throw new TrainingCancelled('Training stopped by the operator.');
        }
    }

    /**
     * Poll tick from the loop that waits on the python child: optionally write
     * the heartbeat (+ log tail), then report whether a stop was requested.
     *
     * @param  list<string>|null  $logLines  null = leave the stored tail alone
     *
     * @throws TrainingRunLost
     */
    public function tick(string $runId, bool $heartbeat, ?array $logLines): bool
    {
        if ($heartbeat) {
            $now = MlTrainingRun::utcNow();
            $update = ['heartbeat_at' => $now, 'updated_at' => $now];
            if ($logLines !== null) {
                $update['log_tail'] = $this->tailText($logLines);
            }
            if (MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update($update) !== 1) {
                throw new TrainingRunLost("Run {$runId} is no longer RUNNING.");
            }
        }

        return $this->cancelRequested($runId);
    }

    /** @param list<string> $lines */
    public function writeLog(string $runId, array $lines): void
    {
        MlTrainingRun::query()->whereKey($runId)->update(['log_tail' => $this->tailText($lines), 'updated_at' => MlTrainingRun::utcNow()]);
    }

    public function finish(string $runId, string $modelStatus, ?string $reason): void
    {
        $now = MlTrainingRun::utcNow();
        MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update([
            'status' => MlTrainingRun::FINISHED, 'outcome' => $modelStatus === 'CHAMPION' ? 'CHAMPION' : 'REJECTED',
            'outcome_reason' => $reason, 'phase' => 'done', 'progress_pct' => 100, 'finished_at' => $now, 'heartbeat_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** End a RUNNING run from the failure that stopped it. Returns the error_code written. */
    public function fail(string $runId, Throwable $exception, string $stage): string
    {
        $now = MlTrainingRun::utcNow();
        if ($exception instanceof TrainingCancelled) {
            MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update([
                'status' => MlTrainingRun::STOPPED, 'error_code' => 'CANCELLED', 'error_message' => 'Stopped by the operator.',
                'finished_at' => $now, 'updated_at' => $now,
            ]);

            return 'CANCELLED';
        }
        $code = self::classify($exception, $stage);
        $message = $exception->getMessage();
        if ($code === 'TIMEOUT') {
            $message .= ' The host may be overloaded; raise AUTOFINX_ML_TRAINING_TIMEOUT_SECONDS (and the queue retry_after) if a longer run is intended.';
        }
        MlTrainingRun::query()->whereKey($runId)->where('status', MlTrainingRun::RUNNING)->update([
            'status' => $code === 'INTERRUPTED' ? MlTrainingRun::INTERRUPTED : MlTrainingRun::FAILED,
            'error_code' => $code, 'error_message' => mb_substr($this->redact($message), 0, 2000),
            'finished_at' => $now, 'updated_at' => $now,
        ]);

        return $code;
    }

    public static function classify(Throwable $exception, string $stage): string
    {
        return match (true) {
            $exception instanceof ProcessTimedOutException => 'TIMEOUT',
            $exception instanceof TrainerProcessFailed => $exception->signaled ? 'INTERRUPTED' : 'PYTHON_EXIT',
            $exception instanceof TrainingRunLost => 'INTERRUPTED',
            $exception instanceof TrainingCancelled => 'CANCELLED',
            $stage === 'exporting' => 'EXPORT_FAILED',
            default => 'OTHER',
        };
    }

    public function cancelRequested(string $runId): bool
    {
        return MlTrainingRun::query()->whereKey($runId)->whereNotNull('cancel_requested_at')->exists();
    }

    /** @param list<string> $lines */
    private function tailText(array $lines): string
    {
        $keep = max(1, (int) config('ml.training.log_tail_lines', 40));
        $text = implode("\n", array_slice($lines, -$keep));

        return mb_strlen($text) > 12000 ? mb_substr($text, -12000) : $text;
    }

    /** Strip ANSI colour, credentials, bearer tokens and long opaque strings from log text. */
    public function redact(string $text): string
    {
        $text = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
        $text = (string) preg_replace('~://[^/\s:@]+:[^/\s@]+@~', '://[redacted]@', $text);
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $text);
        $text = (string) preg_replace('/((?:authorization|api[_-]?key|apikey|secret|token|passwd|password|private[_-]?key)["\']?\s*[:=]\s*)["\']?[^\s"\',;]+/i', '$1[redacted]', $text);
        $text = (string) preg_replace('/\b[A-Za-z0-9+\/_-]{40,}={0,2}/', '[redacted]', $text);

        return $text;
    }

    /** Redact, cap the line length, and drop empty lines. */
    public function cleanLine(string $line): ?string
    {
        $line = trim($this->redact(rtrim($line, "\r\n")));

        return $line === '' ? null : mb_substr($line, 0, 400);
    }

    public static function newRunId(): string
    {
        return (string) Str::uuid7();
    }
}
