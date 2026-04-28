<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\RatioBotConfig;

interface StrategyInterface
{
    public function key(): string;

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): StrategySignal;
}
