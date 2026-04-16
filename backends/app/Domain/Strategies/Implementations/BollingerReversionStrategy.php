<?php

namespace App\Domain\Strategies\Implementations;

use App\Domain\Market\Indicators\Indicators;
use App\Domain\Strategies\DTO\StrategySignal;
use App\Domain\Strategies\StrategyInterface;

class BollingerReversionStrategy implements StrategyInterface
{
    public function key(): string
    {
        return 'bollinger_reversion';
    }

    public function name(): string
    {
        return 'Bollinger Mean Reversion';
    }

    public function paramsSchema(): array
    {
        return [
            'period' => ['type' => 'number', 'default' => 20],
            'stddev' => ['type' => 'number', 'default' => 2],
            'mean_revert_tp_pct' => ['type' => 'number', 'default' => 1.0],
        ];
    }

    public function evaluate(array $candles, array $params): StrategySignal
    {
        $closes = array_map(fn ($c) => (float) $c['close'], $candles);
        $period = (int) ($params['period'] ?? 20);
        $stddev = (float) ($params['stddev'] ?? 2.0);
        $tpPct = (float) ($params['mean_revert_tp_pct'] ?? 1.0);

        $bands = Indicators::bollinger($closes, $period, $stddev);
        $price = end($closes) ?: null;
        if ($price === null || $bands === null) {
            return new StrategySignal('HOLD', 0.0, 'insufficient_data');
        }

        if ($price < $bands['lower']) {
            $distance = ($bands['lower'] - $price) / $bands['lower'];
            $confidence = min(1.0, 0.5 + $distance);
            return new StrategySignal('BUY', $confidence, 'below_lower_band', null, [
                'target' => $price * (1 + $tpPct / 100),
                'bands' => $bands,
            ]);
        }

        if ($price > $bands['upper']) {
            $distance = ($price - $bands['upper']) / $bands['upper'];
            $confidence = min(1.0, 0.5 + $distance);
            return new StrategySignal('SELL', $confidence, 'above_upper_band', null, [
                'target' => $price * (1 - $tpPct / 100),
                'bands' => $bands,
            ]);
        }

        return new StrategySignal('HOLD', 0.2, 'inside_band', null, ['bands' => $bands]);
    }
}
