<?php

namespace App\Domain\Ml\Shadow;

use App\Models\NexusEntry;

/**
 * Builds bounded, review-only research hypotheses from shadow diagnostics.
 * This deliberately does not call an LLM or mutate an ML model. A hosted/local
 * provider can consume the stored evidence later, but the evidence and safety
 * contract are always recorded first.
 */
final class ShadowHypothesisService
{
    public function create(string $workspace, string $symbol, array $diagnostics, ?string $note = null): NexusEntry
    {
        $buckets = (array) ($diagnostics['failure_buckets'] ?? []);
        $hypotheses = [];
        if (($buckets['DATA'] ?? 0) > 0) {
            $hypotheses[] = ['bucket' => 'DATA', 'hypothesis' => 'Validate timestamp alignment, missing candles, and label completeness before changing model features.', 'experiment' => 'Replay the same window after dropping incomplete rows and compare holdout metrics.'];
        }
        if (($buckets['MODEL'] ?? 0) > 0) {
            $hypotheses[] = ['bucket' => 'MODEL', 'hypothesis' => 'Probability calibration or feature drift may be suppressing directional decisions.', 'experiment' => 'Compare calibrated and uncalibrated probabilities on an untouched holdout.'];
        }
        if (($buckets['COST'] ?? 0) > 0) {
            $hypotheses[] = ['bucket' => 'COST', 'hypothesis' => 'Expected move may be below the round-trip fee, slippage, and funding hurdle.', 'experiment' => 'Measure gross edge and net edge separately; do not lower the cost gate.'];
        }
        if (($buckets['REGIME_OR_UNKNOWN'] ?? 0) > 0 || $hypotheses === []) {
            $hypotheses[] = ['bucket' => 'REGIME_OR_UNKNOWN', 'hypothesis' => 'The observed window may not represent the training market regime.', 'experiment' => 'Run chronological walk-forward and regime-sliced holdout evaluation.'];
        }

        $fingerprint = hash('sha256', json_encode(['shadow-hypothesis-v1', $workspace, $symbol, $diagnostics['counts'] ?? [], $diagnostics['sample_count'] ?? 0], JSON_THROW_ON_ERROR));

        return NexusEntry::firstOrCreate(
            ['workspace_id' => $workspace, 'kind' => 'candidate', 'fingerprint' => $fingerprint],
            [
                'title' => 'Shadow research hypotheses: '.$symbol,
                'status' => 'PROPOSED',
                'evidence_level' => 'C',
                'payload' => [
                    'source' => 'ml_shadow_hypothesis_v1',
                    'symbol' => $symbol,
                    'diagnostics' => $diagnostics,
                    'hypotheses' => $hypotheses,
                    'user_note' => $note,
                    'provider_status' => 'READY_FOR_LLM_REVIEW',
                    'research_only' => true,
                    'execution_authorized' => false,
                    'promotion_requires' => ['unseen_holdout', 'net_cost_gate', 'human_review'],
                ],
            ]
        );
    }
}
