<?php

namespace Services\MarketService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MarketTickGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $symbol,
        public float $price,
        public float $volume,
        public \DateTimeInterface $timestamp,
    ) {
    }
}
