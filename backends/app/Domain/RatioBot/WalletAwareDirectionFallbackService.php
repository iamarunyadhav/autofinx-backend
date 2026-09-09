<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use Illuminate\Support\Carbon;

/**
 * WalletAwareDirectionFallback -- resolveAutoStartDirection() blocks purely
 * because the wallet lacks balance for the brain/trend-resolved direction
 * (e.g. bullish-resolved but no base-asset balance to watch for SELL). This
 * service ONLY fires for that specific wallet-insufficiency block -- never
 * for a confidence block, never for a trend/forecast-disagreement block --
 * and asks: is the OPPOSITE direction also independently justified by the
 * market (via the same tick's MultiWindowTrendScoringService result, not a
 * re-run of the reliability-weighted forecast arbitration -- see the
 * caller's own docblock for why) AND does the wallet actually hold balance
 * for that opposite side? If both hold continuously across a dynamic,
 * volatility-adaptive observation window (DynamicObservationWindow, reused
 * via its override params so this never shares state with the unrelated
 * Recovery-monitoring feature), the caller is told to arm the opposite
 * direction instead of staying idle forever.
 *
 * Does NOT place any order and does NOT change execution timing -- returning
 * a direction from resolveAutoStartDirection() flows into the SAME existing
 * anchorManualBuy/anchorManualSell call the normal auto-direction path
 * already uses, and the actual BUY/SELL still only executes once price
 * reaches the trigger and shows a genuine reversal, exactly as today.
 */
class WalletAwareDirectionFallbackService
{
    public function __construct(
        private readonly DynamicObservationWindow $observationWindow,
    ) {
    }

    /**
     * @param string $blockedDirection 'BUY'|'SELL' -- the direction the
     *        brain/trend resolution wanted to start, blocked purely by
     *        insufficient wallet balance for that side.
     * @param array $trendScore MultiWindowTrendScoringService::score() result
     *        for THIS tick, already computed by the caller -- reused as-is,
     *        never recomputed here.
     * @return array{action: string}
     */
    public function evaluate(
        RatioBotConfig $config,
        RatioBotState $state,
        string $blockedDirection,
        array $trendScore,
        bool $hasBase,
        bool $hasQuote,
    ): array {
        if (! $config->walletFallbackEnabled()) {
            return ['action' => 'disabled'];
        }

        $oppositeDirection = $blockedDirection === 'BUY' ? 'SELL' : 'BUY';
        $oppositeTrendLabel = $oppositeDirection === 'SELL' ? 'bullish' : 'bearish';

        $independentlyJustified = ($trendScore['direction'] ?? 'neutral') === $oppositeTrendLabel;
        $hasOppositeBalance = $oppositeDirection === 'SELL' ? $hasBase : $hasQuote;

        if (! $hasOppositeBalance || ! $independentlyJustified) {
            $streakActive = $state->wallet_fallback_streak_started_at !== null
                || (int) ($state->wallet_fallback_streak_ticks ?? 0) !== 0;
            if ($streakActive) {
                $state->update([
                    'wallet_fallback_streak_started_at' => null,
                    'wallet_fallback_streak_ticks' => 0,
                ]);
            }

            return [
                'action' => 'none',
                'opposite_direction' => $oppositeDirection,
                'independently_justified' => $independentlyJustified,
                'has_opposite_balance' => $hasOppositeBalance,
            ];
        }

        if ($state->wallet_fallback_streak_started_at === null) {
            $state->update([
                'wallet_fallback_streak_started_at' => now(),
                'wallet_fallback_streak_ticks' => 1,
            ]);

            return [
                'action' => 'observing',
                'streak_started' => true,
                'opposite_direction' => $oppositeDirection,
            ];
        }

        $state->update(['wallet_fallback_streak_ticks' => (int) ($state->wallet_fallback_streak_ticks ?? 0) + 1]);

        $previousComposite = $state->wallet_fallback_trend_composite_at_last_review !== null
            ? (float) $state->wallet_fallback_trend_composite_at_last_review
            : null;

        $window = $this->observationWindow->resolveWindowMinutes(
            $config,
            $state,
            $trendScore,
            baseMinutesOverride: $config->walletFallbackBaseObservationMinutes(),
            previousCompositeOverride: $previousComposite,
            minMinutesOverride: $config->walletFallbackMinObservationMinutes(),
            maxMinutesOverride: $config->walletFallbackMaxObservationMinutes(),
        );

        // Mirrors RatioBotRealtimeEngine::applyRatioBrainSideEffects()'s own
        // "update after computing" timing for the equivalent recovery field --
        // updated here, from this service only, never touching the
        // recovery-owned field.
        $state->update(['wallet_fallback_trend_composite_at_last_review' => (float) ($trendScore['composite_score'] ?? 0.0)]);

        $streakStartedAt = $state->wallet_fallback_streak_started_at instanceof Carbon
            ? $state->wallet_fallback_streak_started_at
            : Carbon::parse($state->wallet_fallback_streak_started_at);
        $elapsedMinutes = $streakStartedAt->diffInMinutes(now());
        $requiredMinutes = (int) $window['minutes'];

        if ($elapsedMinutes < $requiredMinutes) {
            return [
                'action' => 'observing',
                'opposite_direction' => $oppositeDirection,
                'elapsed_minutes' => $elapsedMinutes,
                'required_minutes' => $requiredMinutes,
                'observation_window' => $window,
            ];
        }

        if (
            $state->last_wallet_fallback_flip_at !== null
            && $state->last_wallet_fallback_flip_at->diffInMinutes(now()) < $config->walletFallbackMinCooldownMinutes()
        ) {
            // Streak state is NOT reset here -- a bot that's still qualifying
            // just waits out the cooldown, it doesn't lose its confirmed
            // streak.
            return [
                'action' => 'cooldown_blocked',
                'reason' => 'flipped too recently',
                'opposite_direction' => $oppositeDirection,
            ];
        }

        $confirmedTicks = (int) ($state->wallet_fallback_streak_ticks ?? 0);

        $state->update([
            'wallet_fallback_streak_started_at' => null,
            'wallet_fallback_streak_ticks' => 0,
            'last_wallet_fallback_flip_at' => now(),
        ]);

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_WALLET_FALLBACK_FLIP,
            'context' => [
                'blocked_direction' => $blockedDirection,
                'to_direction' => $oppositeDirection,
                'trend_score' => $trendScore,
                'streak_ticks_confirmed' => $confirmedTicks,
                'observation_window_minutes' => $requiredMinutes,
                'elapsed_minutes' => $elapsedMinutes,
            ],
            'occurred_at' => now(),
        ]);

        return [
            'action' => 'flip',
            'new_direction' => $oppositeDirection,
            'observation_window' => $window,
        ];
    }
}
