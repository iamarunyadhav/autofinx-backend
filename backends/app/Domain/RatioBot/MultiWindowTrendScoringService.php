<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;

/**
 * Replaces RunRatioBotEvaluationJob::observedCandleDirection()'s single
 * lookback window with three windows (short/medium/long, built off the
 * same auto_start_observation_candles base) whose moves are each scaled by
 * that window's own ATR (via TradeForecastService::computeAtrAvgPct() --
 * reused rather than re-derived, so this never drifts from the ATR math
 * the Near-Term Forecast card already shows) so a "big" move on a volatile
 * symbol and a "big" move on a calm one are judged on the same footing.
 *
 * A window's raw score is its move as a multiple of its own ATR, clamped to
 * [-1, 1]. The composite is a fixed 0.5/0.3/0.2 weighted sum (short window
 * weighted highest, matching the pre-existing single-window default's own
 * near-term bias) -- disagreement between windows pulls the composite
 * toward zero on its own, without a separate "agreement bonus" needing to
 * be invented.
 *
 * Deliberately has no direct relationship to config('assets.*.default_
 * threshold_pct') -- that key does not exist anywhere in config/assets.php
 * (verified before writing this), so a dynamic per-window ATR reference is
 * used instead of a static, asset-class-specific magic number that would
 * need inventing (and would silently be missing for crypto symbols, which
 * have no default_threshold_pct-shaped field either).
 */
class MultiWindowTrendScoringService
{
    private const WINDOW_DEFS = [
        ['key' => 'short', 'label' => 'Short', 'multiplier' => 0.5, 'weight' => 0.5],
        ['key' => 'medium', 'label' => 'Medium', 'multiplier' => 1.0, 'weight' => 0.3],
        ['key' => 'long', 'label' => 'Long', 'multiplier' => 2.0, 'weight' => 0.2],
    ];

    private const DIRECTION_SCORE_THRESHOLD = 0.3;

    public function __construct(
        private readonly TradeForecastService $forecast,
    ) {
    }

    public function score(RatioBotConfig $config): array
    {
        // Thin wrapper: extract the same two RatioBotConfig coupling points
        // this class has always had (strategy_params scalars, and
        // exchange/symbol/timeframe scalars) and delegate to the generic
        // scoreFor(). The two new diagnostic keys scoreFor() adds
        // ('threshold_cleared', 'status' -- see that method's docblock) are
        // stripped back off here so this method's output stays byte-for-byte
        // identical to before scoreFor() existed; Ratio Bot's own call sites
        // and tests never see them.
        $baseCandles = (int) data_get($config->strategy_params, 'auto_start_observation_candles', 24);
        $minComposite = (float) data_get($config->strategy_params, 'trend_score_min_composite', 0.15);

        $result = $this->scoreFor(
            $config->exchangeKey(),
            $config->normalizedSymbol(),
            $config->timeFrame(),
            $baseCandles,
            $minComposite,
        );

        unset($result['threshold_cleared'], $result['status']);

        return $result;
    }

