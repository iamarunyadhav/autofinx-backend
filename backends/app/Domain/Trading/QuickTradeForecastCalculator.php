<?php

namespace App\Domain\Trading;

use App\Domain\AuditResearch\AuditEventRecorder;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\MarketIntelligence\UnifiedForecastLiveGate;
use App\Domain\Ops\FeatureFlagService;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shared calculation engine for HTTP compatibility and background forecasts. */
class QuickTradeForecastCalculator
{
    public function forecast(array $data, TradeForecastService $forecastSvc): array
    {
        $candles = $data['candles'];
        $decision = isset($data['signal']) ? $forecastSvc->decisionFromSignalPreview($data['signal']) : null;

        // Optional, additive: reuse the same live-feed-freshness mechanism
        // already gating live order entry (QuickTradeService::assertFreshLiveFeedForEntry,
        // CheckQuickTradeStopLossJob) so a confident BUY/SELL forecast is not
        // shown against a price the platform itself would refuse to trade
        // on. Only meaningful when a snapshot actually exists for this
        // symbol -- absence (e.g. no active WS subscription yet) is left
        // null, not treated as stale (see TradeForecastService::forecast()).
        $liveFeed = null;
        if (! empty($data['symbol'])) {
            $maxAge = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds(null);
            $snapshot = app(MarketPriceCacheService::class)->getLiveFeedSnapshot('binance_futures', (string) $data['symbol'], $maxAge);
            if ($snapshot !== null) {
                $liveFeed = ['fresh' => (bool) ($snapshot['fresh'] ?? false), 'age_seconds' => $snapshot['age_seconds'] ?? null];
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
        );
        $netEdgeResult = $forecastSvc->netEdge($candles, (float) $data['stake_amount']);

        // Additive baseline set BEFORE the shadow block below so the
        // AuditEventRecorder 'old' snapshot it logs and the actual JSON
        // response always carry an identical $forecastResult -- the shadow
        // wiring's own test asserts response==audit-log-old byte-for-byte.
        $forecastResult['decision_source'] = 'internal';

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
