<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_enabled')) {
                $table->boolean('recovery_enabled')->default(false)->after('v2_enabled');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_timeout_minutes')) {
                $table->integer('recovery_timeout_minutes')->nullable()->after('recovery_enabled');
            }
        });

        // recovery_action_required already exists on ratio_bot_states (dormant,
        // added by an earlier migration) — reused as-is for the new recovery
        // states, no new column needed for the trigger flag itself.
        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_mode')) {
                $table->string('recovery_mode', 24)->nullable()->after('recovery_action_required'); // position_stuck|cash_stuck
            }
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_started_at')) {
                $table->timestamp('recovery_started_at')->nullable()->after('recovery_mode');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_review_at')) {
                $table->timestamp('recovery_review_at')->nullable()->after('recovery_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_states', 'recovery_review_at')) {
                $table->dropColumn('recovery_review_at');
            }
            if (Schema::hasColumn('ratio_bot_states', 'recovery_started_at')) {
                $table->dropColumn('recovery_started_at');
            }
            if (Schema::hasColumn('ratio_bot_states', 'recovery_mode')) {
                $table->dropColumn('recovery_mode');
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'recovery_timeout_minutes')) {
                $table->dropColumn('recovery_timeout_minutes');
            }
            if (Schema::hasColumn('ratio_bot_configs', 'recovery_enabled')) {
                $table->dropColumn('recovery_enabled');
            }
        });
    }
};
