<?php

namespace App\Domain\ForexGrid;

use App\Models\ForexGridBotConfig;

/**
 * Pure position-sizing function -- zero I/O, zero side effects. Given a
 * 0-based level index (how many levels are already open on the relevant
 * side, per RunForexGridBotEvaluationJob's per-side counting convention)
 * and a config, returns the lot size the NEXT level at that index should
 * use.
 */
class GridLotSizer
{
    public function lotSizeForLevel(int $levelIndex, ForexGridBotConfig $config): float
    {
        if ($config->position_sizing_mode === 'fixed') {
            return (float) $config->base_lot_size;
        }

        if ($config->position_sizing_mode === 'martingale') {
            $size = (float) $config->base_lot_size * ((float) $config->martingale_multiplier ** $levelIndex);
            return min($size, (float) $config->martingale_max_lot_size);
        }

        // hybrid
        $escalationLevels = (int) $config->hybrid_martingale_level_count;
        if ($levelIndex < $escalationLevels) {
            $size = (float) $config->base_lot_size * ((float) $config->martingale_multiplier ** $levelIndex);
            return min($size, (float) $config->martingale_max_lot_size);
        }

        $cappedFinalEscalationSize = min(
            (float) $config->base_lot_size * ((float) $config->martingale_multiplier ** ($escalationLevels - 1)),
            (float) $config->martingale_max_lot_size,
        );
        return $cappedFinalEscalationSize;
    }
}
