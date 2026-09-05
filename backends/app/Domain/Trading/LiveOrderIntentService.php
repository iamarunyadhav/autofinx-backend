<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Exchange\BinanceSpotSymbolService;
use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Ops\LiveExecutionGuard;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Domain\Venue\TradingVenueClientInterface;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\ExecutionAttempt;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Symbol;
use Illuminate\Support\Str;
use RuntimeException;

class LiveOrderIntentService
{
    public function __construct(
        private readonly TradingVenueClientFactory $venues,
        private readonly LiveExecutionGuard $guard,
        private readonly ReconciliationService $reconciliation,
        private readonly BinanceSpotSymbolService $symbols,
        private readonly ExchangeClientFactory $clients,
        private readonly LiveExecutionEventService $events,
        private readonly BinanceSpotHealthService $health,
    ) {
    }

    public function place(array $payload): Order
    {
        $workspaceId = (string) $payload['workspace_id'];
        $target = $this->resolveExecutionTarget($workspaceId, $payload);
        /** @var Integration|null $integration */
        $integration = $target['integration'];
        /** @var ExchangeConnection|null $connection */
        $connection = $target['connection'];
        /** @var Bot|null $bot */
        $bot = $target['bot'];
        /** @var TradingVenueClientInterface $venue */
        $venue = $target['venue'];
        $provider = (string) $target['provider'];
        $symbolCode = BinanceSymbolNormalizer::normalize((string) $payload['symbol']);
        $side = strtolower((string) $payload['side']);
        $type = strtolower((string) ($payload['type'] ?? 'market'));
        $qty = (float) $payload['qty'];
        $price = isset($payload['price']) ? (float) $payload['price'] : null;
        $idempotencyKey = (string) ($payload['idempotency_key']
            ?? ($workspaceId . '-' . $symbolCode . '-' . $side . '-' . now()->format('YmdHis')));

        $existing = Order::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $this->events->recordOrderEvent($existing, 'idempotent_reused', [
                'idempotency_key' => $idempotencyKey,
            ], 'info', 'submission_reuse');

            return $existing;
        }

        $referencePrice = $price ?? $this->marketReferencePrice($venue, $symbolCode);
        if ($referencePrice <= 0) {
            throw new RuntimeException('A reference price is required before placing a live order.');
        }

        $riskRules = $this->guard->assertWorkspaceCanSubmit(
            $workspaceId,
            $symbolCode,
            $referencePrice * max($qty, 0),
            $provider,
            $side,
            $idempotencyKey,
            $bot?->risk_profile_id ?? ($payload['risk_profile_id'] ?? null)
        );

        $validated = $integration
            ? $this->symbols->validateForOrder($integration, $symbolCode, $qty, $price, $referencePrice, $type)
            : $this->symbols->validateForConnection($connection, $symbolCode, $qty, $price, $referencePrice, $type);
        $symbolCode = (string) $validated['symbol'];
        $qty = (float) $validated['qty'];
        $price = isset($validated['price']) ? (float) $validated['price'] : null;
        $referencePrice = $price ?: $referencePrice;

        $traceId = (string) Str::uuid();
        $symbol = Symbol::firstOrCreate(['symbol' => $symbolCode], [
            'id' => (string) Str::uuid(),
            'type' => 'crypto',
            'exchange' => strtolower((string) $integration->provider_key),
            'metadata' => ['auto_created' => true],
        ]);

