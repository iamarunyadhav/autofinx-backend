<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function queuedPayload(string $displayName): string
{
    return json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => $displayName,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'maxTries' => 3,
        'data' => [],
    ]);
}

test('jobs dashboard shows blocked backlog and per queue counts', function () {
    config()->set('ops.live_readiness.queue_backlog_warning', 2);
    config()->set('ops.live_readiness.queue_backlog_block', 3);
    config()->set('queue_control.stale_prune.safe_job_classes', ['App\\Jobs\\DetectStaleFeedsJob']);

    $user = User::factory()->create();
    foreach (['execution', 'execution', 'signals'] as $queue) {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => queuedPayload('Demo queued job'),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'signals',
        'payload' => queuedPayload('Demo failed job'),
        'exception' => "RuntimeException: Binance API timeout\nstack",
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/jobs/dashboard')
        ->assertOk()
        ->assertJsonPath('state', 'blocked')
        ->assertJsonPath('backlog', 3)
        ->assertJsonPath('failed', 1)
        ->assertJsonPath('failed_jobs.0.exception', 'RuntimeException: Binance API timeout')
        ->assertJsonPath('protected_queues.0', 'critical')
        ->assertJsonPath('worker.profiles.0.key', 'worker_1_live');
});

test('jobs dashboard exposes predictions-queue p50/p95 latency and the 20s drop count', function () {
    config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
    \Illuminate\Support\Facades\Cache::forgetDriver('redis');
    $metrics = app(\App\Domain\Ops\PredictionQueueLatencyMetrics::class);
    foreach ([100, 200, 300, 400] as $wait) {
        $metrics->record(\App\Domain\Ops\PredictionQueueLatencyMetrics::OUTCOME_PUBLISHED, $wait, $wait / 10);
    }
    $metrics->record(\App\Domain\Ops\PredictionQueueLatencyMetrics::OUTCOME_DROPPED_BEFORE_COMPUTE, 22000, null);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/jobs/dashboard')
        ->assertOk()
        ->assertJsonPath('prediction_worker_latency.available', true)
        ->assertJsonPath('prediction_worker_latency.queue', 'predictions')
        ->assertJsonPath('prediction_worker_latency.published', 4)
        ->assertJsonPath('prediction_worker_latency.dropped_total', 1)
        ->assertJsonPath('prediction_worker_latency.dropped_expired_before_compute', 1)
        // queue wait over 100,200,300,400,22000 -> p50 3rd = 300, p95 5th = 22000
        ->assertJsonPath('prediction_worker_latency.queue_wait_ms.p50', 300)
        ->assertJsonPath('prediction_worker_latency.queue_wait_ms.p95', 22000)
        // compute over 10,20,30,40 -> p50 2nd = 20, p95 4th = 40
        ->assertJsonPath('prediction_worker_latency.compute_ms.p50', 20)
        ->assertJsonPath('prediction_worker_latency.compute_ms.p95', 40);
});

test('jobs dashboard can clear a specific pending queue', function () {
    $user = User::factory()->create();
    foreach (['execution', 'signals'] as $queue) {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => queuedPayload('Clear queue test'),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    $this->actingAs($user)
        ->postJson('/api/v1/jobs/pending/clear', ['queue' => 'signals', 'connection' => 'database', 'confirm' => true])
        ->assertOk()
        ->assertJsonPath('action', 'clear_pending');

    expect(DB::table('jobs')->where('queue', 'signals')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'execution')->count())->toBe(1);
});

test('jobs dashboard can forget a reviewed failed job', function () {
    $user = User::factory()->create();
    $id = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'ops',
        'payload' => queuedPayload('Reviewed failed job'),
        'exception' => 'RuntimeException: reviewed and not retryable',
        'failed_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/jobs/failed/forget', ['id' => (string) $id])
        ->assertOk()
        ->assertJsonPath('action', 'forget_failed')
        ->assertJsonPath('forgotten', 1);

    expect(DB::table('failed_jobs')->count())->toBe(0);
});

test('jobs dashboard blocks clearing protected queues', function () {
    config()->set('queue_control.protected_queues', ['execution']);

    $user = User::factory()->create();
    DB::table('jobs')->insert([
        'queue' => 'execution',
        'payload' => queuedPayload('Protected execution job'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time() - 4000,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/jobs/pending/clear', ['queue' => 'execution', 'connection' => 'database', 'confirm' => true])
        ->assertStatus(422);

    expect(DB::table('jobs')->where('queue', 'execution')->count())->toBe(1);
});

test('jobs dashboard prunes only stale safe pending jobs', function () {
    config()->set('queue_control.protected_queues', ['critical', 'execution', 'reconcile', 'market']);
    config()->set('queue_control.stale_prune.safe_queues', ['analytics', 'default']);
    config()->set('queue_control.stale_prune.safe_job_classes', ['App\\Jobs\\DetectStaleFeedsJob']);
    config()->set('queue_control.stale_prune.older_than_minutes', 30);

    $user = User::factory()->create();

    DB::table('jobs')->insert([
        'queue' => 'analytics',
        'payload' => queuedPayload('App\\Jobs\\ComputeAnalyticsJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'monitoring_safe',
        'payload' => queuedPayload('App\\Jobs\\DetectStaleFeedsJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'monitoring',
        'payload' => queuedPayload('App\\Jobs\\RunExitMonitoringJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'execution',
        'payload' => queuedPayload('App\\Jobs\\ExecuteSignalJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => queuedPayload('App\\Jobs\\RecentDefaultJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/jobs/pending/prune-stale', ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('action', 'prune_stale_pending')
        ->assertJsonPath('pruned', 2)
        ->assertJsonPath('pruned_by_queue.analytics', 1)
        ->assertJsonPath('pruned_by_queue.monitoring_safe', 1);

    expect(DB::table('jobs')->where('queue', 'analytics')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'monitoring')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'monitoring_safe')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'execution')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'default')->count())->toBe(1);
});

test('automatic backlog maintenance prunes safe stale jobs only after threshold is crossed', function () {
    config()->set('queue_control.auto_maintenance.enabled', true);
    config()->set('queue_control.auto_maintenance.trigger_backlog', 3);
    config()->set('queue_control.auto_maintenance.prune_older_than_minutes', 30);
    config()->set('queue_control.stale_prune.safe_queues', ['analytics', 'default', 'monitoring_safe']);
    config()->set('queue_control.stale_prune.safe_job_classes', ['App\\Jobs\\DetectStaleFeedsJob']);
    config()->set('queue_control.protected_queues', ['critical', 'execution', 'reconcile', 'market', 'monitoring']);
    config()->set('ops.live_readiness.queue_backlog_excluded_queues', []);

    DB::table('jobs')->insert([
        'queue' => 'analytics',
        'payload' => queuedPayload('App\\Jobs\\ComputeAnalyticsJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'monitoring_safe',
        'payload' => queuedPayload('App\\Jobs\\DetectStaleFeedsJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    DB::table('jobs')->insert([
        'queue' => 'execution',
        'payload' => queuedPayload('App\\Jobs\\ExecuteSignalJob'),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    $result = app(\App\Domain\Ops\QueueControlService::class)->maintainBacklog();

    expect($result['prune']['pruned'])->toBe(2)
        ->and($result['blocking_backlog_before'])->toBe(3)
        ->and($result['blocking_backlog_after'])->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'execution')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'analytics')->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'monitoring_safe')->count())->toBe(0);
});
