<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;

class TradeFrequencyService
{
    public function validate(Fast2XConfig $config, array $settings): array
    {
        $state = $config->state;
        if ($state?->cooldown_until && $state->cooldown_until->isFuture()) {
            return ['allowed' => false, 'reason' => 'Cooldown active'];
        }

        $minuteCount = $config->trades()->where('created_at', '>=', now()->subMinute())->count();
        if ($minuteCount >= (int) ($settings['maxTradesPerMinute'] ?? 5)) {
            return ['allowed' => false, 'reason' => 'Trade count per minute exceeded'];
        }

        $dayCount = $config->trades()->whereDate('created_at', today())->count();
        if ($dayCount >= (int) ($settings['maxTradesPerDay'] ?? config('fast2x.max_trades_per_day', 50))) {
            return ['allowed' => false, 'reason' => 'Trade count per day exceeded'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
