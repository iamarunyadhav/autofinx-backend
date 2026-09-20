<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fast2x_states', function (Blueprint $table) {
            // Written every loop iteration by `fast2x:run`, independent of
            // whether a tick was actually fetched/processed — this is the
            // "is the OS process still spinning" signal `fast2x:supervise`
            // uses to detect a crashed/hung daemon. Deliberately separate
            // from `last_price_update_at`, which only advances when a real
            // tick is fetched and can legitimately go stale during a
            // Binance-side outage even while the process itself is healthy.
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_price_update_at');
        });
    }

    public function down(): void
    {
        Schema::table('fast2x_states', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });
    }
};
