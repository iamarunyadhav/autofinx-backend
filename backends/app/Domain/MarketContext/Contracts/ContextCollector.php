<?php

namespace App\Domain\MarketContext\Contracts;

use App\Domain\MarketContext\Data\CollectResult;
use Illuminate\Support\Carbon;

/**
 * One public data source. Implementations fetch ONLY the fixed registry URL
 * through SafeHttpFetcher and return bounded, already-parsed items/metrics.
 * They never write to the database and never throw upstream bodies.
 */
interface ContextCollector
{
    public function sourceId(): string;

    /** Conditional-GET validators come from the last successful fetch (may be null). */
    public function collect(?string $etag, ?string $lastModified, Carbon $now): CollectResult;
}
