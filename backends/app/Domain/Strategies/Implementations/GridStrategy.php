<?php

namespace App\Domain\Strategies\Implementations;

use App\Domain\Strategies\DTO\StrategySignal;
use App\Domain\Strategies\StrategyInterface;

class GridStrategy implements StrategyInterface
{
    public function key(): string
    {
        return 'grid';
    }

    public function name(): string
    {
        return 'Grid Trader (paper)';
    }

    public function paramsSchema(): array
    {
        return [
            'grid_spacing_pct' => ['type' => 'number', 'default' => 0.5],
            'grid_levels' => ['type' => 'number', 'default' => 5],
            'base_price' => ['type' => 'number', 'default' => null],
            'base_order_pct' => ['type' => 'number', 'default' => 1.0],
        ];
    }

    public function evaluate(array $candles, array $params): StrategySignal
    {
        $price = $candles ? (float) end($candles)['close'] : null;
        if ($price === null) {
            return new StrategySignal('HOLD', 0.0, 'no_price');
        }

        $spacing = (float) ($params['grid_spacing_pct'] ?? 0.5);
        $levels = (int) ($params['grid_levels'] ?? 5);
        $base = isset($params['base_price']) ? (float) $params['base_price'] : $price;

        $distancePct = (($price - $base) / $base) * 100;
        $step = $spacing;
        $upperBand = $spacing * $levels;
        $lowerBand = -$upperBand;

        if ($distancePct >= $step && $distancePct <= $upperBand) {
            return new StrategySignal('SELL', 0.55, 'grid_sell', null, [
                'distance_pct' => $distancePct,
                'base_price' => $base,
            ]);
        }

        if ($distancePct <= -$step && $distancePct >= $lowerBand) {
            return new StrategySignal('BUY', 0.55, 'grid_buy', null, [
                'distance_pct' => $distancePct,
                'base_price' => $base,
            ]);
        }

        return new StrategySignal('HOLD', 0.2, 'inside_grid', null, [
            'distance_pct' => $distancePct,
            'base_price' => $base,
        ]);
    }
}
