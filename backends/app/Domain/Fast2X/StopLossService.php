<?php

namespace App\Domain\Fast2X;

class StopLossService
{
    public function shouldStop(float $netPnlPercent, array $settings): bool
    {
        return (bool) ($settings['useStopLoss'] ?? true)
            && $netPnlPercent <= -abs((float) ($settings['stopLossPercent'] ?? 0.4));
    }
}
