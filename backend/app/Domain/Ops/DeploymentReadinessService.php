<?php

namespace App\Domain\Ops;

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\FuturesReadinessService;
use App\Models\AiTradingConfig;
use App\Models\Integration;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Str;

class DeploymentReadinessService
{
    public function __construct(
        private readonly TradingEnvironmentGuard $environmentGuard,
        private readonly IntegrationManager $integrations,
        private readonly FuturesReadinessService $futuresReadiness,
    ) {
    }

    public function state(string $workspaceId): array
    {
        $guardState = $this->environmentGuard->resolveState($workspaceId);
        $environment = $this->environmentClassification($guardState);
        $aiConfig = AiTradingConfig::query()->where('workspace_id', $workspaceId)->first();
        $integration = $this->activeBinanceIntegration($workspaceId);
        $integrationSecrets = $integration ? $this->integrations->decryptSecrets($integration->secrets_encrypted) : [];
        $ownerTwoFactor = $this->ownerTwoFactorReady($workspaceId);
        $futures = $this->futuresReadiness->state($workspaceId);
        $warnings = [];
        $blockers = [];

        if ($guardState['settings']['trading_mode'] === null && $environment === 'local') {
            $warnings[] = 'environment_derived_from_local';
        }

        if ($guardState['live_enabled'] && $environment !== 'live') {
            $blockers[] = 'live_mode_environment_mismatch';
        }

        if ($guardState['live_enabled'] && ! $integration) {
            $blockers[] = 'active_binance_integration_missing';
        } elseif ($guardState['live_enabled'] && $integration && $integrationSecrets === []) {
            $blockers[] = 'binance_credentials_missing';
        }

        if ($guardState['live_enabled'] && (bool) config('ops.require_2fa_for_live', true) && ! $ownerTwoFactor) {
            $blockers[] = 'two_factor_not_confirmed';
        }

        if ($guardState['live_enabled'] && $this->killSwitchActive($workspaceId)) {
            $blockers[] = 'kill_switch_active';
        }

        if ($aiConfig && (string) $aiConfig->mode === 'restricted_auto') {
            if (! (bool) $aiConfig->require_stop_loss) {
                $blockers[] = 'restricted_auto_requires_stop_loss';
            }
            if (! (bool) $aiConfig->require_take_profit) {
                $blockers[] = 'restricted_auto_requires_take_profit';
            }
            if (! (bool) $aiConfig->require_time_stop) {
                $blockers[] = 'restricted_auto_requires_time_stop';
            }
        }

        $aiProvider = strtolower((string) ($aiConfig->provider ?? config('intelligence.ai_trading.provider', 'heuristic')));
        if ($guardState['live_enabled'] && $aiConfig && (bool) $aiConfig->enabled && ! $this->aiProviderReady($aiProvider)) {
            $blockers[] = 'ai_provider_secret_missing';
        }

        if ($futures['state'] !== 'disabled' && ! in_array($futures['state'], ['testnet_ready', 'review_only'], true)) {
            $warnings[] = 'futures_not_testnet_ready';
        } elseif ($futures['state'] !== 'disabled') {
            $warnings[] = 'futures_restricted_to_testnet';
        }

        $state = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'caution' : 'healthy');

        return [
            'workspace_id' => $workspaceId,
            'environment' => $environment,
            'state' => $state,
            'deployment_ready' => $blockers === [],
            'live_enabled' => (bool) $guardState['live_enabled'],
            'live_mode' => (string) $guardState['mode'],
            'trading_settings' => $guardState,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'details' => [
                'binance_integration' => $integration ? [
                    'id' => $integration->id,
                    'display_name' => $integration->display_name,
                    'status' => $integration->status,
                ] : null,
                'owner_two_factor_ready' => $ownerTwoFactor,
                'ai_provider' => $aiProvider,
                'ai_mode' => $aiConfig?->mode ?? 'advisor',
                'futures' => $futures,
            ],
        ];
    }

    private function environmentClassification(array $guardState): string
    {
        $explicit = strtolower(trim((string) config('ops.deployment_environment', '')));
        if (in_array($explicit, ['local', 'demo', 'paper', 'sandbox', 'live'], true)) {
            return $explicit;
        }

        $appEnv = strtolower((string) app()->environment());
        if (in_array($appEnv, ['local', 'testing'], true)) {
            return 'local';
        }

        $settings = $guardState['settings'] ?? [];
        $venueEnvironment = strtolower((string) ($settings['venue_environment'] ?? ''));
        $liveEnabled = (bool) ($guardState['live_enabled'] ?? false);

        if ($liveEnabled && $venueEnvironment === 'live') {
            return 'live';
        }
        if (! $liveEnabled && $venueEnvironment === 'sandbox') {
            return 'sandbox';
        }
        if (! $liveEnabled && config('ops.allow_demo_mode', true) && ! in_array($appEnv, ['production'], true)) {
            return 'demo';
        }

        return $liveEnabled ? 'live' : 'paper';
    }

    private function activeBinanceIntegration(string $workspaceId): ?Integration
    {
        return Integration::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_key', 'binance')
            ->where('status', 'active')
            ->latest('updated_at')
            ->first();
    }

    private function ownerTwoFactorReady(string $workspaceId): bool
    {
        $members = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', ['owner', 'admin'])
            ->with('user')
            ->get();

        foreach ($members as $member) {
            $user = $member->user;
            if ($user instanceof User && ! empty($user->two_factor_secret) && ! empty($user->two_factor_confirmed_at)) {
                return true;
            }
        }

        return false;
    }

    private function aiProviderReady(string $provider): bool
    {
        return match ($provider) {
            'heuristic', 'null', 'mock' => true,
            'openai' => (bool) config('intelligence.ai_trading.openai.api_key'),
            'gemini' => (bool) config('intelligence.ai_trading.gemini.api_key'),
            'anthropic' => (bool) config('intelligence.ai_trading.anthropic.api_key'),
            default => false,
        };
    }

    private function killSwitchActive(string $workspaceId): bool
    {
        return app(KillSwitchService::class)->isActive($workspaceId);
    }
}
