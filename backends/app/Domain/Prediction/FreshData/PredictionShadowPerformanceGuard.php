<?php

namespace App\Domain\Prediction\FreshData;

/**
 * Read-only model-health assessment for an already shadow-only artifact.
 * It deliberately has no registry, execution, or publication dependency.
 */
final class PredictionShadowPerformanceGuard
{
    /** @return array{status:string,reason:string,live_samples:int,live_direction_accuracy_pct:?float,training_holdout_direction_accuracy_pct:?float} */
    public function assess(?float $trainingAccuracy, ?float $liveAccuracy, int $samples): array
    {
        $minimumSamples = max(1, (int) config('prediction_fresh_data.monitoring.minimum_live_samples', 30));
        if ($samples < $minimumSamples || $liveAccuracy === null) {
            return ['status' => 'INSUFFICIENT_LIVE_DATA', 'reason' => 'awaiting_settled_shadow_observations', 'live_samples' => $samples, 'live_direction_accuracy_pct' => $liveAccuracy, 'training_holdout_direction_accuracy_pct' => $trainingAccuracy];
        }

        $minimumAccuracy = (float) config('prediction_fresh_data.monitoring.minimum_live_direction_accuracy_pct', 52);
        $maximumDrop = (float) config('prediction_fresh_data.monitoring.maximum_accuracy_drop_from_holdout_pct', 10);
        if ($liveAccuracy < $minimumAccuracy) {
            return ['status' => 'REVIEW_REQUIRED', 'reason' => 'live_shadow_accuracy_below_minimum', 'live_samples' => $samples, 'live_direction_accuracy_pct' => $liveAccuracy, 'training_holdout_direction_accuracy_pct' => $trainingAccuracy];
        }
        if ($trainingAccuracy !== null && $trainingAccuracy - $liveAccuracy >= $maximumDrop) {
            return ['status' => 'REVIEW_REQUIRED', 'reason' => 'live_shadow_accuracy_materially_below_holdout', 'live_samples' => $samples, 'live_direction_accuracy_pct' => $liveAccuracy, 'training_holdout_direction_accuracy_pct' => $trainingAccuracy];
        }

        return ['status' => 'SHADOW_HEALTHY', 'reason' => 'live_shadow_outcomes_within_review_bounds', 'live_samples' => $samples, 'live_direction_accuracy_pct' => $liveAccuracy, 'training_holdout_direction_accuracy_pct' => $trainingAccuracy];
    }
}
