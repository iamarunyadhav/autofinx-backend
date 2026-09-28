<?php

namespace App\Domain\Prediction;

/** Documents and exposes research-only promotion thresholds; never auto-promotes. */
final class ShadowValidationPolicy
{
    public function assess(array $metrics): array
    {
        $samples = (int) ($metrics['samples'] ?? 0);
        $accuracy = $metrics['direction_accuracy_pct'] ?? null;
        $ready = $samples >= 100 && is_numeric($accuracy) && (float) $accuracy >= 55;

        return [
            'status' => $ready ? 'VALIDATION_CANDIDATE' : 'INSUFFICIENT_DATA',
            'samples_required' => 100,
            'minimum_direction_accuracy_pct' => 55,
            'samples' => $samples,
            'direction_accuracy_pct' => $accuracy,
            'auto_promote' => false,
            'execution_enabled' => false,
        ];
    }
}
