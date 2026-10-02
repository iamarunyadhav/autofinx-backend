<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlModel;

/**
 * Decides whether a symbol × horizon has earned a new training attempt.
 * Pure read-only check: counts MATURE labels only (PENDING never counts).
 */
class RetrainEligibility
{
    /** @return array{eligible:bool, reason:string, mature_total:int, new_mature:int, required:int, last_trained_to_ms:?int} */
    public function check(string $symbol, int $horizon): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        // Only rows the trainer can actually use: MATURE labels that have a
        // feature row of the training schema (v2 needs taker volume).
        $mature = MlShadowTrainer::matureTrainingRows($symbol, $horizon, $timeframe, ShadowFeatureBuilder::SCHEMA_VERSION);
        $total = (clone $mature)->count();

        // FAILED/TRAINING attempts produced no evaluated model, so they do not
        // consume the new-label budget; the next run retries.
        $last = MlModel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_VERSION)
            ->whereIn('status', ['CANDIDATE', 'CHAMPION', 'REJECTED', 'RETIRED'])
            ->orderByDesc('trained_at')->first();

        if ($last === null) {
            $required = (int) config('ml.training.min_total_mature_labels', 20000);

            return [
                'eligible' => $total >= $required,
                'reason' => $total >= $required ? 'first_training' : 'insufficient_mature_labels',
                'mature_total' => $total, 'new_mature' => $total, 'required' => $required, 'last_trained_to_ms' => null,
            ];
        }

        // The holdout was already used to judge that model, so "new" labels
        // are counted from the end of its full data window, not its train end.
        $lastTo = max((int) $last->training_window_to_ms, (int) data_get($last->metrics, 'holdout_to_ms', 0));
        $new = (clone $mature)->where('l.anchor_time_ms', '>', $lastTo)->count();
        $required = (int) config('ml.training.min_new_mature_labels', 288);

        return [
            'eligible' => $new >= $required,
            'reason' => $new >= $required ? 'enough_new_mature_labels' : 'insufficient_new_mature_labels',
            'mature_total' => $total, 'new_mature' => $new, 'required' => $required, 'last_trained_to_ms' => $lastTo,
        ];
    }
}
