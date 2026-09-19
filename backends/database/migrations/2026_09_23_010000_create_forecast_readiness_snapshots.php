<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time readiness snapshots (forecast:evaluate-readiness command).
 * Distinct from `ratio_bot_forecast_backtests` (per-confidence-bucket
 * calibration rows written by ForecastHorizonBacktestService) -- this table
 * stores the AGGREGATE verdict `ForecastProductionReadinessReport::generate()`
 * produces for a whole (bot_type, bot_instance_id, horizon_seconds) scope,
 * once per scheduled run, so status-over-time (e.g. VALIDATED ->
 * NOT_TRADEABLE) can be queried without recomputing the report. Read-only
 * reporting table -- never read by any live trading/execution path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_readiness_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_type', 32)->index();
            $table->string('bot_instance_id', 191);
            $table->unsignedInteger('horizon_seconds');
            $table->string('status', 32)->index();
            $table->json('reasons')->nullable();
            $table->json('report_json');
            // Microsecond precision (matches audit_research's occurred_at/
            // received_at convention) -- two runs of the scheduled command
            // can otherwise tie at whole-second resolution, making
            // "immediately preceding snapshot" ambiguous for status-change
            // detection.
            $table->timestamp('computed_at', 6)->index();
            $table->timestamps();

            $table->index(['bot_type', 'bot_instance_id', 'horizon_seconds', 'computed_at'], 'forecast_readiness_snapshots_scope_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_readiness_snapshots');
    }
};
