<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\RatioBotConfig;
use InvalidArgumentException;

class StrategyRegistry
{
    /** @var array<string, StrategyInterface> */
    private array $strategies;

    public function __construct(
        RatioThresholdStrategy $ratioThreshold,
        TrailingStrategy $trailing,
        MovingAverageCrossStrategy $movingAverage,
        RsiStrategy $rsi,
        BreakoutStrategy $breakout,
    ) {
        $this->strategies = [
            $ratioThreshold->key() => $ratioThreshold,
            'threshold' => $ratioThreshold,
            $trailing->key() => $trailing,
            $movingAverage->key() => $movingAverage,
            'ma' => $movingAverage,
            $rsi->key() => $rsi,
            $breakout->key() => $breakout,
        ];
    }

    public function resolve(RatioBotConfig $config): StrategyInterface
    {
        $key = $config->algorithmKey();
        if (! isset($this->strategies[$key])) {
            throw new InvalidArgumentException("Unsupported ratio bot algorithm [{$key}].");
        }

        return $this->strategies[$key];
    }
}
