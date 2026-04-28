<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\RatioBotConfig;

class MovingAverageCrossStrategy extends BaseIndicatorStrategy
{
    public function key(): string
    {
        return 'ma_crossover';
    }

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): StrategySignal
    {
        $params = (array) ($config->strategy_params ?? []);
        $shortWindow = max(3, (int) ($params['ma_short'] ?? 9));
        $longWindow = max($shortWindow + 1, (int) ($params['ma_long'] ?? 21));
        $closes = $this->closes($config, $longWindow + 2);

        if (count($closes) < $longWindow + 1) {
            return StrategySignal::hold('ma_crossover_waiting_for_candles', [
                'required' => $longWindow + 1,
                'available' => count($closes),
            ]);
        }

        $prevShort = $this->avg(array_slice($closes, -$shortWindow - 1, $shortWindow));
        $prevLong = $this->avg(array_slice($closes, -$longWindow - 1, $longWindow));
        $curShort = $this->avg(array_slice($closes, -$shortWindow));
        $curLong = $this->avg(array_slice($closes, -$longWindow));

        if ($prevShort <= $prevLong && $curShort > $curLong) {
            return $this->directionalSignal($config, 'bull', 'ma_bullish_cross', [
                'ma_short' => $curShort,
                'ma_long' => $curLong,
            ]);
        }

        if ($prevShort >= $prevLong && $curShort < $curLong) {
            return $this->directionalSignal($config, 'bear', 'ma_bearish_cross', [
                'ma_short' => $curShort,
                'ma_long' => $curLong,
            ]);
        }

        return StrategySignal::hold('ma_no_cross', [
            'ma_short' => $curShort,
            'ma_long' => $curLong,
        ]);
    }
}
