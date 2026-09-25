<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ratio Bot trade-level accuracy tracking (observation only).
 *
 * One row per REAL filled spot BUY/SELL RatioBotDecision, written by
 * RatioBotTradeOutcomeService::captureBuyFill()/captureSellFill() from
 * RatioBotStateService::onBuyFilled()/onSellFilled() (the single choke
 * point every fill path -- live sync, sandbox, async order-update --
 * converges on). Scored later, lazily, by the same service from
 * RunRatioBotEvaluationJob's existing cadence.
 *
 * Purely additive: a new table, nothing existing is altered or dropped.
 * No trading path reads it.
 *
 * Idempotency: at most ONE row per ratio_bot_decision_id (unique index).
 *
 * MySQL/MariaDB strict-mode audit: every timestamp column below is
 * nullable() -- `timestamp NOT NULL` without an explicit DEFAULT is
 * rejected by real MariaDB (explicit_defaults_for_timestamp) even though
 * the SQLite test suite silently accepts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratio_bot_trade_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id');
            $table->uuid('ratio_bot_decision_id');
            // Stage-plan lot this BUY created (RatioBotLot.buy_decision_id),
            // or the merged recovery-average lot it was folded into. Null for
            // legacy (non-stage-plan) bots, which keep no lot rows.
            $table->uuid('ratio_bot_lot_id')->nullable();
            $table->string('side', 8); // BUY | SELL
            $table->string('execution_mode', 16)->nullable(); // live | sandbox | ...
            $table->string('trigger', 64)->nullable(); // decision metadata 'trigger'
            $table->string('lot_type', 24)->nullable();
            $table->unsignedSmallInteger('stage_index')->nullable();
            $table->unsignedInteger('cycle_number')->nullable();
            $table->boolean('is_recovery_lot')->default(false);
            $table->boolean('protective_exit')->default(false);
            $table->string('order_id', 128)->nullable();

            // Price-path source snapshot (bot symbol/timeframe can change later).
            $table->string('exchange', 32);
            $table->string('symbol', 32);
            $table->string('timeframe', 16);

            $table->decimal('executed_price', 24, 10);
            $table->decimal('executed_qty', 24, 10);
            $table->timestamp('executed_at')->nullable();

            // Cost snapshot taken at fill time (config edits later never
            // re-interpret an already-captured trade).
            $table->decimal('cost_ratio', 14, 10)->nullable();
            $table->decimal('min_net_profit_ratio', 14, 10)->nullable();
            // BUY: exit ratio above entry the lot needed to clear net of costs.
            $table->decimal('required_exit_ratio', 14, 10)->nullable();
            // SELL: weighted cost basis of the lots this sell consumed.
            $table->decimal('cost_basis_price', 24, 10)->nullable();
            $table->decimal('realized_net_ratio', 14, 10)->nullable();
            $table->boolean('net_profitable')->nullable();

            $table->unsignedInteger('horizon_seconds');
            $table->timestamp('window_ends_at')->nullable();
            $table->timestamp('evaluate_after')->nullable();
            $table->timestamp('next_evaluation_at')->nullable();

            // pending | evaluated | insufficient_data | not_applicable
            $table->string('evaluation_status', 24)->default('pending');
            $table->string('status_reason', 64)->nullable();
            // BUY: recovered_net_profitable | not_recovered_within_horizon
            // SELL: well_timed | sold_early
            $table->string('verdict', 40)->nullable();
            $table->boolean('decision_correct')->nullable();

            $table->decimal('best_price', 24, 10)->nullable();   // max high in window
            $table->decimal('worst_price', 24, 10)->nullable();  // min low in window
            $table->decimal('best_net_edge_ratio', 14, 10)->nullable(); // BUY
            $table->decimal('max_adverse_ratio', 14, 10)->nullable();   // BUY
            $table->decimal('missed_edge_ratio', 14, 10)->nullable();   // SELL
            $table->decimal('avoided_drawdown_ratio', 14, 10)->nullable(); // SELL
            $table->timestamp('reached_floor_at')->nullable();
            $table->unsignedInteger('candles_observed')->nullable();
            $table->unsignedInteger('candles_expected')->nullable();
            $table->unsignedInteger('evaluation_attempts')->default(0);
            $table->timestamp('evaluated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('ratio_bot_decision_id', 'rb_trade_outcome_decision_unique');
            $table->index(['ratio_bot_config_id', 'evaluation_status', 'next_evaluation_at'], 'rb_trade_outcome_due_idx');
            $table->index(['ratio_bot_config_id', 'side', 'executed_at'], 'rb_trade_outcome_side_idx');

            $table->foreign('ratio_bot_config_id')
                ->references('id')->on('ratio_bot_configs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_trade_outcomes');
    }
};
