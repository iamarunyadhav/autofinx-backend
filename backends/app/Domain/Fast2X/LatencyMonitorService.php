<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;

class LatencyMonitorService
{
    public function validate(Fast2XConfig $config, int $latencyMs, array $settings): array
    {
        $max = (int) ($settings['maxApiLatencyMs'] ?? config('fast2x.max_latency_ms', 500));
        $config->state?->update(['latency_ms' => $latencyMs]);

        if ($latencyMs > $max) {
            return ['allowed' => false, 'reason' => 'Latency too high', 'event' => 'LATENCY_WARNING'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
