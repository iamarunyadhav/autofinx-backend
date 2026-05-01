<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_bot_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->unique()->index();
            $table->string('bot_name')->default('Future Trade Bot');
            $table->boolean('paper_trading')->default(true);
            $table->boolean('enable_live_trading')->default(false);
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->text('algorithm_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('future_trade_bot_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->unique()->index();
            $table->string('status', 32)->default('Stopped');
            $table->string('state', 32)->default('IDLE');
            $table->string('connection_status', 32)->default('Disconnected');
            $table->string('current_symbol', 32)->nullable();
            $table->string('current_signal', 32)->default('WAITING_FOR_SIGNAL');
            $table->decimal('current_price', 20, 8)->nullable();
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8)->default(0);
            $table->string('position_side', 16)->nullable();
            $table->decimal('peak_price', 20, 8)->nullable();
            $table->decimal('bottom_price', 20, 8)->nullable();
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('unrealized_pnl_percent', 10, 6)->default(0);
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->decimal('daily_profit', 20, 8)->default(0);
            $table->decimal('daily_loss', 20, 8)->default(0);
            $table->unsignedInteger('trade_count_today')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->decimal('confidence', 6, 4)->default(0);
            $table->timestamp('last_scan_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->json('scanner_snapshot')->nullable();
            $table->json('chart_points')->nullable();
            $table->timestamps();
        });

        Schema::create('future_trade_bot_trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->index();
            $table->string('symbol', 32);
            $table->string('side', 16);
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8)->default(0);
            $table->decimal('leverage', 8, 2)->default(1);
            $table->decimal('gross_pnl', 20, 8)->nullable();
            $table->decimal('fees', 20, 8)->default(0);
            $table->decimal('net_pnl', 20, 8)->nullable();
            $table->string('reason', 128)->nullable();
            $table->string('mode', 16)->default('PAPER');
            $table->string('status', 32)->default('FILLED');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('future_trade_bot_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->index();
            $table->string('event', 96);
            $table->string('symbol', 32)->nullable();
            $table->string('signal', 32)->nullable();
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('confidence', 6, 4)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_bot_logs');
        Schema::dropIfExists('future_trade_bot_trades');
        Schema::dropIfExists('future_trade_bot_states');
        Schema::dropIfExists('future_trade_bot_configs');
    }
};
