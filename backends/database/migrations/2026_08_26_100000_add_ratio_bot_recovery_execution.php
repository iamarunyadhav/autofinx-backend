<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'starting_entry')) {
                $table->string('starting_entry', 8)->nullable()->after('side_mode');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_buy_drop_pct')) {
                $table->decimal('recovery_buy_drop_pct', 6, 4)->nullable()->after('recovery_timeout_minutes');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_sell_rise_pct')) {
                $table->decimal('recovery_sell_rise_pct', 6, 4)->nullable()->after('recovery_buy_drop_pct');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_used_at')) {
                $table->timestamp('recovery_used_at')->nullable()->after('recovery_review_at');
            }
        });

        Schema::table('ratio_bot_lots', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_lots', 'lot_type')) {
                $table->string('lot_type', 16)->default('NORMAL')->after('buy_stage_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_lots', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_lots', 'lot_type')) {
                $table->dropColumn('lot_type');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_states', 'recovery_used_at')) {
                $table->dropColumn('recovery_used_at');
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            foreach (['recovery_sell_rise_pct', 'recovery_buy_drop_pct', 'starting_entry'] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
