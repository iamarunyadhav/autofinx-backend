<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'reserve_mode')) {
                $table->string('reserve_mode', 16)->default('manual')->after('sell_tranche_count'); // manual|dynamic
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'reserve_min_pct')) {
                $table->decimal('reserve_min_pct', 6, 4)->nullable()->after('reserve_mode');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'reserve_max_pct')) {
                $table->decimal('reserve_max_pct', 6, 4)->nullable()->after('reserve_min_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'reserve_max_pct')) {
                $table->dropColumn('reserve_max_pct');
            }
            if (Schema::hasColumn('ratio_bot_configs', 'reserve_min_pct')) {
                $table->dropColumn('reserve_min_pct');
            }
            if (Schema::hasColumn('ratio_bot_configs', 'reserve_mode')) {
                $table->dropColumn('reserve_mode');
            }
        });
    }
};
