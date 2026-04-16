<?php

use App\Domain\Exchange\BinanceSpotSymbolService;
use App\Domain\Integrations\IntegrationManager;
use App\Models\ExchangeSymbolMetadata;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createUnitBinanceIntegration(): Integration
{
    $workspace = Workspace::factory()->create();
    $manager = app(IntegrationManager::class);

    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Testnet',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => $manager->encryptSecrets([
            'api_key' => 'key',
            'api_secret' => 'secret',
        ]),
    ]);
}

test('it normalizes symbol quantity and price using cached binance metadata', function () {
    $integration = createUnitBinanceIntegration();

    ExchangeSymbolMetadata::create([
        'id' => (string) Str::uuid(),
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'status' => 'TRADING',
        'base_asset' => 'BTC',
        'quote_asset' => 'USDT',
        'min_qty' => 0.0001,
        'max_qty' => 100,
        'step_size' => 0.0001,
        'min_notional' => 10,
        'tick_size' => 0.01,
        'last_synced_at' => now(),
        'raw_json' => [],
    ]);

    $validated = app(BinanceSpotSymbolService::class)->validateForOrder(
        $integration,
        'btc/usdt',
        0.00123456,
        30000.1234,
        30000.1234,
        'limit',
    );

    expect($validated['symbol'])->toBe('BTCUSDT')
        ->and($validated['qty'])->toBe(0.0012)
        ->and($validated['price'])->toBe(30000.12)
        ->and($validated['rules']['step_size'])->toBe(0.0001)
        ->and($validated['rules']['tick_size'])->toBe(0.01);
});

test('it rejects orders that violate cached min notional filters', function () {
    $integration = createUnitBinanceIntegration();

    ExchangeSymbolMetadata::create([
        'id' => (string) Str::uuid(),
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'status' => 'TRADING',
        'base_asset' => 'BTC',
        'quote_asset' => 'USDT',
        'min_qty' => 0.0001,
        'max_qty' => 100,
        'step_size' => 0.0001,
        'min_notional' => 10,
        'tick_size' => 0.01,
        'last_synced_at' => now(),
        'raw_json' => [],
    ]);

    expect(fn () => app(BinanceSpotSymbolService::class)->validateForOrder(
        $integration,
        'BTCUSDT',
        0.0001,
        10000,
        10000,
        'limit',
    ))->toThrow(RuntimeException::class, 'below Binance minNotional');
});
