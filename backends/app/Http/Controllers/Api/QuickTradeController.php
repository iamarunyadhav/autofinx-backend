<?php

namespace App\Http\Controllers\Api;

use App\Domain\AuditResearch\AuditEventRecorder;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\MarketIntelligence\UnifiedForecastLiveGate;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\ForecastLiveEvidenceService;
use App\Domain\Trading\QuickTradeService;
use App\Domain\Trading\RuntimeSafetySettingsService;
use App\Domain\Trading\TradeForecastService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class QuickTradeController extends Controller
{
    public function metadata(Request $request, TradingModuleSettingsService $settings)
    {
        return response()->json($settings->metadata($request->query('workspace_id')));
    }

    public function chart(Request $request, QuickTradeService $quickTrades)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'timeframe' => 'nullable|string|max:16',
            'chart_type' => 'nullable|string|max:24',
            'indicator_set' => 'nullable|array',
            'mode' => 'nullable|string|in:paper,live',
            'provider' => 'nullable|string|max:32',
            'integration_id' => 'nullable|uuid',
        ]);

        return response()->json($quickTrades->chart(
            $data['workspace_id'] ?? '',
            (string) $data['symbol'],
            (string) ($data['timeframe'] ?? '1m'),
            (string) ($data['chart_type'] ?? 'area'),
            (array) ($data['indicator_set'] ?? []),
            [
                'mode' => $data['mode'] ?? null,
                'provider' => $data['provider'] ?? null,
                'integration_id' => $data['integration_id'] ?? null,
            ]
        ));
    }

    public function technicalSummary(Request $request, QuickTradeService $quickTrades)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'timeframe' => 'nullable|string|max:16',
            'indicator_set' => 'nullable|array',
        ]);

        $chart = $quickTrades->chart(
            $data['workspace_id'] ?? '',
            (string) $data['symbol'],
            (string) ($data['timeframe'] ?? '1m'),
            'area',
            (array) ($data['indicator_set'] ?? [])
        );

        return response()->json([
            'symbol' => $chart['symbol'],
            'timeframe' => $chart['timeframe'],
            'quote' => $chart['quote'],
            'signal_preview' => $chart['signal_preview'],
        ]);
    }

    /**
     * Near-term forecast + fee/slippage-aware net edge for the trade the
     * user is about to place — pure compute, no market-data fetching.
     * The caller supplies candles/signal it already has from its own
     * chart poll, so this never doubles the Binance REST calls that
     * feed the chart.
     */
    public function forecast(Request $request, TradeForecastService $forecastSvc)
    {
        $data = $request->validate([
            'candles' => 'required|array|min:10',
            'candles.*.open' => 'required|numeric',
            'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric',
            'candles.*.close' => 'required|numeric',
            // Optional, additive: the chart already carries per-candle volume
            // (see useQuickTradeChart / ChartCandle); accepting it here lets
            // contextFeatures() compute real volume_ratio/volume_status
            // instead of always falling back to "unknown".
            'candles.*.volume' => 'nullable|numeric',
            'signal' => 'nullable|array',
            'signal.direction' => 'nullable|string',
            'signal.confidence' => 'nullable|numeric',
            'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600',
            'stake_amount' => 'required|numeric|min:0.01',
            // Optional, additive: only used for the Unified Forecast Engine
            // shadow comparison below -- today's real callers don't send
            // these (see useQuickTrade.ts), and the response is byte-for-
            // byte unchanged whether they're present or not.
            'symbol' => 'nullable|string|max:32',
            'exchange' => 'nullable|string|max:32',
        ]);

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

        return response()->json([
            'forecast' => $forecastResult,
            'net_edge' => $netEdgeResult,
        ]);
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

    /** Read-only normalized comparison for Quick/Robot/Future decision-support UIs. */
    public function forecastComparison(Request $request, TradeForecastService $forecastSvc)
    {
        $data = $request->validate([
            'bot_type' => 'required|string|in:quick_trade,robot_trade,future_trade',
            'symbol' => 'required|string|max:32', 'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600', 'candles' => 'required|array|min:10',
            'candles.*.open' => 'required|numeric', 'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric', 'candles.*.close' => 'required|numeric',
            'signal' => 'nullable|array', 'exchange' => 'nullable|string|max:32',
        ]);
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
        return response()->json(['schema_version' => 1, 'bot_type' => $data['bot_type'],
            'current' => array_merge($current, ['source' => 'current', 'mode' => 'primary',
                'symbol' => $data['symbol'], 'timeframe' => $data['timeframe'], 'price_target_available' => true]),
            'unified' => $unified, 'comparison' => ['direction_agreement' => $unified === null ? null : $same,
                'agreement' => $unified === null ? 'UNAVAILABLE' : ($same ? 'AGREE' : 'DISAGREE')],
            'execution_source' => 'unchanged_existing_engine']);
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

    /**
     * Backend-owned Binance Futures candle stream for Quick Trade.
     *
     * The browser consumes this SSE endpoint; only the Laravel backend opens the
     * Binance REST/WS connections. That keeps Quick Trade market data on the same
     * server-side trust boundary as paper/live execution.
     */
    public function candleFeed(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:32',
            'timeframe' => 'nullable|string|max:16',
            'history_limit' => 'nullable|integer|min:20|max:500',
            'max_seconds' => 'nullable|integer|min:10|max:1800',
        ]);

        $symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $data['symbol']));
        $timeframe = $this->toBinanceInterval((string) ($data['timeframe'] ?? '1m'));
        $historyLimit = (int) ($data['history_limit'] ?? 200);
        $maxSeconds = (int) ($data['max_seconds'] ?? 300);

        $stream = function () use ($symbol, $timeframe, $historyLimit, $maxSeconds) {
            $emit = static function (array $payload): void {
                echo 'data: ' . json_encode($payload) . "\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            };

            $history = $this->fetchBinanceHistory($symbol, $timeframe, $historyLimit);
            if ($history !== []) {
                $emit([
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'source' => 'backend_binance_rest',
                    'candles' => $history,
                    'current' => $history[array_key_last($history)] ?? null,
                    'ts' => now()->toIso8601String(),
                ]);
            }

            $client = new SimpleWebSocketClient();
            $startedAt = time();
            $lastPingAt = time();
            $lastFrameAt = time();

            try {
                $baseUrl = rtrim((string) config('services.binance.futures_ws_url', 'wss://fstream.binance.com'), '/');
                $client->connect($baseUrl . '/ws/' . strtolower($symbol) . '@kline_' . $timeframe, 10);

                $emit([
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'source' => 'backend_binance_ws',
                    'connected' => true,
                    'ts' => now()->toIso8601String(),
                ]);

                while (! connection_aborted() && (time() - $startedAt) < $maxSeconds) {
                    $frame = $client->readFrame(1);

                    if ((time() - $lastPingAt) >= 20) {
                        $client->sendPing('quick-trade');
                        $lastPingAt = time();
                    }

                    if ($frame === null) {
                        if ((time() - $lastFrameAt) >= 15) {
                            $emit([
                                'symbol' => $symbol,
                                'timeframe' => $timeframe,
                                'source' => 'backend_binance_ws',
                                'heartbeat' => true,
                                'ts' => now()->toIso8601String(),
                            ]);
                            $lastFrameAt = time();
                        }
                        continue;
                    }

                    if (($frame['type'] ?? null) === 'close') {
                        break;
                    }

                    if (($frame['type'] ?? null) !== 'text') {
                        continue;
                    }

                    $payload = json_decode((string) ($frame['payload'] ?? ''), true);
                    $kline = is_array($payload) ? ($payload['k'] ?? null) : null;
                    if (! is_array($kline)) {
                        continue;
                    }

                    $eventTs = (int) ($payload['E'] ?? floor(microtime(true) * 1000));
                    $candle = [
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'time' => (int) floor(((int) ($kline['t'] ?? $eventTs)) / 1000),
                        'open' => (float) ($kline['o'] ?? 0),
                        'high' => (float) ($kline['h'] ?? 0),
                        'low' => (float) ($kline['l'] ?? 0),
                        'close' => (float) ($kline['c'] ?? 0),
                        'volume' => (float) ($kline['v'] ?? 0),
                        'isClosed' => (bool) ($kline['x'] ?? false),
                    ];

                    $lastFrameAt = time();
                    $emit([
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'source' => 'backend_binance_ws',
                        'candle' => $candle,
                        'current' => $candle,
                        'event_ts' => $eventTs,
                        'latency_ms' => max(0, (int) floor(microtime(true) * 1000) - $eventTs),
                        'ts' => now()->toIso8601String(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('Quick Trade candle feed disconnected', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'message' => $exception->getMessage(),
                ]);

                $emit([
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'source' => 'backend_binance_ws',
                    'connected' => false,
                    'error' => 'backend_feed_disconnected',
                    'ts' => now()->toIso8601String(),
                ]);
            } finally {
                $client->close();
            }
        };

        return response()->stream($stream, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function place(
        Request $request,
        QuickTradeService $quickTrades,
        TradingModuleSettingsService $settings,
    )
    {
        $payload = $request->validate([
            'workspace_id' => 'required|uuid',
            'wallet_account_id' => 'nullable|uuid',
            'robot_session_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'side' => 'required|in:buy,sell',
            'stake_amount' => 'required|numeric|min:0.01',
            'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600',
            'mode' => 'nullable|string|in:paper,live',
            'provider' => 'nullable|string|max:32',
            'integration_id' => 'nullable|uuid',
            'indicator_set' => 'nullable|array',
            'idempotency_key' => 'nullable|string|max:120',
            // Optional per-trade stop-loss, live mode only (silently ignored
            // for paper trades in QuickTradeService::place — see docblock
            // there). Negative percentage of stake, e.g. -2.00 for a 2% stop.
            'stop_loss_pct' => 'nullable|numeric|lt:0|gte:-100',
            // Optional per-trade take-profit, live mode only (silently ignored
            // for paper trades in QuickTradeService::place, mirroring
            // stop_loss_pct exactly). POSITIVE percentage of stake, e.g. 5.00
            // for a 5% target — opposite sign convention from stop_loss_pct.
            'take_profit_pct' => 'nullable|numeric|gt:0',
            // Optional per-trade leverage, live mode only. No app-layer upper
            // bound — the real ceiling is whatever LeverageValidationService
            // determines from Binance's own tiered leverage brackets for this
            // symbol/notional (see QuickTradeService::placeLiveTrade()).
            'leverage_enabled' => 'nullable|boolean',
            'leverage' => 'required_if:leverage_enabled,true|nullable|numeric|min:1',
        ]);

        $headerKey = $request->header('Idempotency-Key');
        if (! isset($payload['idempotency_key']) && is_string($headerKey) && trim($headerKey) !== '') {
            $payload['idempotency_key'] = trim($headerKey);
        }

        if (($payload['mode'] ?? 'paper') === 'live') {
            $state = $settings->tradingState((string) $payload['workspace_id']);
            if (! ($state['live_ready'] ?? false)) {
                return response()->json([
                    'message' => $state['live_ready_reason'] ?? 'Live quick trading is not ready for this workspace.',
                ], 422);
            }
        }

        try {
            $trade = $quickTrades->place($payload, $request->user()?->id);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($trade->fresh(), 201);
    }

    public function active(Request $request, string $workspaceId, QuickTradeService $quickTrades)
    {
        return response()->json($quickTrades->active(
            $workspaceId,
            $request->query('source'),
            $request->query('robot_session_id')
        ));
    }

    public function history(Request $request, string $workspaceId, QuickTradeService $quickTrades)
    {
        return response()->json($quickTrades->history(
            $workspaceId,
            $request->query('source'),
            $request->query('robot_session_id'),
            (int) $request->query('limit', 100)
        ));
    }

    public function settleDue(Request $request, QuickTradeService $quickTrades)
    {
        return response()->json([
            'settled' => $quickTrades->settleExpired((int) $request->input('limit', 100)),
        ]);
    }

    private function fetchBinanceHistory(string $symbol, string $timeframe, int $limit): array
    {
        try {
            $baseUrl = rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/');
            $rows = Http::timeout(8)
                ->retry(1, 250)
                ->get($baseUrl . '/fapi/v1/klines', [
                    'symbol' => $symbol,
                    'interval' => $timeframe,
                    'limit' => $limit,
                ])
                ->throw()
                ->json();

            if (! is_array($rows)) {
                return [];
            }

            return array_values(array_filter(array_map(static function ($row) use ($symbol, $timeframe): ?array {
                if (! is_array($row) || count($row) < 6) {
                    return null;
                }

                return [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'time' => (int) floor(((int) $row[0]) / 1000),
                    'open' => (float) $row[1],
                    'high' => (float) $row[2],
                    'low' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                    'isClosed' => true,
                ];
            }, $rows)));
        } catch (\Throwable $exception) {
            Log::warning('Quick Trade candle history fetch failed', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function toBinanceInterval(string $timeframe): string
    {
        $normalized = trim($timeframe);
        $allowed = [
            '1m', '3m', '5m', '15m', '30m',
            '1h', '2h', '4h', '6h', '8h', '12h',
            '1d', '3d', '1w', '1M',
        ];

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        return match (strtolower($normalized)) {
            '60m' => '1h',
            '240m' => '4h',
            default => '1m',
        };
    }
}
