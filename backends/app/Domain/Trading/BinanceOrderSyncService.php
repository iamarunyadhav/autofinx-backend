<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Exchange\BinanceSpotClient;
use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\Order;
use Illuminate\Support\Str;

class BinanceOrderSyncService
{
    public function __construct(
        private readonly ExchangeClientFactory $clients,
        private readonly ReconciliationService $reconciliation,
        private readonly LiveExecutionEventService $events,
        private readonly BinanceSpotHealthService $health,
    ) {
    }

    public function sync(Order $order, ?string $traceId = null, string $source = 'rest_sync'): ?Order
    {
        if ($this->provider($order) !== 'binance') {
            return null;
        }

        $client = $this->resolveClient($order);
        if (! $client) {
            return null;
        }

        $symbol = $this->resolveSymbol($order);
        if ($symbol === '') {
            return null;
        }

        try {
            $snapshot = $this->fetchSnapshot($client, $order, $symbol, $source);
        } catch (\Throwable $e) {
            $order->sync_error_count = max(0, (int) $order->sync_error_count) + 1;
            $order->meta = array_merge((array) ($order->meta ?? []), [
                'last_sync_error' => $e->getMessage(),
                'last_sync_error_at' => now()->toIso8601String(),
            ]);
            $order->save();
            $this->health->recordFailure((string) $order->workspace_id, $e->getMessage(), [
                'order_id' => $order->id,
                'source' => $source,
            ]);
            $this->events->recordOrderEvent($order, 'sync_failed', [
                'error' => $e->getMessage(),
                'source' => $source,
            ], 'warning', $source, $traceId ?: ($order->trace_id ?: null));

            throw $e;
        }

        if ($snapshot === null) {
            return null;
        }

        $this->health->recordSuccess((string) $order->workspace_id, [
            'order_id' => $order->id,
            'source' => $source,
        ]);

        return $this->reconciliation->reconcileExchangeOrder(
            $order,
            $snapshot,
            $traceId ?: ($order->trace_id ?: (string) Str::uuid())
        );
    }

    public function syncForConnection(ExchangeConnection $connection, int $limit = 100): int
    {
        $orders = Order::with('bot.connection', 'symbol')
            ->where(function ($query) use ($connection): void {
                $query->whereHas('bot', fn ($botQuery) => $botQuery->where('connection_id', $connection->id))
                    ->orWhere('meta->connection_id', $connection->id);
            })
            ->whereIn('status', [Order::STATUS_PENDING_PROVIDER, Order::STATUS_NEW, Order::STATUS_PARTIAL, Order::STATUS_UNCERTAIN])
            ->limit($limit)
            ->get();

        return $this->syncEach($orders, fn (Order $order) => $this->sync($order));
    }

    public function syncForWorkspace(string $workspaceId, ?string $symbol = null, ?string $integrationId = null, int $limit = 100): int
    {
        $orders = Order::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', [Order::STATUS_PENDING_PROVIDER, Order::STATUS_NEW, Order::STATUS_PARTIAL, Order::STATUS_UNCERTAIN])
            ->where(function ($query): void {
                $query->where('provider', 'binance')
                    ->orWhere('exchange', 'binance');
            })
            ->when($symbol, fn ($query) => $query->where('asset_code', BinanceSymbolNormalizer::normalize((string) $symbol)))
            ->when($integrationId, fn ($query) => $query->where('integration_id', $integrationId))
            ->orderBy('placed_ts')
            ->limit($limit)
            ->get();

        return $this->syncEach($orders, fn (Order $order) => $this->sync($order, $order->trace_id ?: (string) Str::uuid(), 'manual_workspace_sync'));
    }

    /**
     * One order that cannot be synced (its error is already recorded on it by
     * sync()) must not stop every newer order behind it. Rethrows only when
     * nothing could be synced, so a caller still sees a total failure.
     */
    private function syncEach(iterable $orders, callable $sync): int
    {
        $synced = 0;
        $firstError = null;
        foreach ($orders as $order) {
            try {
                if ($sync($order)) {
                    $synced++;
                }
            } catch (\Throwable $e) {
                $firstError ??= $e;
            }
        }
        if ($firstError !== null && $synced === 0) {
            throw $firstError;
        }

        return $synced;
    }

