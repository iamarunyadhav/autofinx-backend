<?php

namespace App\Domain\Fast2X;

class DynamicThresholdService
{
    public function resolve(array $ticks, array $settings): array
    {
        $prices = array_values(array_filter(array_map(fn ($tick) => (float) ($tick['price'] ?? 0), $ticks)));
        $baseProfit = (float) ($settings['minProfitTargetPercent'] ?? 0.2);
        $rollback = (float) ($settings['rollbackLossPercent'] ?? 0.15);
        $trailing = (float) ($settings['trailingTakeProfitPercent'] ?? 0.25);
        $stop = (float) ($settings['stopLossPercent'] ?? 0.4);

        if (count($prices) < 3 || ! (bool) ($settings['useDynamicThreshold'] ?? true)) {
            return [
                'sellThresholdPercent' => (float) ($settings['manualSellThresholdPercent'] ?? 0.5),
                'buyThresholdPercent' => (float) ($settings['manualBuyThresholdPercent'] ?? 0.5),
                'minProfitTargetPercent' => $baseProfit,
                'rollbackLossPercent' => $rollback,
                'trailingTakeProfitPercent' => $trailing,
                'stopLossPercent' => $stop,
                'volatilityLevel' => 'NORMAL',
                'marketMode' => 'RANGING',
            ];
        }

        $first = $prices[0];
        $last = $prices[count($prices) - 1];
        $rangePct = $first > 0 ? ((max($prices) - min($prices)) / $first) * 100 : 0;
        $momentumPct = $first > 0 ? (($last - $first) / $first) * 100 : 0;
        $vol = $rangePct >= 1.2 ? 'HIGH' : ($rangePct <= 0.25 ? 'LOW' : 'NORMAL');
        $mode = abs($momentumPct) >= 0.35 ? 'TRENDING' : ($rangePct >= 1.6 ? 'CHOPPY' : 'SIDEWAYS');
        $multiplier = $vol === 'HIGH' ? 1.5 : ($vol === 'LOW' ? 0.75 : 1.0);

        return [
            'sellThresholdPercent' => round(((float) ($settings['manualSellThresholdPercent'] ?? 0.5)) * $multiplier, 4),
            'buyThresholdPercent' => round(((float) ($settings['manualBuyThresholdPercent'] ?? 0.5)) * $multiplier, 4),
            'minProfitTargetPercent' => round($baseProfit * $multiplier, 4),
            'rollbackLossPercent' => round($rollback * $multiplier, 4),
            'trailingTakeProfitPercent' => round($trailing * ($mode === 'TRENDING' ? 1.25 : $multiplier), 4),
            'stopLossPercent' => round($stop * $multiplier, 4),
            'volatilityLevel' => $vol,
            'marketMode' => $mode,
        ];
    }
}
