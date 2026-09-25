<?php

use App\Models\ExchangeConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * POST /connections/{connection}/sync used to be backed entirely by a
 * frontend mock (client.ts's functions.invoke('sync-connection', ...))
 * that always returned fixed fake balances (0.5 BTC, 2.3 ETH, 10000 USDT)
 * regardless of what the connection actually held — dangerous on a
 * real-money platform. This proves the real endpoint reports whatever the
 * exchange actually returns instead.
 */
it('syncs real balances from the exchange for a binance connection', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/connections", [
        'name' => 'Binance Testnet',
        'provider' => 'binance',
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
        'is_testnet' => true,
    ])->assertCreated();

    $connection = ExchangeConnection::first();
    $this->assertNull($connection->last_synced_at);

    Http::fake([
        'https://testnet.binance.vision/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.5', 'locked' => '0.25'],
                ['asset' => 'USDT', 'free' => '2000', 'locked' => '0'],
                ['asset' => 'DUST', 'free' => '0', 'locked' => '0'],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/connections/{$connection->id}/sync")
        ->assertOk();

    $response->assertJson(['success' => true]);
    $balances = $response->json('balances');

    // Zero-balance assets are filtered out by fetchBalances() itself.
    expect($balances)->toHaveCount(2);
    $byCurrency = collect($balances)->keyBy('currency');
    expect((float) $byCurrency['BTC']['available'])->toBe(1.5);
    expect((float) $byCurrency['BTC']['locked'])->toBe(0.25);
    expect((float) $byCurrency['BTC']['total'])->toBe(1.75);
    expect((float) $byCurrency['USDT']['available'])->toBe(2000.0);

    $this->assertNotNull($connection->fresh()->last_synced_at);
});

it('rejects sync for a provider without balance-sync support', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/connections", [
        'name' => 'Coinbase',
        'provider' => 'coinbase',
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
    ])->assertCreated();

    $connection = ExchangeConnection::first();

    $this->actingAs($user)
        ->postJson("/api/v1/connections/{$connection->id}/sync")
        ->assertStatus(422)
        ->assertJson(['success' => false]);
});
