<?php

namespace App\Domain\Trading;

/** Pure accounting: positive funding is a cost, negative funding a credit. */
class ExchangeExecutionCostReconciliationService
{
    public function resolveFees(?float $entryActual, ?float $exitActual, ?float $fundingActual, float $entryEstimate, float $exitEstimate): array
    {
        $known = count(array_filter([$entryActual, $exitActual, $fundingActual], static fn ($value) => $value !== null));

        return [
            $entryActual ?? $entryEstimate,
            $exitActual ?? $exitEstimate,
            $fundingActual ?? 0.0,
            $entryActual,
            $exitActual,
            $fundingActual,
            $known === 3 ? 'real' : ($known > 0 ? 'partial' : 'estimated'),
        ];
    }

    /** Fill-based gross PnL already includes spread/slippage. Never deduct it twice. */
    public function netPnl(float $grossPnl, float $commission, float $fundingCost = 0.0): float
    {
        return round($grossPnl - $commission - $fundingCost, 8);
    }
}
