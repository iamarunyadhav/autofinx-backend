<?php

namespace App\Domain\RatioBot\Strategies;

use App\Models\RatioBotConfig;

class BreakoutStrategy extends BaseIndicatorStrategy
{
    public function key(): string
    {
        return 'breakout';
    }

    public function evaluate(RatioBotConfig $config, float $currentPrice, array $context = []): StrategySignal
    {
        $params = (array) ($config->strategy_params ?? []);
        $window = max(5, (int) ($params['breakout_window'] ?? 20));
        $bufferPct = max(0.0, (float) ($params['breakout_buffer_pct'] ?? 0.0));
        $candles = $this->candles($config, $window + 1);

        if ($candles->count() < $window + 1) {
            return StrategySignal::hold('breakout_waiting_for_candles', [
                'required' => $window + 1,
                'available' => $candles->count(),
            ]);
        }

        $reference = $candles->slice(0, $candles->count() - 1);
        $highestHigh = (float) $reference->max(fn ($candle) => (float) $candle->high);
        $lowestLow = (float) $reference->min(fn ($candle) => (float) $candle->low);
        $upBreak = $highestHigh > 0 ? $highestHigh * (1 + ($bufferPct / 100)) : 0;
        $downBreak = $lowestLow > 0 ? $lowestLow * (1 - ($bufferPct / 100)) : 0;

        if ($currentPrice >= $upBreak && $upBreak > 0) {
            return $this->directionalSignal($config, 'bull', 'breakout_upside', [
                'highest_high' => $highestHigh,
                'trigger_price' => $upBreak,
            ]);
        }

        if ($currentPrice <= $downBreak && $downBreak > 0) {
            return $this->directionalSignal($config, 'bear', 'breakout_downside', [
                'lowest_low' => $lowestLow,
                'trigger_price' => $downBreak,
            ]);
        }

        return StrategySignal::hold('breakout_range_intact', [
            'highest_high' => $highestHigh,
            'lowest_low' => $lowestLow,
        ]);
    }
}
