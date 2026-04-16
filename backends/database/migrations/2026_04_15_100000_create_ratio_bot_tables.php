<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // ratio_bot_configs
        // Operator-controlled settings per workspace + symbol.
        // ----------------------------------------------------------------
        Schema::create('ratio_bot_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('name')->default('Ratio Bot');
            $table->string('symbol', 32);           // e.g. BTCUSDT
            $table->string('exchange', 32)->default('binance');
            $table->string('market_type', 16)->default('spot');
            $table->boolean('enabled')->default(false);

            // Mode
            $table->string('mode', 32)->default('manual_approval');
            // advisor | manual_approval | restricted_auto
            $table->string('execution_mode', 16)->default('sandbox');
            // sandbox | live

            // Capital allocation
            $table->decimal('base_quote_amount', 20, 8)->default(0);
            $table->string('order_size_mode', 32)->default('fixed_quote');
            // fixed_quote | fixed_base | percent_of_balance
            $table->decimal('max_trade_size_pct', 6, 4)->default(0.10);
            $table->decimal('reserve_cash_buffer_pct', 6, 4)->default(0.05);

            // Core ratio thresholds
            $table->decimal('sell_threshold_ratio', 10, 6)->default(0.50);
            // e.g. 0.50 = sell when price gains 50% from buy
            $table->decimal('buy_threshold_ratio', 10, 6)->default(0.25);
            // e.g. 0.25 = rebuy when price drops 25% from last sell

            // Partial fills (reserved for future use, safe defaults)
            $table->boolean('allow_partial_sell')->default(false);
            $table->boolean('allow_partial_buy')->default(false);
            $table->decimal('partial_sell_pct', 6, 4)->nullable();
            $table->decimal('partial_buy_pct', 6, 4)->nullable();

            // Timing / trigger mode
            $table->string('evaluation_mode', 16)->default('polling');
            // polling | websocket | hybrid
            $table->integer('poll_interval_seconds')->default(300);
            // 60, 300, 600, 900 …
            $table->integer('cooldown_seconds_after_trade')->default(60);

            // Risk limits
            $table->decimal('daily_loss_limit_pct', 6, 4)->nullable();
            $table->decimal('max_drawdown_pct', 6, 4)->nullable();
            $table->integer('max_open_orders')->default(1);
            $table->decimal('max_position_pct', 6, 4)->default(1.00);
            $table->boolean('kill_switch_enabled')->default(true);
            $table->integer('pause_on_order_failure_streak')->default(3);
            $table->boolean('pause_on_slippage_spike')->default(true);
            $table->boolean('pause_on_exchange_unhealthy')->default(true);
            $table->boolean('pause_on_stale_feed')->default(true);

            // Stop-loss / take-profit guards (optional, bot-level)
            $table->boolean('require_stop_loss')->default(false);
            $table->boolean('require_take_profit')->default(false);
            $table->boolean('require_time_stop')->default(false);
            $table->decimal('stop_loss_pct', 6, 4)->nullable();
            $table->decimal('take_profit_pct', 6, 4)->nullable();
            $table->integer('time_stop_minutes')->nullable();

            // Connection reference
            $table->uuid('connection_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['workspace_id', 'symbol', 'name']);
        });

        // ----------------------------------------------------------------
        // ratio_bot_states
        // Live runtime state, anchor prices, position tracking.
        // One row per ratio_bot_config.
        // ----------------------------------------------------------------
        Schema::create('ratio_bot_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id')->unique()->index();

            // State machine
            $table->string('position_state', 32)->default('idle');
            // idle | holding | flat | paused | cooldown | blocked
            // | awaiting_execution_result | error_review_required

            // Anchor prices (updated after every confirmed trade)
            $table->decimal('last_buy_price', 20, 8)->nullable();
            $table->decimal('last_sell_price', 20, 8)->nullable();
            $table->decimal('last_buy_quantity', 20, 8)->nullable();
            $table->decimal('last_sell_quantity', 20, 8)->nullable();
            $table->string('last_trade_side', 8)->nullable();   // BUY | SELL
            $table->timestamp('last_trade_time')->nullable();

            // Active anchor (derived, persisted for clarity)
            $table->decimal('active_anchor_price', 20, 8)->nullable();
            $table->string('active_anchor_type', 16)->nullable();
            // buy_anchor | sell_anchor

            // Order idempotency tracking
            $table->string('last_executed_order_id')->nullable();
            $table->string('last_executed_client_order_id')->nullable();
            $table->integer('consecutive_order_failures')->default(0);

            // Pause / block reason
            $table->string('block_reason_code', 64)->nullable();
            $table->text('block_reason_detail')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();

            // Evaluation tracking
            $table->timestamp('last_evaluated_at')->nullable();
            $table->decimal('last_seen_price', 20, 8)->nullable();
            $table->decimal('current_ratio', 10, 6)->nullable();

            $table->timestamps();

            $table->foreign('ratio_bot_config_id')
                ->references('id')->on('ratio_bot_configs')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // ratio_bot_decisions
        // Every decision the engine makes (BUY / SELL / HOLD / PAUSE / BLOCK).
        // Full audit trail.
        // ----------------------------------------------------------------
        Schema::create('ratio_bot_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id')->index();
            $table->string('correlation_id', 64)->nullable()->index();

            $table->string('decision', 16);
            // BUY | SELL | HOLD | PAUSE | BLOCK | COOLDOWN

            $table->decimal('evaluated_price', 20, 8)->nullable();
            $table->decimal('anchor_price', 20, 8)->nullable();
            $table->string('anchor_type', 16)->nullable();
            $table->decimal('computed_ratio', 10, 6)->nullable();
            $table->decimal('threshold_used', 10, 6)->nullable();
            $table->string('position_state_before', 32)->nullable();
            $table->string('position_state_after', 32)->nullable();

            // Execution outcome
            $table->string('execution_status', 32)->nullable();
            // pending | submitted | filled | partially_filled
            // | failed | skipped | duplicate_blocked
            $table->string('order_id')->nullable();
            $table->string('client_order_id')->nullable();
            $table->decimal('executed_qty', 20, 8)->nullable();
            $table->decimal('executed_price', 20, 8)->nullable();

            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('decided_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // ratio_bot_events
        // Lifecycle events: paused, blocked, resumed, kill-switch, etc.
        // ----------------------------------------------------------------
        Schema::create('ratio_bot_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id')->index();

            $table->string('event_type', 64);
            // started | paused | resumed | stopped | blocked | unblocked
            // | kill_switch_activated | cooldown_entered | cooldown_exited
            // | order_failure | reconciliation_mismatch | feed_stale

            $table->text('detail')->nullable();
            $table->json('context')->nullable();
            $table->uuid('triggered_by_user_id')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_events');
        Schema::dropIfExists('ratio_bot_decisions');
        Schema::dropIfExists('ratio_bot_states');
        Schema::dropIfExists('ratio_bot_configs');
    }
};
