<?php

namespace App\Jobs;

use App\Domain\Trading\BotLogService;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\ReconciliationService;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SyncOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $limit = 100)
    {
        $this->onQueue('reconcile');
    }

    public function handle(
        TradingVenueClientFactory $venues,
        BinanceOrderSyncService $binanceSync,
        ReconciliationService $reconciliation,
        BotLogService $logs,
    ): void {
        $orders = Order::with('bot.connection')
            ->whereIn('status', ['NEW', 'PARTIAL', Order::STATUS_PENDING_PROVIDER, Order::STATUS_UNCERTAIN])
            ->orderBy('placed_ts')
            ->limit($this->limit)
            ->get();

        foreach ($orders as $order) {
            $bot = $order->bot;
            $provider = strtolower((string) ($order->provider ?: $bot?->provider ?: $order->exchange ?: 'binance'));
            if ($provider === 'metals') {
                $provider = 'metal_broker';
            }

                try {
                    if ($provider === 'binance') {
                        $binanceSync->sync($order, $order->trace_id ?: (string) Str::uuid(), 'scheduled_rest_sync');
                        continue;
                    }

                $connection = $bot?->connection;
                if (! $order->exchange_order_id || ! $connection || ! $connection->is_active) {
                    continue;
                }

                $assetCode = strtoupper((string) ($order->asset_code ?: $order->symbol?->symbol ?: $bot?->asset_code ?: $bot?->symbol));
                if ($assetCode === '') {
                    continue;
                }

                $client = $venues->forProvider($provider, $connection);
                $snapshot = $client->fetchOrder((string) $order->exchange_order_id, $assetCode);
                $reconciliation->reconcileExchangeOrder($order, [
                    'orderId' => (string) ($snapshot['order_id'] ?? ''),
                    'symbol' => $assetCode,
                    'status' => (string) ($snapshot['status'] ?? 'NEW'),
                    'executedQty' => (float) ($snapshot['executed_qty'] ?? 0),
                    'price' => (float) ($snapshot['avg_price'] ?? 0),
                    'fills' => (array) ($snapshot['fills'] ?? []),
                    'raw' => $snapshot['raw'] ?? $snapshot,
                ], $order->trace_id ?: (string) Str::uuid());
            } catch (\Throwable $e) {
                if ($bot) {
                    $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Order status sync failed.', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ], $order->trace_id);
                }
            }
        }
    }
}
