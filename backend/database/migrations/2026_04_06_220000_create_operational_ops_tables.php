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
        if (! Schema::hasTable('operational_readiness_snapshots')) {
            Schema::create('operational_readiness_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('scope', 32)->index();
                $table->string('environment', 32)->index();
                $table->string('state', 32)->index();
                $table->string('severity', 16)->index();
                $table->text('summary')->nullable();
                $table->json('reason_codes_json')->nullable();
                $table->json('warning_codes_json')->nullable();
                $table->json('blocker_codes_json')->nullable();
                $table->json('details_json')->nullable();
                $table->json('health_json')->nullable();
                $table->string('source_hash', 80)->nullable()->index();
                $table->timestamp('checked_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('incidents')) {
            Schema::create('incidents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('incident_key', 120)->unique();
                $table->string('kind', 64)->index();
                $table->string('severity', 16)->index();
                $table->string('status', 24)->index()->default('open');
                $table->string('title', 180);
                $table->text('summary')->nullable();
                $table->string('runbook_key', 120)->nullable()->index();
                $table->string('detection_source', 64)->nullable()->index();
                $table->boolean('block_live')->default(false);
                $table->json('affected_entities_json')->nullable();
                $table->json('context_json')->nullable();
                $table->timestamp('detected_at')->nullable()->index();
                $table->timestamp('acknowledged_at')->nullable()->index();
                $table->timestamp('mitigated_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamp('last_activity_at')->nullable()->index();
                $table->uuid('created_by')->nullable()->index();
                $table->uuid('acknowledged_by')->nullable()->index();
                $table->uuid('resolved_by')->nullable()->index();
                $table->text('resolution_summary')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('incident_events')) {
            Schema::create('incident_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('incident_id')->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('event_type', 64)->index();
                $table->uuid('actor_id')->nullable()->index();
                $table->string('actor_type', 32)->nullable()->index();
                $table->text('message')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('incident_actions')) {
            Schema::create('incident_actions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('incident_id')->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('action_key', 64)->index();
                $table->string('status', 24)->default('recorded')->index();
                $table->uuid('actor_id')->nullable()->index();
                $table->string('actor_type', 32)->nullable()->index();
                $table->text('message')->nullable();
                $table->text('reason')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamp('executed_at')->nullable()->index();
                $table->timestamps();

                $table->foreign('incident_id')->references('id')->on('incidents')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('runbooks')) {
            Schema::create('runbooks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('key', 120)->unique();
                $table->string('title', 180);
                $table->string('category', 64)->index();
                $table->string('severity', 16)->default('medium')->index();
                $table->string('audience', 32)->default('operator');
                $table->text('summary')->nullable();
                $table->json('steps_json');
                $table->text('automation_notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('is_builtin')->default(true)->index();
                $table->timestamps();
            });
        }

        $now = now();
        $runbooks = [
            [
                'key' => 'exchange-outage',
                'title' => 'Exchange Outage',
                'category' => 'exchange',
                'severity' => 'critical',
                'summary' => 'Use when the exchange API or user stream is unhealthy.',
                'steps_json' => [
                    'Pause new live submissions.',
                    'Confirm provider breaker and stale-feed state.',
                    'Check current open orders for uncertainty.',
                    'Wait for recovery signals before resuming.',
                ],
            ],
            [
                'key' => 'stale-feed',
                'title' => 'Stale Feed',
                'category' => 'market-data',
                'severity' => 'high',
                'summary' => 'Use when live price or market data is stale.',
                'steps_json' => [
                    'Confirm the market feed job and websocket stream.',
                    'Block price-driven entry decisions.',
                    'Keep exits/reconciliation under operator review.',
                    'Resume only after feed freshness returns.',
                ],
            ],
            [
                'key' => 'reconciliation-mismatch',
                'title' => 'Reconciliation Mismatch',
                'category' => 'execution',
                'severity' => 'high',
                'summary' => 'Use when fills, orders, or positions disagree with exchange truth.',
                'steps_json' => [
                    'Trigger a manual sync sweep.',
                    'Inspect uncertain orders and partial fills.',
                    'Do not re-submit until the state is resolved.',
                    'Escalate if the mismatch repeats.',
                ],
            ],
            [
                'key' => 'repeated-order-rejection',
                'title' => 'Repeated Order Rejection',
                'category' => 'execution',
                'severity' => 'high',
                'summary' => 'Use when the venue is rejecting repeated submissions.',
                'steps_json' => [
                    'Check precision, filters, and symbol allowlists.',
                    'Confirm exchange health and client order ids.',
                    'Pause the affected symbol if rejection streak continues.',
                    'Review operator sizing and policy limits.',
                ],
            ],
            [
                'key' => 'uncertain-execution',
                'title' => 'Uncertain Execution State',
                'category' => 'execution',
                'severity' => 'critical',
                'summary' => 'Use when an order acknowledgement or fill is uncertain.',
                'steps_json' => [
                    'Do not re-submit blindly.',
                    'Run reconciliation against exchange truth.',
                    'Inspect client order id and exchange order id mapping.',
                    'Escalate to operator review before resubmission.',
                ],
            ],
            [
                'key' => 'queue-backlog',
                'title' => 'Queue Backlog',
                'category' => 'operations',
                'severity' => 'medium',
                'summary' => 'Use when jobs or scheduled work are lagging.',
                'steps_json' => [
                    'Check the queue worker and failed jobs.',
                    'Inspect the last scheduler run.',
                    'Pause non-critical automation if backlog is growing.',
                    'Restore workers before re-enabling live submission automation.',
                ],
            ],
            [
                'key' => 'ai-provider-outage',
                'title' => 'AI Provider Outage',
                'category' => 'ai',
                'severity' => 'medium',
                'summary' => 'Use when AI decisioning is unavailable or degraded.',
                'steps_json' => [
                    'Verify provider credentials and API health.',
                    'Keep the system in manual approval or advisor mode.',
                    'Do not allow auto mode until the provider recovers.',
                    'Review cached decisions and rejection reasons.',
                ],
            ],
            [
                'key' => 'external-context-outage',
                'title' => 'External Context Outage',
                'category' => 'context',
                'severity' => 'medium',
                'summary' => 'Use when news/macro/exchange context sources are unavailable.',
                'steps_json' => [
                    'Confirm source health and freshness windows.',
                    'Treat the system as reduced-confidence.',
                    'Switch to no-trade or manual approval if policy requires it.',
                    'Resume context-aware routing after source recovery.',
                ],
            ],
            [
                'key' => 'live-disable-kill-switch',
                'title' => 'Live Disable / Kill Switch',
                'category' => 'control',
                'severity' => 'critical',
                'summary' => 'Use to stop live submission immediately.',
                'steps_json' => [
                    'Activate the workspace kill switch.',
                    'Confirm new submissions are blocked.',
                    'Review open positions and exit automation policy.',
                    'Resume only after explicit operator approval.',
                ],
            ],
            [
                'key' => 'post-incident-verification',
                'title' => 'Post-Incident Verification',
                'category' => 'operations',
                'severity' => 'low',
                'summary' => 'Use after mitigation to verify the system is stable again.',
                'steps_json' => [
                    'Confirm readiness and health have recovered.',
                    'Check incidents and unresolved anomalies are cleared or acknowledged.',
                    'Verify scheduler, queue, and exchange sync are stable.',
                    'Record the operator summary before resuming live flow.',
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
        Schema::dropIfExists('incident_actions');
        Schema::dropIfExists('incident_events');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('operational_readiness_snapshots');
        Schema::dropIfExists('runbooks');
    }
};
