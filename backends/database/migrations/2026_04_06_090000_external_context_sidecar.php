<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_context_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('provider', 64)->index();
            $table->string('category', 64)->index();
            $table->string('severity', 16)->index();
            $table->string('impact_flag', 32)->index();
            $table->string('symbol', 32)->nullable()->index();
            $table->string('title', 500);
            $table->text('summary')->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->string('source_id', 200)->nullable();
            $table->string('dedupe_key', 200)->unique();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('fetched_at')->nullable()->index();
            $table->json('raw_payload_json')->nullable();
            $table->json('normalized_payload_json')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('external_context_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('symbol', 32)->nullable()->index();
            $table->string('status', 32)->default('available')->index(); // available|partial|unavailable|stale
            $table->unsignedInteger('age_minutes')->nullable();
            $table->string('freshness_state', 32)->default('unknown')->index(); // fresh|stale|unknown
            $table->string('recommended_bias', 32)->default('neutral')->index(); // neutral|caution|no_trade|pause_review
            $table->decimal('confidence_adjustment_hint', 10, 4)->default(0); // negative reduces confidence
            $table->boolean('no_trade_hint')->default(false);
            $table->boolean('pause_review_hint')->default(false);
            $table->json('event_flags')->nullable(); // CAUTION/NO_TRADE/PAUSE_REVIEW etc
            $table->json('high_severity_events')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('source_health_json')->nullable();
            $table->timestamp('computed_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('external_context_source_health', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('provider', 64)->index();
            $table->string('status', 32)->default('unknown')->index(); // ok|degraded|down|unknown
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::table('ai_trading_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_trading_configs', 'use_external_context')) {
                $table->boolean('use_external_context')->default(false)->after('auto_trade_enabled');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'max_external_context_age_minutes')) {
                $table->unsignedInteger('max_external_context_age_minutes')->default(60)->after('use_external_context');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'block_on_high_severity_exchange_incident')) {
                $table->boolean('block_on_high_severity_exchange_incident')->default(false)->after('max_external_context_age_minutes');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'block_on_critical_macro_window')) {
                $table->boolean('block_on_critical_macro_window')->default(false)->after('block_on_high_severity_exchange_incident');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'reduce_size_on_medium_macro_risk')) {
                $table->boolean('reduce_size_on_medium_macro_risk')->default(false)->after('block_on_critical_macro_window');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'require_manual_approval_when_external_risk_high')) {
                $table->boolean('require_manual_approval_when_external_risk_high')->default(false)->after('reduce_size_on_medium_macro_risk');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'auto_pause_on_critical_external_event')) {
                $table->boolean('auto_pause_on_critical_external_event')->default(false)->after('require_manual_approval_when_external_risk_high');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'ignore_low_severity_news')) {
                $table->boolean('ignore_low_severity_news')->default(true)->after('auto_pause_on_critical_external_event');
            }
        });

        Schema::table('ai_decisions', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_decisions', 'external_context_snapshot_id')) {
                $table->uuid('external_context_snapshot_id')->nullable()->index()->after('integration_id');
                $table->foreign('external_context_snapshot_id')
                    ->references('id')
                    ->on('external_context_snapshots')
                    ->nullOnDelete();
            }
        });

        Schema::table('ai_decision_cycles', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_decision_cycles', 'external_context_snapshot_id')) {
                $table->uuid('external_context_snapshot_id')->nullable()->index()->after('integration_id');
                $table->foreign('external_context_snapshot_id')
                    ->references('id')
                    ->on('external_context_snapshots')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_decision_cycles', function (Blueprint $table) {
            if (Schema::hasColumn('ai_decision_cycles', 'external_context_snapshot_id')) {
                $table->dropForeign(['external_context_snapshot_id']);
                $table->dropColumn('external_context_snapshot_id');
            }
        });

        Schema::table('ai_decisions', function (Blueprint $table) {
            if (Schema::hasColumn('ai_decisions', 'external_context_snapshot_id')) {
                $table->dropForeign(['external_context_snapshot_id']);
                $table->dropColumn('external_context_snapshot_id');
            }
        });

        Schema::table('ai_trading_configs', function (Blueprint $table) {
            foreach ([
                'use_external_context',
                'max_external_context_age_minutes',
                'block_on_high_severity_exchange_incident',
                'block_on_critical_macro_window',
                'reduce_size_on_medium_macro_risk',
                'require_manual_approval_when_external_risk_high',
                'auto_pause_on_critical_external_event',
                'ignore_low_severity_news',
            ] as $column) {
                if (Schema::hasColumn('ai_trading_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('external_context_source_health');
        Schema::dropIfExists('external_context_snapshots');
        Schema::dropIfExists('external_context_events');
    }
};
