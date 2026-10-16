<?php

namespace App\Domain\MarketOpportunityBrain\Data;

use App\Domain\MarketOpportunityBrain\Enums\ResearchDecision;

/**
 * Immutable research decision record.
 *
 * Field-for-field mirror of docs/market-opportunity-brain-v4/contracts/decision.v1.example.json
 * (round-trip parity enforced by tests/Unit/MarketOpportunityBrain/DecisionRecordContractTest.php).
 *
 * Hard safety invariant (MASTER_SPEC.md Layer G §76, Section M test #15): this record can NEVER
 * carry order fields (`quantity`, `leverage`, `api_key`, `order_type`, ...) and `execution_authorized`
 * is always false / `mode` is always RESEARCH_ONLY -- neither can be overridden by input data, even
 * accidentally. fromArray() throws rather than silently accepting a tampered contract.
 */
final readonly class DecisionRecord
{
    public const SCHEMA = 'mob.decision.v1';

    public const MODE = 'RESEARCH_ONLY';

    /** Fields this record must NEVER carry -- MASTER_SPEC.md Layer G §76. */
    private const FORBIDDEN_FIELDS = ['quantity', 'leverage', 'api_key', 'order_type', 'order_id', 'exchange_order_id'];

    public function __construct(
        public string $decisionId,
        public string $workspaceId,
        public string $exchange,
        public string $market,
        public string $symbol,
        public string $baseTimeframe,
        public int $horizonBars,
        public string $decisionAsOfUtc,
        public string $inputsKnownByUtc,
        public ?string $candidateId,
        public ?string $forecastId,
        public string $policyId,
        public string $policyVersion,
        public ResearchDecision $decision,
        public ?string $directionalBias,
        public array $reasonCodes,
        public bool $forecastCalibrated,
        public string $dataQuality,
        public string $simulationCostProfile,
        public ?string $inputHashSha256,
        public ?float $realizedLivePnl = null,
        public ?float $hypotheticalNetReturn = null,
        public ?string $note = null,
    ) {
        if ($realizedLivePnl !== null && $hypotheticalNetReturn !== null) {
            // Section M test #19 / MASTER_SPEC.md Layer H §88: never conflate hypothetical and live P&L.
            throw new \InvalidArgumentException('DecisionRecord cannot carry both realized_live_pnl and hypothetical_net_return.');
        }
    }

    public static function fromArray(array $data): self
    {
        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                throw new \InvalidArgumentException("DecisionRecord must never carry field: {$field}");
            }
        }
        if (array_key_exists('execution_authorized', $data) && $data['execution_authorized'] !== false) {
            throw new \InvalidArgumentException('DecisionRecord.execution_authorized must always be false.');
        }
        if (array_key_exists('mode', $data) && $data['mode'] !== self::MODE) {
            throw new \InvalidArgumentException('DecisionRecord.mode must always be RESEARCH_ONLY.');
        }

        return new self(
            decisionId: (string) ($data['decision_id'] ?? ''),
            workspaceId: (string) ($data['workspace_id'] ?? ''),
            exchange: (string) ($data['exchange'] ?? ''),
            market: (string) ($data['market'] ?? ''),
            symbol: (string) ($data['symbol'] ?? ''),
            baseTimeframe: (string) ($data['base_timeframe'] ?? ''),
            horizonBars: (int) ($data['horizon_bars'] ?? 0),
            decisionAsOfUtc: (string) ($data['decision_as_of_utc'] ?? ''),
            inputsKnownByUtc: (string) ($data['inputs_known_by_utc'] ?? ''),
            candidateId: $data['candidate_id'] ?? null,
            forecastId: $data['forecast_id'] ?? null,
            policyId: (string) ($data['policy_id'] ?? ''),
            policyVersion: (string) ($data['policy_version'] ?? ''),
            decision: ResearchDecision::from((string) ($data['decision'] ?? ResearchDecision::WAIT->value)),
            directionalBias: $data['directional_bias'] ?? null,
            reasonCodes: (array) ($data['reason_codes'] ?? []),
            forecastCalibrated: (bool) ($data['forecast_calibrated'] ?? false),
            dataQuality: (string) ($data['data_quality'] ?? 'UNVERIFIED_SAMPLE'),
            simulationCostProfile: (string) ($data['simulation_cost_profile'] ?? ''),
            inputHashSha256: $data['input_hash_sha256'] ?? null,
            realizedLivePnl: $data['realized_live_pnl'] ?? null,
            hypotheticalNetReturn: $data['hypothetical_net_return'] ?? null,
            note: $data['note'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'decision_id' => $this->decisionId,
            'workspace_id' => $this->workspaceId,
            'exchange' => $this->exchange,
            'market' => $this->market,
            'symbol' => $this->symbol,
            'base_timeframe' => $this->baseTimeframe,
            'horizon_bars' => $this->horizonBars,
            'decision_as_of_utc' => $this->decisionAsOfUtc,
            'inputs_known_by_utc' => $this->inputsKnownByUtc,
            'candidate_id' => $this->candidateId,
            'forecast_id' => $this->forecastId,
            'policy_id' => $this->policyId,
            'policy_version' => $this->policyVersion,
            'decision' => $this->decision->value,
            'directional_bias' => $this->directionalBias,
            'reason_codes' => array_values($this->reasonCodes),
            'forecast_calibrated' => $this->forecastCalibrated,
            'data_quality' => $this->dataQuality,
            'simulation_cost_profile' => $this->simulationCostProfile,
            'input_hash_sha256' => $this->inputHashSha256,
            'execution_authorized' => false,
            'mode' => self::MODE,
            'realized_live_pnl' => $this->realizedLivePnl,
            'hypothetical_net_return' => $this->hypotheticalNetReturn,
            'note' => $this->note,
        ];
    }
}
