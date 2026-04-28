<?php

namespace App\Domain\Fast2X;

class RollbackService
{
    public function shouldRollback(float $netPnlPercent, array $settings): bool
    {
        return $netPnlPercent <= -abs((float) ($settings['rollbackLossPercent'] ?? 0.15));
    }
}
