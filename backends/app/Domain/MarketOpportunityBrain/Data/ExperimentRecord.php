<?php

namespace App\Domain\MarketOpportunityBrain\Data;

/**
 * Immutable preregistered-experiment record (MASTER_SPEC.md Layer I).
 *
 * Field-for-field mirror of docs/market-opportunity-brain-v4/contracts/experiment.v1.example.json
 * (round-trip parity enforced by tests/Unit/MarketOpportunityBrain/ExperimentRecordContractTest.php).
 * Criteria/arms/holdout policy must be frozen before any result -- never modified after the fact.
 */
final readonly class ExperimentRecord
{
    public const SCHEMA = 'mob.experiment.v1';

    public function __construct(
        public string $experimentId,
        public string $status,
        public string $workspaceId,
        public string $hypothesis,
        public ?string $registrationSha256,
        public ?string $datasetSha256,
        public string $universePolicy,
        public string $timeframe,
        public int $horizonBars,
        public array $arms,
        public string $holdoutPolicy,
        public ?string $modelRevision,
        public ?string $costProfileVersion,
        public int $sampleCount,
        public ?array $metrics,
        public string $verdict,
    ) {
        if ($this->holdoutPolicy !== 'SEALED_NO_ACCESS' && str_contains(strtolower($this->holdoutPolicy), 'sealed')) {
            // Defensive: any policy that mentions "sealed" must use the exact canonical value, never a
            // near-miss string that could slip past a naive equality check elsewhere (D-046/D-050 freeze).
            throw new \InvalidArgumentException('ExperimentRecord.holdout_policy must be the exact literal SEALED_NO_ACCESS when sealed.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_key_exists('execution_authorized', $data) && $data['execution_authorized'] !== false) {
            throw new \InvalidArgumentException('ExperimentRecord.execution_authorized must always be false.');
        }

        return new self(
            experimentId: (string) ($data['experiment_id'] ?? ''),
            status: (string) ($data['status'] ?? 'PREREGISTRATION_DRAFT'),
            workspaceId: (string) ($data['workspace_id'] ?? ''),
            hypothesis: (string) ($data['hypothesis'] ?? ''),
            registrationSha256: $data['registration_sha256'] ?? null,
            datasetSha256: $data['dataset_sha256'] ?? null,
            universePolicy: (string) ($data['universe_policy'] ?? ''),
            timeframe: (string) ($data['timeframe'] ?? ''),
            horizonBars: (int) ($data['horizon_bars'] ?? 0),
            arms: (array) ($data['arms'] ?? []),
            holdoutPolicy: (string) ($data['holdout_policy'] ?? 'SEALED_NO_ACCESS'),
            modelRevision: $data['model_revision'] ?? null,
            costProfileVersion: $data['cost_profile_version'] ?? null,
            sampleCount: (int) ($data['sample_count'] ?? 0),
            metrics: $data['metrics'] ?? null,
            verdict: (string) ($data['verdict'] ?? 'NOT_RUN'),
        );
    }

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'experiment_id' => $this->experimentId,
            'status' => $this->status,
            'workspace_id' => $this->workspaceId,
            'hypothesis' => $this->hypothesis,
            'registration_sha256' => $this->registrationSha256,
            'dataset_sha256' => $this->datasetSha256,
            'universe_policy' => $this->universePolicy,
            'timeframe' => $this->timeframe,
            'horizon_bars' => $this->horizonBars,
            'arms' => array_values($this->arms),
            'holdout_policy' => $this->holdoutPolicy,
            'model_revision' => $this->modelRevision,
            'cost_profile_version' => $this->costProfileVersion,
            'sample_count' => $this->sampleCount,
            'metrics' => $this->metrics,
            'verdict' => $this->verdict,
            'execution_authorized' => false,
        ];
    }
}
