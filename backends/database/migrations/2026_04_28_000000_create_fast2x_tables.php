<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fast2x_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->unique()->index();
            $table->string('bot_name')->default('Fast2X');
            $table->string('symbol', 32)->default('BTCUSDT');
            $table->string('trade_mode', 16)->default('SPOT');
            $table->string('bot_mode', 32)->default('FAST2X');
            $table->boolean('paper_trading')->default(true);
            $table->boolean('enable_live_trading')->default(false);
            $table->boolean('ai_brain_enabled')->default(false);
            $table->text('algorithm_markdown')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('fast2x_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('fast2x_config_id')->unique()->index();
            $table->string('state', 32)->default('IDLE');
            $table->string('status', 32)->default('Stopped');
            $table->string('websocket_status', 32)->default('Disconnected');
            $table->string('decision', 64)->default('WAITING_FOR_SIGNAL');
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('current_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8)->default(0);
            $table->decimal('current_peak', 20, 8)->nullable();
            $table->decimal('current_bottom', 20, 8)->nullable();
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('unrealized_pnl_percent', 10, 6)->default(0);
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->decimal('net_pnl', 20, 8)->default(0);
            $table->decimal('net_pnl_percent', 10, 6)->default(0);
            $table->decimal('daily_profit', 20, 8)->default(0);
            $table->decimal('daily_loss', 20, 8)->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->unsignedInteger('trade_count_today')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('last_price_update_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->boolean('order_in_progress')->default(false);
            $table->string('last_signal_key')->nullable();
            $table->json('runtime_meta')->nullable();
            $table->timestamps();
        });

        Schema::create('fast2x_trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('fast2x_config_id')->index();
            $table->string('bot_name')->default('Fast2X');
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8)->default(0);
            $table->decimal('gross_pnl', 20, 8)->nullable();
            $table->decimal('fees', 20, 8)->default(0);
            $table->decimal('slippage', 20, 8)->default(0);
            $table->decimal('net_pnl', 20, 8)->nullable();
            $table->string('reason', 128)->nullable();
            $table->string('mode', 16)->default('PAPER');
            $table->string('status', 32)->default('FILLED');
            $table->string('order_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('fast2x_decision_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('fast2x_config_id')->index();
            $table->string('event', 96);
            $table->string('bot_name')->default('Fast2X');
            $table->string('symbol', 32);
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('current_price', 20, 8)->nullable();
            $table->decimal('net_pnl_percent', 10, 6)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fast2x_decision_logs');
        Schema::dropIfExists('fast2x_trades');
        Schema::dropIfExists('fast2x_states');
        Schema::dropIfExists('fast2x_configs');
    }
};
