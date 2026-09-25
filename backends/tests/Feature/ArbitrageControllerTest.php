<?php

use App\Models\ExchangeConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns arbitrage opportunities when multiple connections exist', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    ExchangeConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'provider' => 'binance',
        'api_key_encrypted' => 'k1',
        'api_secret_encrypted' => 's1',
        'is_enabled' => true,
    ]);
    ExchangeConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'provider' => 'coinbase',
        'api_key_encrypted' => 'k2',
        'api_secret_encrypted' => 's2',
        'is_enabled' => true,
    ]);

    $res = $this->actingAs($user)->getJson("/api/v1/workspaces/{$workspace->id}/arbitrage?symbols[]=BTCUSDT")
        ->assertOk()
        ->json();

    expect($res)->toBeArray();
});
