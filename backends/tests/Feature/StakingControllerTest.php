<?php

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('simulates and starts staking position', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $simulate = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/staking/simulate", [
        'asset' => 'USDT',
        'principal' => 1000,
        'apy' => 10,
        'duration_days' => 30,
    ])->assertOk()
      ->json();

    expect($simulate['projected_yield'])->toBeGreaterThan(0);

    $start = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/staking/start", [
        'asset' => 'USDT',
        'principal' => 1000,
        'apy' => 10,
        'duration_days' => 30,
    ])->assertCreated()
      ->json();

    expect($start['asset'])->toBe('USDT')
        ->and($start['status'])->toBe('active');

    $list = $this->actingAs($user)->getJson("/api/v1/workspaces/{$workspace->id}/staking")
        ->assertOk()
        ->json();

    expect($list)->toHaveCount(1);
});
