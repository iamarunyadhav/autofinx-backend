<?php

namespace App\Domain\Nexus;

final class NexusInnovationRankingService
{
    /** Transparent research triage only. Never a probability of profit. */
    public function rank(array $candidate): array
    {
        $observed = ($candidate['origin'] ?? '') === 'LOCAL_COMPUTATION';
        $hasSources = ! empty($candidate['evidence']);
        $hasPlan = ! empty($candidate['data_required']);

        return ['score' => ($observed ? 30 : 10) + ($hasSources ? 20 : 0) + ($hasPlan ? 10 : 0),
            'basis' => ['workspace_observation' => $observed, 'source_evidence' => $hasSources, 'data_plan' => $hasPlan],
            'meaning' => 'Research triage score out of 60; not profitability or confidence.'];
    }
}
