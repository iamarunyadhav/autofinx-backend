<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_states', 'current_cycle_number')) {
                $table->unsignedInteger('current_cycle_number')->default(1)->after('recovery_used_at');
            }
        });

        Schema::table('ratio_bot_lots', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_lots', 'cycle_number')) {
                $table->unsignedInteger('cycle_number')->default(1)->after('lot_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_lots', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_lots', 'cycle_number')) {
                $table->dropColumn('cycle_number');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_states', 'current_cycle_number')) {
                $table->dropColumn('current_cycle_number');
            }
        });
    }
};
