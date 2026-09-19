<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive companion table to `ratio_bot_forecast_backtests` (Unified
 * Forecast Engine plan -- "exact Brier score" task). That table only ever
 * stores BUCKET-LEVEL aggregates (sample_size/hits/hit_rate_pct/
 * avg_confidence_pct) per (bot_type, bot_instance_id, horizon_seconds,
 * confidence_bucket, evaluation_period) -- it is NOT touched, removed, or
 * reshaped by this migration; every existing consumer
 * (ForecastModelPromotionGate/ForecastMetricsService/ForecastCalibration-
 * QualityService's approximated path/existing tests) keeps working exactly
 * as before.
 *
 * This new table stores one row per INDIVIDUAL walk-forward prediction
 * (`ForecastHorizonBacktestService::walkForward()`'s in-memory records,
 * now additionally persisted instead of only ever existing transiently) so
 * a true, non-approximated Brier score and reliability curve can be
 * computed later from real per-prediction confidence values instead of a
 * bucket's shared average.
 *
 * No FK to ratio_bot_configs (unlike the older table) -- bot_type here can
 * be 'ratio_bot', 'quick_trade', 'robot_trade', 'future_trade', or 'fast2x'
 * (see ForecastHorizonBacktestService's outcome-measurement-contracts
 * docblock), most of which have no ratio_bot_configs row to point at.
 *
 * Retention note (documented, not implemented in this task -- see this
 * session's handback report for the full reasoning): every walk-forward
 * run currently DELETES then re-inserts its own
 * (bot_type, bot_instance_id, horizon_seconds, evaluation_period) scope's
 * rows (mirrors the existing bucket table's updateOrCreate-overwrite
 * semantics), so repeated re-runs of the SAME scope do not accumulate
 * unbounded rows. What is NOT bounded yet: the number of DISTINCT scopes
 * (many symbols x many horizons x many bot instances), which could still
 * grow large in production over time. A time-based or scope-count-based
 * pruning job is a reasonable follow-up, not implemented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_backtest_predictions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('bot_type');
            $table->string('bot_instance_id');
            $table->integer('horizon_seconds');
            // Matches RatioBotForecastBacktest's evaluation_period values
            // exactly ('training' | 'holdout' | 'full') -- not enforced as
            // a DB enum (this codebase does not use DB-level enums for this
            // column on the sibling table either), just a documented
            // string contract shared with that table.
            $table->string('evaluation_period')->default('full');
            // The REAL per-prediction confidence (not a bucket average) --
            // the entire point of this table.
            $table->decimal('confidence_pct', 5, 2);
            $table->boolean('predicted_hit');
            // The timestamp of the candle the prediction was made FROM
            // (i.e. the last candle in walkForward()'s no-lookahead slice
            // at that index) -- preserves chronological ordering for
            // auditing the no-lookahead guarantee independently of
            // insertion order.
            $table->timestamp('candle_ts');
            // Groups every prediction written by one walkForward() call
            // together, purely for debugging/auditing/future pruning --
            // no code currently queries by this column alone.
            $table->uuid('backtest_run_id')->nullable();
            $table->timestamps();

            $table->index(
                ['bot_type', 'bot_instance_id', 'horizon_seconds', 'evaluation_period'],
                'forecast_backtest_predictions_scope_idx',
            );
            $table->index('candle_ts', 'forecast_backtest_predictions_candle_ts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_backtest_predictions');
    }
};
