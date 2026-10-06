<?php

namespace App\Domain\Ml\Training;

use App\Models\MlTrainingRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * RunSummary (docs/ML_TRAINING_DASHBOARD_PLAN.md section 3). Everything time-based
 * is computed here, on the server, from UTC instants: heartbeat age, elapsed
 * and ETA never depend on the browser clock or the app timezone.
 */
class TrainingRunPresenter
{
    /** @return array<string, mixed> */
    public function summary(MlTrainingRun $run): array
    {
        return $this->summaries(collect([$run]))[0];
    }

    /**
     * @param  iterable<MlTrainingRun>  $runs
     * @return list<array<string, mixed>>
     */
    public function summaries(iterable $runs): array
    {
        $runs = Collection::make($runs)->values();
        if ($runs->isEmpty()) {
            return [];
        }
        $context = $this->context($runs);

        return $runs->map(fn (MlTrainingRun $run): array => $this->row($run, $context))->all();
    }

    /** @return array{medians: array<int, int|null>, retried: array<string, true>, active: array<string, true>} */
    private function context(Collection $runs): array
    {
        $medians = [];
        foreach ($runs->pluck('horizon_minutes')->unique()->values() as $horizon) {
            $medians[(int) $horizon] = $this->medianDuration((int) $horizon);
        }
        $retried = MlTrainingRun::query()->whereIn('retry_of', $runs->pluck('id')->all())->pluck('retry_of')
            ->mapWithKeys(fn ($id) => [(string) $id => true])->all();
        $active = MlTrainingRun::query()->whereIn('status', MlTrainingRun::ACTIVE)->get(['symbol', 'horizon_minutes'])
            ->mapWithKeys(fn ($r) => [$r->symbol.':'.$r->horizon_minutes => true])->all();

        return ['medians' => $medians, 'retried' => $retried, 'active' => $active];
    }

    /** Median wall time (s) of the last 5 FINISHED runs of this horizon; null with no history. */
    private function medianDuration(int $horizon): ?int
    {
        $durations = MlTrainingRun::query()->where('horizon_minutes', $horizon)->where('status', MlTrainingRun::FINISHED)
            ->whereNotNull('started_at')->whereNotNull('finished_at')->orderByDesc('id')->limit(5)->get(['started_at', 'finished_at'])
            ->map(fn ($r) => max(0, $r->finished_at->getTimestamp() - $r->started_at->getTimestamp()))->sort()->values();
        if ($durations->isEmpty()) {
            return null;
        }
        $n = $durations->count();

        return (int) ($n % 2 === 1 ? $durations[intdiv($n, 2)] : round(($durations[$n / 2 - 1] + $durations[$n / 2]) / 2));
    }

    /** @return array<string, mixed> */
    private function row(MlTrainingRun $run, array $context): array
    {
        $now = now()->getTimestamp();
        $running = $run->status === MlTrainingRun::RUNNING;
        $elapsed = $run->started_at ? max(0, ($run->finished_at?->getTimestamp() ?? $now) - $run->started_at->getTimestamp()) : null;
        $median = $context['medians'][(int) $run->horizon_minutes] ?? null;
        $retryable = in_array($run->status, [MlTrainingRun::FAILED, MlTrainingRun::STOPPED, MlTrainingRun::INTERRUPTED], true)
            && ! isset($context['retried'][$run->id])
            && ! isset($context['active'][$run->symbol.':'.$run->horizon_minutes]);

        return [
            'id' => $run->id, 'symbol' => $run->symbol, 'horizon_minutes' => (int) $run->horizon_minutes, 'model_type' => $run->model_type,
            'status' => $run->status, 'outcome' => $run->outcome, 'outcome_reason' => $run->outcome_reason, 'phase' => $run->phase,
            'progress_pct' => $run->progress_pct, 'source' => $run->source, 'triggered_by' => $run->triggered_by, 'batch_id' => $run->batch_id,
            'attempt' => (int) $run->attempt, 'max_attempts' => (int) $run->max_attempts, 'retry_of' => $run->retry_of,
            'next_retry_at' => $this->iso($run->next_retry_at), 'queued_at' => $this->iso($run->queued_at), 'started_at' => $this->iso($run->started_at),
            'heartbeat_at' => $this->iso($run->heartbeat_at),
            'heartbeat_age_seconds' => $running && $run->heartbeat_at ? max(0, $now - $run->heartbeat_at->getTimestamp()) : null,
            'finished_at' => $this->iso($run->finished_at), 'elapsed_seconds' => $elapsed,
            'eta_seconds' => $running && $median !== null && $elapsed !== null ? max(0, $median - $elapsed) : null,
            'rows_exported' => $run->rows_exported, 'error_code' => $run->error_code, 'error_message' => $run->error_message,
            'can_stop' => $run->isActive() && $run->cancel_requested_at === null,
            'can_retry' => $retryable, 'model_id' => $run->model_id,
        ];
    }

    private function iso(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }
}
