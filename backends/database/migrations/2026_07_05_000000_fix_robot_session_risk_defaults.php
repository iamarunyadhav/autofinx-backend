<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            $table->decimal('stop_loss_pct', 8, 4)->default(12.0)->change();
            $table->decimal('take_profit_pct', 8, 4)->nullable()->default(2.0)->change();
            $table->unsignedTinyInteger('confidence_threshold')->default(70)->change();
            $table->unsignedInteger('cooldown_seconds')->default(600)->change();
        });

        // Backfill rows still sitting on the old, never-customized column default (20.0)
        // so they pick up the actual app default (12.0). Rows a user has ever saved
        // (updated_at !== created_at) are left untouched.
        DB::table('robot_sessions')
            ->where('stop_loss_pct', 20.0)
            ->whereColumn('updated_at', 'created_at')
            ->update(['stop_loss_pct' => 12.0]);

        // Existing sessions had no take-profit at all (exits relied solely on the
        // trailing-stop giveback). Give them the new default so they aren't left
        // running with an unset take-profit indefinitely.
        DB::table('robot_sessions')
            ->whereNull('take_profit_pct')
            ->update(['take_profit_pct' => 2.0]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            $table->decimal('stop_loss_pct', 8, 4)->default(20.0)->change();
            $table->decimal('take_profit_pct', 8, 4)->nullable()->default(null)->change();
            $table->unsignedTinyInteger('confidence_threshold')->default(60)->change();
            $table->unsignedInteger('cooldown_seconds')->default(300)->change();
        });
    }
};
