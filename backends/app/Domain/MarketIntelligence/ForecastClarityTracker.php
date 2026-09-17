<?php

namespace App\Domain\MarketIntelligence;

use Illuminate\Support\Facades\Cache;

/**
 * Stateful wrapper around MultiWindowTrendScoringService::scoreFor(), which
 * is deliberately stateless (see that method's own docblock -- it reports
 * `status: 'clear'|'insufficient_clarity'` per call, with no memory across
 * calls). This class supplies the memory: it mirrors
 * App\Domain\RatioBot\WalletAwareDirectionFallbackService's own
 * streak-tracking pattern (`*_streak_started_at`/`*_streak_ticks`,
 * reset-on-dropout) but inverted -- that service tracks a streak of
 * AGREEMENT building toward a flip; this one tracks a streak of WAITING
 * for clarity, reset the moment clarity actually arrives.
 *
 * Per-tick state like this belongs in cache, not a database table --
 * mirrors existing precedent in this codebase
 * (RatioBotExecutionService.php:901 uses a 4s TTL, FutureTradeBotService.php
 * uses 10s/300s TTLs for equivalent short-lived per-tick bookkeeping) rather
 * than inventing a new persistence layer for something this ephemeral.
 */
class ForecastClarityTracker
{
    /**
     * Generous enough to survive a normal polling gap: Quick Trade's own
     * forecast poll interval is 15s (`QUICK_TRADE_HISTORY_REFRESH_MS` in
     * useQuickTrade.ts), so this TTL comfortably survives many missed/slow
     * polls in a row without ever pretending the wait streak survives
     * indefinitely (a stale, months-old wait-tick count would be
     * meaningless -- this cache key is scoped to "recent, live" bookkeeping
     * only). Matches this codebase's existing 300s TTL precedent
     * (FutureTradeBotService.php).
     */
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Default "we've waited too long, give up this tick" bound (plan
     * correction #1's explicit max-wait constant). At a 15s poll interval,
     * 10 ticks is ~2.5 minutes of genuinely unclear market conditions --
     * long enough to not flap on a single noisy tick, short enough to stay
     * well inside CACHE_TTL_SECONDS above so the counter is never silently
     * reset by cache expiry before this bound is ever reached.
     */
    public const DEFAULT_MAX_WAIT_TICKS = 10;

    /**
     * Record this tick's scoreFor() result against the running wait-streak
     * for this (botType, botInstanceId, symbol, timeframe) key, and return
     * the updated bookkeeping.
     *
     * @param  array{status?: string}  $scoreForResult  MultiWindowTrendScoringService::scoreFor()'s
     *         return value for THIS tick (reused as-is, never recomputed
     *         here -- same discipline as WalletAwareDirectionFallbackService's
     *         own $trendScore parameter).
     * @return array{status: string, wait_ticks: int, max_wait_ticks: int, max_wait_exceeded: bool}
     */
    public function track(
        string $botType,
        string $botInstanceId,
        string $symbol,
        string $timeframe,
        array $scoreForResult,
        int $maxWaitTicks = self::DEFAULT_MAX_WAIT_TICKS,
    ): array {
        $key = $this->cacheKey($botType, $botInstanceId, $symbol, $timeframe);
        $status = (string) ($scoreForResult['status'] ?? 'insufficient_clarity');

        if ($status === 'clear') {
            // Reset-on-dropout-into-clarity, mirroring
            // WalletAwareDirectionFallbackService's own reset of its streak
            // fields the moment the condition it was waiting for stops
            // holding (there, a dropout from agreement; here, an arrival at
            // clarity -- the streak this class tracks is "still waiting",
            // so clarity ends it).
            Cache::forget($key);

            return [
                'status' => 'clear',
                'wait_ticks' => 0,
                'max_wait_ticks' => $maxWaitTicks,
                'max_wait_exceeded' => false,
            ];
        }

        $waitTicks = ((int) Cache::get($key, 0)) + 1;
        Cache::put($key, $waitTicks, self::CACHE_TTL_SECONDS);

        return [
            'status' => 'insufficient_clarity',
            'wait_ticks' => $waitTicks,
            'max_wait_ticks' => $maxWaitTicks,
            'max_wait_exceeded' => $waitTicks >= $maxWaitTicks,
        ];
    }

    /**
     * Read-only peek at the current wait-tick count for this key, without
     * recording a new tick -- useful for diagnostics/tests.
     */
    public function currentWaitTicks(string $botType, string $botInstanceId, string $symbol, string $timeframe): int
    {
        return (int) Cache::get($this->cacheKey($botType, $botInstanceId, $symbol, $timeframe), 0);
    }

    /**
     * Scoped per (botType, botInstanceId, symbol, timeframe) so two
     * different symbols/bots never share wait-streak state -- the same
     * scoping granularity scoreFor() itself is called with.
     */
    private function cacheKey(string $botType, string $botInstanceId, string $symbol, string $timeframe): string
    {
        return sprintf('unified_forecast:clarity:%s:%s:%s:%s', $botType, $botInstanceId, $symbol, $timeframe);
    }
}
