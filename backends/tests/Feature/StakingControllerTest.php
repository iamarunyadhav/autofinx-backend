<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('simulates and starts staking position', function () {
    $workspace = Workspace::factory()->create();

    $simulate = $this->postJson("/api/v1/workspaces/{$workspace->id}/staking/simulate", [
        'asset' => 'USDT',
        'principal' => 1000,
        'apy' => 10,
        'duration_days' => 30,
    ])->assertOk()
      ->json();

    expect($simulate['projected_yield'])->toBeGreaterThan(0);

    $start = $this->postJson("/api/v1/workspaces/{$workspace->id}/staking/start", [
        'asset' => 'USDT',
        'principal' => 1000,
        'apy' => 10,
        'duration_days' => 30,
    ])->assertCreated()
      ->json();

    expect($start['asset'])->toBe('USDT')
        ->and($start['status'])->toBe('active');

    $list = $this->getJson("/api/v1/workspaces/{$workspace->id}/staking")
        ->assertOk()
        ->json();

    expect($list)->toHaveCount(1);
});
