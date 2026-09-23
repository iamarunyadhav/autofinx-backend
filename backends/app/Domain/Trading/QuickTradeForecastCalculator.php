<?php

namespace App\Domain\Trading;

use App\Domain\AuditResearch\AuditEventRecorder;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\MarketIntelligence\UnifiedForecastLiveGate;
use App\Domain\Ops\FeatureFlagService;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shared calculation engine for HTTP compatibility and background forecasts. */
class QuickTradeForecastCalculator
{
    public function forecast(array $data, TradeForecastService $forecastSvc): array
    {
        // --- Part B1: decisions are computed from CLOSED candles only -------
        // The forming bar is still what supplies `current_price` (via the
        // canonical live quote resolved below), it just never contributes to
        // direction / trade decision / indicator evidence.
        $rawCandles = $data['candles'];
        $timing = app(CandleCloseResolver::class)->resolve($rawCandles, (string) $data['timeframe']);
        $candles = $timing['candles'];
        $decision = isset($data['signal']) ? $forecastSvc->decisionFromSignalPreview($data['signal']) : null;

        // Optional, additive: reuse the same live-feed-freshness mechanism
        // already gating live order entry (QuickTradeService::assertFreshLiveFeedForEntry,
        // CheckQuickTradeStopLossJob) so a confident BUY/SELL forecast is not
        // shown against a price the platform itself would refuse to trade
        // on. Only meaningful when a snapshot actually exists for this
        // symbol -- absence (e.g. no active WS subscription yet) is left
        // null, not treated as stale (see TradeForecastService::forecast()).
        $liveFeed = null;
        $referencePrice = null;
        $liveExchange = $this->liveFeedExchange($data['exchange'] ?? null);
        if (! empty($data['symbol']) && $liveExchange !== null) {
            $maxAge = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds(null);
            $snapshot = app(MarketPriceCacheService::class)->getLiveFeedSnapshot($liveExchange, (string) $data['symbol'], $maxAge);
            if ($snapshot !== null) {
                $liveFeed = [
                    'fresh' => (bool) ($snapshot['fresh'] ?? false),
                    'age_seconds' => $snapshot['age_seconds'] ?? null,
                    'event_lag_ms' => $snapshot['event_lag_ms'] ?? null,
                    'max_age_seconds' => $snapshot['max_age_seconds'] ?? null,
                    'max_event_lag_ms' => $snapshot['max_event_lag_ms'] ?? null,
                    'market_type' => $snapshot['market_type'] ?? null,
                ];
                // Never trust a browser-supplied display quote for a forecast
                // that may be shown beside a live-order control. Only the
                // same server-side canonical quote that passed freshness can
                // become the price reference.
                if (($snapshot['fresh'] ?? false) && (float) ($snapshot['price'] ?? 0) > 0) {
                    $referencePrice = (float) $snapshot['price'];
                }
            }
        }

        $forecastResult = $forecastSvc->forecast(
            $candles,
            (string) $data['timeframe'],
            (int) $data['expiry_seconds'],
            $decision,
            null,
            $liveFeed,
            ! empty($data['symbol']) ? (string) $data['symbol'] : null,
            $referencePrice,
            $timing,
        );
        $costModel = app(QuickTradeCostModel::class);
        $horizonCandles = $costModel->horizonCandles((int) $data['expiry_seconds'], (int) $timing['timeframe_seconds']);
        $netEdgeResult = $forecastSvc->netEdge($candles, (float) $data['stake_amount'], $horizonCandles);

        // --- Part C2/C3: per-duration availability at the CURRENT volatility.
        // Everything the duration picker renders (max achievable %, required
        // %, the disabled reason, the shortest viable expiry) is computed
        // here and sent down, so the UI never hardcodes a percentage.
        $atrPct = (float) ($forecastResult['atr_pct'] ?? 0);
        $mode = strtolower((string) ($data['mode'] ?? 'paper'));
        $durations = array_values(array_filter(array_map(
            static fn ($value) => (int) $value,
            (array) ($data['durations'] ?? []),
        ), static fn (int $value) => $value > 0));
        if ($durations !== []) {
            $forecastResult['duration_availability'] = $costModel->durationAvailability(
                $durations,
                $atrPct,
                (int) $timing['timeframe_seconds'],
                $mode,
            );
            $shortest = $costModel->shortestViableExpirySeconds($durations, $atrPct, (int) $timing['timeframe_seconds']);
            $forecastResult['shortest_viable_expiry_seconds'] = $shortest;
        }
        $forecastResult['cost_model'] = $costModel->costs();
        $forecastResult['candle_timing'] = [
            'timeframe_seconds' => $timing['timeframe_seconds'],
            'decision_candle_close_at' => $timing['decision_candle_close_at'],
            'seconds_since_last_close' => $timing['seconds_since_last_close'],
            'next_close_in_seconds' => $timing['next_close_in_seconds'],
            'grace_ms' => $timing['grace_ms'],
            'within_grace' => $timing['within_grace'],
            'candle_gap' => $timing['candle_gap'],
            'forming_bar_dropped' => $timing['forming_bar_dropped'],
            'timestamps_available' => $timing['timestamps_available'],
            'closed_candles_used' => count($candles),
            'raw_candles_supplied' => count($rawCandles),
        ];

        // --- Part B2: close-anchored decision cache ------------------------
        // The decision is recomputed once per CLOSED candle (plus a grace
        // window that absorbs feed-delivery jitter) and reused until the next
        // close, so the verdict on screen cannot flicker mid-candle. Only the
        // DECISION fields are cached — price, live_feed and the candle
        // countdown stay live on every call, because freezing a displayed
        // price beside a live order button would be actively dangerous.
        // Scoped to this Quick Trade calculator: TradeForecastService itself
        // is untouched, so Ratio Bot / Robot Trade / Future Trade keep their
        // existing every-call behaviour.
        $forecastResult = $this->applyCloseAnchoredDecisionCache($forecastResult, $data, $timing);

        // Additive baseline set BEFORE the shadow block below so the
        // AuditEventRecorder 'old' snapshot it logs and the actual JSON
        // response always carry an identical $forecastResult -- the shadow
        // wiring's own test asserts response==audit-log-old byte-for-byte.
        $forecastResult['decision_source'] = 'internal';
        // UI-only telemetry. This is intentionally copied from the exact
        // server-side snapshot used as the reference above, so the browser
        // can make clear whether the target is based on a fresh canonical
        // quote or the candle-close fallback. It never becomes an execution
        // authorization and contains no user-supplied price.
        $forecastResult['live_feed'] = $liveFeed;

        // Unified Forecast Engine, Phase 5 -- SHADOW COMPARISON ONLY. This
        // block never changes $forecastResult/$netEdgeResult above (already
        // computed) and the response returned below is identical whether
        // this runs or not. Feature-flag-checked FIRST, before any
        // computation, so the flag being off means genuinely zero
        // UnifiedForecastEngine work happens -- not just "the result is
        // discarded".
        $shadowEngineResult = null;
        if (app(FeatureFlagService::class)->enabled('unified_forecast.quick_trade_shadow', true) && ! empty($data['symbol'])) {
            $shadowEngineResult = $this->recordUnifiedForecastShadowComparison($data, $forecastResult);
        }

        // Unified Forecast Engine, Phase 6 -- LIVE decision wiring
        // (`unified_forecast.quick_trade_live`, default OFF). Additive only:
        // 'decision_source' stays 'internal' (set above) unless the flag is
        // on AND the exact scope is real-evidence VALIDATED AND the engine
        // itself succeeds, and only ever overrides decision_trend/
        // confidence -- never predicted price targets or any other field.
        // A data-quality gate that already fired inside forecast() itself
        // (stale feed / low volume -- see 'gate_reason') is authoritative
        // and is never second-guessed by the engine: WAIT stands regardless
        // of which model would have said BUY/SELL.
        if (! empty($data['symbol']) && ($forecastResult['gate_reason'] ?? null) === null) {
            $exchange = (string) ($data['exchange'] ?: config('unified_forecast.quick_trade_default_exchange', 'binance'));
            $symbol = (string) $data['symbol'];
            $timeframe = (string) $data['timeframe'];
            $horizonSeconds = (int) $data['expiry_seconds'];
            $botInstanceId = sprintf('%s:%s:%s', $exchange, $symbol, $timeframe);

            $liveDecision = app(UnifiedForecastLiveGate::class)->resolve(
                'unified_forecast.quick_trade_live',
                'quick_trade',
                $botInstanceId,
                $exchange,
                $symbol,
                $timeframe,
                $horizonSeconds,
                fn () => app(ForecastLiveEvidenceService::class)->quickTradeHoldoutPnlSummary($symbol, $timeframe, $horizonSeconds),
                $shadowEngineResult,
            );

            $forecastResult = $this->applyUnifiedForecastLiveDecision($forecastResult, $liveDecision);
        }

        return [
            'forecast' => $forecastResult,
            'net_edge' => $netEdgeResult,
         ];
    }

