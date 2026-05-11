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
