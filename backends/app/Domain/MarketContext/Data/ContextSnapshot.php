<?php

namespace App\Domain\MarketContext\Data;

use Illuminate\Support\Carbon;

/** Output contract (plan section 2). Advisory data only: nothing here places or blocks an order. */
final class ContextSnapshot
{
    public function __construct(
        public readonly string $instrument,
        public readonly string $assetClass,
        public readonly Carbon $asOf,
        public readonly int $schemaVersion,
        public readonly array $freshness,
        public readonly array $sentiment,
        public readonly array $risk,
        public readonly array $metrics,
        public readonly array $drivers,
        public readonly array $provenance,
    ) {}

    public function toArray(): array
    {
        return [
            'instrument' => $this->instrument,
            'asset_class' => $this->assetClass,
            'as_of' => $this->asOf->copy()->utc()->toIso8601String(),
            'schema_version' => $this->schemaVersion,
            'freshness' => $this->freshness,
            'sentiment' => $this->sentiment,
            'risk' => $this->risk,
            'metrics' => $this->metrics,
            'drivers' => $this->drivers,
            'provenance' => $this->provenance,
        ];
    }
}
