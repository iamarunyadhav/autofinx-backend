<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'sell_tranche_count')) {
                $table->unsignedTinyInteger('sell_tranche_count')->default(0)->after('reserve_tranche_count');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'initial_exit_pct')) {
                $table->decimal('initial_exit_pct', 6, 4)->default(1.0000)->after('sell_tranche_count');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_states', 'sell_tranches_remaining')) {
                $table->unsignedTinyInteger('sell_tranches_remaining')->nullable()->default(0)->after('reserve_quote_remaining');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'realized_pnl')) {
                $table->decimal('realized_pnl', 20, 8)->nullable()->default(0)->after('unrealized_pnl');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_states', 'realized_pnl')) {
                $table->dropColumn('realized_pnl');
            }
            if (Schema::hasColumn('ratio_bot_states', 'sell_tranches_remaining')) {
                $table->dropColumn('sell_tranches_remaining');
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'initial_exit_pct')) {
                $table->dropColumn('initial_exit_pct');
            }
            if (Schema::hasColumn('ratio_bot_configs', 'sell_tranche_count')) {
                $table->dropColumn('sell_tranche_count');
            }
        });
    }
};
