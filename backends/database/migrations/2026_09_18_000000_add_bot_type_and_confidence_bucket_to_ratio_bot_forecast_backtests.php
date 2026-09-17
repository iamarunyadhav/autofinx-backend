<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalizes `ratio_bot_forecast_backtests` (previously Ratio-Bot-only) so
 * `ForecastHorizonBacktestService::runForBot()` can track calibration for
 * Quick Trade / Robot Trade / Future Trade / Fast2X too, per the Unified
 * Forecast Engine plan's Phase 1 (foundation only -- no other engine is
 * wired to call runForBot() yet).
 *
 * Purely additive for existing Ratio Bot behavior:
 * - `ratio_bot_config_id` and its FK are kept (name, target, cascade) exactly
 *   as they are today -- only relaxed from NOT NULL to nullable, because
 *   non-ratio_bot rows (Quick Trade/Robot Trade/Future Trade/Fast2X, wired in
 *   a later task) have no `ratio_bot_configs` row to point at. Every existing
 *   consumer (`PredictionAccuracyService::forConfig()`,
 *   `ProductionReadinessService`, `RatioBotController::trendScore()`) filters
 *   BY `ratio_bot_config_id`, so relaxing nullability changes nothing for
 *   them -- they never see a null value for their own rows.
 * - `bot_type` defaults to 'ratio_bot' so every pre-existing row keeps its
 *   real meaning without a manual backfill of that column.
 * - `bot_instance_id` cannot stay nullable (plan correction #4: MySQL/MariaDB
 *   treats each NULL as distinct under a unique index, silently allowing
 *   duplicate (bot_type, NULL, horizon, bucket) rows) -- it's added nullable,
 *   backfilled from the existing `ratio_bot_config_id` value (the only
 *   sensible per-bot identity pre-existing rows have), then tightened to
 *   NOT NULL.
 * - `confidence_bucket` defaults to 'all', matching
 *   `ForecastHorizonBacktestService::runForConfig()`'s unchanged aggregate
 *   (non-bucketed) write path -- see that method's docblock for why Ratio
 *   Bot's own rows are deliberately NOT retroactively bucketed by confidence.
 *
 * The unique index widens from (ratio_bot_config_id, horizon_seconds) to
 * (bot_type, bot_instance_id, horizon_seconds, confidence_bucket) -- still
 * unique per (config, horizon) for existing 'ratio_bot'/'all' rows, since
 * bot_instance_id is backfilled 1:1 from ratio_bot_config_id for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->string('bot_type')->default('ratio_bot')->after('id');
            $table->string('bot_instance_id')->nullable()->after('bot_type');
            $table->string('confidence_bucket')->default('all')->after('horizon_seconds');
            $table->uuid('ratio_bot_config_id')->nullable()->change();
        });

        DB::table('ratio_bot_forecast_backtests')
            ->whereNull('bot_instance_id')
            ->update(['bot_instance_id' => DB::raw('ratio_bot_config_id')]);

        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->string('bot_instance_id')->nullable(false)->change();

            $table->dropUnique('rb_forecast_backtests_config_horizon_unique');
            $table->unique(
                ['bot_type', 'bot_instance_id', 'horizon_seconds', 'confidence_bucket'],
                'rb_forecast_backtests_bot_horizon_bucket_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->dropUnique('rb_forecast_backtests_bot_horizon_bucket_unique');
            $table->unique(['ratio_bot_config_id', 'horizon_seconds'], 'rb_forecast_backtests_config_horizon_unique');
        });

        Schema::table('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->uuid('ratio_bot_config_id')->nullable(false)->change();
            $table->dropColumn(['bot_type', 'bot_instance_id', 'confidence_bucket']);
        });
    }
};
