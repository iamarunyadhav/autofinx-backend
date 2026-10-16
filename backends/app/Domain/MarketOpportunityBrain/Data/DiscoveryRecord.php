<?php

namespace App\Domain\MarketOpportunityBrain\Data;

/**
 * Immutable point-in-time discovery/universe snapshot record.
 *
 * Field-for-field mirror of docs/market-opportunity-brain-v4/contracts/discovery.v1.example.json
 * (round-trip parity enforced by tests/Unit/MarketOpportunityBrain/DiscoveryRecordContractTest.php).
 * A candidate is eligible for further analysis only, never an order (MASTER_SPEC.md Layer E §5).
 */
final readonly class DiscoveryRecord
{
    public const SCHEMA = 'mob.discovery.v1';

    public function __construct(
        public string $snapshotId,
        public string $exchange,
        public string $market,
        public string $symbol,
        public string $workspaceId,
        public string $asOfUtc,
        public ?string $lastClosedCandleUtc,
        public bool $listedAsOf,
        public bool $eligibleForAnalysis,
        public ?int $rank,
        public ?float $opportunityScore,
        public ?string $direction,
        public ?int $sourceFreshnessSeconds,
        public array $reasonCodes,
        public string $featureSchemaVersion,
        public ?string $featureWindowHashSha256,
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (array_key_exists('execution_authorized', $data) && $data['execution_authorized'] !== false) {
            throw new \InvalidArgumentException('DiscoveryRecord.execution_authorized must always be false.');
        }

        return new self(
            snapshotId: (string) ($data['snapshot_id'] ?? ''),
            exchange: (string) ($data['exchange'] ?? ''),
            market: (string) ($data['market'] ?? ''),
            symbol: (string) ($data['symbol'] ?? ''),
            workspaceId: (string) ($data['workspace_id'] ?? ''),
            asOfUtc: (string) ($data['as_of_utc'] ?? ''),
            lastClosedCandleUtc: $data['last_closed_candle_utc'] ?? null,
            listedAsOf: (bool) ($data['listed_as_of'] ?? false),
            eligibleForAnalysis: (bool) ($data['eligible_for_analysis'] ?? false),
            rank: $data['rank'] ?? null,
            opportunityScore: $data['opportunity_score'] ?? null,
            direction: $data['direction'] ?? null,
            sourceFreshnessSeconds: $data['source_freshness_seconds'] ?? null,
            reasonCodes: (array) ($data['reason_codes'] ?? []),
            featureSchemaVersion: (string) ($data['feature_schema_version'] ?? ''),
            featureWindowHashSha256: $data['feature_window_hash_sha256'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'snapshot_id' => $this->snapshotId,
            'exchange' => $this->exchange,
            'market' => $this->market,
            'symbol' => $this->symbol,
            'workspace_id' => $this->workspaceId,
            'as_of_utc' => $this->asOfUtc,
            'last_closed_candle_utc' => $this->lastClosedCandleUtc,
            'listed_as_of' => $this->listedAsOf,
            'eligible_for_analysis' => $this->eligibleForAnalysis,
            'rank' => $this->rank,
            'opportunity_score' => $this->opportunityScore,
            'direction' => $this->direction,
            'source_freshness_seconds' => $this->sourceFreshnessSeconds,
            'reason_codes' => array_values($this->reasonCodes),
            'feature_schema_version' => $this->featureSchemaVersion,
            'feature_window_hash_sha256' => $this->featureWindowHashSha256,
            'execution_authorized' => false,
        ];
    }
}
