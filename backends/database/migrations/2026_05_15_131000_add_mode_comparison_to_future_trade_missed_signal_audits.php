<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('future_trade_missed_signal_audits', function (Blueprint $table) {
            $table->json('mode_comparison_json')->nullable()->after('market_snapshot_json');
        });
    }

    public function down(): void
    {
        Schema::table('future_trade_missed_signal_audits', function (Blueprint $table) {
            $table->dropColumn('mode_comparison_json');
        });
    }
};
