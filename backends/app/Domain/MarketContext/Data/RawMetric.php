<?php

namespace App\Domain\MarketContext\Data;

use Illuminate\Support\Carbon;

final class RawMetric
{
    public function __construct(
        public readonly string $source,
        public readonly string $assetClass,
        /** Canonical instrument, or 'ALL' for a market-wide metric. */
        public readonly string $instrument,
        public readonly string $metric,
        public readonly float $value,
        public readonly Carbon $observedAt,
        /** Null = "now" (live). Backfill sets it explicitly and documents why. */
        public readonly ?Carbon $firstSeenAt = null,
    ) {}
}
