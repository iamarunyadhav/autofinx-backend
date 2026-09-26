<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VNext Dynamic Decision Engine, Phase A -- lightweight, read-only audit log
 * for the 3-window (short/medium/long) forecast comparison
 * (App\Domain\Trading\MultiWindowForecastComparisonService).
 *
 * Purely additive: a new table, nothing existing is altered or dropped. No
 * trading/decision/execution path reads this table.
 *
 * Base columns are exactly the minimal set specified for this feature: id,
 * correlation_id, symbol, exchange, window_label, timeframe, expiry_seconds,
 * direction, trade_decision, expiry_score, expected_move_pct,
 * required_move_pct, cost_model, generated_at, created_at (+ updated_at,
 * which Blueprint::timestamps() always pairs with created_at).
 *
 * A small number of additional columns beyond that minimal set are included
 * because Phase A item 3 (per-window accuracy report) explicitly requires
 * hindsight evaluation, and there is nowhere else to store that outcome for
 * a forecast that was never attached to a real placed trade (unlike
 * QuickTrade, which has its own settlement row). These mirror the
 * established `quick_trade_wait_shadows` pattern verbatim (same target_ms /
 * status / evaluated_at shape) rather than inventing a new one:
 * reference_price, workspace_id, mode, target_ms, status, actual_price,
 * direction_correct, hypothetical_net_usdt, evaluated_at.
 *
 * MySQL-strict-mode-safe (explicit_defaults_for_timestamp): every timestamp
 * column is nullable with no bare NOT NULL-without-DEFAULT definition --
 * following the documented fix in 2026_09_30_000000_create_quick_trade_wait_shadows
 * (error 1067 on real MariaDB otherwise). SQLite (used by the test suite)
 * does not enforce this, so a bare NOT NULL timestamp would pass tests while
 * still failing against real MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multi_window_forecast_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Links the 3 rows (short/medium/long) produced by ONE
            // comparison request. Not a foreign key -- purely a grouping
            // label, exactly like RatioBot's correlation_id convention.
            $table->uuid('correlation_id');
            $table->string('symbol', 32);
            $table->string('exchange', 32);
            // short | medium | long
            $table->string('window_label', 16);
            $table->string('timeframe', 16);
            $table->unsignedInteger('expiry_seconds');
            // BUY | SELL | NEUTRAL -- TradeForecastService::forecast()'s
            // cost-blind `direction.value` / legacy `bias`.
            $table->string('direction', 16)->nullable();
            // BUY | SELL | WAIT -- the fee-aware `trade_decision.value` /
            // legacy `decision_trend`.
            $table->string('trade_decision', 16)->nullable();
            $table->decimal('expiry_score', 10, 4)->nullable();
            $table->decimal('expected_move_pct', 12, 6)->nullable();
            $table->decimal('required_move_pct', 12, 6)->nullable();
            $table->json('cost_model')->nullable();
            $table->timestamp('generated_at')->nullable();

            // --- additive, for the Phase A item 3 accuracy report --------
            $table->decimal('reference_price', 24, 10)->nullable();
            $table->uuid('workspace_id')->nullable();
            $table->string('mode', 16)->nullable();
            // generated_at + expiry_seconds, in epoch ms -- when the
            // hindsight evaluation for this window becomes due.
            $table->unsignedBigInteger('target_ms')->nullable();
            // captured | pending | evaluated | not_applicable |
            // missing_horizon_sample | unavailable
            $table->string('status', 32)->default('captured');
            $table->decimal('actual_price', 24, 10)->nullable();
            $table->boolean('direction_correct')->nullable();
            $table->decimal('hypothetical_net_usdt', 18, 8)->nullable();
            $table->timestamp('evaluated_at')->nullable();

            $table->timestamps();

            // One row per window per comparison request -- capture is an
            // idempotent insertOrIgnore against this key, so re-polling the
            // same in-flight comparison never duplicates a row.
            $table->unique(['correlation_id', 'window_label'], 'mwf_audit_correlation_window_unique');
            $table->index(['status', 'target_ms'], 'mwf_audit_due_idx');
            $table->index(['symbol', 'window_label', 'created_at'], 'mwf_audit_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multi_window_forecast_audits');
    }
};
