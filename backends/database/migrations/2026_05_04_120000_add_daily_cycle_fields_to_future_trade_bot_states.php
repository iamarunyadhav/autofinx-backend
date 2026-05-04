<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('future_trade_bot_states', function (Blueprint $table) {
            $table->date('trading_day')->nullable()->after('cooldown_until');
            $table->timestamp('daily_stop_until')->nullable()->after('trading_day');
        });
    }

    public function down(): void
    {
        Schema::table('future_trade_bot_states', function (Blueprint $table) {
            $table->dropColumn(['trading_day', 'daily_stop_until']);
        });
    }
};
