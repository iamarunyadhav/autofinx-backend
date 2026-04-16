<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_trading_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_trading_configs', 'decision_interval_minutes')) {
                $table->unsignedInteger('decision_interval_minutes')->default(5)->after('max_decisions_per_hour_per_symbol');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'enabled_symbols')) {
                $table->json('enabled_symbols')->nullable()->after('blocked_symbols');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'max_decisions_per_hour')) {
                $table->unsignedInteger('max_decisions_per_hour')->default(60)->after('decision_interval_minutes');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'cooldown_minutes_after_trade')) {
                $table->unsignedInteger('cooldown_minutes_after_trade')->default(0)->after('max_decisions_per_hour');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'cooldown_minutes_after_loss')) {
                $table->unsignedInteger('cooldown_minutes_after_loss')->default(0)->after('cooldown_minutes_after_trade');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'pause_until')) {
                $table->timestamp('pause_until')->nullable()->after('cooldown_minutes_after_loss');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'auto_pause_on_daily_loss')) {
                $table->boolean('auto_pause_on_daily_loss')->default(true)->after('pause_until');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'auto_pause_on_drawdown')) {
                $table->boolean('auto_pause_on_drawdown')->default(true)->after('auto_pause_on_daily_loss');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'auto_pause_on_order_failure_streak')) {
                $table->unsignedInteger('auto_pause_on_order_failure_streak')->default(3)->after('auto_pause_on_drawdown');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'auto_pause_on_slippage_spike')) {
                $table->boolean('auto_pause_on_slippage_spike')->default(true)->after('auto_pause_on_order_failure_streak');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'auto_pause_on_reconciliation_mismatch')) {
                $table->boolean('auto_pause_on_reconciliation_mismatch')->default(true)->after('auto_pause_on_slippage_spike');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'manual_resume_required')) {
                $table->boolean('manual_resume_required')->default(false)->after('auto_pause_on_reconciliation_mismatch');
            }
        });

        Schema::create('ai_trading_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('symbol', 32)->nullable()->index();
            $table->string('scope_key', 120)->unique();
            $table->string('status', 32)->default('active')->index();
            $table->string('reason_code', 64)->nullable()->index();
            $table->string('reason_detail')->nullable();
            $table->timestamp('pause_until')->nullable()->index();
            $table->timestamp('cooldown_until')->nullable()->index();
            $table->boolean('manual_resume_required')->default(false);
            $table->timestamp('last_cycle_at')->nullable()->index();
            $table->timestamp('last_trade_at')->nullable()->index();
            $table->timestamp('last_loss_at')->nullable();
            $table->timestamp('last_order_failed_at')->nullable();
            $table->unsignedInteger('consecutive_order_failures')->default(0);
            $table->decimal('last_slippage_pct', 10, 4)->nullable();
            $table->json('anomaly_snapshot_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('ai_decision_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('correlation_id', 150)->unique();
            $table->uuid('workspace_id')->index();
            $table->uuid('ai_trading_config_id')->nullable()->index();
            $table->uuid('ai_decision_id')->nullable()->index();
            $table->uuid('triggered_by')->nullable()->index();
            $table->uuid('integration_id')->nullable()->index();
            $table->string('trigger_type', 32)->default('scheduled')->index();
            $table->string('mode', 32)->default('advisor')->index();
            $table->string('symbol', 32)->index();
            $table->string('status', 32)->default('started')->index();
            $table->string('provider_name', 64)->nullable();
            $table->string('provider_model', 128)->nullable();
            $table->json('context_summary_json')->nullable();
            $table->json('validation_result_json')->nullable();
            $table->json('policy_result_json')->nullable();
            $table->json('anomalies_json')->nullable();
            $table->json('state_snapshot_json')->nullable();
            $table->json('execution_result_json')->nullable();
            $table->string('message')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('ai_trading_config_id')->references('id')->on('ai_trading_configs')->nullOnDelete();
            $table->foreign('ai_decision_id')->references('id')->on('ai_decisions')->nullOnDelete();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decision_cycles');
        Schema::dropIfExists('ai_trading_states');

        Schema::table('ai_trading_configs', function (Blueprint $table) {
            foreach ([
                'decision_interval_minutes',
                'enabled_symbols',
                'max_decisions_per_hour',
                'cooldown_minutes_after_trade',
                'cooldown_minutes_after_loss',
                'pause_until',
                'auto_pause_on_daily_loss',
                'auto_pause_on_drawdown',
                'auto_pause_on_order_failure_streak',
                'auto_pause_on_slippage_spike',
                'auto_pause_on_reconciliation_mismatch',
                'manual_resume_required',
            ] as $column) {
                if (Schema::hasColumn('ai_trading_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
