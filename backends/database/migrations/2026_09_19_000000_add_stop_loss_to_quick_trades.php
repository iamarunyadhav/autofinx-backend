<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_trades', 'stop_loss_pct')) {
                // Signed percentage of stake, e.g. -2.00 means "close early if
                // unrealized loss reaches 2% of stake". Null (default) = the
                // feature is off for this trade. Live-mode Quick Trade only —
                // paper trades never have a real position to protect.
                $table->decimal('stop_loss_pct', 6, 2)->nullable()->after('payout_ratio');
            }
            if (! Schema::hasColumn('quick_trades', 'stop_loss_triggered_at')) {
                // Audit trail: set only when CheckQuickTradeStopLossJob actually
                // closed the position early because the threshold was breached.
                $table->timestamp('stop_loss_triggered_at')->nullable()->after('stop_loss_pct');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            foreach (['stop_loss_triggered_at', 'stop_loss_pct'] as $column) {
                if (Schema::hasColumn('quick_trades', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
