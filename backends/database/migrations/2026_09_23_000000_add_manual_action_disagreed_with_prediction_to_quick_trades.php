<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_trades', 'manual_action_disagreed_with_prediction')) {
                // Observational only (spec item 61): true when the user's manually
                // chosen side (buy/sell) genuinely differed from the system's own
                // most-recent decision_trend prediction for this same symbol/
                // timeframe/expiry at the moment of entry (the pre-entry research
                // snapshot captured in signal_payload.research.primary). Nullable,
                // default null -- left null (not false) whenever no recent
                // prediction snapshot exists to honestly compare against, e.g. the
                // trade wasn't sourced from manual_quick_trade, research capture
                // was disabled/unavailable, or the prediction itself was WAIT/
                // unavailable (no directional decision to disagree with). Never
                // used to alter the order actually placed.
                $table->boolean('manual_action_disagreed_with_prediction')->nullable()->default(null)->after('signal_payload');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            if (Schema::hasColumn('quick_trades', 'manual_action_disagreed_with_prediction')) {
                $table->dropColumn('manual_action_disagreed_with_prediction');
            }
        });
    }
};
