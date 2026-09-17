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
            if (! Schema::hasColumn('quick_trades', 'take_profit_pct')) {
                // Signed percentage of stake, e.g. 5.00 means "close early once
                // unrealized profit reaches 5% of stake". POSITIVE value —
                // opposite sign convention from stop_loss_pct (negative). Null
                // (default) = the feature is off for this trade. Live-mode
                // Quick Trade only — paper trades never have a real position
                // to protect/lock in profit on.
                $table->decimal('take_profit_pct', 6, 2)->nullable()->after('leverage');
            }
            if (! Schema::hasColumn('quick_trades', 'take_profit_triggered_at')) {
                // Audit trail: set only when the exit-conditions job actually
                // closed the position early because the take-profit threshold
                // was reached.
                $table->timestamp('take_profit_triggered_at')->nullable()->after('take_profit_pct');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            foreach (['take_profit_triggered_at', 'take_profit_pct'] as $column) {
                if (Schema::hasColumn('quick_trades', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
