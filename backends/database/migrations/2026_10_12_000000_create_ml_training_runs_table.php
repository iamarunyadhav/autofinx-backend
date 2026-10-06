<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ML Training dashboard control plane. A run is a request to train one
 * symbol x horizon x type; the ml_models row is its outcome. Purely additive
 * (new table only). Every instant is a dateTime holding UTC wall time (the
 * model reads them with App\Casts\UtcDateTime). dateTime(), never timestamp():
 * MariaDB adds ON UPDATE CURRENT_TIMESTAMP to the first TIMESTAMP NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ml_training_runs')) {
            return;
        }
        Schema::create('ml_training_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol', 30);
            $table->unsignedSmallInteger('horizon_minutes');
            $table->string('model_type', 24);
            $table->string('status', 16);            // QUEUED|RUNNING|FINISHED|FAILED|STOPPED|INTERRUPTED
            $table->string('outcome', 16)->nullable(); // CHAMPION|REJECTED (only when FINISHED)
            $table->text('outcome_reason')->nullable();
            $table->string('phase', 16)->default('queued'); // queued|exporting|fitting|evaluating|promoting|done
            $table->decimal('progress_pct', 5, 2)->nullable();
            $table->string('source', 12);            // ui|scheduler|cli
            $table->uuid('triggered_by')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->uuid('retry_of')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('cancel_requested_at')->nullable();
            $table->unsignedInteger('worker_pid')->nullable();
            $table->string('host', 120)->nullable();
            $table->string('error_code', 24)->nullable(); // TIMEOUT|PYTHON_EXIT|INTERRUPTED|CANCELLED|EXPORT_FAILED|OTHER
            $table->text('error_message')->nullable();
            $table->unsignedInteger('rows_exported')->nullable();
            $table->text('log_tail')->nullable();
            $table->uuid('model_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'heartbeat_at'], 'ml_training_runs_status_heartbeat_idx');
            $table->index(['symbol', 'horizon_minutes', 'status'], 'ml_training_runs_pair_status_idx');
            $table->index(['batch_id'], 'ml_training_runs_batch_idx');
            $table->index(['retry_of'], 'ml_training_runs_retry_of_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_training_runs');
    }
};
