<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately NOT named `stop_loss_pct` -- `robot_sessions` already has a
     * `stop_loss_pct` column (added 2026-06-14) that means something entirely
     * different: a positive-percentage trailing-stop-of-peak-profit setting
     * for the candle-direction bot's own internal position management
     * (`RobotSessionService::computeTrailingStop()` / the emergency-stop
     * guard), defaulting to 12.0/20.0 and validated `min:0.1|max:100`.
     *
     * This new column mirrors `quick_trades.stop_loss_pct` instead: a
     * negative percentage-of-stake threshold (`-100 < x < 0`), forwarded
     * verbatim into every `QuickTradeService::place()` call this session
     * makes so the pre-existing, unmodified `CheckQuickTradeStopLossJob`
     * picks it up automatically. Nullable / no default -- opt-in only,
     * mirroring Quick Trade's own opt-in (empty = feature off).
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('robot_sessions', 'quick_trade_stop_loss_pct')) {
                $table->decimal('quick_trade_stop_loss_pct', 6, 2)->nullable()->after('stop_loss_pct');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('robot_sessions', 'quick_trade_stop_loss_pct')) {
                $table->dropColumn('quick_trade_stop_loss_pct');
            }
        });
    }
};
