<?php

namespace App\Domain\Integrations;

use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Models\Integration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class IntegrationManager
{
    public function __construct(private readonly LiveTradeProviderRegistry $providers)
    {
    }

    public function encryptSecrets(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload));
    }

    public function decryptSecrets(?string $cipher): array
    {
        if (!$cipher) {
            return [];
        }
        return json_decode(Crypt::decryptString($cipher), true) ?? [];
    }

    public function upsert(array $data, ?string $id = null): Integration
    {
        $secrets = $data['secrets'] ?? [];
        unset($data['secrets']);

        return Integration::updateOrCreate(
            ['id' => $id ?? (string) Str::uuid()],
            array_merge($data, [
                'secrets_encrypted' => $secrets ? $this->encryptSecrets($secrets) : null,
            ])
        );
    }

    public function health(Integration $integration): string
    {
        $status = $integration->secrets_encrypted ? 'active' : 'error';

        if ($integration->secrets_encrypted) {
            try {
                $provider = $this->providers->for((string) $integration->provider_key);
                $capabilities = $provider->capabilities();

                if (($capabilities['live_supported'] ?? false) === true) {
                    $provider->availableBalance($integration, 'USDT');
                    $status = 'active';
                }
            } catch (\Throwable) {
                $status = 'error';
            }
        }

        $integration->update([
            'status' => $status,
            'last_health_at' => now(),
            'last_health_status' => $status,
        ]);
        return $status;
    }
}
