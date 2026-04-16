<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_intelligence_configs')) {
            Schema::create('portfolio_intelligence_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->unique()->index();
                $table->boolean('enabled')->default(false);
                $table->string('ranking_mode', 32)->default('heuristic');
                $table->string('allocation_mode', 32)->default('capped_score_weighted');
                $table->decimal('opportunity_score_threshold', 10, 4)->default(65);
                $table->decimal('max_total_allocated_pct', 10, 4)->default(35);
                $table->decimal('max_symbol_allocated_pct', 10, 4)->default(8);
                $table->decimal('max_correlated_group_allocated_pct', 10, 4)->default(15);
                $table->unsignedInteger('max_simultaneous_new_trades')->default(3);
                $table->decimal('reserve_cash_buffer_pct', 10, 4)->default(15);
                $table->unsignedInteger('max_open_positions')->nullable();
                $table->unsignedInteger('max_open_decision_count')->nullable();
                $table->decimal('max_total_notional', 20, 8)->nullable();
                $table->decimal('max_daily_loss_pct', 10, 4)->nullable();
                $table->decimal('max_drawdown_pct', 10, 4)->nullable();
                $table->boolean('prefer_hold_cash_under_high_uncertainty')->default(true);
                $table->boolean('reduce_size_under_external_risk')->default(true);
                $table->boolean('require_approval_for_rotation')->default(true);
                $table->boolean('enable_reduce_recommendations')->default(true);
                $table->boolean('enable_rotation_recommendations')->default(false);
                $table->unsignedInteger('max_universe_symbols')->default(20);
                $table->json('score_weights_json')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('portfolio_intelligence_runs')) {
            Schema::create('portfolio_intelligence_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('portfolio_intelligence_config_id')->nullable()->index();
                $table->string('trigger_type', 32)->default('manual')->index();
                $table->string('status', 32)->default('completed')->index();
                $table->string('correlation_id', 100)->unique();
                $table->json('symbol_universe_json')->nullable();
                $table->json('portfolio_context_json')->nullable();
                $table->json('ranked_opportunities_json')->nullable();
                $table->json('allocation_json')->nullable();
                $table->json('risk_budget_json')->nullable();
                $table->json('recommendations_json')->nullable();
                $table->json('selection_summary_json')->nullable();
                $table->json('config_snapshot_json')->nullable();
                $table->json('external_context_json')->nullable();
                $table->decimal('equity_estimate', 20, 8)->nullable();
                $table->decimal('allocated_capital', 20, 8)->nullable();
                $table->decimal('free_capital', 20, 8)->nullable();
                $table->unsignedInteger('selected_count')->default(0);
                $table->unsignedInteger('blocked_count')->default(0);
                $table->unsignedInteger('paused_count')->default(0);
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('portfolio_intelligence_config_id')->references('id')->on('portfolio_intelligence_configs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_intelligence_runs');
        Schema::dropIfExists('portfolio_intelligence_configs');
    }
};
