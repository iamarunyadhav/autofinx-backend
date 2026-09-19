<?php

namespace App\Domain\MarketIntelligence;

use App\Domain\Ops\FeatureFlagService;
use App\Domain\RatioBot\ForecastModelPromotionGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Unified Forecast Engine LIVE decision resolver (Unified Forecast Engine
 * plan -- "wire quick_trade_live / robot_trade_live" task). Answers exactly
 * one question for one (bot_type, bot_instance_id, horizon_seconds) scope:
 * "should a real trading decision be driven by
 * UnifiedForecastEngine::evaluate() instead of the caller's existing
 * internal logic, right now?" -- and does so triple-fail-closed, in this
 * exact priority order, so a human flipping a feature flag can never alone
 * put unvalidated automation in charge of real money:
 *
 *   1. Feature flag OFF (default for both `unified_forecast.quick_trade_live`
 *      and `unified_forecast.robot_trade_live`) -> zero computation beyond
 *      the flag read itself, decision_source is always 'internal'.
 *   2. Feature flag ON but `ForecastModelPromotionGate::evaluate()` for this
 *      exact scope is NOT `VALIDATED` -> falls back to 'internal' (logged
 *      at info level so it is visible in ops without ever blocking the
 *      trade). The promotion gate's real, evidence-based status -- not the
 *      human toggle -- is what actually authorizes live automation.
 *   3. Feature flag ON and scope IS `VALIDATED` -> the caller receives the
 *      real `UnifiedForecastEngine::evaluate()` result and decision_source
 *      'unified_forecast_engine'.
 *   4. Any exception anywhere in this chain (promotion-gate evidence
 *      lookup, or the engine's own evaluate()) is caught here and never
 *      propagates -- falls back to 'internal', logged as a warning.
 *
 * This class deliberately does NOT decide what to do with the engine's
 * direction/confidence once returned -- existing data-quality safety gates
 * (stale-feed WAIT, low-volume WAIT, context_caution) live in each caller
 * (TradeForecastService::forecast()'s own gates, RobotSessionService's
 * volatility gate) and must keep applying on top of whichever engine
 * produced the direction; bypassing them here would defeat their purpose.
 *
 * Never writes to any table itself -- `ForecastModelPromotionGate` is
 * read-only by its own contract, and the only "write" this class performs
 * is a short-TTL Cache entry for its OWN verdict (see
 * GATE_CACHE_TTL_SECONDS), purely to avoid re-running a real, potentially
 * DB-heavy holdout-P&L aggregation on every single forecast/session-eval
 * tick for the same scope -- a promotion verdict, backed by real settled
 * trade outcomes, does not meaningfully change within a few minutes.
 */
class UnifiedForecastLiveGate
{
    private const GATE_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly ForecastModelPromotionGate $gate,
        private readonly UnifiedForecastEngine $engine,
    ) {
    }

    /**
     * @param  callable(): ?array  $holdoutPnlSummaryResolver  Lazily invoked
     *         ONLY when the flag is on -- callers should not pay for
     *         building real holdout P&L evidence (DB queries) when the flag
     *         is off. Must return `ForecastMetricsService::summarize()`'s
     *         shape, or null when no real evidence exists yet (never
     *         fabricated) -- see `ForecastModelPromotionGate::evaluate()`'s
     *         own contract.
     * @param  array|null  $reuseEngineResult  Pass the already-computed
     *         `UnifiedForecastEngine::evaluate()` result for this EXACT
     *         scope when the caller's request already computed one this
     *         same tick (e.g. for the sibling shadow comparison) -- this
     *         method then reuses it instead of calling evaluate() a second
     *         time. Only safe to pass when the botInstanceId used to
     *         compute it matches the one passed here.
     * @return array{decision_source:string, engine_result:?array, promotion_status:?string, promotion_reasons:array<int,string>}
     */
    public function resolve(
        string $flagKey,
        string $botType,
        string $botInstanceId,
        string $exchange,
        string $symbol,
        string $timeframe,
        int $horizonSeconds,
        callable $holdoutPnlSummaryResolver,
        ?array $reuseEngineResult = null,
    ): array {
        $fallback = [
            'decision_source' => 'internal',
            'engine_result' => null,
            'promotion_status' => null,
            'promotion_reasons' => [],
        ];

        // Priority 1: flag off -- genuinely zero further work, not just "the
        // result is discarded".
        if (! $this->flags->enabled($flagKey, false)) {
            return $fallback;
        }

        // Priority 2: real-evidence promotion gate.
        try {
            $promotion = $this->promotionStatus($botType, $botInstanceId, $horizonSeconds, $holdoutPnlSummaryResolver);
        } catch (Throwable $e) {
            Log::warning('[UnifiedForecastEngine] live mode promotion-gate evaluation failed, falling back to internal logic', [
                'flag_key' => $flagKey, 'bot_type' => $botType, 'bot_instance_id' => $botInstanceId,
                'horizon_seconds' => $horizonSeconds, 'error' => $e->getMessage(),
            ]);

            return $fallback;
        }

        if ($promotion['status'] !== ForecastModelPromotionGate::STATUS_VALIDATED) {
            Log::info('[UnifiedForecastEngine] live mode enabled but scope not validated, falling back', [
                'flag_key' => $flagKey, 'bot_type' => $botType, 'bot_instance_id' => $botInstanceId,
                'horizon_seconds' => $horizonSeconds, 'promotion_status' => $promotion['status'],
                'promotion_reasons' => $promotion['reasons'],
            ]);

            return array_merge($fallback, [
                'promotion_status' => $promotion['status'],
                'promotion_reasons' => $promotion['reasons'],
            ]);
        }

        // Priority 3/4: scope is validated -- use the real engine result,
        // unless it throws, in which case fall back rather than ever
        // blocking/breaking the trade.
        try {
            $engineResult = $reuseEngineResult ?? $this->engine->evaluate($botType, $botInstanceId, $exchange, $symbol, $timeframe, $horizonSeconds);
        } catch (Throwable $e) {
            Log::warning('[UnifiedForecastEngine] live mode engine evaluate() failed, falling back to internal logic', [
                'flag_key' => $flagKey, 'bot_type' => $botType, 'bot_instance_id' => $botInstanceId,
                'horizon_seconds' => $horizonSeconds, 'error' => $e->getMessage(),
            ]);

            return array_merge($fallback, [
                'promotion_status' => $promotion['status'],
                'promotion_reasons' => $promotion['reasons'],
            ]);
        }

        return [
            'decision_source' => 'unified_forecast_engine',
            'engine_result' => $engineResult,
            'promotion_status' => $promotion['status'],
            'promotion_reasons' => $promotion['reasons'],
        ];
    }

    private function promotionStatus(string $botType, string $botInstanceId, int $horizonSeconds, callable $holdoutPnlSummaryResolver): array
    {
        $cacheKey = sprintf('unified_forecast:live_promotion_gate:%s:%s:%d', $botType, $botInstanceId, $horizonSeconds);

        return Cache::remember($cacheKey, self::GATE_CACHE_TTL_SECONDS, function () use ($botType, $botInstanceId, $horizonSeconds, $holdoutPnlSummaryResolver) {
            $holdoutPnlSummary = $holdoutPnlSummaryResolver();

            return $this->gate->evaluate($botType, $botInstanceId, $horizonSeconds, $holdoutPnlSummary);
        });
    }
}
