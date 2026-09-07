<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_trend_composite_at_last_review')) {
                $table->decimal('recovery_trend_composite_at_last_review', 8, 4)->nullable()->after('recovery_review_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_trend_reviewed_at')) {
                $table->timestamp('recovery_trend_reviewed_at')->nullable()->after('recovery_trend_composite_at_last_review');
            }
            // Deliberately NOT stored on `position_state` -- isHolding()/
            // isFlat()/isPartialHolding() all do an exact string match against
            // position_state, and the rest of the decision pipeline branches
            // on those. Overloading position_state with 'emergency' would
            // make the bot forget it has an open position while emergency is
            // active. This is a separate, independent signal purely for the
            // UI banner + the new-buy-block gate.
            if (! Schema::hasColumn('ratio_bot_states', 'emergency_state')) {
                $table->string('emergency_state')->nullable()->after('recovery_trend_reviewed_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'emergency_entered_at')) {
                $table->timestamp('emergency_entered_at')->nullable()->after('emergency_state');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'emergency_cleared_at')) {
                $table->timestamp('emergency_cleared_at')->nullable()->after('emergency_entered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'emergency_cleared_at',
                'emergency_entered_at',
                'emergency_state',
                'recovery_trend_reviewed_at',
                'recovery_trend_composite_at_last_review',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
