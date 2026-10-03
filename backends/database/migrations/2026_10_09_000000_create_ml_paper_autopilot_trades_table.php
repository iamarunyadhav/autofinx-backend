<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ML Paper Autopilot — simulated trades that follow the shadow champion's
 * BUY/SELL decisions. A self-contained table with its own virtual balance:
 * it is NOT quick_trades, NOT a wallet, and nothing here is ever an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ml_paper_autopilot_trades')) {
            return;
        }
        Schema::create('ml_paper_autopilot_trades', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prediction_id');
            $table->string('symbol', 30);
            $table->unsignedSmallInteger('horizon_minutes');
            $table->uuid('model_id')->nullable();
            $table->string('model_version', 64)->nullable();
            $table->string('model_type', 24)->nullable();
            $table->string('side', 4);
            $table->decimal('p_buy', 8, 6)->nullable();
            $table->decimal('p_sell', 8, 6)->nullable();
            $table->decimal('expected_net_pct', 16, 8)->nullable();
            $table->unsignedBigInteger('signal_time_ms');
            $table->decimal('signal_price', 24, 10);
            $table->unsignedBigInteger('entry_time_ms');
            $table->decimal('entry_price', 24, 10);
            $table->string('entry_price_source', 32);
            $table->unsignedBigInteger('expires_at_ms');
            $table->unsignedBigInteger('exit_time_ms')->nullable();
            $table->decimal('exit_price', 24, 10)->nullable();
            $table->string('exit_price_source', 32)->nullable();
            $table->decimal('stake_usd', 16, 4);
            $table->decimal('cost_pct', 10, 6);
            $table->decimal('funding_buy_pct', 16, 8)->nullable();
            $table->decimal('gross_return_pct', 16, 8)->nullable();
            $table->decimal('net_return_pct', 16, 8)->nullable();
            $table->decimal('pnl_usd', 16, 6)->nullable();
            $table->string('status', 12)->default('OPEN');
            $table->string('outcome', 8)->nullable();
            $table->string('close_reason', 60)->nullable();
            $table->timestamps();
            $table->unique(['prediction_id'], 'ml_paper_autopilot_prediction_unique');
            $table->index(['symbol', 'horizon_minutes', 'status'], 'ml_paper_autopilot_scope_idx');
            $table->index(['status', 'expires_at_ms'], 'ml_paper_autopilot_settle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_paper_autopilot_trades');
    }
};
