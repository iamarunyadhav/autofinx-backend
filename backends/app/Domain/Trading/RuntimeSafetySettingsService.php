<?php

namespace App\Domain\Trading;

use App\Models\Setting;
use Illuminate\Support\Str;

class RuntimeSafetySettingsService
{
    public const GROUP = 'runtime_safety';
    public const LIVE_FEED_MAX_AGE_SECONDS = 'live_feed_max_age_seconds';
    public const WEBHOOK_MAX_SIGNAL_LATENCY_MS = 'webhook_max_signal_latency_ms';

    public function liveFeedMaxAgeSeconds(?string $workspaceId = null): int
    {
        return $this->clampInt(
            $this->value(self::LIVE_FEED_MAX_AGE_SECONDS, $workspaceId),
            1,
            30,
            (int) config('autofinx.runtime.live_feed_max_age_seconds', config('future_trade.live_feed_max_age_seconds', 3))
        );
    }

    public function webhookMaxSignalLatencyMs(?string $workspaceId = null): int
    {
        return $this->clampInt(
            $this->value(self::WEBHOOK_MAX_SIGNAL_LATENCY_MS, $workspaceId),
            1000,
            60000,
            (int) config('future_trade.pine_webhook_max_signal_latency_ms', 4000)
        );
    }

    public function normalize(array $settings, ?string $workspaceId = null): array
    {
        $settings['liveFeedMaxAgeSeconds'] = $this->clampInt(
            $settings['liveFeedMaxAgeSeconds'] ?? null,
            1,
            30,
            $this->liveFeedMaxAgeSeconds($workspaceId)
        );
        $settings['webhookMaxSignalLatencyMs'] = $this->clampInt(
            $settings['webhookMaxSignalLatencyMs'] ?? null,
            1000,
            60000,
            $this->webhookMaxSignalLatencyMs($workspaceId)
        );

        return $settings;
    }

    public function persistFromSettings(string $workspaceId, array $settings, ?string $userId = null): void
    {
        $updates = [];

        if (array_key_exists('liveFeedMaxAgeSeconds', $settings)) {
            $updates[self::LIVE_FEED_MAX_AGE_SECONDS] = $this->clampInt($settings['liveFeedMaxAgeSeconds'], 1, 30, 3);
        }

        if (array_key_exists('webhookMaxSignalLatencyMs', $settings)) {
            $updates[self::WEBHOOK_MAX_SIGNAL_LATENCY_MS] = $this->clampInt($settings['webhookMaxSignalLatencyMs'], 1000, 60000, 4000);
        }

        foreach ($updates as $key => $value) {
            Setting::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'group' => self::GROUP,
                    'key' => $key,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'value_json' => $value,
                    'encrypted' => false,
                    'updated_by' => $userId,
                ]
            );
        }
    }

    private function value(string $key, ?string $workspaceId): mixed
    {
        if ($workspaceId) {
            $workspace = Setting::query()
                ->where('workspace_id', $workspaceId)
                ->where('group', self::GROUP)
                ->where('key', $key)
                ->first();

            if ($workspace) {
                return $workspace->value_json;
            }
        }

        return Setting::query()
            ->whereNull('workspace_id')
            ->where('group', self::GROUP)
            ->where('key', $key)
            ->value('value_json');
    }

    private function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        $number = is_numeric($value) ? (int) $value : $fallback;

        return max($min, min($max, $number));
    }
}
