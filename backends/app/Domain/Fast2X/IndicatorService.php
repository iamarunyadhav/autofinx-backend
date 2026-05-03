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

        $ema9  = $this->ema($prices, 9);
        $ema21 = $this->ema($prices, 21);
        $rsi   = $this->rsi($prices);
        $spreadPct = $this->latestSpreadPct($ticks);
        $volumeTrend = $this->volumeTrend($ticks);

        // Strict crossover: EMA9 must be at or above EMA21 (bullish structure)
        $emaOk = $ema9 >= $ema21;

        // RSI momentum zone 30–68: avoid oversold traps (< 30) and overbought entries (> 68)
        $rsiOk = $rsi >= 30 && $rsi < 68;

        $spreadOk = $spreadPct <= (float) ($settings['maxAllowedSlippagePercent'] ?? 0.15);

        // Require positive volume trend on entry (recent volume >= average)
        $volumeOk = $volumeTrend >= 0.0;

        $allow = $emaOk && $rsiOk && $spreadOk && $volumeOk;

        $reasons = [];
        $reasons[] = $emaOk  ? 'EMA9 above EMA21 (bullish)' : 'EMA9 below EMA21 (bearish — skip)';
        $reasons[] = $rsiOk  ? sprintf('RSI %.1f in momentum zone (30–68)', $rsi) : sprintf('RSI %.1f outside momentum zone', $rsi);
        $reasons[] = $spreadOk ? 'Spread acceptable' : 'Spread too wide';
        $reasons[] = $volumeOk ? 'Volume trend positive' : 'Volume declining — weak signal';

        // Confidence: 0.6 base + contribution from each passing indicator
        $confidence = 0.6
            + ($emaOk    ? 0.12 : 0.0)
            + ($rsiOk    ? 0.10 : 0.0)
            + ($volumeOk ? 0.08 : 0.0)
            + ($spreadOk ? 0.05 : 0.0);

        return [
            'allowTrade'   => $allow,
            'confidence'   => round(min(0.95, $confidence), 4),
            'reasons'      => $reasons,
            'rsi'          => round($rsi, 2),
            'ema9'         => round($ema9, 8),
            'ema21'        => round($ema21, 8),
            'spreadPercent' => round($spreadPct, 6),
            'volumeTrend'  => round($volumeTrend, 4),
        ];
    }

    private function ema(array $prices, int $period): float
    {
        $k   = 2 / ($period + 1);
        $ema = $prices[0];
        foreach ($prices as $price) {
            $ema = ($price * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    private function rsi(array $prices, int $period = 14): float
    {
        $gains  = 0.0;
        $losses = 0.0;
        $window = array_slice($prices, -($period + 1));
        for ($i = 1; $i < count($window); $i++) {
            $delta = $window[$i] - $window[$i - 1];
            $delta >= 0 ? $gains += $delta : $losses += abs($delta);
        }
        if ($losses == 0.0) {
            return 100.0;
        }
        $count = max(1, count($window) - 1);
        $rs    = ($gains / $count) / ($losses / $count);
        return 100 - (100 / (1 + $rs));
    }

    /**
     * Returns the ratio of recent average volume vs overall average.
     * Positive (>= 0) means recent volume is at least as strong as average.
     */
    private function volumeTrend(array $ticks): float
    {
        $volumes = array_values(array_filter(
            array_map(fn ($tick) => (float) ($tick['volume'] ?? $tick['quoteVolume'] ?? 0), $ticks),
            fn ($v) => $v > 0
        ));

        if (count($volumes) < 6) {
            return 0.0;
        }

        $recentWindow = array_slice($volumes, -5);
        $avgRecent    = array_sum($recentWindow) / count($recentWindow);
        $avgAll       = array_sum($volumes) / count($volumes);

        return $avgAll > 0 ? ($avgRecent - $avgAll) / $avgAll : 0.0;
    }

    private function latestSpreadPct(array $ticks): float
    {
        $tick   = end($ticks) ?: [];
        $price  = (float) ($tick['price'] ?? 0);
        $spread = (float) ($tick['spread'] ?? 0);
        return $price > 0 ? ($spread / $price) * 100 : 0;
    }
}
