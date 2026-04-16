<?php

namespace App\Domain\Trading;

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\Integration;

class FuturesReadinessService
{
    public function __construct(
        private readonly FuturesConfigService $configs,
        private readonly IntegrationManager $integrations,
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    public function state(string $workspaceId): array
    {
        $config = $this->configs->effective($workspaceId);

        if (! ($config['futures_enabled'] ?? false) || ($config['futures_mode'] ?? 'disabled') === 'disabled') {
            return [
                'state' => 'disabled',
                'reasons' => ['futures_disabled'],
                'supports_manual_test_order' => false,
                'supports_auto_testnet' => false,
                'live_ready' => false,
                'config' => $config,
            ];
        }

        $integration = Integration::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_key', 'binance')
            ->where('status', 'active')
            ->latest('updated_at')
            ->first();

        $reasons = ['live_futures_not_supported'];
        if (! ($config['futures_force_testnet'] ?? false)) {
            $reasons[] = 'futures_force_testnet_required';
        }
        if (! $integration) {
            $reasons[] = 'active_binance_integration_missing';
        } elseif ($this->integrations->decryptSecrets($integration->secrets_encrypted) === []) {
            $reasons[] = 'binance_credentials_missing';
        }

        if ($this->breakers->isProviderOutageActive($workspaceId, 'binance')) {
            $reasons[] = 'provider_breaker_active';
        }
        if ($this->breakers->isStaleFeedBreakerActive($workspaceId)) {
            $reasons[] = 'stale_feed_breaker_active';
        }

        $blockingReasons = array_values(array_filter($reasons, static fn (string $reason) => ! in_array($reason, [
            'live_futures_not_supported',
            'provider_breaker_active',
            'stale_feed_breaker_active',
        ], true)));

        if ($blockingReasons !== []) {
            return [
                'state' => 'config_incomplete',
                'reasons' => $reasons,
                'supports_manual_test_order' => false,
                'supports_auto_testnet' => false,
                'live_ready' => false,
                'config' => $config,
                'integration_id' => $integration?->id,
            ];
        }

        $mode = (string) ($config['futures_mode'] ?? 'disabled');
        $state = $mode === 'review_only' ? 'review_only' : 'testnet_ready';

        return [
            'state' => $state,
            'reasons' => $reasons,
            'supports_manual_test_order' => ! in_array('provider_breaker_active', $reasons, true) && ! in_array('stale_feed_breaker_active', $reasons, true),
            'supports_auto_testnet' => $mode === 'restricted_testnet_auto'
                && ! in_array('provider_breaker_active', $reasons, true)
                && ! in_array('stale_feed_breaker_active', $reasons, true),
            'live_ready' => false,
            'config' => $config,
            'integration_id' => $integration?->id,
            'integration_display_name' => $integration?->display_name,
            'environment' => 'testnet',
        ];
    }
}
