<?php

namespace App\Domain\Prediction;

/** Conservative checklist report; it never changes configuration or enables execution. */
final class LiveReadinessReport
{
    /** @param array<string, mixed> $evidence */
    public function generate(array $evidence): array
    {
        $checks = [
            'sample_size' => (int) ($evidence['samples'] ?? 0) >= 500,
            'non_overlapping_samples' => (int) ($evidence['non_overlapping_samples'] ?? 0) >= 100,
            'direction_accuracy' => is_numeric($evidence['direction_accuracy_pct'] ?? null) && (float) $evidence['direction_accuracy_pct'] >= 55,
            'walk_forward' => ($evidence['walk_forward_status'] ?? '') === 'PASS',
            'confidence_calibration' => ($evidence['calibration_status'] ?? '') === 'PASS',
            'provider_stability' => ($evidence['stability_status'] ?? '') === 'PASS',
            'latency' => is_numeric($evidence['p95_latency_ms'] ?? null) && (float) $evidence['p95_latency_ms'] <= 2000,
            'drawdown_reviewed' => ($evidence['drawdown_review_status'] ?? '') === 'PASS',
        ];

        return [
            'status' => !in_array(false, $checks, true) ? 'MANUAL_REVIEW_REQUIRED' : 'NOT_READY',
            'checks' => $checks,
            'failed_checks' => array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed)),
            'auto_promote' => false,
            'execution_enabled' => false,
            'required_next_step' => 'human_review',
        ];
    }
}
