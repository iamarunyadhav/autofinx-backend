<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'initial_entry_pct')) {
                $table->decimal('initial_entry_pct', 6, 4)->default(1.0000)->after('base_quote_amount');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'reserve_tranche_count')) {
                $table->unsignedTinyInteger('reserve_tranche_count')->default(0)->after('initial_entry_pct');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_states', 'reserve_tranches_remaining')) {
                $table->unsignedTinyInteger('reserve_tranches_remaining')->nullable()->default(0)->after('last_buy_quantity');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'reserve_quote_remaining')) {
                $table->decimal('reserve_quote_remaining', 20, 8)->nullable()->default(0)->after('reserve_tranches_remaining');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_states', 'reserve_quote_remaining')) {
                $table->dropColumn('reserve_quote_remaining');
            }
            if (Schema::hasColumn('ratio_bot_states', 'reserve_tranches_remaining')) {
                $table->dropColumn('reserve_tranches_remaining');
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'reserve_tranche_count')) {
                $table->dropColumn('reserve_tranche_count');
            }
            if (Schema::hasColumn('ratio_bot_configs', 'initial_entry_pct')) {
                $table->dropColumn('initial_entry_pct');
            }
        });
    }
};
