<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\RatioBotConfig;

class RsiStrategy extends BaseIndicatorStrategy
{
    public function key(): string
    {
        return 'rsi';
    }

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): StrategySignal
    {
        $params = (array) ($config->strategy_params ?? []);
        $period = max(5, (int) ($params['rsi_period'] ?? 14));
        $oversold = (float) ($params['rsi_oversold'] ?? 30);
        $overbought = (float) ($params['rsi_overbought'] ?? 70);
        $closes = $this->closes($config, $period + 2);

        if (count($closes) < $period + 1) {
            return StrategySignal::hold('rsi_waiting_for_candles', [
                'required' => $period + 1,
                'available' => count($closes),
            ]);
        }

        $rsi = $this->computeRsi(array_slice($closes, -($period + 1)));
        if ($rsi <= $oversold) {
            return $this->directionalSignal($config, 'bull', 'rsi_oversold_reversal', [
                'rsi' => $rsi,
                'oversold' => $oversold,
            ]);
        }
        if ($rsi >= $overbought) {
            return $this->directionalSignal($config, 'bear', 'rsi_overbought_reversal', [
                'rsi' => $rsi,
                'overbought' => $overbought,
            ]);
        }

        return StrategySignal::hold('rsi_neutral', [
            'rsi' => $rsi,
            'oversold' => $oversold,
            'overbought' => $overbought,
        ]);
    }

    private function computeRsi(array $closes): float
    {
        $gains = 0.0;
        $losses = 0.0;

        for ($i = 1; $i < count($closes); $i++) {
            $delta = (float) $closes[$i] - (float) $closes[$i - 1];
            if ($delta >= 0) {
                $gains += $delta;
            } else {
                $losses += abs($delta);
            }
        }

        $period = max(1, count($closes) - 1);
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        if ($avgLoss <= 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $rs));
    }
}
