<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('future_trade_logical_trades')) {
            return;
        }

        Schema::table('future_trade_logical_trades', function (Blueprint $table): void {
            foreach ([
                'entry_fee_actual', 'exit_fee_actual', 'funding_fee_actual',
                'entry_fill_price', 'exit_fill_price', 'entry_slippage_pct', 'exit_slippage_pct',
            ] as $column) {
                if (! Schema::hasColumn('future_trade_logical_trades', $column)) {
                    $table->decimal($column, 24, 10)->nullable();
                }
            }
            if (! Schema::hasColumn('future_trade_logical_trades', 'fee_source')) {
                $table->string('fee_source', 16)->default('estimated');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('future_trade_logical_trades')) {
            return;
        }
        Schema::table('future_trade_logical_trades', function (Blueprint $table): void {
            foreach ([
                'fee_source', 'exit_slippage_pct', 'entry_slippage_pct', 'exit_fill_price',
                'entry_fill_price', 'funding_fee_actual', 'exit_fee_actual', 'entry_fee_actual',
            ] as $column) {
                if (Schema::hasColumn('future_trade_logical_trades', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
