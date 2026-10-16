<?php

namespace App\Domain\MarketOpportunityBrain\Data;

use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;

/**
 * Immutable forecast adapter output record.
 *
 * Field-for-field mirror of docs/market-opportunity-brain-v4/contracts/forecast.v1.example.json
 * (round-trip parity enforced by tests/Unit/MarketOpportunityBrain/ForecastRecordContractTest.php).
 *
 * No automatic LONG/SHORT is derived here (MASTER_SPEC.md Layer F §60): this record only carries
 * quantiles/probabilities plus provenance and status. Direction is the Decision Engine's job (Layer G).
 */
final readonly class ForecastRecord
{
    public const SCHEMA = 'mob.forecast.v1';

    /**
     * @param  array{p10: ?float, p50: ?float, p90: ?float}  $predictionQuantiles
     * @param  array{up: ?float, neutral: ?float, down: ?float}  $probabilities
     */
    public function __construct(
        public string $forecastId,
        public string $workspaceId,
        public string $exchange,
        public string $market,
        public string $symbol,
        public string $timeframe,
        public int $horizonBars,
        public string $asOfUtc,
        public string $knownByUtc,
        public ?string $labelAvailableAtUtc,
        public string $modelName,
        public string $modelRevision,
        public string $featureSchemaVersion,
        public ?string $datasetHashSha256,
        public array $predictionQuantiles,
        public array $probabilities,
        public bool $probabilityCalibrated,
        public ForecastStatus $status,
        public ?string $reason,
    ) {
        $this->assertQuantilesWellFormed($predictionQuantiles);
        $this->assertProbabilitiesWellFormed($probabilities);
    }

    private function assertQuantilesWellFormed(array $q): void
    {
        foreach (['p10', 'p50', 'p90'] as $key) {
            if (! array_key_exists($key, $q)) {
                throw new \InvalidArgumentException("ForecastRecord.prediction_quantiles missing key: {$key}");
            }
        }
        if ($q['p10'] !== null && $q['p50'] !== null && $q['p10'] > $q['p50']) {
            throw new \InvalidArgumentException('ForecastRecord.prediction_quantiles p10 must be <= p50.');
        }
        if ($q['p50'] !== null && $q['p90'] !== null && $q['p50'] > $q['p90']) {
            throw new \InvalidArgumentException('ForecastRecord.prediction_quantiles p50 must be <= p90.');
        }
    }

    private function assertProbabilitiesWellFormed(array $p): void
    {
        foreach (['up', 'neutral', 'down'] as $key) {
            if (! array_key_exists($key, $p)) {
                throw new \InvalidArgumentException("ForecastRecord.probabilities missing key: {$key}");
            }
            if ($p[$key] !== null && ($p[$key] < 0.0 || $p[$key] > 1.0)) {
                throw new \InvalidArgumentException("ForecastRecord.probabilities.{$key} must be within [0,1] or null.");
            }
        }
        // Spec note: P(up) + P(down) need not equal 1 when a neutral band exists, but no
        // individual probability may exceed 1 and the sum may never exceed 1 either.
        $sum = array_sum(array_map(static fn ($v) => $v ?? 0.0, $p));
        if ($sum > 1.0 + 1e-9) {
            throw new \InvalidArgumentException('ForecastRecord.probabilities must not sum above 1.0.');
        }
    }

    public static function fromArray(array $data): self
    {
        if (array_key_exists('execution_authorized', $data) && $data['execution_authorized'] !== false) {
            throw new \InvalidArgumentException('ForecastRecord.execution_authorized must always be false.');
        }

        return new self(
            forecastId: (string) ($data['forecast_id'] ?? ''),
            workspaceId: (string) ($data['workspace_id'] ?? ''),
            exchange: (string) ($data['exchange'] ?? ''),
            market: (string) ($data['market'] ?? ''),
            symbol: (string) ($data['symbol'] ?? ''),
            timeframe: (string) ($data['timeframe'] ?? ''),
            horizonBars: (int) ($data['horizon_bars'] ?? 0),
            asOfUtc: (string) ($data['as_of_utc'] ?? ''),
            knownByUtc: (string) ($data['known_by_utc'] ?? ''),
            labelAvailableAtUtc: $data['label_available_at_utc'] ?? null,
            modelName: (string) ($data['model_name'] ?? ''),
            modelRevision: (string) ($data['model_revision'] ?? ''),
            featureSchemaVersion: (string) ($data['feature_schema_version'] ?? ''),
            datasetHashSha256: $data['dataset_hash_sha256'] ?? null,
            predictionQuantiles: (array) ($data['prediction_quantiles'] ?? ['p10' => null, 'p50' => null, 'p90' => null]),
            probabilities: (array) ($data['probabilities'] ?? ['up' => null, 'neutral' => null, 'down' => null]),
            probabilityCalibrated: (bool) ($data['probability_calibrated'] ?? false),
            status: ForecastStatus::from((string) ($data['status'] ?? ForecastStatus::UNAVAILABLE->value)),
            reason: $data['reason'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'forecast_id' => $this->forecastId,
            'workspace_id' => $this->workspaceId,
            'exchange' => $this->exchange,
            'market' => $this->market,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'horizon_bars' => $this->horizonBars,
            'as_of_utc' => $this->asOfUtc,
            'known_by_utc' => $this->knownByUtc,
            'label_available_at_utc' => $this->labelAvailableAtUtc,
            'model_name' => $this->modelName,
            'model_revision' => $this->modelRevision,
            'feature_schema_version' => $this->featureSchemaVersion,
            'dataset_hash_sha256' => $this->datasetHashSha256,
            'prediction_quantiles' => $this->predictionQuantiles,
            'probabilities' => $this->probabilities,
            'probability_calibrated' => $this->probabilityCalibrated,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'execution_authorized' => false,
        ];
    }
}
