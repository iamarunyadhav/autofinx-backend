<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('robot_sessions', 'bot_mode')) {
                $table->string('bot_mode', 24)->default('timed_expiry')->index();
            }
            if (! Schema::hasColumn('robot_sessions', 'stop_loss_pct')) {
                $table->decimal('stop_loss_pct', 8, 4)->default(20.0);
            }
            if (! Schema::hasColumn('robot_sessions', 'take_profit_pct')) {
                $table->decimal('take_profit_pct', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('robot_sessions', 'multi_symbol')) {
                $table->boolean('multi_symbol')->default(false);
            }
            if (! Schema::hasColumn('robot_sessions', 'symbol_list')) {
                $table->json('symbol_list')->nullable();
            }
            if (! Schema::hasColumn('robot_sessions', 'peak_profit')) {
                $table->decimal('peak_profit', 20, 8)->default(0);
            }
            if (! Schema::hasColumn('robot_sessions', 'active_side')) {
                $table->string('active_side', 8)->nullable();
            }
            if (! Schema::hasColumn('robot_sessions', 'active_entry_price')) {
                $table->decimal('active_entry_price', 20, 8)->nullable();
            }
            if (! Schema::hasColumn('robot_sessions', 'active_since')) {
                $table->dateTime('active_since')->nullable();
            }
            if (! Schema::hasColumn('robot_sessions', 'trade_stats_json')) {
                $table->json('trade_stats_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table) {
            foreach ([
                'bot_mode',
                'stop_loss_pct',
                'take_profit_pct',
                'multi_symbol',
                'symbol_list',
                'peak_profit',
                'active_side',
                'active_entry_price',
                'active_since',
                'trade_stats_json',
            ] as $column) {
                if (Schema::hasColumn('robot_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
