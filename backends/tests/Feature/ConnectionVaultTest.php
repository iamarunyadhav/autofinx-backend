<?php

use App\Models\ExchangeConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ExchangeSecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('encrypts and masks exchange secrets', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $vault = app(ExchangeSecretVault::class);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/connections", [
        'name' => 'Binance',
        'provider' => 'binance',
        'api_key' => 'abc123',
        'api_secret' => 'secretXYZ',
    ])->assertCreated();

    $connection = ExchangeConnection::first();
    expect($connection->api_key_encrypted)->not()->toBe('abc123');
    expect($vault->decrypt($connection->api_key_encrypted))->toBe('abc123');

    $list = $this->actingAs($user)->getJson("/api/v1/workspaces/{$workspace->id}/connections")->assertOk()->json();
    expect($list[0]['masked_api_key'])->toContain('••••');
});
