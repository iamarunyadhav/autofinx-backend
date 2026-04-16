<?php

namespace App\Domain\Strategies;

use App\Domain\Strategies\Implementations\BollingerReversionStrategy;
use App\Domain\Strategies\Implementations\GridStrategy;
use App\Domain\Strategies\Implementations\MaRsiStrategy;
use App\Models\Strategy;

class StrategyRegistry
{
    /**
     * @return array<string, StrategyInterface>
     */
    public function all(): array
    {
        return [
            'ma_rsi' => new MaRsiStrategy(),
            'bollinger_reversion' => new BollingerReversionStrategy(),
            'grid' => new GridStrategy(),
        ];
    }

    public function resolve(?Strategy $strategy): ?StrategyInterface
    {
        if (! $strategy) {
            return null;
        }

        return $this->resolveByKey($strategy->slug ?: $strategy->name);
    }

    public function resolveByKey(?string $key): ?StrategyInterface
    {
        if (! $key) {
            return null;
        }

        $normalized = strtolower(trim($key));
        $registry = $this->all();
        if (isset($registry[$normalized])) {
            return $registry[$normalized];
        }

        foreach ($registry as $candidate) {
            if (strtolower($candidate->name()) === $normalized) {
                return $candidate;
            }
        }

        return null;
    }
}
