<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'no_trade_alert_minutes')) {
                $table->integer('no_trade_alert_minutes')->nullable()->after('recovery_sell_rise_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'no_trade_alert_minutes')) {
                $table->dropColumn('no_trade_alert_minutes');
            }
        });
    }
};
