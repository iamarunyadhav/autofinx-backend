<?php

use App\Http\Middleware\RequireTwoFactor;
use App\Models\Position;
use App\Models\PositionExitEvent;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function attachExitApiMember(Workspace $workspace, User $user, string $role = 'owner'): void
{
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
}

function createExitApiPosition(Workspace $workspace, string $symbolCode = 'BTCUSDT'): Position
{
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => $symbolCode,
        'type' => 'crypto',
        'exchange' => 'binance',
    ]);

    return Position::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol_id' => $symbol->id,
        'qty' => 0.5,
        'quantity' => 0.5,
        'avg_entry' => 30000,
        'avg_price' => 30000,
        'last_mark_price' => 30100,
        'asset_class' => 'CRYPTO',
        'asset_code' => $symbolCode,
        'provider' => 'binance',
        'quote_currency' => 'USDT',
    ]);
}

it('supports exit policy api crud and rejects invalid partial ladders', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachExitApiMember($workspace, $user);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/exit-policy")
        ->assertOk()
        ->assertJsonPath('policy.workspace_id', $workspace->id)
        ->assertJsonPath('policy.native_protection_mode', 'disabled');

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->putJson("/api/v1/workspaces/{$workspace->id}/exit-policy", [
            'default_stop_loss_pct' => 2.5,
            'default_take_profit_pct' => 5,
            'trailing_stop_enabled' => true,
            'trailing_stop_pct' => 1.5,
            'partial_take_profit_enabled' => true,
            'partial_take_profit_levels' => [2, 4],
            'partial_take_profit_sizes' => [25, 25],
            'break_even_after_partial' => true,
            'native_protection_mode' => 'disabled',
        ])
        ->assertOk()
        ->assertJsonPath('policy.default_stop_loss_pct', '2.5000')
        ->assertJsonPath('effective_policy.partial_take_profit_enabled', true);

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->putJson("/api/v1/workspaces/{$workspace->id}/exit-policy", [
            'partial_take_profit_levels' => [2, 4],
            'partial_take_profit_sizes' => [25],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Partial take-profit levels and sizes must have the same number of entries.');
});

it('backfills pre existing spot positions into review required managed states and exposes exit events', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachExitApiMember($workspace, $user);
    $position = createExitApiPosition($workspace);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/managed-positions")
        ->assertOk()
        ->assertJsonPath('positions.0.position_id', $position->id)
        ->assertJsonPath('positions.0.managed_mode', 'review_required');

    $this->actingAs($user)
        ->getJson("/api/v1/live/positions/{$position->id}/exit-plan")
        ->assertOk()
        ->assertJsonPath('exit_state.position_id', $position->id)
        ->assertJsonPath('exit_state.managed_mode', 'review_required');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/exit-events")
        ->assertOk()
        ->assertJsonPath('events.0.position_id', $position->id);

    expect(PositionExitEvent::query()->where('position_id', $position->id)->count())->toBeGreaterThan(0);
});

it('can arm pause and resume managed exit automation through protected operator endpoints', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    attachExitApiMember($workspace, $user);
    $position = createExitApiPosition($workspace, 'ETHUSDT');

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->putJson("/api/v1/workspaces/{$workspace->id}/exit-policy", [
            'default_stop_loss_pct' => 2,
            'default_take_profit_pct' => 4,
        ])
        ->assertOk();

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/live/positions/{$position->id}/exit/arm")
        ->assertOk()
        ->assertJsonPath('exit_state.managed_mode', 'armed');

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/live/positions/{$position->id}/exit/pause", [
            'reason' => 'operator_pause',
        ])
        ->assertOk()
        ->assertJsonPath('exit_state.managed_mode', 'paused');

    $this->withoutMiddleware(RequireTwoFactor::class)
        ->actingAs($user)
        ->postJson("/api/v1/live/positions/{$position->id}/exit/resume")
        ->assertOk()
        ->assertJsonPath('exit_state.managed_mode', 'armed');
});
