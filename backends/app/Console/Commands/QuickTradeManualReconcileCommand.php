<?php

namespace App\Console\Commands;

use App\Domain\QuickTradeManual\ManualPositionReconciler;
use App\Models\QuickTradeManualPosition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class QuickTradeManualReconcileCommand extends Command
{
    protected $signature = 'quick-trade-manual:reconcile {--limit=50}';

    protected $description = 'Reconcile open Manual SL/TP Quick Trade positions with the exchange (exchange is authoritative). No-op when none exist.';

    public function handle(ManualPositionReconciler $reconciler): int
    {
        // Runs even when the feature flag is OFF on purpose: switching the flag off must never
        // stop the reconciliation of a position that is still open on the exchange. With no
        // table or no active rows this is one cheap query and nothing else.
        if (! Schema::hasTable('quick_trade_manual_positions')) {
            return self::SUCCESS;
        }
        $active = QuickTradeManualPosition::query()
            ->where(fn ($q) => $q->whereNotIn('status', QuickTradeManualPosition::TERMINAL)
                ->orWhere('sl_status', 'WORKING')->orWhere('tp_status', 'WORKING')
                ->orWhere('sl_status', 'PLACED')->orWhere('tp_status', 'PLACED'))
            ->exists();
        if (! $active) {
            return self::SUCCESS;
        }

        $result = $reconciler->run(max(1, min(200, (int) $this->option('limit'))));
        $this->info(sprintf('Manual SL/TP reconcile: checked=%d errors=%d swept=%d', $result['checked'], $result['errors'], $result['swept']));

        return self::SUCCESS;
    }
}
