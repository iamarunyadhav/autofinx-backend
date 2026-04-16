<?php

use App\Domain\Ops\SafeLiveGate;
use App\Models\ExchangeConnection;
use App\Models\Ledger;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('live trading blocked when disabled', function () {
    $gate = app(SafeLiveGate::class);
    $workspace = Workspace::create(['id' => (string) Str::uuid(), 'name' => 'WS']);
    Ledger::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'balance' => 1000]);
    config()->set('app.env', 'testing');
    putenv('LIVE_TRADING_ENABLED=false');
    expect(fn() => $gate->ensureLiveAllowed($workspace->id, 'BTCUSDT', 100))->toThrow(RuntimeException::class);
});

test('live trading requires connection', function () {
    $gate = app(SafeLiveGate::class);
    $workspace = Workspace::create(['id' => (string) Str::uuid(), 'name' => 'WS']);
    expect(fn() => $gate->ensureConnection($workspace->id))->toThrow(RuntimeException::class);

    ExchangeConnection::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Binance',
        'provider' => 'binance',
        'api_key_encrypted' => 'k',
        'api_secret_encrypted' => 's',
        'is_enabled' => true,
    ]);
    $gate->ensureConnection($workspace->id);
    expect(true)->toBeTrue();
});
