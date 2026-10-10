<?php

namespace App\Domain\QuickTradeManual;

use App\Models\Setting;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Two-gate switch for Manual SL/TP, mirroring Future Trade's live gate: the server flag
 * (config('quick_trade_manual.enabled')) AND the per-workspace Setting row must both be true.
 * Default for both is OFF. Uses config(), never env() (config:cache safe).
 */
class ManualFeatureGate
{
    public const SETTING_GROUP = 'quick_trade_manual';
    public const SETTING_KEY = 'enabled';

    public function serverEnabled(): bool
    {
        return (bool) config('quick_trade_manual.enabled', false);
    }

    public function workspaceEnabled(string $workspaceId): bool
    {
        $row = Setting::query()
            ->where('workspace_id', $workspaceId)
            ->where('group', self::SETTING_GROUP)
            ->where('key', self::SETTING_KEY)
            ->first();

        return $row !== null && $row->value_json === true;
    }

    public function enabled(string $workspaceId): bool
    {
        return $this->serverEnabled() && $this->workspaceEnabled($workspaceId);
    }

    public function assertEnabled(string $workspaceId): void
    {
        if (! $this->serverEnabled()) {
            throw new RuntimeException('feature_disabled: Manual SL/TP mode is disabled on this server.');
        }
        if (! $this->workspaceEnabled($workspaceId)) {
            throw new RuntimeException('feature_disabled: Manual SL/TP mode is not enabled for this workspace.');
        }
    }

    public function assertEnvironmentAllowed(string $environment): void
    {
        if ($environment !== 'testnet' && ! (bool) config('quick_trade_manual.allow_mainnet', false)) {
            throw new RuntimeException('mainnet_blocked: Manual SL/TP mode is limited to Binance TESTNET until the exchange-side stop verification checklist has been completed and mainnet is explicitly allowed.');
        }
    }

    public function setWorkspaceEnabled(string $workspaceId, bool $enabled): void
    {
        Setting::updateOrCreate(
            ['workspace_id' => $workspaceId, 'group' => self::SETTING_GROUP, 'key' => self::SETTING_KEY],
            ['id' => (string) Str::uuid(), 'value_json' => $enabled, 'encrypted' => false],
        );
    }
}
