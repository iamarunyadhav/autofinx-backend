<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;

class DuplicateOrderGuard
{
    public function validate(Fast2XConfig $config, string $signalKey): array
    {
        $state = $config->state;
        if ($state?->order_in_progress) {
            return ['allowed' => false, 'reason' => 'Order already in progress'];
        }

        if ($state?->last_signal_key === $signalKey) {
            return ['allowed' => false, 'reason' => 'Duplicate signal ignored'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
