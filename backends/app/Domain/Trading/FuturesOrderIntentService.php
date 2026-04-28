<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Domain\Integrations\IntegrationManager;
use App\Models\ExecutionAttempt;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Symbol;
use Illuminate\Support\Str;
use RuntimeException;

class FuturesOrderIntentService
{
    public function __construct(
        private readonly FuturesExecutionGuard $guard,
        private readonly FuturesPositionSyncService $sync,
        private readonly FuturesAnomalyService $anomalies,
        private readonly IntegrationManager $integrations,
        private readonly LiveExecutionEventService $events,
    ) {
    }

    public function evaluate(string $workspaceId, array $payload, Integration $integration, array $config): array
    {
        $symbol = BinanceSymbolNormalizer::normalize((string) ($payload['symbol'] ?? ''));
        $client = $this->client($integration, $config);
        $mark = $client->fetchMarkPrice($symbol);
        $exchangeInfo = $client->fetchExchangeInfo($symbol);
        $market = $this->marketSnapshot($payload, $mark, $exchangeInfo);
        $proposal = $this->proposal($payload, $market);

        return [
            'market' => $market,
            'guard' => $this->guard->evaluate($workspaceId, $proposal, $market),
        ];
    }

    public function place(string $workspaceId, array $payload, Integration $integration, array $config): Order
    {
        $symbol = BinanceSymbolNormalizer::normalize((string) ($payload['symbol'] ?? ''));
        $client = $this->client($integration, $config);
        $mark = $client->fetchMarkPrice($symbol);
        $exchangeInfo = $client->fetchExchangeInfo($symbol);
        $market = $this->marketSnapshot($payload, $mark, $exchangeInfo);
        $proposal = $this->proposal($payload, $market);
        $evaluation = $this->guard->assertCanSubmit($workspaceId, $proposal, $market);

        if (($evaluation['policy']['mode'] ?? 'disabled') === 'review_only') {
            throw new RuntimeException('Futures review_only mode blocks direct order submission. Use evaluation only.');
        }

        $idempotencyKey = (string) ($payload['idempotency_key']
            ?? ('futures:' . $workspaceId . ':' . $symbol . ':' . strtolower((string) ($payload['side'] ?? 'buy')) . ':' . now()->format('YmdHis')));
        $existing = Order::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $clientOrderId = substr($idempotencyKey, 0, 36);
        $symbolModel = Symbol::firstOrCreate(['symbol' => $symbol], [
            'id' => (string) Str::uuid(),
            'type' => 'crypto',
            'exchange' => 'binance_futures',
            'metadata' => ['auto_created' => true],
        ]);

        $traceId = (string) Str::uuid();
        $meta = array_merge([
            'mode' => 'futures_testnet',
            'market_type' => 'futures',
            'environment' => 'testnet',
            'margin_mode' => $proposal['margin_mode'],
            'position_mode' => $proposal['position_mode'],
            'position_side' => $proposal['position_side'] ?? null,
            'leverage' => $proposal['leverage'],
            'reduce_only' => $proposal['reduce_only'],
            'protective_exit' => $proposal['protective_exit'],
            'source' => $proposal['source'],
            'guard' => $evaluation,
            'client_order_id' => $clientOrderId,
        ], (array) ($payload['meta'] ?? []));

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'integration_id' => $integration->id,
            'symbol_id' => $symbolModel->id,
            'provider' => 'binance',
            'exchange' => 'binance_futures',
            'asset_class' => 'CRYPTO',
            'asset_code' => $symbol,
            'quote_currency' => $this->quoteCurrency($symbol),
            'side' => strtolower((string) ($payload['side'] ?? 'buy')),
            'type' => strtolower((string) ($payload['type'] ?? 'market')),
            'quantity' => (float) $proposal['qty'],
            'qty' => (float) $proposal['qty'],
            'requested_qty' => (float) $proposal['qty'],
            'price' => (float) ($market['mark_price'] ?? 0),
            'limit_price' => isset($payload['price']) ? (float) $payload['price'] : null,
            'status' => Order::STATUS_PENDING_PROVIDER,
            'provider_status' => 'PENDING_PROVIDER',
            'client_order_id' => $clientOrderId,
            'idempotency_key' => $idempotencyKey,
            'trace_id' => $traceId,
            'placed_ts' => now(),
            'reconciliation_state' => Order::RECONCILIATION_PENDING,
            'meta' => $meta,
        ]);

        ExecutionAttempt::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'attempt_no' => 1,
            'trace_id' => $traceId,
            'client_order_id' => $clientOrderId,
            'status' => 'pending',
            'request_json' => $proposal,
        ]);

        $this->events->recordOrderEvent($order, 'futures_intent_created', [
            'environment' => 'testnet',
            'leverage' => $proposal['leverage'],
            'margin_mode' => $proposal['margin_mode'],
            'position_mode' => $proposal['position_mode'],
            'reduce_only' => $proposal['reduce_only'],
        ], 'info', 'futures_submission', $traceId);

        try {
            $client->setLeverage($symbol, (int) round((float) $proposal['leverage']));
            $client->setMarginType($symbol, (string) $proposal['margin_mode']);

            $startedAt = microtime(true);
            $response = $client->placeOrder([
                'symbol' => $symbol,
                'side' => strtoupper((string) $proposal['side']),
                'type' => strtoupper((string) $proposal['type']),
                'quantity' => $this->formatDecimal((float) $proposal['qty']),
                'reduceOnly' => $proposal['reduce_only'] ? 'true' : 'false',
                'newClientOrderId' => $clientOrderId,
                'positionSide' => (string) ($proposal['position_side'] ?? ($proposal['position_mode'] === 'hedge' ? 'LONG' : 'BOTH')),
            ] + ($proposal['type'] === 'limit' ? [
                'timeInForce' => 'GTC',
                'price' => $this->formatDecimal((float) ($proposal['price'] ?? 0)),
            ] : []));
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $order->forceFill([
                'exchange_order_id' => (string) ($response['orderId'] ?? ''),
                'provider_status' => (string) ($response['status'] ?? 'NEW'),
                'status' => $this->mapStatus((string) ($response['status'] ?? 'NEW')),
                'provider_synced_at' => now(),
                'last_sync_source' => 'futures_submission_ack',
                'latency_ms' => $latencyMs,
                'raw_json' => $response,
            ])->save();

            $attempt = $order->executionAttempts()->first();
            if ($attempt) {
                $attempt->forceFill([
                    'status' => 'acknowledged',
                    'provider_order_id' => (string) ($response['orderId'] ?? ''),
                    'response_json' => $response,
                    'latency_ms' => $latencyMs,
                ])->save();
            }

            $this->events->recordOrderEvent($order, 'futures_exchange_acknowledged', [
                'provider_order_id' => $order->exchange_order_id,
                'provider_status' => $order->provider_status,
                'latency_ms' => $latencyMs,
            ], 'info', 'futures_submission_ack', $traceId);

            $this->sync->sync($workspaceId, $integration, $config);

            return $order->fresh();
        } catch (\Throwable $e) {
            $this->anomalies->record($workspaceId, 'futures_order_failure', 'Binance futures testnet order failed.', 'warning', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            $this->events->recordOrderEvent($order, 'futures_submission_failed', [
                'error' => $e->getMessage(),
            ], 'warning', 'futures_submission_error', $traceId);
            $order->forceFill([
                'status' => Order::STATUS_FAILED,
                'provider_status' => 'FAILED',
                'raw_json' => ['error' => $e->getMessage()],
            ])->save();

            throw $e;
        }
    }

    private function proposal(array $payload, array $market): array
    {
        return [
            'symbol' => $market['symbol'],
            'side' => strtolower((string) ($payload['side'] ?? 'buy')),
            'type' => strtolower((string) ($payload['type'] ?? 'market')),
            'qty' => (float) ($payload['qty'] ?? 0),
            'price' => isset($payload['price']) ? (float) $payload['price'] : null,
            'mark_price' => (float) ($market['mark_price'] ?? 0),
            'entry_price' => (float) ($payload['entry_price'] ?? ($market['mark_price'] ?? 0)),
            'notional' => (float) ($market['notional'] ?? 0),
            'leverage' => (float) ($payload['leverage'] ?? 1),
            'margin_mode' => strtolower((string) ($payload['margin_mode'] ?? 'isolated')),
            'position_mode' => strtolower((string) ($payload['position_mode'] ?? 'one_way')),
            'position_side' => strtoupper((string) ($payload['position_side'] ?? 'BOTH')),
            'reduce_only' => (bool) ($payload['reduce_only'] ?? false),
            'protective_exit' => (bool) ($payload['protective_exit'] ?? false),
            'confidence' => array_key_exists('confidence', $payload) ? (float) ($payload['confidence'] ?? 0) : null,
            'source' => strtolower((string) ($payload['source'] ?? 'manual_operator')),
        ];
    }

    private function marketSnapshot(array $payload, array $mark, array $exchangeInfo): array
    {
        $symbol = BinanceSymbolNormalizer::normalize((string) ($payload['symbol'] ?? ''));
        $markPrice = (float) ($mark['markPrice'] ?? $mark['price'] ?? 0);
        $notional = (float) ($payload['qty'] ?? 0) * max($markPrice, 0);
        $liquidationBuffer = null;
        if (isset($payload['liquidation_buffer_pct']) && $payload['liquidation_buffer_pct'] !== null && $payload['liquidation_buffer_pct'] !== '') {
            $liquidationBuffer = (float) $payload['liquidation_buffer_pct'];
        }

        return [
            'symbol' => $symbol,
            'mark_price' => $markPrice,
            'mark_price_source' => 'binance_futures_mark',
            'mark_price_timestamp_ms' => isset($mark['time']) ? (int) $mark['time'] : (int) floor(microtime(true) * 1000),
            'stale' => false,
            'notional' => $notional,
            'liquidation_buffer_pct' => $liquidationBuffer,
            'exchange_info_symbol_count' => count((array) ($exchangeInfo['symbols'] ?? [])),
        ];
    }

    private function client(Integration $integration, array $config): BinanceFuturesClient
    {
        $creds = $this->integrations->decryptSecrets($integration->secrets_encrypted);
        if ($creds === []) {
            throw new RuntimeException('Binance futures credentials are missing.');
        }

        return new BinanceFuturesClient(
            apiKey: (string) ($creds['api_key'] ?? $creds['key'] ?? ''),
            apiSecret: (string) ($creds['api_secret'] ?? $creds['secret'] ?? ''),
            baseUrl: (string) (($config['futures_force_testnet'] ?? true)
                ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
                : config('services.binance.futures_api_url', 'https://fapi.binance.com')),
        );
    }

    private function quoteCurrency(string $symbol): string
    {
        foreach (['USDT', 'USDC', 'BUSD', 'USD'] as $quote) {
            if (str_ends_with($symbol, $quote)) {
                return $quote;
            }
        }

        return 'USDT';
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'NEW' => Order::STATUS_NEW,
            'PARTIALLY_FILLED' => Order::STATUS_PARTIAL,
            'FILLED' => Order::STATUS_FILLED,
            'CANCELED', 'CANCELLED', 'EXPIRED' => Order::STATUS_CANCELLED,
            'REJECTED' => Order::STATUS_REJECTED,
            default => strtoupper($status),
        };
    }

    private function formatDecimal(float $value): string
    {
        $raw = number_format($value, 8, '.', '');
        $trimmed = rtrim(rtrim($raw, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
