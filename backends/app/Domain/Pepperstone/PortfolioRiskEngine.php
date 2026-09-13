<?php

namespace App\Domain\Pepperstone;

use App\Models\PepperstoneBasket;
use App\Models\PepperstoneBasketSlot;

/**
 * Portfolio-wide exposure reasoning for the Pepperstone Metals & Energy
 * module -- sums exposure across ALL of a workspace's active baskets
 * (all 4 instruments), which the existing single-config RiskEngine cannot
 * see (it only ever reasons about one config's own state). New per the
 * plan's §7.
 */
class PortfolioRiskEngine
{
    /**
     * Total filled lot exposure across every non-closed basket belonging
     * to any Pepperstone config in this workspace.
     */
    public function totalExposureLots(string $workspaceId): float
    {
        $basketIds = PepperstoneBasket::query()
            ->whereIn('status', PepperstoneBasket::OPEN_STATUSES)
            ->whereHas('config', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->pluck('id');

        if ($basketIds->isEmpty()) {
            return 0.0;
        }

        return (float) PepperstoneBasketSlot::query()
            ->whereIn('pepperstone_basket_id', $basketIds)
            ->where('status', PepperstoneBasketSlot::STATUS_FILLED)
            ->sum('lot_size');
    }

    public function wouldExceedPortfolioCap(string $workspaceId, float $proposedLots, ?float $capLots): bool
    {
        if ($capLots === null) {
            // No portfolio cap configured for this config -- not the thing
            // under test, matches RiskEngine's own "default that would not
            // itself trigger a block" convention.
            return false;
        }

        return ($this->totalExposureLots($workspaceId) + $proposedLots) > $capLots;
    }
}
