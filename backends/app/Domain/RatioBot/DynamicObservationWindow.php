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
     */
    public function resolveWindowMinutes(RatioBotConfig $config, RatioBotState $state, array $trendScore): array
    {
        $base = max(1, (int) ($config->recovery_timeout_minutes ?? 60));

        $previous = $state->recovery_trend_composite_at_last_review !== null
            ? (float) $state->recovery_trend_composite_at_last_review
            : null;
        $current = (float) ($trendScore['composite_score'] ?? 0.0);
        $velocity = $previous !== null ? ($current - $previous) : 0.0;

        $adjustment = match (true) {
            $velocity > self::STRONG_VELOCITY => 1.5,
            $velocity < self::WEAK_VELOCITY => 0.5,
            default => 1.0,
        };

        $minMinutes = $config->recoveryMinObservationMinutes();
        $maxMinutes = $config->recoveryMaxObservationMinutes();
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
