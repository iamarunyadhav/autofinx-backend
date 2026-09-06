<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * Futures-Adapted Recovery review (Part B) -- a purpose-built service, not a
 * reuse of spot's RecoveryReviewService. Spot has a movable active_anchor
 * reference point separate from cost basis and a lots/stages ladder to
 * average down across; futures has one entry_price and no ladder. This
 * service therefore decides only ever a single, bounded, one-time top-up --
 * never a repeating average-down.
 */
class FuturesRecoveryReviewService
{
    public const OUTCOME_KEEP_WAITING = 'KEEP_WAITING';
    public const OUTCOME_TOP_UP_ONCE  = 'TOP_UP_ONCE';
    public const OUTCOME_PAUSE        = 'PAUSE';

    public function __construct(
        private readonly IndicatorService $indicators,
    ) {
    }

    /**
     * @param  array  $liquidationAssessment  Part A's RatioBotLiquidationGuardService::assess()
     *                                        result for this SAME tick -- passed in, never
     *                                        re-fetched, so this service can never diverge from
     *                                        what the Liquidation Guard already computed.
     */
    public function review(RatioBotConfig $config, RatioBotState $state, float $markPrice, array $liquidationAssessment): array
    {
        // Gate 1: liquidation danger, always checked first. A stuck position
        // that's also near liquidation must never be topped up -- that is
        // exactly backwards from what's safe.
        $dangerLevel = (string) ($liquidationAssessment['danger_level'] ?? 'safe');
        if (in_array($dangerLevel, ['watch', 'critical'], true)) {
            return $this->result(self::OUTCOME_PAUSE, "liquidation guard danger_level={$dangerLevel} -- never top up near liquidation risk");
        }

        // Gate 2: one top-up per open position, ever.
        if ((bool) $state->futures_recovery_used) {
            return $this->result(self::OUTCOME_PAUSE, 'this position has already used its one-time recovery top-up');
        }

        $side = strtolower((string) ($state->last_position_side ?? ''));
        if (! in_array($side, ['long', 'short'], true)) {
            return $this->result(self::OUTCOME_KEEP_WAITING, 'no open futures position to recover');
        }

        // Gate 3: does the signal still support this direction? Never top up
        // a position the bot's own signal no longer believes in -- the
        // position doesn't get help from money, only from the same signal
        // that opened it still confidently agreeing.
        $snapshot = $this->indicators->snapshot($config, $markPrice);
        $confidence = $this->directionConfidencePct($snapshot, $side);
        $minConfidence = $config->futuresRecoveryMinConfidencePct();
        if ($confidence < $minConfidence) {
            return $this->result(
                self::OUTCOME_PAUSE,
                sprintf('signal confidence for %s has dropped to %.1f%%, below the required %.1f%%', $side, $confidence, $minConfidence),
                ['confidence_pct' => $confidence],
            );
        }

        // Gate 4: the top-up itself must not create new liquidation risk --
        // project the post-fill buffer using the same isolated-margin
        // formula FuturesRiskService::estimateLiquidationBuffer() already
        // uses elsewhere in this codebase, applied prospectively.
        $topupNotional = $config->futuresRecoveryTopupNotional($state);
        $liquidationPrice = (float) ($liquidationAssessment['liquidation_price'] ?? 0);
        if ($topupNotional > 0 && $markPrice > 0 && $liquidationPrice > 0) {
            $projectedBuffer = $this->projectedBufferAfterTopup($config, $state, $markPrice, $topupNotional, $side);
            $watchThreshold = $config->liquidationWatchBufferPct();
            if ($projectedBuffer !== null && $projectedBuffer <= $watchThreshold) {
                return $this->result(
                    self::OUTCOME_PAUSE,
                    sprintf(
                        'topping up would push the projected liquidation buffer to %.2f%%, at or below the watch threshold %.2f%% -- refusing a top-up that immediately creates new liquidation risk',
                        $projectedBuffer,
                        $watchThreshold,
                    ),
                    ['projected_buffer_pct' => $projectedBuffer],
                );
            }
        }

        return $this->result(
            self::OUTCOME_TOP_UP_ONCE,
            sprintf('signal still supports %s at %.1f%% confidence and liquidation risk is safe', $side, $confidence),
            ['confidence_pct' => $confidence, 'topup_notional' => $topupNotional],
        );
    }

    /**
     * A deterministic, always-available proxy for "does the signal still
     * support this direction" -- built from indicator fields that genuinely
     * exist and are already computed every tick (IndicatorService::snapshot()),
     * since no strategy in this codebase reports a numeric confidence value.
     * 50 = neutral; EMA trend alignment (weighted by ADX trend strength)
     * moves it toward 100 when aligned with the position's side, toward 0
     * when aligned against it; an extreme counter-trend RSI reading trims a
     * further 10 points.
     */
    public function directionConfidencePct(array $snapshot, string $side): float
    {
        $ema9 = (float) ($snapshot['ema_9'] ?? 0);
        $ema21 = (float) ($snapshot['ema_21'] ?? 0);
        $ema50 = (float) ($snapshot['ema_50'] ?? 0);
        $adx = (float) ($snapshot['adx'] ?? 0);
        $rsi = $snapshot['rsi'] !== null ? (float) $snapshot['rsi'] : 50.0;

        $score = 50.0;
        $emasPresent = $ema9 > 0 && $ema21 > 0 && $ema50 > 0;
        $bullAligned = $emasPresent && $ema9 > $ema21 && $ema21 > $ema50;
        $bearAligned = $emasPresent && $ema9 < $ema21 && $ema21 < $ema50;
        $trendWeight = $adx > 0 ? min(1.0, $adx / 40.0) : 0.5;

        if ($side === 'long') {
            if ($bullAligned) {
                $score += 25 * $trendWeight;
            }
            if ($bearAligned) {
                $score -= 25 * $trendWeight;
            }
            if ($rsi <= 30) {
                $score -= 10;
            }
        } else {
            if ($bearAligned) {
                $score += 25 * $trendWeight;
            }
            if ($bullAligned) {
                $score -= 25 * $trendWeight;
            }
            if ($rsi >= 70) {
                $score -= 10;
            }
        }

        return max(0.0, min(100.0, $score));
    }

    private function projectedBufferAfterTopup(RatioBotConfig $config, RatioBotState $state, float $markPrice, float $topupNotional, string $side): ?float
    {
        $oldQty = abs((float) ($state->last_position_qty ?? 0));
        $oldEntry = (float) ($state->entry_price ?? 0);
        if ($oldQty <= 0 || $oldEntry <= 0) {
            return null;
        }

        $topupQty = $topupNotional / $markPrice;
        $newQty = $oldQty + $topupQty;
        $newEntry = (($oldQty * $oldEntry) + ($topupQty * $markPrice)) / $newQty;

        $leverage = max(1.0, (float) ($config->leverage ?? 1));
        $projectedLiquidationPrice = $side === 'short'
            ? $newEntry * (1 + (1 / $leverage))
            : $newEntry * max(0.0, 1 - (1 / $leverage));

        if ($projectedLiquidationPrice <= 0) {
            return null;
        }

        return $side === 'short'
            ? (($projectedLiquidationPrice - $markPrice) / $markPrice) * 100
            : (($markPrice - $projectedLiquidationPrice) / $markPrice) * 100;
    }

    private function result(string $outcome, string $reason, array $extra = []): array
    {
        return ['outcome' => $outcome, 'reason' => $reason] + $extra;
    }
}
