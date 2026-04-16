<?php

namespace App\Jobs;

use App\Domain\Exchange\BinanceSpotClient;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\Adapters\CoinbaseAdapter;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Trade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class TradesReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $integrationId = null)
    {
        $this->onQueue('reconcile');
    }

    public function handle(IntegrationManager $manager, ExchangeClientFactory $clients): void
    {
        $orders = Order::whereIn('status', ['ack','partial','filled'])
            ->when($this->integrationId, fn ($q) => $q->where('integration_id', $this->integrationId))
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $integration = Integration::find($order->integration_id);
            if (! $integration) {
                continue;
            }

            $provider = strtolower((string) $integration->provider_key);
            $symbolCode = $order->symbol?->symbol ?? 'BTCUSDT';
            if ($provider === 'coinbase') {
                $creds = $manager->decryptSecrets($integration->secrets_encrypted);
                $adapter = new CoinbaseAdapter($creds);
                $trades = method_exists($adapter, 'fetchMyTrades') ? $adapter->fetchMyTrades($symbolCode, null) : [];
            } elseif ($provider === 'binance') {
                $client = $clients->makeFromIntegration($integration);
                if (! $client instanceof BinanceSpotClient) {
                    continue;
                }
                $trades = $client->fetchMyTrades($symbolCode, null);
            } else {
                continue;
            }

            foreach ($trades as $t) {
                $remoteId = $t['id'] ?? $t['tradeId'] ?? null;
                if (! $remoteId) {
                    continue;
                }
                Trade::updateOrCreate(
                    ['integration_id' => $integration->id, 'exchange_trade_id' => (string) $remoteId],
                    [
                        'id' => Trade::where('integration_id', $integration->id)->where('exchange_trade_id', $remoteId)->first()?->id ?? (string) Str::uuid(),
                        'order_id' => $order->id,
                        'workspace_id' => $order->workspace_id,
                        'symbol' => $symbolCode,
                        'side' => strtolower($t['isBuyer'] ?? 'buy') === 'buy' || ($t['isBuyer'] ?? false) ? 'buy' : 'sell',
                        'price' => $t['price'] ?? 0,
                        'quantity' => $t['qty'] ?? $t['quantity'] ?? 0,
                        'fee_amount' => $t['commission'] ?? 0,
                        'fee_asset' => $t['commissionAsset'] ?? null,
                        'executed_at' => now(),
                        'meta' => $t,
                    ]
                );
            }
        }
    }
}
