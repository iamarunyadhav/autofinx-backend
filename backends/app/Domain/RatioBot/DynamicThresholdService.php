<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;

class DynamicThresholdService
{
    /**
     * @param array $regimeContext Optional V2 Strategy Manager output
     *     (`{regime, reason}`, see StrategyManagerService::classifyRegime()).
     *     Defaults to [] so every pre-existing call site (RatioBotRealtimeEngine)
     *     is byte-identical — the regime factor is only applied when a caller
     *     explicitly opts in by passing this.
     */
    public function resolve(RatioBotConfig $config, float $currentPrice, array $indicatorSnapshot = [], array $regimeContext = []): array
    {
        $params = (array) ($config->strategy_params ?? []);
        $enabled = (bool) ($params['use_dynamic_thresholds'] ?? false);

        $baseSell = max(0.0001, (float) ($config->sell_threshold_ratio ?? 0));
        $baseBuy = (float) ($config->buy_threshold_ratio ?? 0);
        $baseBuyMagnitude = abs($baseBuy) > 0 ? abs($baseBuy) : $baseSell;

        $atrPct = max(0.0, (float) ($indicatorSnapshot['atr_pct'] ?? 0));
        $rangePct = max(0.0, (float) ($indicatorSnapshot['average_range_pct'] ?? 0));
        $returnPct = max(0.0, (float) ($indicatorSnapshot['average_abs_return'] ?? 0));
        $trendStrength = max(0.0, (float) ($indicatorSnapshot['trend_strength'] ?? 0));
        $spreadRatio = max(0.0, (float) ($indicatorSnapshot['spread_ratio'] ?? 0));
        $volumeRatio = max(0.0, (float) ($indicatorSnapshot['volume_ratio'] ?? 0));
        $liquidityNotional = max(0.0, (float) ($indicatorSnapshot['liquidity_notional'] ?? 0));
        $marketMode = (string) ($indicatorSnapshot['market_mode'] ?? 'UNKNOWN');
        $volatilityLevel = (string) ($indicatorSnapshot['volatility_level'] ?? 'LOW');

        $volatilityComponent = max($atrPct, $rangePct, $returnPct);
        $trendFactor = $marketMode === 'TRENDING' ? 1.15 : ($marketMode === 'SIDEWAYS' ? 0.90 : 1.00);
        $volumeFactor = $volumeRatio >= 1.25 ? 1.08 : ($volumeRatio > 0 && $volumeRatio < 0.75 ? 0.92 : 1.00);
        $spreadPenalty = 1 + min(0.75, $spreadRatio * 12);
        // A liquidity_notional of exactly 0 (a completely dead market / zero
        // traded volume) is worse than any positive-but-thin value, so it
        // must trip this protective factor too — not fall through to the
        // "normal" 1.00 factor the old `> 0` guard sent it to.
        $liquidityFactor = $liquidityNotional < (float) ($params['dynamic_liquidity_soft_floor'] ?? 10_000)
            ? 0.90
            : 1.00;

        $dynamicFactor = $enabled
            ? $this->clamp((1 + ($volatilityComponent * 18)) * $trendFactor * $volumeFactor * $liquidityFactor / $spreadPenalty, 0.75, 2.40)
            : 1.0;

        $regime = (string) ($regimeContext['regime'] ?? '');
        if ($regime !== '') {
            $regimeFactor = match ($regime) {
                'STRONG_UPTREND', 'STRONG_DOWNTREND' => 1.10,
                'HIGH_VOLATILITY' => 1.20,
                'LOW_LIQUIDITY' => 1.15,
                'RANGE' => 0.90,
                default => 1.00, // WEAK_UPTREND, WEAK_DOWNTREND, UNCERTAIN
            };
            $dynamicFactor = $this->clamp($dynamicFactor * $regimeFactor, 0.75, 2.40);
        }

        $sellThreshold = $this->clamp($baseSell * $dynamicFactor, $baseSell * 0.75, max($baseSell * 2.5, $baseSell + 0.0025));
        $buyMagnitude = $this->clamp($baseBuyMagnitude * $dynamicFactor, $baseBuyMagnitude * 0.75, max($baseBuyMagnitude * 2.5, $baseBuyMagnitude + 0.0025));
        $buyThreshold = $baseBuy < 0 ? -$buyMagnitude : $buyMagnitude;

        $manualTrailing = max(
            0.0005,
            (float) ($params['manual_trailing_take_profit_ratio'] ?? $params['trailing_take_profit_ratio'] ?? 0)
        );
        $fallbackTrailing = max($manualTrailing, $sellThreshold * ($marketMode === 'TRENDING' ? 0.45 : 0.30), $atrPct * 0.75);
        $trailingTakeProfit = $this->clamp(
            $enabled ? $fallbackTrailing : max($manualTrailing, $fallbackTrailing * 0.75),
            0.0005,
            max($sellThreshold * 0.95, 0.0015)
        );

        $manualStopLoss = max(
            0.0005,
            (float) ($params['manual_stop_loss_ratio'] ?? 0),
            $this->ratioFromPct($config->stop_loss_pct)
        );
        $stopLoss = $this->clamp(
            $enabled
                ? max($manualStopLoss, ($atrPct * 0.55), $trailingTakeProfit * 0.90)
                : max($manualStopLoss, $trailingTakeProfit * 0.80),
            0.0005,
            0.25
        );

        $pullbackRatio = $this->clamp(
            $enabled
                ? max(
                    (float) ($params['manual_pullback_ratio'] ?? 0),
                    (float) ($config->selling_loss_ratio ?? 0),
                    $trailingTakeProfit,
                    $atrPct * 0.60
                )
                : max((float) ($config->selling_loss_ratio ?? 0), $trailingTakeProfit),
            0.0005,
            max($sellThreshold, 0.0020)
        );

        $reboundRatio = $this->clamp(
            $enabled
                ? max(
                    (float) ($params['manual_rebound_ratio'] ?? 0),
                    (float) ($config->buying_loss_ratio ?? 0),
                    $trailingTakeProfit,
                    $atrPct * 0.60
                )
                : max((float) ($config->buying_loss_ratio ?? 0), $trailingTakeProfit),
            0.0005,
            max(abs($buyThreshold), 0.0020)
        );

        $minimumProfitRatio = $this->clamp(
            max(
                0.0,
                (float) ($config->min_net_profit_bps ?? 0) / 10000,
                (float) ($params['minimum_profit_ratio'] ?? 0)
            ),
            0.0,
            0.25
        );

        $reason = sprintf(
            'dynamic_thresholds=%s regime=%s market_mode=%s volatility=%s(%.4f) volume_ratio=%.2f factor=%.3f',
            $enabled ? 'on' : 'off',
            $regime !== '' ? $regime : 'none',
            $marketMode,
            $volatilityLevel,
            $volatilityComponent,
            $volumeRatio,
            $dynamicFactor
        );

        return [
            'enabled' => $enabled,
            'sell_threshold_ratio' => round($sellThreshold, 6),
            'buy_threshold_ratio' => round($buyThreshold, 6),
            'trailing_take_profit_ratio' => round($trailingTakeProfit, 6),
            'stop_loss_ratio' => round($stopLoss, 6),
            'pullback_ratio' => round($pullbackRatio, 6),
            'rebound_ratio' => round($reboundRatio, 6),
            'minimum_profit_ratio' => round($minimumProfitRatio, 6),
            'volatility_level' => $volatilityLevel,
            'market_mode' => $marketMode,
            'dynamic_factor' => round($dynamicFactor, 6),
            'reason' => $reason,
            'regime' => $regime !== '' ? $regime : null,
            'sell_threshold_bounds' => [
                'base' => round($baseSell, 6),
                'min' => round($baseSell * 0.75, 6),
                'max' => round(max($baseSell * 2.5, $baseSell + 0.0025), 6),
                'effective' => round($sellThreshold, 6),
                'reason' => $reason,
            ],
            'buy_threshold_bounds' => [
                'base' => round($baseBuyMagnitude, 6),
                'min' => round($baseBuyMagnitude * 0.75, 6),
                'max' => round(max($baseBuyMagnitude * 2.5, $baseBuyMagnitude + 0.0025), 6),
                'effective' => round($buyMagnitude, 6),
                'reason' => $reason,
            ],
            'metrics' => [
                'atr_pct' => $atrPct,
                'range_pct' => $rangePct,
                'average_abs_return' => $returnPct,
                'trend_strength' => $trendStrength,
                'volume_ratio' => $volumeRatio,
                'spread_ratio' => $spreadRatio,
                'liquidity_notional' => $liquidityNotional,
            ],
        ];
    }

    private function ratioFromPct(mixed $value): float
    {
        $ratio = (float) ($value ?? 0);
        if ($ratio <= 0) {
            return 0.0;
        }

        return $ratio > 1 ? $ratio / 100 : $ratio;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
