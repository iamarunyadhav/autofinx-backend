<?php

namespace App\Domain\Ops;

use App\Models\Bot;
use App\Models\Setting;
use RuntimeException;

class TradingEnvironmentGuard
{
    public function assertWorkspaceMode(string $workspaceId, bool $requestLive): void
    {
        $state = $this->resolveState($workspaceId);
        $expectedMode = $requestLive ? 'live' : 'paper';
        if ($state['mode'] !== $expectedMode) {
            throw new RuntimeException(sprintf(
                'Trading mode mismatch. Requested [%s], but workspace/environment mode is [%s].',
                strtoupper($expectedMode),
                strtoupper($state['mode'])
            ));
        }

        if ($requestLive && ! $state['live_enabled']) {
            throw new RuntimeException('Live trading switch is disabled by environment/settings.');
        }
    }

    public function assertBotMode(Bot $bot): void
    {
        $workspaceId = (string) $bot->workspace_id;
        if ($workspaceId === '') {
            throw new RuntimeException('Bot workspace_id is required for mode guard.');
        }

        $this->assertWorkspaceMode($workspaceId, (bool) $bot->is_live);
    }

    public function resolveState(?string $workspaceId): array
    {
        $envTradingMode = $this->normalizeMode((string) env('TRADING_MODE', 'paper'));
        $envLiveEnabled = $this->toBool(env('LIVE_TRADING_ENABLED', false));

        $settings = $this->loadTradingSettings($workspaceId);
        $settingsMode = $this->normalizeMode((string) ($settings['trading_mode'] ?? ''));
        $settingsLiveEnabled = $settings['live_enabled'] ?? null;
        $settingsVenueEnv = $this->normalizeVenueEnvironment((string) ($settings['venue_environment'] ?? ''));

        $effectiveMode = $settingsMode ?: $envTradingMode;
        if ($settingsVenueEnv === 'sandbox') {
            $effectiveMode = 'paper';
        } elseif ($settingsVenueEnv === 'live') {
            $effectiveMode = 'live';
        }

        $effectiveLiveEnabled = is_bool($settingsLiveEnabled)
            ? $settingsLiveEnabled
            : $envLiveEnabled;

        return [
            'mode' => $effectiveMode,
            'live_enabled' => $effectiveLiveEnabled,
            'settings' => [
                'trading_mode' => $settingsMode,
                'venue_environment' => $settingsVenueEnv,
                'live_enabled' => $settingsLiveEnabled,
            ],
            'env' => [
                'trading_mode' => $envTradingMode,
                'live_enabled' => $envLiveEnabled,
            ],
        ];
    }

    private function loadTradingSettings(?string $workspaceId): array
    {
        $workspace = $workspaceId ? $this->settingMap('trading', $workspaceId) : [];
        $global = $this->settingMap('trading', null);

        return array_merge($global, $workspace);
    }

    private function settingMap(string $group, ?string $workspaceId): array
    {
        return Setting::query()
            ->where('group', $group)
            ->where(function ($q) use ($workspaceId): void {
                if ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                } else {
                    $q->whereNull('workspace_id');
                }
            })
            ->get()
            ->mapWithKeys(fn (Setting $row) => [$row->key => $row->value_json])
            ->toArray();
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'live') {
            return 'live';
        }

        return 'paper';
    }

    private function normalizeVenueEnvironment(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['sandbox', 'testnet', 'paper'], true)) {
            return 'sandbox';
        }
        if ($value === 'live') {
            return 'live';
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
