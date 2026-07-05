<?php

use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Domain\Venue\MetalBrokerClient;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('provider catalog includes metal_broker tagged as FOREX', function () {
    $catalog = app(LiveTradeProviderRegistry::class)->catalog();

    expect($catalog)->toHaveKey('metal_broker');
    expect($catalog['metal_broker']['asset_class'])->toBe('FOREX');
    expect($catalog['metal_broker']['label'])->toBe('Forex & Metals Broker');

    expect($catalog['binance']['asset_class'])->toBe('CRYPTO');
    expect($catalog['binance']['live_supported'])->toBeTrue();
});

test('LiveTradeProviderRegistry::for still throws for metal_broker (no LiveTradeProviderInterface impl yet)', function () {
    app(LiveTradeProviderRegistry::class)->for('metal_broker');
})->throws(RuntimeException::class);

test('TradingVenueClientFactory resolves a metal_broker integration to MetalBrokerClient', function () {
    $workspace = Workspace::factory()->create();

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'trading',
        'provider_key' => 'metal_broker',
        'display_name' => 'Test Metals Broker',
        'status' => 'active',
        'environment' => 'live',
    ]);

    $client = app(TradingVenueClientFactory::class)->forIntegration($integration);

    expect($client)->toBeInstanceOf(MetalBrokerClient::class);
    expect($client->provider())->toBe('metal_broker');
});

test('TradingVenueClientFactory still throws for a truly unsupported integration provider', function () {
    $workspace = Workspace::factory()->create();

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'trading',
        'provider_key' => 'unknown_broker',
        'display_name' => 'Unknown',
        'status' => 'active',
        'environment' => 'live',
    ]);

    app(TradingVenueClientFactory::class)->forIntegration($integration);
})->throws(RuntimeException::class);
