<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive support for holdout validation (Unified Forecast Engine plan,
 * Phase 2 -- "Holdout validation" task). `ForecastHorizonBacktestService`
 * gained `runForBotWithHoldout()`, which needs to persist TWO rows per
 * (bot_type, bot_instance_id, horizon_seconds, confidence_bucket) instead of
 * one -- a `training` row (calibration-only) and a `holdout` row (scored
 * independently, out-of-sample) -- without colliding under the existing
 * unique index, and without reinterpreting any row already written by
 * `runForConfig()`/`runForBot()` before this migration existed.
 *
 * - `evaluation_period` defaults to `'full'` so every pre-existing row (all
 *   of which were computed over one continuous window, neither a true
 *   training-only nor holdout-only slice) keeps an honest, distinct label --
 *   it is NOT retroactively relabeled as `training` or `holdout`, since
 *   doing so would claim an out-of-sample guarantee that was never actually
 *   enforced for those rows.
 * - The unique index widens from
 *   (bot_type, bot_instance_id, horizon_seconds, confidence_bucket) to also
 *   include `evaluation_period`. This is still unique per (bot, horizon,
 *   bucket) for every pre-existing row, because they all share the same
 *   `evaluation_period = 'full'` value -- nothing already written becomes a
 *   duplicate under the new, wider index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->string('evaluation_period')->default('full')->after('confidence_bucket');
        });

        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->dropUnique('rb_forecast_backtests_bot_horizon_bucket_unique');
            $table->unique(
                ['bot_type', 'bot_instance_id', 'horizon_seconds', 'confidence_bucket', 'evaluation_period'],
                'rb_forecast_backtests_bot_horizon_bucket_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->dropUnique('rb_forecast_backtests_bot_horizon_bucket_period_unique');
            $table->unique(
                ['bot_type', 'bot_instance_id', 'horizon_seconds', 'confidence_bucket'],
                'rb_forecast_backtests_bot_horizon_bucket_unique',
            );
        });

        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->dropColumn('evaluation_period');
        });
    }
};
