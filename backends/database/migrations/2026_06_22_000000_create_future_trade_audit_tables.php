<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_webhook_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->nullable()->index();
            $table->string('workspace_id', 64)->nullable()->index();
            $table->string('source', 32)->default('tradingview')->index();
            $table->timestamp('received_at')->index();
            $table->string('request_ip', 64)->nullable();
            $table->boolean('token_valid')->default(false)->index();
            $table->json('raw_payload')->nullable();
            $table->string('symbol', 32)->nullable()->index();
            $table->string('timeframe', 16)->nullable();
            $table->string('signal', 32)->nullable();
            $table->string('normalized_signal', 32)->nullable();
            $table->decimal('price', 24, 10)->nullable();
            $table->timestamp('bar_time')->nullable();
            $table->boolean('bar_confirmed')->nullable();
            $table->string('profile', 96)->nullable();
            $table->integer('latency_from_bar_close_ms')->nullable();
            $table->string('validation_status', 32)->default('received')->index();
            $table->string('reject_reason', 128)->nullable()->index();
            $table->string('decision', 32)->nullable()->index();
            $table->string('decision_reason', 191)->nullable();
            $table->integer('total_latency_ms')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('future_trade_order_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->nullable()->index();
            $table->uuid('future_trade_webhook_audit_id')->nullable()->index();
            $table->string('workspace_id', 64)->nullable()->index();
            $table->string('source', 32)->default('tradingview')->index();
            $table->timestamp('order_request_at')->index();
            $table->string('binance_symbol', 32)->nullable()->index();
            $table->string('side', 8)->nullable();
            $table->string('position_side', 16)->nullable();
            $table->decimal('quantity', 24, 10)->nullable();
            $table->decimal('leverage', 8, 2)->nullable();
            $table->string('margin_type', 24)->nullable();
            $table->boolean('reduce_only')->default(false);
            $table->string('order_type', 32)->nullable();
            $table->decimal('price', 24, 10)->nullable();
            $table->decimal('stop_loss', 24, 10)->nullable();
            $table->decimal('take_profit', 24, 10)->nullable();
            $table->decimal('trailing_stop', 24, 10)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('binance_order_id', 64)->nullable()->index();
            $table->string('client_order_id', 64)->nullable()->index();
            $table->string('execution_status', 32)->default('SUBMITTED')->index();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('robot_trade_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('robot_session_id')->nullable()->index();
            $table->string('workspace_id', 64)->nullable()->index();
            $table->string('symbol', 32)->nullable()->index();
            $table->string('timeframe', 16)->nullable();
            $table->timestamp('candle_open_time')->nullable()->index();
            $table->decimal('candle_open_price', 24, 10)->nullable();
            $table->decimal('current_price', 24, 10)->nullable();
            $table->decimal('candle_body_size', 24, 10)->nullable();
            $table->decimal('upper_wick', 24, 10)->nullable();
            $table->decimal('lower_wick', 24, 10)->nullable();
            $table->string('candle_direction', 16)->nullable();
            $table->string('previous_candle_direction', 16)->nullable();
            $table->string('trend_alignment', 32)->nullable();
            $table->boolean('noisy_candle')->default(false)->index();
            $table->string('robot_signal', 16)->default('WAIT')->index();
            $table->decimal('confidence_score', 8, 4)->nullable();
            $table->string('decision_reason', 191)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_trade_audits');
        Schema::dropIfExists('future_trade_order_audits');
        Schema::dropIfExists('future_trade_webhook_audits');
    }
};
