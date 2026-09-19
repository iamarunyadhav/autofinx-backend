<?php

namespace App\Domain\Nexus;

use App\Models\NexusEntry;

final class NexusDiscoveryService
{
    public function __construct(private readonly NexusStore $store) {}

    public function baseline(string $workspace, array $observations): void
    {
        // Seed hypotheses, explicitly labelled; these are not claims from external research.
        foreach ([
            ['scalping', 'Microstructure research dataset', 'NEW_DATA_SOURCE', 'crypto', 'Persistent spread, depth snapshots, trade flow and replay timestamps', 'Collect an immutable L2 dataset before comparing scalpers.'],
            ['metals', 'Metals session research', 'NEW_STRATEGY', 'metals', 'Broker bid/ask history, session tags and macro publication timestamps', 'Compare session-conditioned behaviour after spreads and costs.'],
            ['forecast', 'Forecast regime stability review', 'EXISTING_IMPROVEMENT', 'multi', 'Versioned predictions and chronological holdout outcomes', 'Compare calibration and net expectancy separately across regimes.'],
        ] as [$module, $title, $type, $market, $data, $plan]) {
            $this->candidate($workspace, $title, ['type' => $type, 'module' => $module, 'market' => $market,
                'idea' => $plan, 'data_required' => $data, 'origin' => 'BASELINE_HYPOTHESIS',
                'current_gap' => 'Dataset completeness and runtime support require verification.',
                'evidence' => [], 'limitations' => 'Seed research direction, not an externally discovered or validated strategy.']);
        }
        foreach ($observations['quick_trade_outcomes'] ?? [] as $scope) {
            if ((int) $scope['samples'] >= 30 && (float) $scope['booked_net_pnl'] < 0) {
                $this->candidate($workspace, 'Review negative booked expectancy: '.$scope['symbol'].' '.$scope['source'].' '.$scope['mode'].' '.$scope['expiry_seconds'].'s', [
                    'type' => 'EXISTING_IMPROVEMENT', 'module' => 'quick_trade', 'market' => $scope['symbol'],
                    'origin' => 'LOCAL_COMPUTATION', 'idea' => 'Investigate fees, entry filters and settlement outcomes for this exact scope.',
                    'data_required' => 'Reconciled costs and a longer independent evaluation window.', 'evidence' => [$scope],
                    'limitations' => '24-hour selection; an observation, not a causal diagnosis.']);
            }
        }
    }

    public function candidate(string $workspace, string $title, array $payload): NexusEntry
    {
        $payload['research_priority'] = app(NexusInnovationRankingService::class)->rank($payload);
        $key = hash('sha256', mb_strtolower(trim($title)).'|'.($payload['module'] ?? '').'|'.($payload['market'] ?? ''));

        // Repeated cycles must preserve operator approvals/rejections and evidence revisions.
        return NexusEntry::firstOrCreate(['workspace_id' => $workspace, 'kind' => 'candidate', 'fingerprint' => $key], [
            'title' => mb_substr($title, 0, 255), 'status' => 'DATA_REQUIRED', 'evidence_level' => 'C',
            'payload' => $payload + ['why_it_matters' => 'Potential research improvement; quantify before adoption.',
                'expected_benefit' => 'Unmeasured', 'risk' => 'Unvalidated research', 'testing_plan' => 'Use chronological holdout with costs and realistic fills.',
                'implementation_plan' => 'Verify capability, collect data, run experiment, review.',
                'rollback_plan' => 'Disable the separately approved feature; Nexus cannot change live trading.'],
        ]);
    }
}
