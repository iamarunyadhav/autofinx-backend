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
        // Gap-checklist N3 -- stake is MARGIN; the USDT cost/PnL estimate must
        // be computed on the notional the order would really carry (margin x
        // leverage, after the exchange qty-step rounding placement applies).
        // Percent gates (required_move_pct, cost_pct, the trade_decision
        // block) are leverage-independent and are NOT touched here.
        $positionSizing = $this->positionSizing($data, $forecastResult);
        $netEdgeResult = $forecastSvc->netEdge($candles, (float) $positionSizing['cost_basis_notional'], $horizonCandles);
        $netEdgeResult = $this->withMarginView($netEdgeResult, $positionSizing);

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

        // Gap-checklist N4-shadow -- untraded WAIT shadow capture. Observation
        // only (never influences the response above), flag-gated (default
        // OFF), one row per symbol/timeframe/expiry/decision-candle-close,
        // and fail-safe: QuickTradeWaitShadowService never throws.
        app(QuickTradeWaitShadowService::class)->captureFromForecast($data, $forecastResult);

        return [
            'forecast' => $forecastResult,
            'net_edge' => $netEdgeResult,
            'position_sizing' => $positionSizing,
         ];
    }

    /**
     * Gap-checklist N3 -- the single, explicit statement of what the order
     * would be: margin (stake), leverage, target notional and -- when the
     * exchange's qty-step rule is available -- the rounded quantity and the
     * REAL notional placement would produce. The UI renders these verbatim
     * rather than guessing.
     *
     * Leverage is honoured in LIVE mode only (paper trades are payout-style
     * and QuickTradeService::place() silently ignores leverage for them).
     * Rounding is only attempted when the caller actually sent `leverage`
     * (today's order form always does in live mode) for a Binance symbol, so
     * callers that omit it keep today's exact net_edge numbers.
     *
     * `cost_basis_notional` is what net_edge's USDT fields are computed on:
     * the rounded notional when known and non-zero, otherwise the unrounded
     * target (and `rounding` says so).
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $forecastResult
     * @return array<string,mixed>
     */
    private function positionSizing(array $data, array $forecastResult): array
    {
        $stake = max(0.0, (float) $data['stake_amount']);
        $mode = strtolower((string) ($data['mode'] ?? 'paper'));
        $leverageRequested = array_key_exists('leverage', $data) && $data['leverage'] !== null;
        $leverage = $mode === 'live' && $leverageRequested ? max(1.0, (float) $data['leverage']) : 1.0;
        $notionalTarget = round($stake * $leverage, 8);
        $referencePrice = (float) ($forecastResult['reference_price'] ?? $forecastResult['current_price'] ?? 0);

        $sizing = [
            'mode' => $mode,
            'stake_margin' => round($stake, 8),
            'leverage' => $leverage,
            'leverage_applied' => $mode === 'live' && $leverageRequested,
            'notional_target' => $notionalTarget,
            'reference_price' => $referencePrice > 0 ? round($referencePrice, 8) : null,
            'rounding' => $mode !== 'live' ? 'not_applicable_paper' : 'not_requested',
            'rounding_source' => null,
            'step_size' => null,
            'min_qty' => null,
            'min_notional' => null,
            'quantity' => null,
            'notional' => $notionalTarget,
            'below_exchange_minimum' => null,
            'cost_basis_notional' => $notionalTarget,
        ];

        $liveExchange = $this->liveFeedExchange($data['exchange'] ?? null);
        if ($mode !== 'live' || ! $leverageRequested || empty($data['symbol']) || $liveExchange !== 'binance_futures') {
            return $sizing;
        }

        $estimate = app(Providers\BinanceTimedFuturesProvider::class)
            ->sizingEstimate((string) $data['symbol'], $notionalTarget, $referencePrice);
        $sizing = array_merge($sizing, [
            'rounding' => $estimate['rounding'],
            'rounding_source' => $estimate['rounding'] === 'exchange_step' ? 'binance_futures_exchange_info' : null,
            'step_size' => $estimate['step_size'],
            'min_qty' => $estimate['min_qty'],
            'min_notional' => $estimate['min_notional'],
            'quantity' => $estimate['quantity'],
            'below_exchange_minimum' => $estimate['below_exchange_minimum'],
        ]);
        if ($estimate['rounding'] === 'exchange_step') {
            $sizing['notional'] = (float) $estimate['notional'];
            if ((float) $estimate['notional'] > 0) {
                $sizing['cost_basis_notional'] = (float) $estimate['notional'];
            }
        }

        return $sizing;
    }

    /**
     * Adds the margin-relative view of net_edge's (notional-based) USDT
     * figures, so a 10x trade visibly costs ~1% of stake round trip. Purely
     * additive; `stake` keeps meaning "the amount the percentages were
     * applied to" -- restated explicitly as `notional` beside
     * `stake_margin` so nothing downstream has to infer which it is.
     *
     * @param  array<string,mixed>  $netEdge
     * @param  array<string,mixed>  $sizing
     * @return array<string,mixed>
     */
    private function withMarginView(array $netEdge, array $sizing): array
    {
        $margin = (float) $sizing['stake_margin'];
        $notional = (float) $sizing['cost_basis_notional'];
        $roundTripFeeUsdt = $notional * ((float) ($netEdge['round_trip_fee_pct'] ?? 0) / 100);
        $pctOfMargin = static fn (float $usdt): ?float => $margin > 0 ? round($usdt / $margin * 100, 6) : null;

        return array_merge($netEdge, [
            'stake_margin' => round($margin, 8),
            'notional' => round($notional, 8),
            'leverage' => $sizing['leverage'],
            'notional_rounding' => $sizing['rounding'],
            'round_trip_fee_usdt' => round($roundTripFeeUsdt, 8),
            'round_trip_fee_pct_of_margin' => $pctOfMargin($roundTripFeeUsdt),
            'actual_cost_pct_of_margin' => $pctOfMargin((float) ($netEdge['actual_cost_usdt'] ?? 0)),
        ]);
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
