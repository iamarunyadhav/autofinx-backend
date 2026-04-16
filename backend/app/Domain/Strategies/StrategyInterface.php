<?php

namespace App\Domain\Strategies;

use App\Domain\Strategies\DTO\StrategySignal;

interface StrategyInterface
{
    public function key(): string;

    public function name(): string;

    /**
     * Params schema structure for UI + validation.
     */
    public function paramsSchema(): array;

    /**
     * @param array $candles Array of candles (newest last) each with open, high, low, close, volume, opens_at
     * @param array $params  Strategy parameters (validated upstream)
     */
    public function evaluate(array $candles, array $params): StrategySignal;
}
