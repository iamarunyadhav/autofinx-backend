<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Take-profit + leverage parity for Robot Trade, mirroring the exact
     * `quick_trade_stop_loss_pct` pattern added
     * (2026_09_20_000000_add_quick_trade_stop_loss_to_robot_sessions.php):
     * every field here is forwarded verbatim into
     * `QuickTradeService::place()`'s already-existing `take_profit_pct` /
     * `leverage_enabled` / `leverage` parameters, so the pre-existing,
     * unmodified `CheckQuickTradeStopLossJob` (take-profit) and
     * `LeverageValidationService` (leverage, via
     * `BinanceTimedFuturesProvider::openTimedTrade()`) pick these up
     * automatically -- no new validation/monitoring mechanism is introduced.
     *
     * `quick_trade_take_profit_pct` mirrors `quick_trades.take_profit_pct`:
     * a POSITIVE percentage-of-stake target. Nullable / no default --
     * opt-in only, same as `quick_trade_stop_loss_pct`.
     *
     * `quick_trade_leverage_enabled` / `quick_trade_leverage` mirror
     * `quick_trades.leverage_enabled` / `quick_trades.leverage` exactly:
     * boolean gate defaulting to false (off), decimal leverage value
     * meaningful only when the gate is true. No app-layer maximum is
     * hardcoded here -- the real ceiling is fetched live from Binance's own
     * leverage brackets by `LeverageValidationService::validate()`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('robot_sessions', 'quick_trade_take_profit_pct')) {
                $table->decimal('quick_trade_take_profit_pct', 6, 2)->nullable()->after('quick_trade_stop_loss_pct');
            }
            if (! Schema::hasColumn('robot_sessions', 'quick_trade_leverage_enabled')) {
                $table->boolean('quick_trade_leverage_enabled')->default(false)->after('quick_trade_take_profit_pct');
            }
            if (! Schema::hasColumn('robot_sessions', 'quick_trade_leverage')) {
                $table->decimal('quick_trade_leverage', 6, 2)->nullable()->after('quick_trade_leverage_enabled');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            foreach (['quick_trade_leverage', 'quick_trade_leverage_enabled', 'quick_trade_take_profit_pct'] as $column) {
                if (Schema::hasColumn('robot_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
