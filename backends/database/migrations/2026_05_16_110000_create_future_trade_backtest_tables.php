<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_backtest_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('future_trade_bot_config_id')->nullable()->index();
            $table->string('status', 16)->default('pending'); // pending|running|completed|failed
            $table->date('from_date');
            $table->date('to_date');
            $table->json('symbols')->nullable();
            $table->json('settings_snapshot')->nullable();
            $table->decimal('initial_capital_usdt', 12, 2)->default(1000);

            // Results
            $table->unsignedInteger('total_trades')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->decimal('win_rate', 5, 4)->default(0);
            $table->decimal('total_pnl_usdt', 12, 4)->default(0);
            $table->decimal('total_pnl_percent', 8, 4)->default(0);
            $table->decimal('max_drawdown_usdt', 12, 4)->default(0);
            $table->decimal('max_drawdown_percent', 8, 4)->default(0);
            $table->decimal('profit_factor', 8, 4)->default(0);
            $table->decimal('sharpe_ratio', 8, 4)->default(0);
            $table->unsignedInteger('avg_trade_duration_minutes')->default(0);
            $table->json('by_symbol')->nullable();
            $table->json('by_exit_reason')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('future_trade_backtest_trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_backtest_run_id')->index();
            $table->string('symbol', 32);
            $table->string('side', 8);      // LONG|SHORT
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('quantity', 20, 8)->default(0);
            $table->decimal('leverage', 8, 2)->default(1);
            $table->decimal('gross_pnl', 12, 4)->nullable();
            $table->decimal('fees', 12, 4)->default(0);
            $table->decimal('net_pnl', 12, 4)->nullable();
            $table->string('entry_signal', 16)->default('BUY');
            $table->decimal('entry_confidence', 6, 4)->default(0);
            $table->string('exit_reason', 64)->nullable();
            $table->timestamp('entry_time');
            $table->timestamp('exit_time')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_backtest_trades');
        Schema::dropIfExists('future_trade_backtest_runs');
    }
};
