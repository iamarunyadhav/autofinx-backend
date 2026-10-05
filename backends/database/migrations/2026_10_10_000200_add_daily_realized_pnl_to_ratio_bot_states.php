<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-bot net realized PnL for the current trade_day, maintained by
 * RatioBotStateService::recordTradeOutcome(). The UI's "today" figure used to
 * sum the shared Trade table by workspace + symbol, mixing every Ratio Bot and
 * manual order on that symbol into one number.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ratio_bot_states', 'daily_realized_pnl')) {
            Schema::table('ratio_bot_states', function (Blueprint $table) {
                $table->decimal('daily_realized_pnl', 20, 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ratio_bot_states', 'daily_realized_pnl')) {
            Schema::table('ratio_bot_states', function (Blueprint $table) {
                $table->dropColumn('daily_realized_pnl');
            });
        }
    }
};
