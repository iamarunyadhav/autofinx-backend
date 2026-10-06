<?php

namespace App\Domain\MarketContext\Data;

use Illuminate\Support\Carbon;

/** asOf is an absolute UTC instant: only rows first seen at or before it may be used. */
final class ContextQuery
{
    public readonly Carbon $asOf;

    public function __construct(
        public readonly string $assetClass,
        public readonly string $instrument,
        Carbon $asOf,
        public readonly int $lookbackSeconds = 86400,
    ) {
        $this->asOf = $asOf->copy()->utc();
    }
}
