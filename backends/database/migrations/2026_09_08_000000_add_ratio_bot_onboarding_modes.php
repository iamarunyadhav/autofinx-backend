<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'seed_position_pct')) {
                $table->decimal('seed_position_pct', 5, 2)->nullable()->after('starting_entry');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'seed_position_executed_at')) {
                // Set BEFORE the seed buy is ever submitted (not after) -- a
                // single, bounded, one-time action must never retry even if
                // execution fails. maybeAutoAnchor()'s own idle-tick loop has
                // no one-shot flag and re-fires every tick while idle; without
                // this column a failed seed buy would resubmit indefinitely.
                $table->timestamp('seed_position_executed_at')->nullable()->after('recovery_used_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (Schema::hasColumn('ratio_bot_states', 'seed_position_executed_at')) {
                $table->dropColumn('seed_position_executed_at');
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (Schema::hasColumn('ratio_bot_configs', 'seed_position_pct')) {
                $table->dropColumn('seed_position_pct');
            }
        });
    }
};
