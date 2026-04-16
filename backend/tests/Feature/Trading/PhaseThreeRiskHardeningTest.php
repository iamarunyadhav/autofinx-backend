<?php

use App\Domain\Integrations\IntegrationManager;
use App\Models\Guardrails;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\RiskProfile;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('LIVE_TRADING_ENABLED=true');
    $_ENV['LIVE_TRADING_ENABLED'] = 'true';
    $_SERVER['LIVE_TRADING_ENABLED'] = 'true';
    config([
        'services.binance.api_url' => 'https://api.binance.local',
        'services.binance.ws_url' => 'wss://api.binance.local/ws',
        'services.binance.testnet_api_url' => 'https://testnet.binance.local',
        'services.binance.testnet_ws_url' => 'wss://testnet.binance.local/ws',
    ]);
});

function enablePhaseThreeLiveMode(string $workspaceId): void
{
    Setting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'group' => 'trading',
        'key' => 'trading_mode',
        'value_json' => 'live',
    ]);

    Setting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'group' => 'trading',
        'key' => 'live_enabled',
        'value_json' => true,
    ]);

    ModuleSetting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'module_key' => 'trading',
        'mode' => 'live',
    ]);
}

function createPhaseThreeIntegration(string $workspaceId): Integration
{
    $manager = app(IntegrationManager::class);

    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => $manager->encryptSecrets([
            'api_key' => 'key',
            'api_secret' => 'secret',
        ]),
    ]);
}

test('live manual orders are blocked by default risk profile blocked symbols', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enablePhaseThreeLiveMode($workspace->id);
    $integration = createPhaseThreeIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Blocked BTC',
        'is_default' => true,
        'rules' => [
            'blocked_symbols' => ['BTCUSDT'],
        ],
    ]);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Symbol BTCUSDT is blocked by workspace risk policy.');
});

test('live manual orders are blocked after daily max orders reached', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enablePhaseThreeLiveMode($workspace->id);
    $integration = createPhaseThreeIntegration($workspace->id);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'ETHUSDT',
        'type' => 'crypto',
    ]);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Daily cap',
        'is_default' => true,
        'rules' => [
            'daily_max_orders' => 1,
        ],
    ]);

    Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'ETHUSDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'market',
        'quantity' => 0.01,
        'qty' => 0.01,
        'requested_qty' => 0.01,
        'price' => 2500,
        'status' => Order::STATUS_FILLED,
        'idempotency_key' => 'daily-cap-order',
        'meta' => ['mode' => 'live'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 2500,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Daily max live order limit reached.');
});

test('live manual orders are blocked by duplicate pending order protection', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enablePhaseThreeLiveMode($workspace->id);
    $integration = createPhaseThreeIntegration($workspace->id);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Default',
        'is_default' => true,
        'rules' => [],
    ]);

    Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'BTCUSDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'market',
        'quantity' => 0.01,
        'qty' => 0.01,
        'requested_qty' => 0.01,
        'price' => 30000,
        'status' => Order::STATUS_NEW,
        'idempotency_key' => 'pending-order',
        'meta' => ['mode' => 'live'],
    ]);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.02,
        'price' => 30000,
        'idempotency_key' => 'new-order',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Duplicate live order already pending for this symbol and side.');
});

test('risk profile create and update accept flat ui fields and persist canonical rules', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $create = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/risk-profiles", [
        'name' => 'UI Profile',
        'is_default' => true,
        'max_position_percent' => 12,
        'max_daily_loss_percent' => 4,
        'max_drawdown_percent' => 11,
        'max_leverage' => 2,
        'stop_loss_percent' => 1.5,
        'take_profit_percent' => 4.5,
        'slippage_tolerance' => 0.005,
        'daily_max_orders' => 8,
        'allowed_symbols' => ['btc/usdt', 'ethusdt'],
        'blocked_symbols' => ['xrpusdt'],
    ])->assertCreated();

    $profileId = (string) $create->json('id');
    $stored = RiskProfile::findOrFail($profileId);

    expect($stored->is_default)->toBeTrue()
        ->and((float) $stored->rules['max_position_pct'])->toBe(12.0)
        ->and((float) $stored->rules['daily_loss_pct'])->toBe(4.0)
        ->and((float) $stored->rules['drawdown_pct'])->toBe(11.0)
        ->and($stored->rules['stop_loss_pct'])->toBe(1.5)
        ->and($stored->rules['take_profit_pct'])->toBe(4.5)
        ->and((float) $stored->rules['slippage_bps'])->toBe(50.0)
        ->and($stored->rules['allowed_symbols'])->toBe(['BTCUSDT', 'ETHUSDT'])
        ->and($stored->rules['blocked_symbols'])->toBe(['XRPUSDT']);

    $this->actingAs($user)->patchJson("/api/v1/workspaces/{$workspace->id}/risk-profiles/{$profileId}", [
        'max_open_orders' => 3,
        'max_open_positions' => 2,
        'trailing_stop_percent' => 2.25,
    ])->assertOk()
        ->assertJsonPath('max_open_orders', 3)
        ->assertJsonPath('max_open_positions', 2)
        ->assertJsonPath('trailing_stop_percent', 2.25);
});

test('guardrails max open orders acts as a stricter workspace wide live cap', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enablePhaseThreeLiveMode($workspace->id);
    $integration = createPhaseThreeIntegration($workspace->id);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'ETHUSDT',
        'type' => 'crypto',
    ]);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Open order cap',
        'is_default' => true,
        'rules' => [
            'max_open_orders' => 5,
        ],
    ]);

    Guardrails::create([
        'workspace_id' => $workspace->id,
        'max_open_orders' => 1,
    ]);

    Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'ETHUSDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'market',
        'quantity' => 0.01,
        'qty' => 0.01,
        'requested_qty' => 0.01,
        'price' => 2500,
        'status' => Order::STATUS_NEW,
        'idempotency_key' => 'guardrail-order',
        'meta' => ['mode' => 'live'],
    ]);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Max open live orders reached.');
});
