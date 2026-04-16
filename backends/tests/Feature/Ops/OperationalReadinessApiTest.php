<?php

use App\Domain\Integrations\IntegrationManager;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\AiTradingConfig;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Integration;
use App\Models\ModuleSetting;
use App\Models\OperationalReadinessSnapshot;
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

function seedOpsFeatureWorkspace(bool $withTwoFactor = false): array
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

    $manager = app(IntegrationManager::class);
    Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => $manager->encryptSecrets([
            'api_key' => 'key',
            'api_secret' => 'secret',
        ]),
    ]);

    return [$workspace, $user];
}

it('requires workspace membership and records readiness incidents', function () {
    [$workspace] = seedOpsFeatureWorkspace(false);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/readiness")
        ->assertForbidden();

    [$workspace, $user] = seedOpsFeatureWorkspace(false);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/readiness")
        ->assertOk()
        ->assertJsonPath('readiness.state', 'blocked')
        ->assertJsonPath('deployment.state', 'blocked');

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ops/readiness/run")
        ->assertOk()
        ->assertJsonPath('readiness.state', 'blocked');

    expect(OperationalReadinessSnapshot::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and(Incident::query()->where('workspace_id', $workspace->id)->where('incident_key', 'ops-readiness:' . $workspace->id)->count())->toBe(1);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/incidents")
        ->assertOk()
        ->assertJsonCount(1, 'incidents');

    $incidentId = Incident::query()->where('workspace_id', $workspace->id)->value('id');
    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ops/incidents/{$incidentId}/acknowledge", [
            'note' => 'Investigating readiness blocker',
        ])
        ->assertOk()
        ->assertJsonPath('incident.status', 'acknowledged');

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ops/incidents/{$incidentId}/resolve", [
            'summary' => 'Recovered after operator review',
        ])
        ->assertOk()
        ->assertJsonPath('incident.status', 'resolved');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/readiness/history")
        ->assertOk()
        ->assertJsonCount(1, 'snapshots');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/incidents")
        ->assertOk()
        ->assertJsonCount(1, 'incidents')
        ->assertJsonPath('incidents.0.status', 'resolved');
});

it('serves runbooks, provider health, and safe operator actions', function () {
    [$workspace, $user] = seedOpsFeatureWorkspace(true);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/runbooks")
        ->assertOk()
        ->assertJsonFragment(['key' => 'exchange-outage']);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ops/runbooks/exchange-outage")
        ->assertOk()
        ->assertJsonPath('key', 'exchange-outage');

    $this->actingAs($user)
        ->getJson('/api/v1/ops/provider-health')
        ->assertOk()
        ->assertJsonStructure([
            'webhooks',
            'ticks' => ['last_tick_at', 'symbol_id', 'stale'],
            'breakers' => ['stale_feed'],
            'binance_connections',
        ]);

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ops/actions", [
            'action_key' => 'disable_external_context',
            'reason' => 'operator test',
        ])
        ->assertOk()
        ->assertJsonPath('result.enabled', false);

    expect(AiTradingConfig::query()->where('workspace_id', $workspace->id)->firstOrFail()->use_external_context)->toBeFalse()
        ->and(AuditLog::query()->where('workspace_id', $workspace->id)->where('action', 'ops_action:disable_external_context')->exists())->toBeTrue();

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ops/actions", [
            'action_key' => 'enable_external_context',
            'reason' => 'operator test',
        ])
        ->assertOk()
        ->assertJsonPath('result.enabled', true);
});