    /**
     * Generalized, `RatioBotConfig`-free version of the real windowing/ATR-
     * normalization/composite logic that used to live only inside score().
     * Reused as-is by score() above (zero behavior change for Ratio Bot) and
     * intended for later reuse by Quick Trade/Robot Trade/Future Trade call
     * sites (not wired in this pass).
     *
     * Max-wait bound (plan correction #1): this method is stateless -- it
     * has no memory of how many prior ticks failed to clear the composite
     * threshold. What it DOES do is expose that per-call fact explicitly:
     * `threshold_cleared` (bool) and `status` ('clear' | 'insufficient_clarity')
     * tell a caller whether THIS tick's composite genuinely cleared
     * min_composite. A single 'insufficient_clarity' tick is not itself a
     * verdict -- it is the raw signal a STATEFUL caller (built in a later
     * phase) is expected to accumulate across ticks, the same way
     * `WalletAwareDirectionFallbackService` tracks a streak-with-reset-on-
     * dropout using its own `*_streak_started_at`/`*_streak_ticks` state
     * fields rather than asking this kind of stateless scorer to remember
     * anything itself. `$maxWaitTicks`, when supplied, is only echoed back
     * into the return array (as `max_wait_ticks`) for that future stateful
     * caller's own bookkeeping -- it does not change this method's own
     * computation, keeping it a pure function.
     *
     * @return array{
     *   direction:string, composite_score:float, min_composite:float,
     *   agreement_count:int, window_count:int, windows:array,
     *   threshold_cleared:bool, status:string, max_wait_ticks?:int,
     * }
     */
    public function scoreFor(
        string $exchange,
        string $symbol,
        string $timeframe,
        int $baseCandles,
        float $minComposite,
        ?int $maxWaitTicks = null,
    ): array {
        $baseCandles = max(8, min(120, $baseCandles));
        $minComposite = max(0.01, $minComposite);

        $windows = [];
        foreach (self::WINDOW_DEFS as $def) {
            $limit = max(8, min(240, (int) round($baseCandles * $def['multiplier'])));
            $windows[] = array_merge($def, ['candles' => $limit], $this->windowScoreFor($exchange, $symbol, $timeframe, $limit));
        }

        $composite = 0.0;
        foreach ($windows as $window) {
            $composite += $window['score'] * $window['weight'];
        }
        $composite = round($composite, 4);

        $direction = 'neutral';
        if ($composite >= $minComposite) {
            $direction = 'bullish';
        } elseif ($composite <= -$minComposite) {
            $direction = 'bearish';
        }

        $thresholdCleared = $direction !== 'neutral';

        $agreementCount = 0;
        if ($direction !== 'neutral') {
            foreach ($windows as $window) {
                if ($window['direction'] === $direction) {
                    $agreementCount++;
                }
            }
        }

        $result = [
            'direction' => $direction,
            'composite_score' => $composite,
            'min_composite' => round($minComposite, 4),
            'agreement_count' => $agreementCount,
            'window_count' => count($windows),
            'windows' => $windows,
            'threshold_cleared' => $thresholdCleared,
            'status' => $thresholdCleared ? 'clear' : 'insufficient_clarity',
        ];

        if ($maxWaitTicks !== null) {
            $result['max_wait_ticks'] = $maxWaitTicks;
        }

        return $result;
    }

    private function windowScoreFor(string $exchange, string $symbol, string $timeframe, int $limit): array
    {
        $candles = MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->orderByDesc('ts')
            ->limit($limit)
            ->get(['open', 'high', 'low', 'close'])
            ->reverse()
            ->values();

        $empty = [
            'move_pct' => 0.0,
            'reference_move_pct' => 0.0,
            'score' => 0.0,
            'direction' => 'neutral',
            'sample_size' => $candles->count(),
        ];

        if ($candles->count() < 8) {
            return $empty;
        }

        $first = (float) $candles->first()->open;
        $last = (float) $candles->last()->close;
        if ($first <= 0 || $last <= 0) {
            return $empty;
        }

        $movePct = (($last / $first) - 1) * 100;

        $atrCandles = $candles->map(fn ($candle) => [
            'high' => (float) $candle->high,
            'low' => (float) $candle->low,
            'close' => (float) $candle->close,
        ])->all();
        $referenceMovePct = max(0.05, $this->forecast->computeAtrAvgPct($atrCandles));

        $rawScore = $movePct / $referenceMovePct;
        $score = max(-1.0, min(1.0, $rawScore));

        $direction = 'neutral';
        if ($score >= self::DIRECTION_SCORE_THRESHOLD) {
            $direction = 'bullish';
        } elseif ($score <= -self::DIRECTION_SCORE_THRESHOLD) {
            $direction = 'bearish';
        }

        return [
            'move_pct' => round($movePct, 4),
            'reference_move_pct' => round($referenceMovePct, 4),
            'score' => round($score, 4),
            'direction' => $direction,
            'sample_size' => $candles->count(),
        ];
    }
}
