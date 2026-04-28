<?php

namespace App\Domain\Fast2X;

class TrailingProfitService
{
    public function resolve(float $entryPrice, float $currentPrice, ?float $peak, array $settings): array
    {
        $peak = max($peak ?: $entryPrice, $currentPrice);
        $distance = abs((float) ($settings['trailingTakeProfitPercent'] ?? 0.25));
        $exit = $peak * (1 - ($distance / 100));
        $inProfit = $currentPrice > $entryPrice;

        return [
            'peak' => $peak,
            'trailingExitPrice' => $exit,
            'shouldSell' => (bool) ($settings['useTrailingTakeProfit'] ?? true) && $inProfit && $currentPrice <= $exit && $exit >= $entryPrice,
        ];
    }
}
