<?php

namespace App\Domain\Fast2X;

class IndicatorService
{
    public function confirm(array $ticks, array $settings): array
    {
        if (! (bool) ($settings['useIndicators'] ?? true)) {
            return ['allowTrade' => true, 'confidence' => 0.6, 'reasons' => ['Indicators disabled by config']];
        }

        $prices = array_values(array_filter(array_map(fn ($tick) => (float) ($tick['price'] ?? 0), $ticks)));
        if (count($prices) < 5) {
            return ['allowTrade' => true, 'confidence' => 0.55, 'reasons' => ['Warmup tick window']];
        }

        $ema9 = $this->ema($prices, 9);
        $ema21 = $this->ema($prices, 21);
        $rsi = $this->rsi($prices);
        $spreadPct = $this->latestSpreadPct($ticks);
        $allow = $ema9 >= ($ema21 * 0.999) && $rsi < 75 && $spreadPct <= (float) ($settings['maxAllowedSlippagePercent'] ?? 0.15);
        $reasons = [];
        $reasons[] = $ema9 >= $ema21 ? 'EMA trend positive' : 'EMA trend weak';
        $reasons[] = $rsi < 75 ? 'RSI acceptable' : 'RSI dangerous';
        $reasons[] = $spreadPct <= (float) ($settings['maxAllowedSlippagePercent'] ?? 0.15) ? 'Spread acceptable' : 'Spread too wide';

        return [
            'allowTrade' => $allow,
            'confidence' => $allow ? 0.78 : 0.38,
            'reasons' => $reasons,
            'rsi' => round($rsi, 2),
            'ema9' => round($ema9, 8),
            'ema21' => round($ema21, 8),
            'spreadPercent' => round($spreadPct, 6),
        ];
    }

    private function ema(array $prices, int $period): float
    {
        $k = 2 / ($period + 1);
        $ema = $prices[0];
        foreach ($prices as $price) {
            $ema = ($price * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    private function rsi(array $prices, int $period = 14): float
    {
        $gains = 0.0;
        $losses = 0.0;
        $window = array_slice($prices, -($period + 1));
        for ($i = 1; $i < count($window); $i++) {
            $delta = $window[$i] - $window[$i - 1];
            $delta >= 0 ? $gains += $delta : $losses += abs($delta);
        }
        if ($losses == 0.0) {
            return 100.0;
        }
        $rs = ($gains / max(1, count($window) - 1)) / ($losses / max(1, count($window) - 1));
        return 100 - (100 / (1 + $rs));
    }

    private function latestSpreadPct(array $ticks): float
    {
        $tick = end($ticks) ?: [];
        $price = (float) ($tick['price'] ?? 0);
        $spread = (float) ($tick['spread'] ?? 0);
        return $price > 0 ? ($spread / $price) * 100 : 0;
    }
}