    private function resolveClient(Order $order): ?BinanceSpotClient
    {
        if ($order->integration_id) {
            $integration = Integration::find($order->integration_id);
            if ($integration && strtolower((string) $integration->provider_key) === 'binance') {
                return $this->clients->makeBinanceFromIntegration($integration);
            }
        }

        $connection = $order->relationLoaded('bot')
            ? $order->bot?->connection
            : $order->bot?->connection;
        if ($connection instanceof ExchangeConnection) {
            return $this->clients->makeBinanceFromConnection($connection);
        }

        $connectionId = (string) data_get($order->meta, 'connection_id', '');
        if ($connectionId !== '') {
            $legacyConnection = ExchangeConnection::find($connectionId);
            if ($legacyConnection) {
                return $this->clients->makeBinanceFromConnection($legacyConnection);
            }
        }

        return null;
    }

    private function resolveSymbol(Order $order): string
    {
        $raw = (string) ($order->asset_code
            ?: $order->symbol?->symbol
            ?: data_get($order->meta, 'symbol_normalized')
            ?: data_get($order->raw_json, 'symbol')
            ?: '');

        return BinanceSymbolNormalizer::normalize($raw);
    }

    private function fetchSnapshot(BinanceSpotClient $client, Order $order, string $symbol, string $source): ?array
    {
        $exchangeOrderId = trim((string) ($order->exchange_order_id ?? ''));
        $clientOrderId = trim((string) (data_get($order->meta, 'client_order_id') ?: $order->idempotency_key ?: ''));

        $remote = null;
        if ($exchangeOrderId !== '') {
            try {
                $remote = $client->fetchOrder($symbol, $exchangeOrderId)->toArray();
            } catch (\Throwable $e) {
                if ($clientOrderId === '') {
                    throw $e;
                }
            }
        }
        if (! is_array($remote) && $clientOrderId !== '') {
            try {
                $remote = $client->fetchOrderByClientOrderId($symbol, $clientOrderId)->toArray();
            } catch (\Throwable $e) {
                if (! $this->confirmedNeverPlaced($order, $e)) {
                    throw $e;
                }

                // No order with this client id exists on the exchange, so
                // nothing filled. Resolving it lets the bot move on instead
                // of staying blocked on an "uncertain" order forever.
                return [
                    'orderId' => '',
                    'symbol' => $symbol,
                    'status' => 'REJECTED',
                    'executedQty' => 0,
                    'price' => 0,
                    'fills' => [],
                    'raw' => ['error' => 'order_not_found_on_exchange', 'detail' => $e->getMessage()],
                    'source' => $source,
                ];
            }
        }

        if (! is_array($remote)) {
            return null;
        }

        return [
            'orderId' => (string) ($remote['orderId'] ?? data_get($remote, 'raw.orderId', '')),
            'symbol' => $symbol,
            'status' => (string) ($remote['status'] ?? data_get($remote, 'raw.status', Order::STATUS_NEW)),
            'executedQty' => (float) ($remote['executedQty'] ?? data_get($remote, 'raw.executedQty', 0)),
            'price' => (float) ($remote['price'] ?? data_get($remote, 'raw.price', 0)),
            'fills' => (array) (data_get($remote, 'raw.fills', [])),
            'raw' => $remote['raw'] ?? $remote,
            'source' => $source,
        ];
    }

    /**
     * Binance -2013 "Order does not exist" for an order we never got an
     * exchange id for, asked well after submission. Orders that filled stay
     * queryable; only never-created (or expired-unfilled) ones return -2013,
     * so in both cases nothing filled.
     */
    private function confirmedNeverPlaced(Order $order, \Throwable $e): bool
    {
        $graceSeconds = max(60, (int) config('services.binance.unknown_order_resolve_after_seconds', 120));
        $placedAt = $order->placed_ts ?? $order->created_at;

        return trim((string) $order->exchange_order_id) === ''
            && in_array($order->status, [Order::STATUS_UNCERTAIN, Order::STATUS_PENDING_PROVIDER], true)
            && $placedAt !== null && $placedAt->lt(now()->subSeconds($graceSeconds))
            && str_contains($e->getMessage(), '-2013');
    }

    private function provider(Order $order): string
    {
        return strtolower((string) ($order->provider ?: $order->exchange ?: $order->bot?->provider ?: ''));
    }
}
