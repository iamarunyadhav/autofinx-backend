<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * V2 "Strategy Manager" — a thin composition layer over the EXISTING
 * IndicatorService::snapshot() classification (market_mode/volatility_level/
 * trend_strength, already used today to gate trade confirmation). It does
 * NOT implement a new regime detector from scratch: classifyRegime() only
 * maps that existing, already-proven output onto a finer regime taxonomy,
 * then feeds it into DynamicThresholdService (Threshold Manager) and
 * resolveReserve() (Reserve Manager). Inert entirely unless the bot has
 * opted into config.v2_enabled.
 */
class StrategyManagerService
{
    public const REGIME_STRONG_UPTREND   = 'STRONG_UPTREND';
    public const REGIME_WEAK_UPTREND     = 'WEAK_UPTREND';
    public const REGIME_RANGE            = 'RANGE';
    public const REGIME_WEAK_DOWNTREND   = 'WEAK_DOWNTREND';
    public const REGIME_STRONG_DOWNTREND = 'STRONG_DOWNTREND';
    public const REGIME_HIGH_VOLATILITY  = 'HIGH_VOLATILITY';
    public const REGIME_LOW_LIQUIDITY    = 'LOW_LIQUIDITY';
    public const REGIME_UNCERTAIN        = 'UNCERTAIN';

    private const ADX_STRONG_TREND = 35.0;
    private const ADX_WEAK_TREND = 25.0;
    private const LOW_LIQUIDITY_VOLUME_RATIO = 0.5;

    public function __construct(
        private readonly IndicatorService $indicators,
        private readonly DynamicThresholdService $thresholds,
    ) {
    }

    /**
     * @return array{
     *     enabled: bool,
     *     regime?: string,
     *     regime_reason?: string,
     *     indicator_snapshot?: array,
     *     dynamic_thresholds?: array,
     *     reserve?: array,
     * }
     */
    public function analyze(RatioBotConfig $config, RatioBotState $state, float $currentPrice): array
    {
        if (! $config->v2_enabled) {
            return ['enabled' => false];
        }

        $snapshot = $this->indicators->snapshot($config, $currentPrice);
        ['regime' => $regime, 'reason' => $regimeReason] = $this->classifyRegime($snapshot);

        $dynamicThresholds = $this->thresholds->resolve($config, $currentPrice, $snapshot, [
            'regime' => $regime,
        ]);

        return [
            'enabled' => true,
            'regime' => $regime,
            'regime_reason' => $regimeReason,
            'indicator_snapshot' => $snapshot,
            'dynamic_thresholds' => $dynamicThresholds,
            'reserve' => $this->resolveReserve($config, $regime),
        ];
    }

