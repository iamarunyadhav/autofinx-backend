<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Trade Python ML — Phase 1 shadow learning loop.
 *
 * Purely additive: new tables only, no existing table is altered. All times
 * that participate in candle alignment are stored as UTC epoch milliseconds
 * (BIGINT) so no DATETIME/app-timezone cast can shift a candle boundary.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Validated, CLOSED Binance Futures klines re-downloaded from the
        // exchange. Never populated from market_ohlc (which can contain
        // forming bars) — this is the only training candle source.
        if (! Schema::hasTable('ml_candles')) {
            Schema::create('ml_candles', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 40);
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->unsignedBigInteger('open_time_ms');
                $table->unsignedBigInteger('close_time_ms');
                $table->decimal('open', 24, 10);
                $table->decimal('high', 24, 10);
                $table->decimal('low', 24, 10);
                $table->decimal('close', 24, 10);
                $table->decimal('volume', 30, 10);
                $table->decimal('quote_volume', 30, 10)->nullable();
                $table->unsignedInteger('trade_count')->nullable();
                $table->string('source', 60);
                $table->timestamp('fetched_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'symbol', 'timeframe', 'open_time_ms'], 'ml_candles_identity_unique');
                $table->index(['symbol', 'timeframe', 'open_time_ms'], 'ml_candles_series_idx');
            });
        }

        // Every rejected kline and every detected missing interval, with the
        // reason. Rejected rows never enter ml_candles, so never training.
        if (! Schema::hasTable('ml_candle_rejections')) {
            Schema::create('ml_candle_rejections', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 40);
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->unsignedBigInteger('open_time_ms')->default(0);
                $table->string('reason', 40);
                $table->json('payload')->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->boolean('resolved')->default(false);
                $table->timestamps();
                $table->unique(['provider', 'symbol', 'timeframe', 'open_time_ms', 'reason'], 'ml_candle_rejections_unique');
                $table->index(['symbol', 'timeframe', 'reason'], 'ml_candle_rejections_lookup');
            });
        }

        if (! Schema::hasTable('ml_backfill_checkpoints')) {
            Schema::create('ml_backfill_checkpoints', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 40);
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->unsignedBigInteger('range_from_ms');
                $table->unsignedBigInteger('range_to_ms');
                $table->unsignedBigInteger('cursor_ms');
                $table->string('status', 20)->default('RUNNING');
                $table->unsignedInteger('pages')->default(0);
                $table->unsignedInteger('rows_written')->default(0);
                $table->unsignedInteger('rows_rejected')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'symbol', 'timeframe', 'range_from_ms'], 'ml_backfill_checkpoint_unique');
            });
        }

        // Causal feature vector at anchor T = close time of the anchor candle.
        if (! Schema::hasTable('ml_feature_rows')) {
            Schema::create('ml_feature_rows', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->unsignedBigInteger('anchor_time_ms');
                $table->decimal('anchor_close', 24, 10);
                $table->string('feature_schema_version', 40);
                $table->json('features');
                $table->timestamps();
                $table->unique(['symbol', 'timeframe', 'anchor_time_ms', 'feature_schema_version'], 'ml_feature_rows_unique');
            });
        }

        // One label per symbol × horizon × anchor. PENDING until the exact
        // T+h candle close exists; never filled with a default/false value.
        if (! Schema::hasTable('ml_labels')) {
            Schema::create('ml_labels', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->unsignedSmallInteger('horizon_minutes');
                $table->unsignedBigInteger('anchor_time_ms');
                $table->unsignedBigInteger('target_time_ms');
                $table->decimal('anchor_close', 24, 10);
                $table->decimal('target_close', 24, 10)->nullable();
                $table->decimal('future_return', 20, 12)->nullable();
                $table->string('status', 16)->default('PENDING');
                $table->string('feature_schema_version', 40);
                $table->timestamp('matured_at')->nullable();
                $table->timestamps();
                $table->unique(['symbol', 'timeframe', 'horizon_minutes', 'anchor_time_ms'], 'ml_labels_unique');
                $table->index(['symbol', 'horizon_minutes', 'status', 'anchor_time_ms'], 'ml_labels_status_idx');
                $table->index(['status', 'target_time_ms'], 'ml_labels_maturation_idx');
            });
        }

        // Model registry: every training attempt is a row; nothing is ever
        // overwritten. Status: CANDIDATE → CHAMPION | REJECTED | FAILED;
        // a replaced champion becomes RETIRED (kept for rollback).
        if (! Schema::hasTable('ml_models')) {
            Schema::create('ml_models', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('model_version', 64)->nullable();
                $table->string('symbol', 30);
                $table->unsignedSmallInteger('horizon_minutes');
                $table->string('timeframe', 10);
                $table->string('feature_schema_version', 40);
                $table->string('status', 16);
                $table->unsignedBigInteger('training_window_from_ms')->nullable();
                $table->unsignedBigInteger('training_window_to_ms')->nullable();
                $table->unsignedInteger('total_samples')->default(0);
                $table->unsignedInteger('train_samples')->default(0);
                $table->unsignedInteger('holdout_samples')->default(0);
                $table->json('metrics')->nullable();
                $table->json('comparison')->nullable();
                $table->text('decision_reason')->nullable();
                $table->string('artifact_dir')->nullable();
                $table->json('artifact_checksums')->nullable();
                $table->uuid('previous_champion_id')->nullable();
                $table->timestamp('trained_at')->nullable();
                $table->timestamp('promoted_at')->nullable();
                $table->timestamp('retired_at')->nullable();
                $table->timestamps();
                $table->unique(['model_version'], 'ml_models_version_unique');
                $table->index(['symbol', 'horizon_minutes', 'status'], 'ml_models_scope_idx');
            });
        }

        // Exactly one champion pointer per symbol × horizon.
        if (! Schema::hasTable('ml_champions')) {
            Schema::create('ml_champions', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 30);
                $table->unsignedSmallInteger('horizon_minutes');
                $table->uuid('model_id');
                $table->uuid('previous_model_id')->nullable();
                $table->timestamps();
                $table->unique(['symbol', 'horizon_minutes'], 'ml_champions_scope_unique');
            });
        }

        // Every shadow prediction, later scored against the real T+h close.
        if (! Schema::hasTable('ml_shadow_predictions')) {
            Schema::create('ml_shadow_predictions', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 30);
                $table->unsignedSmallInteger('horizon_minutes');
                $table->unsignedBigInteger('anchor_time_ms');
                $table->unsignedBigInteger('target_time_ms');
                $table->uuid('model_id')->nullable();
                $table->string('model_version', 64)->nullable();
                $table->string('feature_schema_version', 40);
                $table->decimal('current_price', 24, 10);
                $table->decimal('predicted_return', 20, 12)->nullable();
                $table->decimal('predicted_price', 24, 10)->nullable();
                $table->decimal('predicted_low', 24, 10)->nullable();
                $table->decimal('predicted_high', 24, 10)->nullable();
                $table->decimal('buy_net_pct', 16, 8)->nullable();
                $table->decimal('sell_net_pct', 16, 8)->nullable();
                $table->string('decision', 8);
                $table->string('decision_reason', 60)->nullable();
                $table->json('cost_snapshot')->nullable();
                $table->string('status', 16)->default('PENDING');
                $table->decimal('actual_price', 24, 10)->nullable();
                $table->decimal('actual_return', 20, 12)->nullable();
                $table->boolean('direction_hit')->nullable();
                $table->decimal('abs_error_pct', 16, 8)->nullable();
                $table->decimal('decision_net_pct', 16, 8)->nullable();
                $table->timestamp('scored_at')->nullable();
                $table->timestamps();
                $table->unique(['symbol', 'horizon_minutes', 'anchor_time_ms'], 'ml_shadow_predictions_unique');
                $table->index(['symbol', 'horizon_minutes', 'status'], 'ml_shadow_predictions_status_idx');
                $table->index(['status', 'target_time_ms'], 'ml_shadow_predictions_scoring_idx');
            });
        }

        // Per-trade execution-feedback tier (Step 3.5). Separate table so the
        // quick_trades schema is untouched.
        if (! Schema::hasTable('ml_trade_feedback_tiers')) {
            Schema::create('ml_trade_feedback_tiers', function (Blueprint $table): void {
                $table->id();
                $table->uuid('quick_trade_id');
                $table->string('tier', 20);
                $table->json('reasons');
                $table->string('decision_source', 40)->nullable();
                $table->string('rules_version', 20);
                $table->timestamp('classified_at');
                $table->timestamps();
                $table->unique(['quick_trade_id'], 'ml_trade_feedback_tiers_trade_unique');
                $table->index(['tier'], 'ml_trade_feedback_tiers_tier_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_trade_feedback_tiers');
        Schema::dropIfExists('ml_shadow_predictions');
        Schema::dropIfExists('ml_champions');
        Schema::dropIfExists('ml_models');
        Schema::dropIfExists('ml_labels');
        Schema::dropIfExists('ml_feature_rows');
        Schema::dropIfExists('ml_backfill_checkpoints');
        Schema::dropIfExists('ml_candle_rejections');
        Schema::dropIfExists('ml_candles');
    }
};
