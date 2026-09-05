<?php

namespace App\Domain\Exchange;

use App\Domain\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\ExchangeConnection;
use App\Support\ExchangeSecretVault;
use RuntimeException;

class ExchangeClientFactory
{
    public function __construct(
        private readonly ExchangeSecretVault $vault,
        private readonly IntegrationManager $integrations,
    )
    {
    }

    public function makeFromConnection(ExchangeConnection $connection): ExchangeClientInterface
    {
        $exchange = strtolower((string) ($connection->exchange ?: $connection->provider));
        if ($exchange !== 'binance') {
            throw new RuntimeException("Unsupported exchange client: {$exchange}");
        }

        return $this->makeBinanceFromConnection($connection);
    }

    public function makeFromIntegration(Integration $integration): ExchangeClientInterface
    {
        $provider = strtolower((string) $integration->provider_key);
        if ($provider !== 'binance') {
            throw new RuntimeException("Unsupported integration client: {$provider}");
        }

        return $this->makeBinanceFromIntegration($integration);
    }

    public function makeBinanceFromConnection(ExchangeConnection $connection): BinanceSpotClient
    {
        return new BinanceSpotClient(
            apiKey: (string) $this->vault->decrypt($connection->api_key_encrypted),
            apiSecret: (string) $this->vault->decrypt($connection->api_secret_encrypted),
            baseUrl: (string) ($connection->is_testnet ? config('services.binance.testnet_api_url') : config('services.binance.api_url')),
            wsUrl: (string) ($connection->is_testnet ? config('services.binance.testnet_ws_url') : config('services.binance.ws_url')),
        );
    }

    public function makeBinanceConvertFromConnection(ExchangeConnection $connection): BinanceConvertClient
    {
        if ($connection->is_testnet) {
            throw new RuntimeException('Binance Convert is not available on Spot testnet connections.');
        }

        return new BinanceConvertClient(
            apiKey: (string) $this->vault->decrypt($connection->api_key_encrypted),
            apiSecret: (string) $this->vault->decrypt($connection->api_secret_encrypted),
            baseUrl: (string) config('services.binance.api_url'),
        );
    }

    public function makeBinanceFromIntegration(Integration $integration): BinanceSpotClient
    {
        $config = (array) ($integration->config_json ?? []);
        $environment = strtolower((string) ($config['environment'] ?? 'live'));
        $useTestnet = $environment === 'testnet';
        $creds = $this->integrations->decryptSecrets($integration->secrets_encrypted);

        return new BinanceSpotClient(
            apiKey: (string) ($creds['api_key'] ?? $creds['key'] ?? ''),
            apiSecret: (string) ($creds['api_secret'] ?? $creds['secret'] ?? ''),
            baseUrl: (string) ($config['api_url'] ?? ($useTestnet ? config('services.binance.testnet_api_url') : config('services.binance.api_url'))),
            wsUrl: (string) ($config['ws_url'] ?? ($useTestnet ? config('services.binance.testnet_ws_url') : config('services.binance.ws_url'))),
        );
    }
}
