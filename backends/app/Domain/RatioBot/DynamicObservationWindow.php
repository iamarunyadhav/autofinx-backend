<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * Volatility-adaptive replacement for a fixed recovery_timeout_minutes --
 * expands the window during a genuinely strengthening recovery and
 * contracts it during a deteriorating one. Reuses
 * MultiWindowTrendScoringService's already-computed composite score as the
 * "is this recovery strengthening or weakening" signal instead of a second,
 * parallel trend calculation.
 *
 * Bounded by recovery_min/max_observation_minutes -- unbounded growth is a
 * real risk (a runaway "always extend" loop could keep a bot in recovery
 * indefinitely), so the clamp is mandatory, mirroring this project's
 * established pattern of always capping adaptive/growing mechanisms (the
 * Recovery Slot's target cap, the leverage-dampened reserve formula).
 */
class DynamicObservationWindow
{
    private const STRONG_VELOCITY = 0.15;
    private const WEAK_VELOCITY = -0.15;

    /**
     * $trendScore is the CURRENT tick's MultiWindowTrendScoringService::score()
     * result -- passed in rather than recomputed here so a caller already
     * holding one fresh call doesn't pay for a second.
     *
     * The four override params let a second, unrelated feature
     * (WalletAwareDirectionFallbackService) reuse this exact same
     * base/velocity/clamp math without sharing state or config with the
     * Recovery-monitoring feature that owns the un-overridden defaults --
     * every existing call site (none of which pass these) is byte-for-byte
     * unaffected. When provided, $previousCompositeOverride replaces reading
     * $state->recovery_trend_composite_at_last_review entirely (the caller
     * passes its own previous-composite field instead).
     */
    public function resolveWindowMinutes(
        RatioBotConfig $config,
        RatioBotState $state,
        array $trendScore,
        ?int $baseMinutesOverride = null,
        ?float $previousCompositeOverride = null,
        ?int $minMinutesOverride = null,
        ?int $maxMinutesOverride = null,
    ): array {
        // $baseMinutesOverride being non-null is the signal that this call is
        // in "override context" (WalletAwareDirectionFallbackService) rather
        // than the default recovery-monitoring context -- that caller always
        // passes all four override args together (see its own docblock), so
        // this single check correctly routes $previousCompositeOverride even
        // when its own value is legitimately null (no prior composite yet,
        // exactly like the un-overridden path's own "no prior review" case).
        $overrideContext = $baseMinutesOverride !== null;
        $base = $overrideContext ? $baseMinutesOverride : max(1, (int) ($config->recovery_timeout_minutes ?? 60));

        $previous = $overrideContext
            ? $previousCompositeOverride
            : ($state->recovery_trend_composite_at_last_review !== null
                ? (float) $state->recovery_trend_composite_at_last_review
                : null);
        $current = (float) ($trendScore['composite_score'] ?? 0.0);
        $velocity = $previous !== null ? ($current - $previous) : 0.0;

        $adjustment = match (true) {
            $velocity > self::STRONG_VELOCITY => 1.5,
            $velocity < self::WEAK_VELOCITY => 0.5,
            default => 1.0,
        };

        $minMinutes = $minMinutesOverride ?? $config->recoveryMinObservationMinutes();
        $maxMinutes = $maxMinutesOverride ?? $config->recoveryMaxObservationMinutes();
        $minutes = (int) max($minMinutes, min($maxMinutes, (int) round($base * $adjustment)));

        return [
            'minutes' => $minutes,
            'base_minutes' => $base,
            'adjustment' => $adjustment,
            'velocity' => round($velocity, 4),
            'previous_composite' => $previous,
            'current_composite' => $current,
            'min_minutes' => $minMinutes,
            'max_minutes' => $maxMinutes,
        ];
    }
}
