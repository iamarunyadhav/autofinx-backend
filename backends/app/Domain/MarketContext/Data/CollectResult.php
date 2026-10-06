<?php

namespace App\Domain\MarketContext\Data;

final class CollectResult
{
    /**
     * @param  list<RawItem>  $items
     * @param  list<RawMetric>  $metrics
     */
    public function __construct(
        public readonly array $items = [],
        public readonly array $metrics = [],
        public readonly bool $notModified = false,
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
        public readonly ?string $contentHash = null,
        /** Sub-requests that failed while others succeeded (non-fatal). */
        public readonly int $failedParts = 0,
    ) {}
}
