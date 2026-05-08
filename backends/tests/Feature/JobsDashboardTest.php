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
        ->assertJsonPath('failed_jobs.0.exception', 'RuntimeException: Binance API timeout');
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
