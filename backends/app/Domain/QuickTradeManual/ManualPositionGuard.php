<?php

namespace App\Domain\QuickTradeManual;

use App\Models\QuickTradeManualPosition;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only question other Quick Trade code may ask: "is a manual SL/TP position active on this
 * symbol?". Used by Time-Based Quick Trade (which also serves Robot Trade) to refuse opening on a
 * symbol a manual position protects: in one-way mode two positions on one symbol are the SAME
 * exchange position, so a time-expiry close would shrink the manual one and a closePosition stop
 * would close both. Fails OPEN (returns false) when the table does not exist or the query fails,
 * so Time-Based behaviour is unchanged until the migration has run AND a manual position exists.
 */
final class ManualPositionGuard
{
    private static ?bool $tableExists = null;

    public static function hasActivePosition(string $workspaceId, string $symbol): bool
    {
        try {
            // Only a POSITIVE answer is memoized: a long-lived worker that saw "no table" before
            // the migration ran must start protecting manual positions without a restart.
            if (self::$tableExists !== true && ! (self::$tableExists = Schema::hasTable('quick_trade_manual_positions'))) {
                self::$tableExists = null;

                return false;
            }

            return QuickTradeManualPosition::query()
                ->where('workspace_id', $workspaceId)
                ->where('symbol', strtoupper($symbol))
                ->whereNotIn('status', QuickTradeManualPosition::TERMINAL)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Test hook: forget the memoized table check. */
    public static function forgetTableCheck(): void
    {
        self::$tableExists = null;
    }
}
