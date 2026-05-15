<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('future_trade_missed_signal_audits', function (Blueprint $table) {
            $table->decimal('take_profit_price', 20, 8)->nullable()->after('max_adverse_move_percent');
            $table->decimal('stop_loss_price', 20, 8)->nullable()->after('take_profit_price');
            $table->timestamp('tp_hit_time')->nullable()->after('stop_loss_price');
            $table->timestamp('sl_hit_time')->nullable()->after('tp_hit_time');
            $table->string('first_hit', 32)->nullable()->after('sl_hit_time');
            $table->decimal('estimated_fee_percent', 10, 4)->nullable()->after('would_hit_stop_loss');
            $table->decimal('estimated_slippage_percent', 10, 4)->nullable()->after('estimated_fee_percent');
            $table->decimal('gross_would_have_pnl_percent', 10, 4)->nullable()->after('estimated_slippage_percent');
            $table->decimal('net_would_have_pnl_percent', 10, 4)->nullable()->after('gross_would_have_pnl_percent');
        });
    }

    public function down(): void
    {
        Schema::table('future_trade_missed_signal_audits', function (Blueprint $table) {
            $table->dropColumn([
                'take_profit_price',
                'stop_loss_price',
                'tp_hit_time',
                'sl_hit_time',
                'first_hit',
                'estimated_fee_percent',
                'estimated_slippage_percent',
                'gross_would_have_pnl_percent',
                'net_would_have_pnl_percent',
            ]);
        });
    }
};