    /**
     * Fields that make up "the decision" for close-anchored caching (B2).
     * Deliberately NOT including price/live_feed/candle_timing: those stay
     * live on every call.
     */
    private const CACHED_DECISION_FIELDS = [
        'bias', 'decision_trend', 'confidence', 'confidence_status',
        'direction', 'trade_decision', 'gate_reason', 'context_caution',
        'evidence', 'current_trend', 'summary', 'generated_at',
        'forecast_stability', 'forecast_stability_flips', 'forecast_stability_window',
    ];

    /**
     * @param  array<string,mixed>  $forecastResult
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $timing
     * @return array<string,mixed>
     */
    private function applyCloseAnchoredDecisionCache(array $forecastResult, array $data, array $timing): array
    {
        if (! (bool) config('quick_trade.decision_close_anchored_cache', true)
            || empty($data['symbol'])
            || ($forecastResult['available'] ?? false) !== true
            || ($timing['timestamps_available'] ?? false) !== true) {
            $forecastResult['decision_cache'] = ['status' => 'not_applicable'];

            return $forecastResult;
        }

        $resolver = app(CandleCloseResolver::class);
        $closeMs = $timing['decision_candle_close_ms'] ?? null;
        // Inside the grace window immediately after a close, the newly closed
        // bar may not be fully delivered yet — keep serving the PREVIOUS
        // candle's decision rather than deciding on a half-written bar.
        $anchorMs = ($timing['within_grace'] ?? false) && $closeMs !== null
            ? $closeMs - ((int) $timing['timeframe_seconds'] * 1000)
            : $closeMs;

        $key = $resolver->decisionCacheKey(
            (string) $data['symbol'],
            (string) $data['timeframe'],
            (int) $data['expiry_seconds'],
            $anchorMs,
        );
        if ($key === null) {
            $forecastResult['decision_cache'] = ['status' => 'not_applicable'];

            return $forecastResult;
        }

        $ttlSeconds = max(60, (int) $timing['timeframe_seconds'] * 3);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            foreach (self::CACHED_DECISION_FIELDS as $field) {
                if (array_key_exists($field, $cached)) {
                    $forecastResult[$field] = $cached[$field];
                }
            }
            $forecastResult['decision_cache'] = [
                'status' => 'reused',
                'anchor_candle_close_ms' => $anchorMs,
                'next_decision_in_seconds' => $timing['next_close_in_seconds'],
            ];

            return $forecastResult;
        }

