<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\ExitOrchestratorService;
use App\Domain\Trading\ReconciliationService;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\PositionExitState;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

function enableExitMonitoringLiveMode(string $workspaceId): void
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

function createExitMonitoringIntegration(string $workspaceId): Integration
{
    $manager = app(IntegrationManager::class);

    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
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

function exitMonitoringExchangeInfoPayload(): array
{
    return [
        'timezone' => 'UTC',
        'serverTime' => now()->valueOf(),
        'symbols' => [[
            'symbol' => 'BTCUSDT',
            'status' => 'TRADING',
            'baseAsset' => 'BTC',
            'quoteAsset' => 'USDT',
            'filters' => [
                ['filterType' => 'LOT_SIZE', 'minQty' => '0.00001000', 'maxQty' => '1000.00000000', 'stepSize' => '0.00001000'],
                ['filterType' => 'PRICE_FILTER', 'minPrice' => '0.01000000', 'maxPrice' => '1000000.00000000', 'tickSize' => '0.01000000'],
                ['filterType' => 'MIN_NOTIONAL', 'minNotional' => '10.00000000'],
            ],
        ]],
    ];
}

it('auto arms new live spot positions from workspace exit defaults during reconciliation', function () {
    $workspace = Workspace::factory()->create();
    $integration = createExitMonitoringIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    app(\App\Domain\Trading\WorkspaceExitPolicyService::class)
        ->policyRecord($workspace->id)
        ->fill([
            'default_stop_loss_pct' => 2,
            'default_take_profit_pct' => 4,
        ])
        ->save();

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
        'exchange' => 'binance',
    ]);

    $order = Order::create([
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
        'quantity' => 0.02,
        'qty' => 0.02,
        'requested_qty' => 0.02,
        'price' => 30000,
        'status' => Order::STATUS_NEW,
        'exchange_order_id' => 'entry-order-1',
        'client_order_id' => 'entry-order-1',
        'idempotency_key' => 'entry-order-1',
        'trace_id' => (string) Str::uuid(),
        'meta' => ['mode' => 'live'],
    ]);

    app(ReconciliationService::class)->reconcileExchangeOrder($order, [
        'orderId' => 'entry-order-1',
        'symbol' => 'BTCUSDT',
        'status' => 'FILLED',
        'executedQty' => '0.02000000',
        'price' => '30000.00',
        'fills' => [[
            'tradeId' => 'entry-fill-1',
            'price' => '30000.00',
            'qty' => '0.02000000',
            'commission' => '0.02',
            'commissionAsset' => 'USDT',
            'time' => now()->valueOf(),
        ]],
        'source' => 'test_reconcile',
    ], (string) Str::uuid());

    $state = PositionExitState::query()->firstOrFail();

    expect($state->managed_mode)->toBe(PositionExitState::MODE_ARMED)
        ->and((float) $state->stop_loss_price)->toBe(29400.0)
        ->and((float) $state->take_profit_price)->toBe(31200.0);
});

it('dispatches a stop loss exit through the canonical live order path without duplicating submissions', function () {
    $workspace = Workspace::factory()->create();
    enableExitMonitoringLiveMode($workspace->id);
    $integration = createExitMonitoringIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    app(\App\Domain\Trading\WorkspaceExitPolicyService::class)
        ->policyRecord($workspace->id)
        ->fill([
            'default_stop_loss_pct' => 2,
            'default_take_profit_pct' => 4,
        ])
        ->save();

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
        'exchange' => 'binance',
    ]);

    $entryOrder = Order::create([
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
        'quantity' => 0.02,
        'qty' => 0.02,
        'requested_qty' => 0.02,
        'price' => 30000,
        'status' => Order::STATUS_NEW,
        'exchange_order_id' => 'entry-order-2',
        'client_order_id' => 'entry-order-2',
        'idempotency_key' => 'entry-order-2',
        'trace_id' => (string) Str::uuid(),
        'meta' => ['mode' => 'live'],
    ]);

    app(ReconciliationService::class)->reconcileExchangeOrder($entryOrder, [
        'orderId' => 'entry-order-2',
        'symbol' => 'BTCUSDT',
        'status' => 'FILLED',
        'executedQty' => '0.02000000',
        'price' => '30000.00',
        'fills' => [[
            'tradeId' => 'entry-fill-2',
            'price' => '30000.00',
            'qty' => '0.02000000',
            'commission' => '0.02',
            'commissionAsset' => 'USDT',
            'time' => now()->valueOf(),
        ]],
        'source' => 'test_reconcile',
    ], (string) Str::uuid());

    app(MarketPriceCacheService::class)->setLatestPrice('binance', 'BTCUSDT', 29350, now()->valueOf());

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(exitMonitoringExchangeInfoPayload(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'exit-order-1',
            'clientOrderId' => 'exit:client',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '29350',
            'fills' => [],
        ], 200),
    ]);

    $state = PositionExitState::query()->firstOrFail();
    $result = app(ExitOrchestratorService::class)->evaluate($state);

    expect($result['executed'])->toBeTrue();

    $exitOrders = Order::query()
        ->where('workspace_id', $workspace->id)
        ->where('meta->origin', 'exit_engine')
        ->get();

    expect($exitOrders)->toHaveCount(1)
        ->and($exitOrders->first()->side)->toBe('sell')
        ->and(data_get($exitOrders->first()->meta, 'position_id'))->toBe($state->position_id);

    $second = app(ExitOrchestratorService::class)->evaluate($state->fresh());

    expect($second['executed'] ?? false)->toBeFalse()
        ->and(Order::query()->where('workspace_id', $workspace->id)->where('meta->origin', 'exit_engine')->count())->toBe(1);
});