    /**
     * Explicit, priority-ordered, no-ML mapping from the existing
     * IndicatorService snapshot onto the finer 8-value regime taxonomy.
     *
     * @return array{regime: string, reason: string}
     */
    public function classifyRegime(array $indicatorSnapshot): array
    {
        if (! (bool) ($indicatorSnapshot['sufficient_data'] ?? false)) {
            return ['regime' => self::REGIME_UNCERTAIN, 'reason' => 'insufficient_indicator_data'];
        }

        $volatilityLevel = (string) ($indicatorSnapshot['volatility_level'] ?? 'LOW');
        if ($volatilityLevel === 'EXTREME') {
            return ['regime' => self::REGIME_HIGH_VOLATILITY, 'reason' => 'volatility_level=EXTREME'];
        }

        $liquidityNotional = (float) ($indicatorSnapshot['liquidity_notional'] ?? 0);
        $volumeRatio = ($indicatorSnapshot['volume_ratio'] ?? null) !== null ? (float) $indicatorSnapshot['volume_ratio'] : null;
        // Mirrors DynamicThresholdService's own dynamic_liquidity_soft_floor default.
        $liquidityFloor = 10_000.0;
        if ($liquidityNotional > 0 && $liquidityNotional < $liquidityFloor
            && $volumeRatio !== null && $volumeRatio < self::LOW_LIQUIDITY_VOLUME_RATIO
        ) {
            return [
                'regime' => self::REGIME_LOW_LIQUIDITY,
                'reason' => sprintf('liquidity_notional=%.2f < %.2f and volume_ratio=%.2f < %.2f', $liquidityNotional, $liquidityFloor, $volumeRatio, self::LOW_LIQUIDITY_VOLUME_RATIO),
            ];
        }

        $marketMode = (string) ($indicatorSnapshot['market_mode'] ?? 'UNKNOWN');

        if ($marketMode === 'TRENDING') {
            $ema9 = (float) ($indicatorSnapshot['ema_9'] ?? 0);
            $ema21 = (float) ($indicatorSnapshot['ema_21'] ?? 0);
            $adx = $indicatorSnapshot['adx'] !== null ? (float) $indicatorSnapshot['adx'] : 0.0;
            $isUp = $ema9 >= $ema21;
            $strong = $adx >= self::ADX_STRONG_TREND;
            $weak = ! $strong && $adx >= self::ADX_WEAK_TREND;

            $regime = match (true) {
                $isUp && $strong => self::REGIME_STRONG_UPTREND,
                $isUp => self::REGIME_WEAK_UPTREND,
                $strong => self::REGIME_STRONG_DOWNTREND,
                default => self::REGIME_WEAK_DOWNTREND,
            };
            $strength = $strong ? 'strong' : ($weak ? 'weak' : 'unconfirmed');

            return [
                'regime' => $regime,
                'reason' => sprintf('market_mode=TRENDING direction=%s adx=%.2f strength=%s', $isUp ? 'up' : 'down', $adx, $strength),
            ];
        }

        if ($marketMode === 'VOLATILE') {
            return ['regime' => self::REGIME_HIGH_VOLATILITY, 'reason' => 'market_mode=VOLATILE'];
        }

        if ($marketMode === 'SIDEWAYS') {
            return ['regime' => self::REGIME_RANGE, 'reason' => 'market_mode=SIDEWAYS'];
        }

        return ['regime' => self::REGIME_UNCERTAIN, 'reason' => "market_mode={$marketMode}"];
    }

    /**
     * Reserve Manager: manual mode returns the static configured pct
     * unchanged. Dynamic mode adjusts within [reserve_min_pct, reserve_max_pct]
     * — the hard clamp means the effective reserve can NEVER exceed the
     * user's own configured bounds regardless of regime.
     *
     * @return array{mode:string, base_pct:float, min_pct:float, max_pct:float, effective_pct:float, reason:string}
     */
    private function resolveReserve(RatioBotConfig $config, string $regime): array
    {
        $base = 1 - max(0.01, min(1.0, (float) ($config->initial_entry_pct ?? 1.0)));
        $min = max(0.0, min(1.0, (float) ($config->reserve_min_pct ?? $base)));
        $max = max($min, min(1.0, (float) ($config->reserve_max_pct ?? $base)));

        if ((string) $config->reserve_mode !== 'dynamic') {
            return [
                'mode' => 'manual',
                'base_pct' => round($base, 4),
                'min_pct' => round($min, 4),
                'max_pct' => round($max, 4),
                'effective_pct' => round($base, 4),
                'reason' => 'reserve_mode=manual: initial_entry_pct unchanged',
            ];
        }

        $factor = $this->reserveAdjustmentFactor($regime);
        $effective = max($min, min($max, $base * $factor));

        return [
            'mode' => 'dynamic',
            'base_pct' => round($base, 4),
            'min_pct' => round($min, 4),
            'max_pct' => round($max, 4),
            'effective_pct' => round($effective, 4),
            'reason' => sprintf('regime=%s factor=%.2f clamped to [%.4f, %.4f]', $regime, $factor, $min, $max),
        ];
    }

    private function reserveAdjustmentFactor(string $regime): float
    {
        return match ($regime) {
            self::REGIME_STRONG_DOWNTREND => 1.50,
            self::REGIME_WEAK_DOWNTREND => 1.25,
            self::REGIME_HIGH_VOLATILITY => 1.35,
            self::REGIME_LOW_LIQUIDITY => 1.20,
            self::REGIME_RANGE => 1.00,
            self::REGIME_WEAK_UPTREND => 0.85,
            self::REGIME_STRONG_UPTREND => 0.65,
            default => 1.00, // UNCERTAIN — no adjustment, stay conservative
        };
    }
}