        $meta = array_merge([
            'mode' => 'live',
            'source' => 'manual_live_order',
            'submission_attempts' => 1,
            'integration_id' => $integration?->id,
            'bot_id' => $bot?->id,
            'connection_id' => $connection?->id,
            'provider' => $provider,
            'client_order_id' => $idempotencyKey,
            'symbol_normalized' => $symbolCode,
            'symbol_rules' => $validated['rules'],
            'risk_profile_id' => $riskRules['_risk_profile_id'] ?? null,
            'risk_profile_name' => $riskRules['_risk_profile_name'] ?? null,
            'risk_defaults' => [
                'stop_loss_pct' => $riskRules['stop_loss_pct'] ?? null,
                'take_profit_pct' => $riskRules['take_profit_pct'] ?? null,
                'trailing_stop_pct' => $riskRules['trailing_stop_pct'] ?? null,
                'max_leverage' => $riskRules['max_leverage'] ?? null,
            ],
        ], (array) ($payload['meta'] ?? []));

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'integration_id' => $integration?->id,
            'bot_id' => $bot?->id,
            'instance_id' => $payload['instance_id'] ?? null,
            'symbol_id' => $symbol->id,
            'provider' => $provider,
            'asset_class' => 'CRYPTO',
            'asset_code' => $symbolCode,
            'quote_currency' => 'USDT',
            'exchange' => $provider,
            'side' => $side,
            'type' => $type,
            'quantity' => $qty,
            'qty' => $qty,
            'requested_qty' => $qty,
            'price' => $referencePrice,
            'limit_price' => $price,
            'status' => Order::STATUS_PENDING_PROVIDER,
            'provider_status' => 'PENDING_PROVIDER',
            'client_order_id' => $idempotencyKey,
            'reconciliation_state' => Order::RECONCILIATION_PENDING,
            'idempotency_key' => $idempotencyKey,
            'trace_id' => $traceId,
            'placed_ts' => now(),
            'meta' => $meta,
        ]);

        $attempt = ExecutionAttempt::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'attempt_no' => 1,
            'trace_id' => $traceId,
            'client_order_id' => $idempotencyKey,
            'status' => 'pending',
            'request_json' => [
                'asset_code' => $symbolCode,
                'side' => strtoupper($side),
                'type' => strtoupper($type),
                'qty' => $qty,
                'price' => $price,
                'reference_price' => $referencePrice,
            ],
        ]);
        $this->events->recordOrderEvent($order, 'intent_created', [
            'attempt_no' => 1,
            'client_order_id' => $idempotencyKey,
            'qty' => $qty,
            'price' => $price,
        ], 'info', 'submission', $traceId);

        $startedAt = microtime(true);

        try {
            $response = $venue->placeOrder([
                'asset_code' => $symbolCode,
                'side' => strtoupper($side),
                'type' => strtoupper($type),
                'qty' => $qty,
                'price' => $price,
                'client_order_id' => $idempotencyKey,
                'max_quote_deviation_bps' => $payload['max_quote_deviation_bps'] ?? null,
            ]);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $attempt->forceFill([
                'status' => 'acknowledged',
                'provider_order_id' => (string) ($response['order_id'] ?? $response['orderId'] ?? ''),
                'response_json' => $response,
                'latency_ms' => $latencyMs,
                'error_message' => null,
            ])->save();

            $order->forceFill([
                'latency_ms' => $latencyMs,
                'client_order_id' => (string) ($response['client_order_id'] ?? $idempotencyKey),
                'provider_status' => (string) ($response['status'] ?? Order::STATUS_NEW),
                'provider_synced_at' => now(),
                'last_sync_source' => 'submission_ack',
            ])->save();

            $this->health->recordSuccess($workspaceId, [
                'order_id' => $order->id,
                'trace_id' => $traceId,
                'latency_ms' => $latencyMs,
            ]);
            $this->events->recordOrderEvent($order, 'exchange_acknowledged', [
                'attempt_no' => 1,
                'provider_order_id' => $attempt->provider_order_id,
                'provider_status' => $response['status'] ?? Order::STATUS_NEW,
                'latency_ms' => $latencyMs,
            ], 'info', 'submission_ack', $traceId);

            return $this->reconciliation->reconcileExchangeOrder(
                $order,
                $this->toReconcilePayload($response, $symbolCode, $referencePrice, 'submission_ack'),
                $traceId
            );
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $attempt->forceFill([
                'status' => 'failed',
                'response_json' => ['error' => $e->getMessage()],
                'latency_ms' => $latencyMs,
                'error_message' => $e->getMessage(),
            ])->save();

            $this->health->recordFailure($workspaceId, $e->getMessage(), [
                'order_id' => $order->id,
                'trace_id' => $traceId,
                'latency_ms' => $latencyMs,
            ]);
            $this->events->recordOrderEvent($order, 'submission_failed', [
                'attempt_no' => 1,
                'error' => $e->getMessage(),
                'latency_ms' => $latencyMs,
            ], 'warning', 'submission_error', $traceId);

            $repaired = $this->repairAfterSubmissionFailure($integration, $connection, $order, $symbolCode, $referencePrice, $traceId);
            if ($repaired) {
                $attempt->forceFill([
                    'status' => 'repaired',
                    'provider_order_id' => (string) ($repaired->exchange_order_id ?: $attempt->provider_order_id),
                    'error_message' => null,
                ])->save();

                return $repaired;
            }

            $order->status = Order::STATUS_UNCERTAIN;
            $order->provider_status = 'UNKNOWN';
            $order->uncertain_at = now();
            $order->reconciliation_state = Order::RECONCILIATION_UNCERTAIN;
            $order->sync_error_count = max(0, (int) $order->sync_error_count) + 1;
            $order->meta = array_merge((array) ($order->meta ?? []), [
                'uncertain_submission' => true,
                'last_execution_error' => $e->getMessage(),
                'last_execution_error_at' => now()->toIso8601String(),
            ]);
            $order->save();

            $this->events->recordAnomaly(
                $order,
                'execution_uncertain',
                'Binance submission outcome is uncertain and requires manual reconcile before retry.',
                [
                    'error' => $e->getMessage(),
                    'attempt_no' => 1,
                ],
                'warning',
                'submission_error'
            );

            throw new UncertainLiveOrderException($order);
        }
    }

    /**
     * Fetch a fresh reference price for the same venue/symbol a live order
     * would use, without placing an order. Used by callers (e.g. Ratio Bot's
     * pre-submit price-drift guard) that need to revalidate freshness right
     * before submission using the exact execution target resolution.
     */
    public function fetchReferencePrice(array $payload): float
    {
        $workspaceId = (string) $payload['workspace_id'];
        $target = $this->resolveExecutionTarget($workspaceId, $payload);
        $symbolCode = BinanceSymbolNormalizer::normalize((string) $payload['symbol']);

        return $this->marketReferencePrice($target['venue'], $symbolCode);
    }

    private function resolveExecutionTarget(string $workspaceId, array $payload): array
    {
        $integrationId = trim((string) ($payload['integration_id'] ?? ''));
        if ($integrationId !== '') {
            $integration = $this->resolveActiveIntegration($workspaceId, $integrationId);

            return [
                'integration' => $integration,
                'connection' => null,
                'bot' => null,
                'venue' => $this->venues->forIntegration($integration),
                'provider' => strtolower((string) $integration->provider_key),
            ];
        }

        $botId = trim((string) ($payload['bot_id'] ?? ''));
        if ($botId !== '') {
            $bot = Bot::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($botId)
                ->firstOrFail();

            if (! $bot->connection_id || ! $bot->connection) {
                throw new RuntimeException('Live bot does not have an active exchange connection.');
            }

            return [
                'integration' => null,
                'connection' => $bot->connection,
                'bot' => $bot,
                'venue' => $this->venues->forBot($bot),
                'provider' => strtolower((string) ($bot->provider ?: $bot->exchange ?: 'binance')),
            ];
        }

        $connectionId = trim((string) ($payload['connection_id'] ?? ''));
        if ($connectionId !== '') {
            $connection = ExchangeConnection::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($connectionId)
                ->firstOrFail();

            if (! $connection->is_active) {
                throw new RuntimeException('Selected exchange connection is not active.');
            }

            $provider = strtolower((string) ($connection->exchange ?: $connection->provider ?: 'binance'));

            return [
                'integration' => null,
                'connection' => $connection,
                'bot' => null,
                'venue' => $this->venues->forProvider($provider, $connection),
                'provider' => $provider,
            ];
        }

        throw new RuntimeException('A live execution target is required.');
    }

    private function resolveActiveIntegration(string $workspaceId, string $integrationId): Integration
    {
        $integration = Integration::where('workspace_id', $workspaceId)
            ->whereKey($integrationId)
            ->firstOrFail();

        if (strtolower((string) $integration->status) !== 'active') {
            throw new RuntimeException('Selected integration is not active.');
        }

        return $integration;
    }

    private function marketReferencePrice(TradingVenueClientInterface $venue, string $symbol): float
    {
        $quote = $venue->getLatestPrice($symbol);

        return (float) ($quote['mid'] ?? $quote['bid'] ?? $quote['ask'] ?? 0);
    }

    private function repairAfterSubmissionFailure(
        ?Integration $integration,
        ?ExchangeConnection $connection,
        Order $order,
        string $symbolCode,
        float $referencePrice,
        string $traceId
    ): ?Order {
        try {
            if ($integration) {
                $client = $this->clients->makeBinanceFromIntegration($integration);
            } elseif ($connection) {
                $client = $this->clients->makeBinanceFromConnection($connection);
            } else {
                return null;
            }

            $remote = $client->fetchOrderByClientOrderId($symbolCode, (string) $order->client_order_id)->toArray();
        } catch (\Throwable) {
            return null;
        }

        $this->events->recordOrderEvent($order, 'submission_repaired', [
            'provider_order_id' => (string) ($remote['orderId'] ?? data_get($remote, 'raw.orderId')),
            'provider_status' => (string) ($remote['status'] ?? data_get($remote, 'raw.status', Order::STATUS_NEW)),
        ], 'warning', 'rest_repair', $traceId);
        $this->health->recordSuccess((string) $order->workspace_id, [
            'order_id' => $order->id,
            'trace_id' => $traceId,
            'repair' => true,
        ]);

        return $this->reconciliation->reconcileExchangeOrder(
            $order,
            $this->toReconcilePayload($remote, $symbolCode, $referencePrice, 'rest_repair'),
            $traceId
        );
    }

    private function toReconcilePayload(array $response, string $symbolCode, float $referencePrice, string $source): array
    {
        return [
            'orderId' => (string) ($response['order_id'] ?? $response['orderId'] ?? data_get($response, 'raw.orderId', '')),
            'symbol' => $symbolCode,
            'status' => (string) ($response['status'] ?? data_get($response, 'raw.status', Order::STATUS_NEW)),
            'executedQty' => (float) ($response['executed_qty'] ?? $response['executedQty'] ?? data_get($response, 'raw.executedQty', 0)),
            'price' => (float) ($response['avg_price'] ?? $response['price'] ?? $response['avgPrice'] ?? data_get($response, 'raw.price', $referencePrice)),
            'fills' => (array) ($response['fills'] ?? data_get($response, 'raw.fills', [])),
            'raw' => $response['raw'] ?? $response,
            'source' => $source,
        ];
    }
}
