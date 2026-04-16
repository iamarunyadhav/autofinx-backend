<?php

namespace App\Domain\Trading;

use App\Models\Integration;
use App\Models\Setting;
use RuntimeException;

class TradingIntegrationResolver
{
    public function activeIntegrations(string $workspaceId): array
    {
        return Integration::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->orderBy('provider_key')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Integration $integration) => [
                'id' => $integration->id,
                'provider_key' => strtolower((string) $integration->provider_key),
                'display_name' => $integration->display_name,
                'status' => $integration->status,
                'last_health_status' => $integration->last_health_status,
                'config_json' => (array) ($integration->config_json ?? []),
            ])
            ->all();
    }

    public function resolve(string $workspaceId, ?string $provider = null, ?string $integrationId = null): Integration
    {
        if ($integrationId) {
            $integration = Integration::where('workspace_id', $workspaceId)
                ->whereKey($integrationId)
                ->first();

            if (! $integration) {
                throw new RuntimeException('Selected live integration was not found.');
            }
            if (strtolower((string) $integration->status) !== 'active') {
                throw new RuntimeException('Selected live integration is not active.');
            }

            return $integration;
        }

        $settings = Setting::query()
            ->where('group', 'trading')
            ->where('workspace_id', $workspaceId)
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value_json])
            ->toArray();

        $preferredProvider = strtolower((string) ($provider ?: ($settings['preferred_provider'] ?? 'binance')));
        $preferredIntegrationId = $settings['preferred_integration_id'] ?? null;

        if (is_string($preferredIntegrationId) && $preferredIntegrationId !== '') {
            $preferred = Integration::where('workspace_id', $workspaceId)
                ->whereKey($preferredIntegrationId)
                ->where('status', 'active')
                ->first();

            if ($preferred && strtolower((string) $preferred->provider_key) === $preferredProvider) {
                return $preferred;
            }
        }

        $integration = Integration::where('workspace_id', $workspaceId)
            ->where('provider_key', $preferredProvider)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();

        if (! $integration) {
            throw new RuntimeException("No active {$preferredProvider} integration found for this workspace.");
        }

        return $integration;
    }
}
