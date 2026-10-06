<?php

namespace App\Domain\MarketContext\Data;

use Illuminate\Support\Carbon;

/** A normalized news/announcement item as a collector saw it. Text is untrusted data. */
final class RawItem
{
    public function __construct(
        public readonly string $source,
        public readonly ?string $externalId,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly ?string $url,
        public readonly Carbon $publishedAt,
        /** Optional collector hint, e.g. 'delisting' for Binance catalog 161. */
        public readonly ?string $hint = null,
    ) {}
}
