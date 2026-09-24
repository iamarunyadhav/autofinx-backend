<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-checklist (Phase 1, item 4) -- shadow records for fee-aware WAIT
 * decisions that nobody traded. Purely additive: a new table, nothing
 * existing is altered or dropped. Observation only; no trading path reads it.
 *
 * Dedupe: at most ONE row per (symbol, timeframe, expiry_seconds, decision
 * candle close), enforced by the unique index below and written with an
 * idempotent insertOrIgnore (QuickTradeWaitShadowService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_trade_wait_shadows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('exchange', 32);
            $table->string('symbol', 32);
            $table->string('timeframe', 16);
            $table->unsignedInteger('expiry_seconds');
            $table->unsignedBigInteger('decision_candle_close_ms');
            $table->timestamp('decision_candle_close_at')->nullable();
            // The forecast's own generated_at: the moment the hypothetical
            // entry would have happened, and the anchor the horizon counts
            // from (the same anchor ForecastOutcomeEvaluator uses). Always
            // set explicitly by QuickTradeWaitShadowService at insert time --
            // nullable() only to satisfy MySQL strict mode / ONLY_FULL_GROUP_BY's
            // explicit_defaults_for_timestamp, which rejects a NOT NULL
            // timestamp column with no explicit DEFAULT (SQLite, used by the
            // test suite, does not enforce this, so it passed tests but
            // failed against real MySQL).
            $table->timestamp('reference_at')->nullable();
            $table->unsignedBigInteger('target_ms');
            $table->string('mode', 16)->nullable();
            $table->string('direction', 16)->nullable();
            $table->string('trade_decision', 16)->nullable();
            $table->decimal('expiry_score', 10, 4)->nullable();
            $table->decimal('expected_move_pct', 12, 6)->nullable();
            $table->decimal('required_move_pct', 12, 6)->nullable();
            $table->decimal('max_achievable_move_pct', 12, 6)->nullable();
            $table->string('gate_reason', 64)->nullable();
            $table->decimal('reference_price', 24, 10)->nullable();
            $table->string('reference_price_source', 64)->nullable();
            $table->unsignedInteger('horizon_seconds')->nullable();
            $table->unsignedInteger('horizon_candles')->nullable();
            $table->json('cost_model')->nullable();
            // The hypothetical position size the USDT outcome is quoted on
            // (config quick_trade.shadow_untraded_waits_reference_notional_usdt)
            // -- untraded decisions have no real stake of their own.
            $table->decimal('reference_notional_usdt', 18, 8);
            // pending | evaluated | not_applicable | unavailable | missing_horizon_sample
            $table->string('status', 32)->default('pending');
            $table->decimal('expiry_price', 24, 10)->nullable();
            $table->bigInteger('expiry_sample_delay_ms')->nullable();
            $table->decimal('gross_move_pct', 12, 6)->nullable();
            $table->decimal('hypothetical_gross_usdt', 18, 8)->nullable();
            $table->decimal('hypothetical_cost_usdt', 18, 8)->nullable();
            $table->decimal('hypothetical_net_usdt', 18, 8)->nullable();
            $table->decimal('wait_saved_usdt', 18, 8)->nullable();
            $table->decimal('wait_missed_usdt', 18, 8)->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'timeframe', 'expiry_seconds', 'decision_candle_close_ms'], 'qt_wait_shadow_decision_unique');
            $table->index(['status', 'target_ms'], 'qt_wait_shadow_due_idx');
            $table->index('created_at', 'qt_wait_shadow_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_trade_wait_shadows');
    }
};
