<?php

namespace Services\StrategyService\Support\Signals;

class Signal
{
    public function __construct(
        public string $action,
        public ?float $price = null,
        public ?array $meta = []
    ) {
    }
}
