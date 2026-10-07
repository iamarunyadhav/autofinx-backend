<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strategy Lab (SPEC §105): research candidates, immutable source versions, async test runs and immutable
 * owner approvals. Purely additive (new tables only); nothing references an order or execution table.
 * dateTime(), never timestamp() (MariaDB ON UPDATE quirk); instants are UTC wall time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategy_lab_candidates')) {
            Schema::create('strategy_lab_candidates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('source_type', 24);          // PINE|RULE_JSON|MANUAL_COMPONENT
                $table->string('intended_purpose', 32);     // FULL_STRATEGY|DIRECTION|TRADEABILITY_FILTER|...
                $table->string('original_timeframe', 16)->nullable();
                $table->string('status', 32);               // lifecycle, see StrategyLabCandidate
                $table->uuid('created_by')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index(['workspace_id', 'status'], 'strategy_lab_candidates_ws_status_idx');
            });
        }
        if (! Schema::hasTable('strategy_lab_versions')) {
            Schema::create('strategy_lab_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('candidate_id');
                $table->unsignedInteger('version');
                $table->uuid('parent_version_id')->nullable();
                $table->longText('source');                 // stored as text only, never executed
                $table->char('sha256', 64);
                $table->char('normalized_sha256', 64);
                $table->string('adapter', 40)->nullable();  // registered translation, if any
                $table->string('audit_status', 24)->nullable(); // CAUSAL|CAUSAL_IF_LAGGED|REPAINT_RISK|UNSUPPORTED|INVALID
                $table->json('audit')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['candidate_id', 'version'], 'strategy_lab_versions_candidate_version_uq');
            });
        }
        if (! Schema::hasTable('strategy_lab_runs')) {
            Schema::create('strategy_lab_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id');
                $table->uuid('candidate_id');
                $table->uuid('version_id');
                $table->string('kind', 12);                 // QUICK|DEEP
                $table->string('status', 12);               // QUEUED|RUNNING|COMPLETED|FAILED|CANCELLED
                $table->string('phase', 20)->default('queued');
                $table->decimal('progress_pct', 5, 2)->nullable();
                $table->string('adapter', 40);
                $table->string('sample_policy', 24)->nullable();
                $table->string('verdict', 32)->nullable();
                $table->json('summary')->nullable();
                $table->char('result_sha256', 64)->nullable();
                $table->string('idempotency_key', 80)->nullable();
                $table->uuid('requested_by')->nullable();
                $table->dateTime('queued_at')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('heartbeat_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->dateTime('cancel_requested_at')->nullable();
                $table->string('error_code', 24)->nullable();
                $table->text('error_message')->nullable();
                $table->text('log_tail')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index(['version_id', 'kind', 'status'], 'strategy_lab_runs_version_kind_status_idx');
                $table->unique(['workspace_id', 'idempotency_key'], 'strategy_lab_runs_ws_idem_uq');
            });
        }
        if (! Schema::hasTable('strategy_lab_approvals')) {
            Schema::create('strategy_lab_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('candidate_id');
                $table->uuid('version_id');
                $table->uuid('run_id');
                $table->string('scope', 16);                // DEEP_TEST|EXPERIMENT
                $table->string('decision', 8);              // APPROVE|REJECT
                $table->text('notes')->nullable();
                $table->uuid('decided_by');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['run_id'], 'strategy_lab_approvals_run_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_lab_approvals');
        Schema::dropIfExists('strategy_lab_runs');
        Schema::dropIfExists('strategy_lab_versions');
        Schema::dropIfExists('strategy_lab_candidates');
    }
};
