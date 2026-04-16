<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Ops\DeploymentReadinessService;
use App\Domain\Ops\LiveReadinessService;
use App\Domain\Ops\OperationalHealthService;
use App\Models\AiTradingConfig;
use App\Models\ExternalContextSourceHealth;
use App\Models\Integration;
use App\Models\ModuleSetting;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'ops.deployment_environment' => 'live',
        'ops.require_2fa_for_live' => true,
    ]);
});

function seedOpsReadinessWorkspace(bool $withTwoFactor = false): Workspace
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create($withTwoFactor ? [
        'two_factor_secret' => 'secret',
        'two_factor_confirmed_at' => now(),
    ] : []);

    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    Setting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'group' => 'trading',
        'key' => 'trading_mode',
        'value_json' => 'live',
    ]);

    Setting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'group' => 'trading',
        'key' => 'live_enabled',
        'value_json' => true,
    ]);

    ModuleSetting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'module_key' => 'trading',
        'mode' => 'live',
    ]);

    $integrationManager = app(IntegrationManager::class);
    Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => $integrationManager->encryptSecrets([
            'api_key' => 'key',
            'api_secret' => 'secret',
        ]),
    ]);

    return $workspace;
}

it('blocks deployment readiness when live mode lacks two factor', function () {
    $workspace = seedOpsReadinessWorkspace(false);

    $state = app(DeploymentReadinessService::class)->state($workspace->id);

    expect($state['environment'])->toBe('live')
        ->and($state['state'])->toBe('blocked')
        ->and($state['deployment_ready'])->toBeFalse()
        ->and($state['blockers'])->toContain('two_factor_not_confirmed');
});

it('marks deployment ready when live config and credentials are valid', function () {
    $workspace = seedOpsReadinessWorkspace(true);

    $state = app(DeploymentReadinessService::class)->state($workspace->id);

    expect($state['environment'])->toBe('live')
        ->and($state['state'])->toBe('healthy')
        ->and($state['deployment_ready'])->toBeTrue()
        ->and($state['blockers'])->toBeEmpty();
});

it('combines deployment and health blockers into blocked live readiness', function () {
    $deployment = Mockery::mock(DeploymentReadinessService::class);
    $deployment->shouldReceive('state')
        ->once()
        ->with('workspace-1')
        ->andReturn([
            'state' => 'blocked',
            'deployment_ready' => false,
            'environment' => 'live',
            'blockers' => ['two_factor_not_confirmed'],
            'warnings' => ['futures_restricted_to_testnet'],
        ]);

    $health = Mockery::mock(OperationalHealthService::class);
    $health->shouldReceive('summary')
        ->once()
        ->with('workspace-1')
        ->andReturn([
            'state' => 'degraded',
            'blockers' => ['queue_backlog_blocked'],
            'warnings' => ['scheduler_stale'],
        ]);

    $result = (new LiveReadinessService($deployment, $health))->state('workspace-1');

    expect($result['state'])->toBe('blocked')
        ->and($result['blocked_live'])->toBeTrue()
        ->and($result['blockers'])->toContain('two_factor_not_confirmed')
        ->and($result['blockers'])->toContain('queue_backlog_blocked')
        ->and($result['warnings'])->toContain('scheduler_stale');
});

it('surfaces stale rss feeds as an external context warning', function () {
    $workspace = seedOpsReadinessWorkspace(true);

    AiTradingConfig::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'enabled' => true,
        'mode' => 'advisor',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'provider' => 'heuristic',
        'use_external_context' => true,
        'allowed_symbols' => ['BTCUSDT'],
        'enabled_symbols' => ['BTCUSDT'],
    ]);

    ExternalContextSourceHealth::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'provider' => 'news:rss',
        'status' => 'degraded',
        'consecutive_failures' => 0,
        'last_ok_at' => now(),
        'meta_json' => [
            'alert' => 'rss_feed_stale',
            'newest_item_age_minutes' => 240,
        ],
    ]);

    $summary = app(OperationalHealthService::class)->summary($workspace->id);

    expect($summary['checks']['external_context']['warnings'])->toContain('external_context_source_degraded')
        ->and($summary['checks']['external_context']['warnings'])->toContain('external_news_feed_stale');
});
