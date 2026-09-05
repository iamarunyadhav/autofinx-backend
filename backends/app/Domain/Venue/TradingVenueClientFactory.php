<?php

namespace App\Domain\Venue;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Integrations\IntegrationManager;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Support\ExchangeSecretVault;
use RuntimeException;

class TradingVenueClientFactory
{
    public function __construct(
        private readonly ExchangeClientFactory $exchangeClients,
        private readonly ExchangeSecretVault $vault,
        private readonly IntegrationManager $integrations,
    ) {
    }

    public function forBot(Bot $bot): TradingVenueClientInterface
    {
        $provider = strtolower((string) ($bot->provider ?: ($bot->isMetals() ? 'metal_broker' : 'binance')));
        if ($provider === 'binance') {
            if (! $bot->connection) {
                throw new RuntimeException('Binance bot requires active exchange connection');
            }
            return new BinanceTradingVenueClient(
                $this->exchangeClients->makeFromConnection($bot->connection),
                $bot->connection->is_testnet ? null : $this->exchangeClients->makeBinanceConvertFromConnection($bot->connection),
            );
        }

        if ($provider === 'metal_broker' || $provider === 'metals') {
            return $this->makeMetalBrokerClient($bot->connection);
        }

        throw new RuntimeException("Unsupported trading provider: {$provider}");
    }

    public function forProvider(string $provider, ?ExchangeConnection $connection = null): TradingVenueClientInterface
    {
        $provider = strtolower($provider);
        if ($provider === 'binance') {
            if (! $connection) {
                throw new RuntimeException('Connection is required for Binance provider');
            }
            return new BinanceTradingVenueClient(
                $this->exchangeClients->makeFromConnection($connection),
                $connection->is_testnet ? null : $this->exchangeClients->makeBinanceConvertFromConnection($connection),
            );
        }

        if ($provider === 'metal_broker' || $provider === 'metals') {
            return $this->makeMetalBrokerClient($connection);
        }

        throw new RuntimeException("Unsupported provider: {$provider}");
    }

    public function forIntegration(Integration $integration): TradingVenueClientInterface
    {
        $provider = strtolower((string) $integration->provider_key);
        if ($provider === 'binance') {
            return new BinanceTradingVenueClient($this->exchangeClients->makeFromIntegration($integration));
        }

        if ($provider === 'metal_broker' || $provider === 'metals') {
            return $this->makeMetalBrokerClientFromIntegration($integration);
        }

        throw new RuntimeException("Unsupported integration provider: {$provider}");
    }

    private function makeMetalBrokerClientFromIntegration(Integration $integration): MetalBrokerClient
    {
        $secrets = $this->integrations->decryptSecrets($integration->secrets_encrypted);

        return $this->makeMetalBrokerClient(null, [
            'api_key' => (string) ($secrets['api_key'] ?? ''),
            'api_secret' => (string) ($secrets['api_secret'] ?? ''),
        ]);
    }

    private function makeMetalBrokerClient(?ExchangeConnection $connection = null, ?array $secretsOverride = null): MetalBrokerClient
    {
        $apiUrl = (string) config('services.metal_broker.api_url', '');
        $apiKey = (string) config('services.metal_broker.api_key', '');
        $apiSecret = (string) config('services.metal_broker.api_secret', '');

        if ($connection) {
            $apiKey = (string) ($this->vault->decrypt($connection->api_key_encrypted) ?: $apiKey);
            $apiSecret = (string) ($this->vault->decrypt($connection->api_secret_encrypted) ?: $apiSecret);
        }

        if ($secretsOverride) {
            $apiKey = $secretsOverride['api_key'] !== '' ? $secretsOverride['api_key'] : $apiKey;
            $apiSecret = $secretsOverride['api_secret'] !== '' ? $secretsOverride['api_secret'] : $apiSecret;
        }

        return new MetalBrokerClient(
            apiUrl: $apiUrl,
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            mode: (string) config('services.metal_broker.mode', 'sandbox'),
            supportsIoc: (bool) config('services.metal_broker.supports_ioc', false),
            orderPath: (string) config('services.metal_broker.order_path', '/v1/orders'),
            statusPath: (string) config('services.metal_broker.status_path', '/v1/orders/{order_id}'),
            balancesPath: (string) config('services.metal_broker.balances_path', '/v1/account/balances'),
            pricePath: (string) config('services.metal_broker.price_path', '/v1/quotes/{asset_code}'),
            defaultSpreadPct: (float) config('services.metal_broker.default_spread_pct', 0.4),
        );
    }
}
