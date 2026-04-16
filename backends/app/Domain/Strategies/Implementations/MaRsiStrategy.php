<?php

namespace App\Domain\Strategies\Implementations;

use App\Domain\Market\Indicators\Indicators;
use App\Domain\Strategies\DTO\StrategySignal;
use App\Domain\Strategies\StrategyInterface;

class MaRsiStrategy implements StrategyInterface
{
    public function key(): string
    {
        return 'ma_rsi';
    }

    public function name(): string
    {
        return 'MA + RSI Crossover';
    }

    public function paramsSchema(): array
    {
        return [
            'fast_period' => ['type' => 'number', 'default' => 9],
            'slow_period' => ['type' => 'number', 'default' => 21],
            'rsi_period' => ['type' => 'number', 'default' => 14],
            'rsi_buy_below' => ['type' => 'number', 'default' => 35],
            'rsi_sell_above' => ['type' => 'number', 'default' => 65],
        ];
    }

    public function evaluate(array $candles, array $params): StrategySignal
    {
        $closes = array_map(fn ($c) => (float) $c['close'], $candles);
        $fast = (int) ($params['fast_period'] ?? 9);
        $slow = (int) ($params['slow_period'] ?? 21);
        $rsiPeriod = (int) ($params['rsi_period'] ?? 14);
        $rsiBuy = (float) ($params['rsi_buy_below'] ?? 35);
        $rsiSell = (float) ($params['rsi_sell_above'] ?? 65);

        $fastSma = Indicators::sma($closes, $fast);
        $slowSma = Indicators::sma($closes, $slow);
        $rsi = Indicators::rsi($closes, $rsiPeriod);
        $price = end($closes) ?: null;

        if ($price === null || $fastSma === null || $slowSma === null || $rsi === null) {
            return new StrategySignal('HOLD', 0.0, 'insufficient_data');
        }

        $action = 'HOLD';
        $reason = 'neutral';
        $confidence = 0.3;

        if ($fastSma > $slowSma && $rsi < $rsiBuy) {
            $action = 'BUY';
            $reason = 'ma_cross_up_rsi_oversold';
            $confidence = min(1.0, 0.6 + (max(0, $rsiBuy - $rsi) / 100));
        } elseif ($fastSma < $slowSma && $rsi > $rsiSell) {
            $action = 'SELL';
            $reason = 'ma_cross_down_rsi_overbought';
            $confidence = min(1.0, 0.6 + (max(0, $rsi - $rsiSell) / 100));
        }

        return new StrategySignal($action, $confidence, $reason, null, [
            'price' => $price,
            'fast' => $fastSma,
            'slow' => $slowSma,
            'rsi' => $rsi,
        ]);
    }
}
