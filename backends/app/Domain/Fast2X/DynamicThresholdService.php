<?php

namespace App\Domain\Fast2X;

class DynamicThresholdService
{
    public function resolve(array $ticks, array $settings): array
    {
        $prices = array_values(array_filter(array_map(fn ($tick) => (float) ($tick['price'] ?? 0), $ticks)));

        $baseProfit  = (float) ($settings['minProfitTargetPercent'] ?? 0.35);
        $rollback    = (float) ($settings['rollbackLossPercent'] ?? 0.22);
        $trailing    = (float) ($settings['trailingTakeProfitPercent'] ?? 0.15);
        $stop        = (float) ($settings['stopLossPercent'] ?? 0.38);
        $buyBase     = (float) ($settings['manualBuyThresholdPercent'] ?? 0.45);
        $sellBase    = (float) ($settings['manualSellThresholdPercent'] ?? 0.45);

        if (count($prices) < 3 || ! (bool) ($settings['useDynamicThreshold'] ?? true)) {
            return [
                'sellThresholdPercent'   => $sellBase,
                'buyThresholdPercent'    => $buyBase,
                'minProfitTargetPercent' => $baseProfit,
                'rollbackLossPercent'    => $rollback,
                'trailingTakeProfitPercent' => $trailing,
                'stopLossPercent'        => $stop,
                'volatilityLevel'        => 'NORMAL',
                'marketMode'             => 'RANGING',
                'avoidEntry'             => false,
            ];
        }

        $first    = $prices[0];
        $last     = $prices[count($prices) - 1];
        $high     = max($prices);
        $low      = min($prices);
        $rangePct = $first > 0 ? (($high - $low) / $first) * 100 : 0;

        // Short-term momentum: compare last 5 ticks vs first 5 ticks
        $recentSlice = array_slice($prices, -5);
        $earlySlice  = array_slice($prices, 0, 5);
        $recentAvg   = count($recentSlice) > 0 ? array_sum($recentSlice) / count($recentSlice) : $last;
        $earlyAvg    = count($earlySlice)  > 0 ? array_sum($earlySlice) / count($earlySlice)   : $first;
        $momentumPct  = $earlyAvg > 0 ? (($recentAvg - $earlyAvg) / $earlyAvg) * 100 : 0;

        // Standard deviation for more accurate volatility assessment
        $mean  = array_sum($prices) / count($prices);
        $variance = array_sum(array_map(fn ($p) => ($p - $mean) ** 2, $prices)) / count($prices);
        $stdDevPct = $mean > 0 ? (sqrt($variance) / $mean) * 100 : 0;

        // Volatility from std dev: high >0.35%, low <0.08%
        $vol = $stdDevPct >= 0.35 ? 'HIGH' : ($stdDevPct <= 0.08 ? 'LOW' : 'NORMAL');

        // Market mode: TRENDING requires consistent directional momentum
        // CHOPPY: large range but tiny net move (price going nowhere noisily)
        $directionalConsistency = $this->directionalConsistency($prices);
        $isTrending  = abs($momentumPct) >= 0.25 && $directionalConsistency >= 0.65;
        $isChoppy    = $rangePct >= 1.2 && abs($momentumPct) < 0.20 && $directionalConsistency < 0.55;
        $mode = $isTrending ? 'TRENDING' : ($isChoppy ? 'CHOPPY' : 'SIDEWAYS');

        // In high volatility, widen thresholds to avoid noise-triggered entries
        // In low volatility, tighten to capture smaller moves
        $volatilityMultiplier = $vol === 'HIGH' ? 1.4 : ($vol === 'LOW' ? 0.8 : 1.0);

        // Trending markets: use tighter trailing to lock in directional profits quickly
        $trailingMultiplier = $mode === 'TRENDING' ? 0.85 : $volatilityMultiplier;

        // Avoid entry when market is CHOPPY or extremely low volume/volatility
        $avoidEntry = $mode === 'CHOPPY' || $vol === 'LOW';

        return [
            'sellThresholdPercent'      => round($sellBase * $volatilityMultiplier, 4),
            'buyThresholdPercent'       => round($buyBase  * $volatilityMultiplier, 4),
            'minProfitTargetPercent'    => round($baseProfit * $volatilityMultiplier, 4),
            'rollbackLossPercent'       => round($rollback * $volatilityMultiplier, 4),
            'trailingTakeProfitPercent' => round($trailing * $trailingMultiplier, 4),
            'stopLossPercent'           => round($stop * $volatilityMultiplier, 4),
            'volatilityLevel'           => $vol,
            'marketMode'               => $mode,
            'avoidEntry'               => $avoidEntry,
            'momentumPct'              => round($momentumPct, 4),
            'stdDevPct'                => round($stdDevPct, 4),
            'directionalConsistency'   => round($directionalConsistency, 3),
        ];
    }

    /**
     * Returns 0–1 ratio of how consistently price moved in one direction.
     * 1.0 = all ticks moved in the same direction (strong trend).
     * 0.5 = random / no bias.
     */
    private function directionalConsistency(array $prices): float
    {
        if (count($prices) < 2) {
            return 0.5;
        }

        $ups   = 0;
        $downs = 0;
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i] > $prices[$i - 1]) {
                $ups++;
            } elseif ($prices[$i] < $prices[$i - 1]) {
                $downs++;
            }
        }

        $total = $ups + $downs;
        if ($total === 0) {
            return 0.5;
        }

        return max($ups, $downs) / $total;
    }
}
