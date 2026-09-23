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
            'candles' => 'required|array|min:10|max:500',
            'candles.*.open' => 'required|numeric',
            'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric',
            'candles.*.close' => 'required|numeric',
            // Optional, additive: the chart already carries per-candle volume
            // (see useQuickTradeChart / ChartCandle); accepting it here lets
            // contextFeatures() compute real volume_ratio/volume_status
            // instead of always falling back to "unknown".
            'candles.*.volume' => 'nullable|numeric',
            // Part B: the candle's OPEN time. Required for "closed candles
            // only" to mean anything -- without it CandleCloseResolver
            // cannot tell a closed bar from the forming one and honestly
            // reports timestamps_available=false rather than guessing.
            // Nullable so existing callers that omit it keep working.
            'candles.*.timestamp' => 'nullable|numeric',
            'candles.*.opens_at' => 'nullable|string|max:64',
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
            'market_context' => 'nullable|string|max:200',
            // Part C2/C3: the duration options the picker is showing, so the
            // backend can return each one's real max-achievable move and
            // disabled reason at the CURRENT volatility. Purely additive --
            // omit them and the response simply has no
            // duration_availability block.
            'mode' => 'nullable|string|in:paper,live',
            'durations' => 'nullable|array|max:20',
            'durations.*' => 'integer|min:5|max:3600',
        ]);

        if ($request->boolean('async')) {
            return app(\App\Domain\Trading\AsyncTradeForecast::class)->request(
                (string) $request->user()->getAuthIdentifier(), 'forecast', $data
            );
        }

        return response()->json(app(\App\Domain\Trading\QuickTradeForecastCalculator::class)->forecast($data, $forecastSvc));
    }

    /** Read-only normalized comparison for Quick/Robot/Future decision-support UIs. */
    public function forecastComparison(Request $request, TradeForecastService $forecastSvc)
    {
        $data = $request->validate([
            'bot_type' => 'required|string|in:quick_trade,robot_trade,future_trade',
            'symbol' => 'required|string|max:32', 'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600', 'candles' => 'required|array|min:10|max:500',
            'candles.*.open' => 'required|numeric', 'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric', 'candles.*.close' => 'required|numeric',
            'signal' => 'nullable|array', 'exchange' => 'nullable|string|max:32',
            'market_context' => 'nullable|string|max:200',
        ]);
        if ($request->boolean('async')) {
            return app(\App\Domain\Trading\AsyncTradeForecast::class)->request(
                (string) $request->user()->getAuthIdentifier(), 'comparison', $data
            );
        }

        return response()->json(app(\App\Domain\Trading\QuickTradeForecastCalculator::class)->comparison($data, $forecastSvc));
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
                    $eventLagMs = max(0, (int) floor(microtime(true) * 1000) - $eventTs);
                    if ($eventLagMs > max(1000, (int) config('autofinx.runtime.live_feed_max_event_lag_ms', 10000))) {
                        Log::warning('Quick Trade candle feed rejected stale event', [
                            'symbol' => $symbol,
                            'timeframe' => $timeframe,
                            'event_lag_ms' => $eventLagMs,
                        ]);
                        continue;
                    }
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
                        'latency_ms' => $eventLagMs,
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
