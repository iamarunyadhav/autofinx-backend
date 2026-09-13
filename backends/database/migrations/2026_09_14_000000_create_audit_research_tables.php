<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Research observability subsystem — additive only, no changes to any
 * existing table. Every column here is nullable-by-default where it makes
 * sense so this can be rolled out without a backfill.
 *
 * Deliberately NOT foreign-keying audit_events/audit_decision_snapshots to
 * ratio_bot_decisions/ratio_bot_configs (indexed uuid columns only): keeps
 * this layer's writes fully decoupled from RatioBotController::destroy()'s
 * existing manual cascade-delete transaction (decisions/events/lots deleted
 * directly, no FK) so a bot delete can never be blocked or ordered
 * differently by this new, unrelated audit table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_sessions')) {
            Schema::create('audit_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('name');
            $table->string('bot_type', 32)->default('ratio_bot');
            $table->uuid('bot_instance_id')->nullable()->index();
            $table->string('environment', 16)->default('sandbox');
            // local | testnet | live
            $table->string('symbol', 32)->nullable();
            $table->text('purpose')->nullable();
            $table->string('mode', 32)->default('standard');
            // standard | detailed | deep_diagnostic
            $table->string('status', 16)->default('running');
            // running | completed | failed | stopped
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('build_version')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('audit_events')) {
            Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('audit_session_id')->nullable()->index();
            // Events can occur outside an active session too — always-on
            // lightweight capture. A session is an optional lens/filter.
            $table->string('correlation_id', 64)->nullable()->index();
            // Matches ratio_bot_decisions.correlation_id when applicable.
            $table->string('bot_type', 32)->default('ratio_bot');
            $table->uuid('bot_instance_id')->nullable()->index();
            $table->string('environment', 16)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->string('event_type', 64);
            $table->string('event_category', 24);
            // market | analysis | prediction | decision | risk | execution
            // | health | incident | system
            $table->string('severity', 16)->default('info');
            // info | low | medium | high | critical
            $table->timestamp('occurred_at', 6);
            $table->timestamp('received_at', 6)->nullable();
            $table->decimal('market_price', 20, 8)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['bot_instance_id', 'occurred_at']);
            $table->index(['event_category', 'severity']);
            });
        }

        if (! Schema::hasTable('audit_decision_snapshots')) {
            Schema::create('audit_decision_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('audit_event_id')->nullable()->index();
            $table->uuid('ratio_bot_decision_id')->nullable()->index();
            // Links to the REAL existing RatioBotDecision row when one exists
            // for this tick — never duplicates its columns.
            $table->string('correlation_id', 64)->nullable()->index();
            $table->json('passed_rules')->nullable();
            $table->json('failed_rules')->nullable();
            $table->string('final_decision', 16)->nullable();
            $table->text('final_reason')->nullable();
            $table->text('expected_behavior')->nullable();
            $table->text('actual_behavior')->nullable();
            $table->string('diagnosis_category', 48)->nullable();
            $table->string('diagnosis_confidence', 8)->nullable();
            // high | medium | low
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('audit_incidents')) {
            Schema::create('audit_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('bot_type', 32)->default('ratio_bot');
            $table->uuid('bot_instance_id')->nullable()->index();
            $table->string('symbol', 32)->nullable();
            $table->string('title');
            $table->string('root_cause_category', 48);
            $table->string('severity', 16);
            $table->string('status', 16)->default('detected');
            // detected | active | recovering | recovered | recurring
            // | persistent | resolved
            // Values are supplied by IncidentDetectionService; nullable keeps
            // MySQL strict mode from inventing an invalid zero-date default.
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->boolean('self_recovered')->default(false);
            $table->json('evidence')->nullable();
            $table->text('recommended_action')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'bot_instance_id', 'status']);
            });
        }

        if (! Schema::hasTable('audit_incident_events')) {
            Schema::create('audit_incident_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('audit_incident_id')->index();
            $table->uuid('audit_event_id')->index();
            $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_incident_events');
        Schema::dropIfExists('audit_incidents');
        Schema::dropIfExists('audit_decision_snapshots');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('audit_sessions');
    }
};
