<?php

namespace App\Domain\Regime;

use App\Domain\Settings\SettingsService;
use App\Models\StrategyInstance;

class StrategyAutoSwitchService
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Returns true if the strategy is allowed to run under this regime.
     * If a switch map exists and current instance is disallowed, it will
     * activate the mapped instance and return false so caller can skip.
     */
    public function allowOrSwitch(StrategyInstance $instance, ?string $regime): bool
    {
        if (! $regime) {
            return true;
        }

        $settings = $this->settings->get('regime', $instance->workspace_id);
        $allowedByInstance = $settings['allowed_by_instance'][$instance->id] ?? $settings['default_allowed'] ?? [
            'TRENDING',
            'RANGING',
            'HIGH_VOLATILITY',
            'LOW_VOLATILITY',
        ];

        if (is_array($allowedByInstance) && ! in_array($regime, $allowedByInstance)) {
            $switchMap = $settings['switch_map'] ?? [];
            $targetId = $switchMap[$regime] ?? null;
            if ($targetId && $targetId !== $instance->id) {
                $target = StrategyInstance::find($targetId);
                if ($target && $target->status !== 'running') {
                    $target->status = 'running';
                    $target->save();
                }
            }
            return false;
        }

        return true;
    }
}
