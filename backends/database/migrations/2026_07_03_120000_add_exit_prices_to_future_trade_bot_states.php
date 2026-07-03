<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('future_trade_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('future_trade_bot_states', 'stop_loss')) {
                $table->decimal('stop_loss', 20, 8)->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('future_trade_bot_states', 'take_profit')) {
                $table->decimal('take_profit', 20, 8)->nullable()->after('stop_loss');
            }

            if (! Schema::hasColumn('future_trade_bot_states', 'trailing_take_profit')) {
                $table->decimal('trailing_take_profit', 20, 8)->nullable()->after('take_profit');
            }

            if (! Schema::hasColumn('future_trade_bot_states', 'total_profit')) {
                $table->decimal('total_profit', 20, 8)->default(0)->after('realized_pnl');
            }

            if (! Schema::hasColumn('future_trade_bot_states', 'daily_loss_locked')) {
                $table->boolean('daily_loss_locked')->default(false)->after('daily_loss');
            }

            if (! Schema::hasColumn('future_trade_bot_states', 'open_position')) {
                $table->json('open_position')->nullable()->after('chart_points');
            }

            if (! Schema::hasColumn('future_trade_bot_states', 'runtime_json')) {
                $table->json('runtime_json')->nullable()->after('open_position');
            }
        });
    }

    public function down(): void
    {
        Schema::table('future_trade_bot_states', function (Blueprint $table) {
            foreach (['runtime_json', 'open_position', 'daily_loss_locked', 'total_profit', 'trailing_take_profit', 'take_profit', 'stop_loss'] as $column) {
                if (Schema::hasColumn('future_trade_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
