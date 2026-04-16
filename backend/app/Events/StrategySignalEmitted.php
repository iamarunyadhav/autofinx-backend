<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StrategySignalEmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $instanceId,
        public string $strategyName,
        public string $action,
        public float $price,
        public array $meta = []
    ) {
    }
}
