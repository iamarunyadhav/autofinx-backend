<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceAlgoOrderApi;
use App\Domain\Exchange\BinanceFuturesClient;
use App\Models\FutureTradeBotConfig;
use Throwable;

class FutureTradeOrderRouter
{
    public function __construct(private readonly FutureTradeLatencyService $latency)
    {
    }

    /**
     * Central order-send point for Future Trade.
     *
     * Transport is REST today. The surrounding bot now calls this router instead
     * of BinanceFuturesClient directly, so a Binance WS order transport can be
     * introduced here without touching signal/risk/position logic.
     */
    public function place(FutureTradeBotConfig $config, BinanceFuturesClient $client, array $payload, array $context = []): array
    {
        $settings = $config->mergedSettings();
        $requestedTransport = strtolower((string) ($settings['orderTransport'] ?? config('future_trade.order_router.transport', 'rest')));
        $transport = in_array($requestedTransport, ['rest', 'websocket'], true) ? $requestedTransport : 'rest';
        $websocketEnabled = array_key_exists('orderWebSocketEnabled', $settings)
            ? (bool) $settings['orderWebSocketEnabled']
            : (bool) config('future_trade.order_router.websocket_enabled', false);

        // Conditional (stop/take-profit) types live on Binance's Algo Order service and are sent over
        // REST by BinanceFuturesClient::placeConditionalOrder(). mode=legacy keeps the old path untouched.
        $conditional = BinanceAlgoOrderApi::isConditionalType((string) ($payload['type'] ?? ''))
            && $client->conditionalOrderMode() !== 'legacy';
        if ($conditional && $transport === 'websocket') {
            $transport = 'rest';
            $context['transport_fallback_reason'] = 'conditional_orders_use_rest_algo_endpoint';
        }

        if ($transport === 'websocket' && ! $websocketEnabled) {
            $transport = 'rest';
            $context['transport_fallback_reason'] = 'websocket_order_transport_disabled';
        }

        $trace = $this->latency->start((string) $config->workspace_id, 'order_router', 'place_order', [
            'transport' => $transport,
            'requested_transport' => $requestedTransport,
            'symbol' => $payload['symbol'] ?? null,
            'side' => $payload['side'] ?? null,
            'type' => $payload['type'] ?? null,
            'reduce_only' => $payload['reduceOnly'] ?? null,
            ...$context,
        ]);

        try {
            $response = $conditional
                ? $client->placeConditionalOrder($payload)
                : ($transport === 'websocket'
                    ? $client->placeOrderViaWebSocket($payload)
                    : $client->placeOrder($payload));
            $latency = $this->latency->finish($trace, 'ok', [
                'binance_order_id' => $response['orderId'] ?? null,
                'client_order_id' => $response['clientOrderId'] ?? $payload['newClientOrderId'] ?? null,
                'status' => $response['status'] ?? null,
            ]);

            return [
                'response' => $response,
                'transport' => $transport,
                'latency_ms' => $latency['latency_ms'] ?? null,
                'trace_id' => $latency['trace_id'] ?? null,
            ];
        } catch (Throwable $e) {
            $this->latency->finish($trace, 'failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw $e;
        }
    }

    public function placeReduceOnlyStop(
        FutureTradeBotConfig $config,
        BinanceFuturesClient $client,
        string $symbol,
        string $side,
        string $type,
        float $stopPrice,
        string $clientOrderId,
        string $workingType = 'MARK_PRICE',
        array $context = [],
    ): array {
        $payload = [
            'symbol' => $symbol,
            'side' => $side,
            'type' => $type,
            'stopPrice' => number_format(round($stopPrice, 8), 8, '.', ''),
            'closePosition' => 'true',
            'workingType' => $workingType,
            'newClientOrderId' => $clientOrderId,
        ];

        return $this->place($config, $client, $payload, [
            'intent' => 'protective_stop',
            'stop_price' => $stopPrice,
            ...$context,
        ]);
    }
}
