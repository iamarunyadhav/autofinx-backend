<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\TradingIntegrationResolver;
use App\Models\FutureTradeBotConfig;
use App\Models\Integration;
use Throwable;

class FutureTradeFuturesCredentialResolver
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly TradingIntegrationResolver $resolver,
    ) {
    }

    public function resolveForConfig(FutureTradeBotConfig $config): array
    {
        return $this->resolve($config->mergedSettings(), (string) $config->workspace_id, (string) $config->id);
    }

    public function clientForConfig(FutureTradeBotConfig $config, string $throttleScope = 'default'): ?BinanceFuturesClient
    {
        $credentials = $this->resolveForConfig($config);

        return $this->clientFromResolved($credentials, $throttleScope);
    }

    public function clientFromSettings(array $settings, string $throttleScope = 'default'): ?BinanceFuturesClient
    {
        $credentials = $this->resolve(
            $settings,
            isset($settings['_workspace_id']) ? (string) $settings['_workspace_id'] : null,
            isset($settings['_config_id']) ? (string) $settings['_config_id'] : null,
        );

        return $this->clientFromResolved($credentials, $throttleScope);
    }

    public function resolve(array $settings, ?string $workspaceId = null, ?string $configId = null): array
    {
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        $mode = $forceTestnet ? 'testnet' : 'live';
        $settingsKey = $forceTestnet ? 'testnetApiKey' : 'liveApiKey';
        $settingsSecret = $forceTestnet ? 'testnetApiSecret' : 'liveApiSecret';
        $settingsUrl = $forceTestnet ? 'testnetApiUrl' : 'liveApiUrl';
        $apiKey = trim((string) ($settings[$settingsKey] ?? ''));
        $apiSecret = trim((string) ($settings[$settingsSecret] ?? ''));

        if ($apiKey !== '' && $apiSecret !== '') {
            return [
                'configured' => true,
                'source' => 'future_trade_config',
                'mode' => $mode,
                'force_testnet' => $forceTestnet,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'base_url' => $this->baseUrl($forceTestnet, (string) ($settings[$settingsUrl] ?? '')),
                'workspace_id' => $workspaceId,
                'config_id' => $configId,
                'integration_id' => null,
                'reason' => null,
            ];
        }

        if ($workspaceId === null || trim($workspaceId) === '') {
            return $this->missing($mode, 'missing_config_credentials_and_workspace');
        }

        try {
            $integration = $this->resolveIntegration($workspaceId, $settings);
        } catch (Throwable $e) {
            return $this->missing($mode, 'integration_not_found: '.$e->getMessage(), $workspaceId, $configId);
        }

        $config = (array) ($integration->config_json ?? []);
        $environment = strtolower((string) ($integration->environment ?? $config['environment'] ?? 'live'));
        if ($environment === '') {
            $environment = 'live';
        }
        if ($forceTestnet && $environment !== 'testnet') {
            return $this->missing($mode, 'active_binance_integration_is_not_testnet', $workspaceId, $configId, $integration);
        }
        if (! $forceTestnet && $environment === 'testnet') {
            return $this->missing($mode, 'active_binance_integration_is_testnet_not_live', $workspaceId, $configId, $integration);
        }

        $secrets = $this->integrations->decryptSecrets($integration->secrets_encrypted);
        $apiKey = trim((string) ($secrets['api_key'] ?? $secrets['key'] ?? ''));
        $apiSecret = trim((string) ($secrets['api_secret'] ?? $secrets['secret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            return $this->missing($mode, 'integration_credentials_missing', $workspaceId, $configId, $integration);
        }

        return [
            'configured' => true,
            'source' => 'workspace_integration',
            'mode' => $mode,
            'force_testnet' => $forceTestnet,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'base_url' => $this->baseUrl($forceTestnet, (string) ($config['futures_api_url'] ?? $config['api_url'] ?? '')),
            'workspace_id' => $workspaceId,
            'config_id' => $configId,
            'integration_id' => (string) $integration->id,
            'environment' => $environment,
            'reason' => null,
        ];
    }

    private function resolveIntegration(string $workspaceId, array $settings): Integration
    {
        $integrationId = (string) (
            $settings['integrationId']
            ?? $settings['integration_id']
            ?? $settings['liveIntegrationId']
            ?? $settings['preferredIntegrationId']
            ?? ''
        );

        return $this->resolver->resolve($workspaceId, 'binance', $integrationId !== '' ? $integrationId : null);
    }

    private function clientFromResolved(array $credentials, string $throttleScope): ?BinanceFuturesClient
    {
        if (! ($credentials['configured'] ?? false)) {
            return null;
        }

        return new BinanceFuturesClient(
            apiKey: (string) $credentials['api_key'],
            apiSecret: (string) $credentials['api_secret'],
            baseUrl: (string) $credentials['base_url'],
            throttleScope: $throttleScope,
        );
    }

    private function baseUrl(bool $forceTestnet, string $configuredUrl = ''): string
    {
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        return (string) ($forceTestnet
            ? (config('services.binance.futures_testnet_api_url') ?: 'https://testnet.binancefuture.com')
            : (config('services.binance.futures_api_url') ?: 'https://fapi.binance.com'));
    }

    private function missing(
        string $mode,
        string $reason,
        ?string $workspaceId = null,
        ?string $configId = null,
        ?Integration $integration = null,
    ): array {
        return [
            'configured' => false,
            'source' => 'none',
            'mode' => $mode,
            'force_testnet' => $mode === 'testnet',
            'api_key' => '',
            'api_secret' => '',
            'base_url' => $this->baseUrl($mode === 'testnet'),
            'workspace_id' => $workspaceId,
            'config_id' => $configId,
            'integration_id' => $integration ? (string) $integration->id : null,
            'environment' => $integration ? strtolower((string) ($integration->environment ?? ($integration->config_json['environment'] ?? 'live'))) : null,
            'reason' => $reason,
        ];
    }
}
