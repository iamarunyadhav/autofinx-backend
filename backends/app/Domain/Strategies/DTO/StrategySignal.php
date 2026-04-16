<?php

namespace App\Domain\Strategies\DTO;

class StrategySignal
{
    public function __construct(
        public string $action,
        public float $confidence = 0.0,
        public string $reason = '',
        public ?float $suggestedQty = null,
        public array $meta = []
    ) {
    }
}