        $snapshot = [];
        foreach (self::CACHED_DECISION_FIELDS as $field) {
            if (array_key_exists($field, $forecastResult)) {
                $snapshot[$field] = $forecastResult[$field];
            }
        }
        Cache::put($key, $snapshot, $ttlSeconds);
        $forecastResult['decision_cache'] = [
            'status' => 'computed',
            'anchor_candle_close_ms' => $anchorMs,
            'next_decision_in_seconds' => $timing['next_close_in_seconds'],
        ];

        return $forecastResult;
    }

    /** Returns the only canonical Redis namespaces supported by this preview. */
    private function liveFeedExchange(mixed $exchange): ?string
    {
        return match (strtolower(trim((string) $exchange))) {
            'binance' => 'binance',
            'binance_futures' => 'binance_futures',
            // Existing Quick Trade callers historically omitted exchange and
            // represent Binance timed trades; retain that safe default.
            '' => 'binance_futures',
            default => null,
        };
    }

    /**
     * Applies a `UnifiedForecastLiveGate::resolve()` verdict to an already-
     * computed `TradeForecastService::forecast()` result: additive only
     * (`decision_source`, `unified_forecast_decision`), and only overrides
     * `decision_trend`/`confidence`/`confidence_status` when the verdict
     * actually used the engine. `UnifiedForecastEngine::evaluate()` never
     * produces a price target (see `forecastComparison()`'s own
     * `price_target_available: false`), so predicted_expiry_price/
     * expected_mid/expected_low/expected_high are deliberately left
     * untouched -- out of scope for this task.
     */
    private function applyUnifiedForecastLiveDecision(array $forecastResult, array $liveDecision): array
    {
        $forecastResult['decision_source'] = $liveDecision['decision_source'];

        if ($liveDecision['decision_source'] !== 'unified_forecast_engine' || ! is_array($liveDecision['engine_result'])) {
            return $forecastResult;
        }

        $engine = $liveDecision['engine_result'];
        $direction = strtoupper((string) ($engine['direction'] ?? 'NEUTRAL'));
        $decisionTrend = in_array($direction, ['BUY', 'SELL'], true) ? $direction : 'WAIT';
        $forecastResult['decision_trend'] = $decisionTrend;

        // Never fabricate a confidence figure the engine itself did not
        // provide (e.g. an honest NEUTRAL/insufficient-clarity tick) --
        // leave the existing internally-computed confidence in that case
        // rather than guessing.
        if (($engine['confidence'] ?? null) !== null) {
            $confidence = round((float) $engine['confidence'], 1);
            $forecastResult['confidence'] = $confidence;
            $forecastResult['confidence_status'] = $confidence >= 75 ? 'medium_high' : ($confidence >= 55 ? 'medium' : 'low');
        }

        $forecastResult['unified_forecast_decision'] = $engine;

        return $forecastResult;
    }

    public function comparison(array $data, TradeForecastService $forecastSvc): array
    {
        $candles = $data['candles'];
        $current = $forecastSvc->forecast($candles, $data['timeframe'], (int) $data['expiry_seconds'],
            isset($data['signal']) ? $forecastSvc->decisionFromSignalPreview($data['signal']) : null,
            null, null, (string) $data['symbol']);
        $exchange = (string) ($data['exchange'] ?? 'binance');
        $unified = null;
        try {
            $unified = app(UnifiedForecastEngine::class)->evaluate(
                $data['bot_type'], $exchange.':'.$data['symbol'].':'.$data['timeframe'], $exchange,
                $data['symbol'], $data['timeframe'], (int) $data['expiry_seconds']
            );
            $unified = array_merge($unified, ['source' => 'unified', 'mode' => 'shadow',
                'symbol' => $data['symbol'], 'timeframe' => $data['timeframe'],
                'horizon_seconds' => (int) $data['expiry_seconds'], 'price_target_available' => false]);
        } catch (Throwable $e) {
            Log::warning('[UnifiedForecastEngine] normalized comparison failed', ['bot_type' => $data['bot_type'], 'error' => $e->getMessage()]);
        }
        $same = $unified !== null && strtoupper((string) ($current['bias'] ?? '')) === strtoupper((string) ($unified['direction'] ?? ''));
        return ['schema_version' => 1, 'bot_type' => $data['bot_type'],
            'current' => array_merge($current, ['source' => 'current', 'mode' => 'primary',
                'symbol' => $data['symbol'], 'timeframe' => $data['timeframe'], 'price_target_available' => true]),
            'unified' => $unified, 'comparison' => ['direction_agreement' => $unified === null ? null : $same,
                'agreement' => $unified === null ? 'UNAVAILABLE' : ($same ? 'AGREE' : 'DISAGREE')],
            'execution_source' => 'unchanged_existing_engine' ];
    }

    /**
     * Shadow-only: computes UnifiedForecastEngine::evaluate() for the same
     * symbol/timeframe/horizon the real forecast() call above just used,
     * and logs the comparison via the existing AuditEventRecorder (no
     * second logging mechanism). Wrapped in its own try/catch so a shadow
     * failure can NEVER affect the real response -- AuditEventRecorder::record()
     * itself already never throws, but the engine computation this method
     * adds around it is new and must carry the same guarantee.
     *
     * Returns the raw engine result (or null on failure) purely so the
     * caller's Phase 6 LIVE-decision wiring can reuse this exact same
     * evaluate() call for the same scope instead of computing it twice --
     * this method's own shadow-only contract (never affects the real
     * response by itself) is unchanged.
     */
    private function recordUnifiedForecastShadowComparison(array $data, array $forecastResult): ?array
    {
        try {
            $exchange = (string) ($data['exchange'] ?: config('unified_forecast.quick_trade_default_exchange', 'binance'));
            $symbol = (string) $data['symbol'];
            $timeframe = (string) $data['timeframe'];
            $horizonSeconds = (int) $data['expiry_seconds'];
            $botInstanceId = sprintf('%s:%s:%s', $exchange, $symbol, $timeframe);

            $engine = app(UnifiedForecastEngine::class);
            $newResult = $engine->evaluate($botType = 'quick_trade', $botInstanceId, $exchange, $symbol, $timeframe, $horizonSeconds);

            AuditEventRecorder::record(
                'unified_forecast_shadow_comparison',
                AuditEvent::CATEGORY_DECISION,
                [
                    'bot_type' => $botType,
                    'bot_instance_id' => $botInstanceId,
                    'symbol' => $symbol,
                    'metadata' => [
                        'old' => $forecastResult,
                        'new' => $newResult,
                    ],
                ],
            );

            return $newResult;
        } catch (Throwable $e) {
            Log::warning('[UnifiedForecastEngine] Quick Trade shadow comparison failed (non-fatal, real forecast response unaffected)', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

}
