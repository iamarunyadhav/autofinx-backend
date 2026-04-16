<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_trend_snapshots')) {
            Schema::create('operational_trend_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('scope', 32)->default('workspace')->index();
                $table->string('state', 32)->index();
                $table->string('severity', 16)->index();
                $table->unsignedSmallInteger('window_days')->default(7)->index();
                $table->text('summary')->nullable();
                $table->json('metrics_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamp('observed_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operational_drift_detections')) {
            Schema::create('operational_drift_detections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('metric_key', 80)->index();
                $table->string('state', 32)->index();
                $table->string('severity', 16)->index();
                $table->string('status', 24)->default('open')->index();
                $table->text('summary')->nullable();
                $table->json('details_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamp('detected_at')->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->uuid('created_by')->nullable()->index();
                $table->uuid('resolved_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('maintenance_confidence_snapshots')) {
            Schema::create('maintenance_confidence_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('state', 32)->index();
                $table->decimal('confidence_score', 5, 2)->default(0);
                $table->text('summary')->nullable();
                $table->json('rationale_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamp('observed_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('recovery_drills')) {
            Schema::create('recovery_drills', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('scenario_key', 120)->index();
                $table->string('scenario_name', 180);
                $table->string('environment', 32)->index();
                $table->string('status', 24)->index();
                $table->text('summary')->nullable();
                $table->json('actions_json')->nullable();
                $table->json('findings_json')->nullable();
                $table->json('recommendations_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->text('blocked_reason')->nullable();
                $table->uuid('performed_by')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chaos_simulations')) {
            Schema::create('chaos_simulations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('scenario_key', 120)->index();
                $table->string('scenario_name', 180);
                $table->string('environment', 32)->index();
                $table->string('status', 24)->index();
                $table->text('summary')->nullable();
                $table->json('expected_behavior_json')->nullable();
                $table->json('actual_behavior_json')->nullable();
                $table->json('findings_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->text('blocked_reason')->nullable();
                $table->uuid('requested_by')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('release_records')) {
            Schema::create('release_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('release_key', 120)->unique();
                $table->string('version', 80)->index();
                $table->string('environment', 32)->index();
                $table->string('status', 24)->index();
                $table->text('summary')->nullable();
                $table->json('change_summary_json')->nullable();
                $table->json('metadata_json')->nullable();
                $table->text('blocked_reason')->nullable();
                $table->uuid('performed_by')->nullable()->index();
                $table->timestamp('released_at')->nullable()->index();
                $table->timestamp('verified_at')->nullable()->index();
                $table->timestamp('rolled_back_at')->nullable()->index();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('post_deploy_checks')) {
            Schema::create('post_deploy_checks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('release_record_id')->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('status', 24)->index();
                $table->text('summary')->nullable();
                $table->json('checklist_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->uuid('performed_by')->nullable()->index();
                $table->timestamp('checked_at')->nullable()->index();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamps();

                $table->foreign('release_record_id')->references('id')->on('release_records')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('rollback_records')) {
            Schema::create('rollback_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('release_record_id')->nullable()->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('rollback_key', 120)->unique();
                $table->string('status', 24)->index();
                $table->text('reason')->nullable();
                $table->text('summary')->nullable();
                $table->text('blocked_reason')->nullable();
                $table->json('details_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->uuid('performed_by')->nullable()->index();
                $table->timestamp('requested_at')->nullable()->index();
                $table->timestamp('executed_at')->nullable()->index();
                $table->timestamp('verified_at')->nullable()->index();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamps();

                $table->foreign('release_record_id')->references('id')->on('release_records')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('post_incident_reviews')) {
            Schema::create('post_incident_reviews', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('incident_id')->unique()->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->uuid('reviewed_by')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable()->index();
                $table->text('root_cause_hypothesis')->nullable();
                $table->json('contributing_factors_json')->nullable();
                $table->text('detection_summary')->nullable();
                $table->text('mitigation_summary')->nullable();
                $table->text('lessons_learned')->nullable();
                $table->json('follow_up_actions_json')->nullable();
                $table->json('evidence_json')->nullable();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamps();

                $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
            });
        }

        $now = now();
        $runbooks = [
            [
                'key' => 'drift-detection',
                'title' => 'Operational Drift Detection',
                'category' => 'operations',
                'severity' => 'medium',
                'summary' => 'Use when trend deterioration or creeping fragility is detected.',
                'steps_json' => [
                    'Review the drift evidence and affected metrics.',
                    'Check whether recent releases or config changes correlate with the drift.',
                    'Escalate to incident handling if the drift is action-required.',
                    'Run a recovery drill or post-incident review after stabilization.',
                ],
            ],
            [
                'key' => 'recovery-drill',
                'title' => 'Recovery Drill',
                'category' => 'operations',
                'severity' => 'low',
                'summary' => 'Use for repeatable recovery practice in safe environments.',
                'steps_json' => [
                    'Select the scenario and confirm the environment is safe.',
                    'Record the expected recovery behavior before running the drill.',
                    'Capture the observed behavior and any operator gaps.',
                    'Record follow-up actions if the drill fails.',
                ],
            ],
            [
                'key' => 'deployment-rollback',
                'title' => 'Deployment Rollback',
                'category' => 'deployment',
                'severity' => 'critical',
                'summary' => 'Use when a release or deployment must be reversed.',
                'steps_json' => [
                    'Confirm the rollback target and current version.',
                    'Check whether data migrations or irreversible actions are involved.',
                    'Stop or pause affected live automation before rollback if required.',
                    'Verify readiness again after rollback completes.',
                ],
            ],
            [
                'key' => 'post-incident-review',
                'title' => 'Post-Incident Review',
                'category' => 'operations',
                'severity' => 'low',
                'summary' => 'Use to record lessons learned and corrective actions.',
                'steps_json' => [
                    'Summarize the incident timeline and the trigger.',
                    'Record the root cause hypothesis and contributing factors.',
                    'List what helped detection and mitigation.',
                    'Capture follow-up actions and remaining risks.',
                ],
            ],
            [
                'key' => 'chaos-simulation',
                'title' => 'Chaos Simulation',
                'category' => 'operations',
                'severity' => 'low',
                'summary' => 'Use for safe failure simulation in non-live contexts.',
                'steps_json' => [
                    'Choose a scenario that is safe for the current environment.',
                    'Confirm the simulation is non-destructive and auditable.',
                    'Review expected vs actual behavior after the run.',
                    'Use the findings to improve readiness and recovery.',
                ],
            ],
        ];

        if (Schema::hasTable('runbooks')) {
            foreach ($runbooks as $runbook) {
                DB::table('runbooks')->updateOrInsert(
                    ['key' => $runbook['key']],
                    [
                        'id' => DB::table('runbooks')->where('key', $runbook['key'])->value('id') ?? (string) Str::uuid(),
                        'workspace_id' => null,
                        'title' => $runbook['title'],
                        'category' => $runbook['category'],
                        'severity' => $runbook['severity'],
                        'audience' => 'operator',
                        'summary' => $runbook['summary'],
                        'steps_json' => json_encode($runbook['steps_json']),
                        'automation_notes' => null,
                        'is_active' => true,
                        'is_builtin' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_incident_reviews');
        Schema::dropIfExists('rollback_records');
        Schema::dropIfExists('post_deploy_checks');
        Schema::dropIfExists('release_records');
        Schema::dropIfExists('chaos_simulations');
        Schema::dropIfExists('recovery_drills');
        Schema::dropIfExists('maintenance_confidence_snapshots');
        Schema::dropIfExists('operational_drift_detections');
        Schema::dropIfExists('operational_trend_snapshots');
    }
};
