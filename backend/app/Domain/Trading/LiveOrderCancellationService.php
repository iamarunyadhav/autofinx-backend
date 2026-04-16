<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\Order;
use Illuminate\Support\Str;
use RuntimeException;

class LiveOrderCancellationService
{
    public function __construct(
        private readonly TradingVenueClientFactory $venues,
        private readonly BinanceOrderSyncService $sync,
        private readonly ReconciliationService $reconciliation,
        private readonly LiveExecutionEventService $events,
    ) {
    }

    public function cancel(string $workspaceId, Order $order): Order
    {
        if ((string) $order->workspace_id !== $workspaceId) {
            throw new RuntimeException('Order does not belong to the selected workspace.');
        }

        if (! $this->isLiveOrder($order)) {
            throw new RuntimeException('Only live orders can use the canonical cancel path.');
        }

        $currentStatus = strtoupper((string) $order->status);
        if (in_array($currentStatus, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED], true)) {
            return $order;
        }

        if (in_array($currentStatus, [Order::STATUS_FILLED, 'FILLED'], true)) {
            throw new RuntimeException('Filled orders cannot be cancelled.');
        }

        if (! $order->exchange_order_id) {
            $order = $this->sync->sync($order, $order->trace_id ?: (string) Str::uuid(), 'cancel_repair') ?? $order->fresh();
        }

        if (! $order->exchange_order_id) {
            throw new RuntimeException('Exchange order id is required before cancellation.');
        }

        $assetCode = $this->assetCode($order);
        if ($assetCode === '') {
            throw new RuntimeException('Asset code is required before cancellation.');
        }

        $this->events->recordOrderEvent($order, 'cancel_requested', [
            'exchange_order_id' => $order->exchange_order_id,
        ], 'info', 'cancel_request');
        $venue = $this->resolveVenue($order);
        $response = $venue->cancelOrder((string) $order->exchange_order_id, $assetCode);
        $order->meta = array_merge((array) ($order->meta ?? []), [
            'cancel_reason' => 'manual_operator_cancel',
            'cancel_requested_at' => now()->toIso8601String(),
        ]);
        $order->save();

        $reconciled = $this->reconciliation->reconcileExchangeOrder($order, [
            'orderId' => (string) ($response['orderId'] ?? $response['order_id'] ?? $order->exchange_order_id),
            'symbol' => $assetCode,
            'status' => (string) ($response['status'] ?? Order::STATUS_CANCELLED),
            'executedQty' => (float) ($response['executedQty'] ?? $response['executed_qty'] ?? $order->filled_qty ?? 0),
            'price' => (float) ($response['price'] ?? $response['avgPrice'] ?? $response['avg_price'] ?? $order->avg_fill_price ?? $order->price ?? 0),
            'fills' => (array) ($response['fills'] ?? []),
            'raw' => $response,
            'source' => 'cancel_ack',
        ], $order->trace_id ?: (string) Str::uuid());

        $this->events->recordOrderEvent($reconciled, 'cancel_acknowledged', [
            'provider_status' => $response['status'] ?? Order::STATUS_CANCELLED,
        ], 'info', 'cancel_ack');

        return $reconciled;
    }

    private function isLiveOrder(Order $order): bool
    {
        return strtolower((string) data_get($order->meta, 'mode', '')) === 'live'
            || $order->integration_id !== null
            || $order->bot_id !== null;
    }

    private function resolveVenue(Order $order): object
    {
        if ($order->integration_id) {
            $integration = Integration::find($order->integration_id);
            if (! $integration) {
                throw new RuntimeException('Live integration was not found for this order.');
            }

            return $this->venues->forIntegration($integration);
        }

        if ($order->bot && $order->bot->connection) {
            return $this->venues->forBot($order->bot);
        }

        $connectionId = (string) data_get($order->meta, 'connection_id', '');
        if ($connectionId !== '') {
            $connection = ExchangeConnection::find($connectionId);
            if ($connection) {
                $provider = strtolower((string) ($order->provider ?: $order->exchange ?: $connection->provider ?: 'binance'));

                return $this->venues->forProvider($provider, $connection);
            }
        }

        throw new RuntimeException('No active venue connection was found for this order.');
    }

    private function assetCode(Order $order): string
    {
        return BinanceSymbolNormalizer::normalize((string) (
            $order->asset_code
            ?: $order->symbol?->symbol
            ?: data_get($order->meta, 'symbol_normalized')
            ?: data_get($order->raw_json, 'symbol')
            ?: ''
        ));
    }
}
