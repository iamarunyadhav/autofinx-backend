<?php

namespace App\Jobs;

use App\Domain\Exchange\BinanceSpotClient;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\Adapters\CoinbaseAdapter;
use App\Models\Integration;
use App\Models\WalletSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class WalletReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $integrationId = null)
    {
        $this->onQueue('reconcile');
    }

    public function handle(IntegrationManager $manager, ExchangeClientFactory $clients): void
    {
        $integrations = Integration::when($this->integrationId, fn ($q) => $q->where('id', $this->integrationId))->get();
        foreach ($integrations as $integration) {
            $provider = strtolower((string) $integration->provider_key);
            if ($provider === 'coinbase') {
                $creds = $manager->decryptSecrets($integration->secrets_encrypted);
                $adapter = new CoinbaseAdapter($creds);
                if (! method_exists($adapter, 'balances')) {
                    continue;
                }
                $balances = $adapter->balances();
            } elseif ($provider === 'binance') {
                $client = $clients->makeFromIntegration($integration);
                if (! $client instanceof BinanceSpotClient) {
                    continue;
                }
                $balances = $client->fetchBalances();
            } else {
                continue;
            }

            foreach ($balances as $bal) {
                $asset = $bal['asset'] ?? $bal['currency'] ?? null;
                if (! $asset) {
                    continue;
                }
                $free = (float) ($bal['free'] ?? 0);
                $locked = (float) ($bal['locked'] ?? 0);
                $total = $free + $locked;
                WalletSnapshot::updateOrCreate(
                    [
                        'integration_id' => $integration->id,
                        'asset' => $asset,
                        'captured_at' => now(),
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'free' => $free,
                        'locked' => $locked,
                        'total' => $total,
                    ]
                );
            }
        }
    }
}
