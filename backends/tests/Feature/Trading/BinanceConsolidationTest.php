<?php

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Exchange\BinanceSpotSymbolService;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Ops\LiveExecutionGuard;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\BotLogService;
use App\Domain\Trading\LiveOrderIntentService;
use App\Domain\Trading\LiveExecutionEventService;
use App\Domain\Trading\ReconciliationService;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Domain\Venue\TradingVenueClientInterface;
use App\Jobs\OrdersReconcileJob;
use App\Jobs\SyncOrderStatusJob;
use App\Jobs\TradesReconcileJob;
use App\Jobs\WalletReconcileJob;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletSnapshot;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.binance.api_url' => 'https://api.binance.local',
        'services.binance.ws_url' => 'wss://api.binance.local/ws',
        'services.binance.testnet_api_url' => 'https://testnet.binance.local',
        'services.binance.testnet_ws_url' => 'wss://testnet.binance.local/ws',
    ]);
});

function fakeBinanceExchangeInfo(): array
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

it('routes manual live orders through the shared trading venue factory', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Execution Workspace',
        'timezone' => 'UTC',
    ]);

    ModuleSetting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'module_key' => 'trading',
        'mode' => 'live',
        'settings_json' => [],
    ]);

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Testnet',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => app(IntegrationManager::class)->encryptSecrets([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]),
    ]);

    $venue = Mockery::mock(TradingVenueClientInterface::class);
    $venue->shouldReceive('getLatestPrice')
        ->once()
        ->with('BTCUSDT')
        ->andReturn(['mid' => 30000.0]);
    $venue->shouldReceive('placeOrder')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            return $payload['asset_code'] === 'BTCUSDT'
                && $payload['client_order_id'] === 'manual-1'
                && $payload['side'] === 'BUY';
        }))
        ->andReturn([
            'order_id' => 'binance-1',
            'status' => 'NEW',
            'executed_qty' => 0,
            'avg_price' => 30000,
            'fills' => [],
            'raw' => ['clientOrderId' => 'manual-1'],
        ]);

    $venues = Mockery::mock(TradingVenueClientFactory::class);
    $venues->shouldReceive('forIntegration')
        ->once()
        ->with(Mockery::on(fn (Integration $candidate) => $candidate->id === $integration->id))
        ->andReturn($venue);

    $guard = Mockery::mock(LiveExecutionGuard::class);
    $guard->shouldReceive('assertWorkspaceCanSubmit')
        ->once()
        ->with($workspace->id, 'BTCUSDT', 300.0, 'binance', 'buy', 'manual-1', null)
        ->andReturn([]);

    $reconciliation = Mockery::mock(ReconciliationService::class);
    $reconciliation->shouldReceive('reconcileExchangeOrder')
        ->once()
        ->andReturnUsing(function (Order $order, array $payload) {
            $order->exchange_order_id = $payload['orderId'];
            $order->status = $payload['status'];
            $order->save();

            return $order->fresh();
        });

    $symbols = Mockery::mock(BinanceSpotSymbolService::class);
    $symbols->shouldReceive('validateForOrder')
        ->once()
        ->with(
            Mockery::on(fn (Integration $candidate) => $candidate->id === $integration->id),
            'BTCUSDT',
            0.01,
            null,
            30000.0,
            'market'
        )
        ->andReturn([
            'symbol' => 'BTCUSDT',
            'qty' => 0.01,
            'price' => null,
            'rules' => [
                'status' => 'TRADING',
                'min_qty' => 0.00001,
                'step_size' => 0.00001,
                'min_notional' => 10.0,
                'tick_size' => 0.01,
            ],
        ]);

    $events = Mockery::mock(LiveExecutionEventService::class);
    $events->shouldReceive('recordOrderEvent')->atLeast()->once();

    $health = Mockery::mock(BinanceSpotHealthService::class);
    $health->shouldReceive('recordSuccess')->once();

    $service = new LiveOrderIntentService(
        $venues,
        $guard,
        $reconciliation,
        $symbols,
        app(ExchangeClientFactory::class),
        $events,
        $health,
    );

    $order = $service->place([
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'btc/usdt',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'idempotency_key' => 'manual-1',
    ]);

    expect($order->exchange_order_id)->toBe('binance-1')
        ->and($order->idempotency_key)->toBe('manual-1')
        ->and(data_get($order->meta, 'client_order_id'))->toBe('manual-1')
        ->and($order->asset_code)->toBe('BTCUSDT');
});

it('reconciles integration orders through the shared binance sync service', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Integration Workspace',
        'timezone' => 'UTC',
    ]);

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Testnet',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => app(IntegrationManager::class)->encryptSecrets([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]),
    ]);

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'btc/usdt',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'limit',
        'quantity' => 0.02,
        'qty' => 0.02,
        'requested_qty' => 0.02,
        'price' => 30250,
        'status' => Order::STATUS_NEW,
        'exchange_order_id' => '777',
        'idempotency_key' => 'integration-order-1',
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(fakeBinanceExchangeInfo(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'symbol' => 'BTCUSDT',
            'orderId' => 777,
            'price' => '30250.00',
            'origQty' => '0.02000000',
            'executedQty' => '0.02000000',
            'status' => 'FILLED',
            'fills' => [
                [
                    'tradeId' => 'fill-777',
                    'price' => '30250.00',
                    'qty' => '0.02000000',
                    'commission' => '0.05',
                    'commissionAsset' => 'USDT',
                    'time' => now()->valueOf(),
                ],
            ],
        ], 200),
    ]);

    (new OrdersReconcileJob($integration->id))->handle(app(BinanceOrderSyncService::class));

    $order->refresh();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')
        && str_contains($request->url(), 'symbol=BTCUSDT'));

    expect($order->status)->toBe('FILLED')
        ->and((float) $order->filled_qty)->toBe(0.02);
});

it('reconciles bot orders through the shared binance sync service', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Bot Workspace',
        'timezone' => 'UTC',
    ]);

    $user = User::factory()->create();
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    $connection = ExchangeConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'is_testnet' => true,
    ]);

    $bot = Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connection_id' => $connection->id,
        'name' => 'Sync Bot',
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'BTCUSDT',
        'quote_currency' => 'USDT',
        'provider' => 'binance',
        'interval_sec' => 60,
        'is_live' => true,
        'status' => 'RUNNING',
        'strategy_type' => 'threshold',
        'strategy_params' => [],
    ]);

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'bot_id' => $bot->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'btc-usdt',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'limit',
        'quantity' => 0.03,
        'qty' => 0.03,
        'requested_qty' => 0.03,
        'price' => 29950,
        'status' => Order::STATUS_NEW,
        'exchange_order_id' => '888',
        'idempotency_key' => 'bot-order-1',
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(fakeBinanceExchangeInfo(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'symbol' => 'BTCUSDT',
            'orderId' => 888,
            'price' => '29950.00',
            'origQty' => '0.03000000',
            'executedQty' => '0.01000000',
            'status' => 'PARTIALLY_FILLED',
            'fills' => [
                [
                    'tradeId' => 'fill-888',
                    'price' => '29950.00',
                    'qty' => '0.01000000',
                    'commission' => '0.01',
                    'commissionAsset' => 'USDT',
                    'time' => now()->valueOf(),
                ],
            ],
        ], 200),
    ]);

    (new SyncOrderStatusJob(50))->handle(
        app(TradingVenueClientFactory::class),
        app(BinanceOrderSyncService::class),
        app(ReconciliationService::class),
        app(BotLogService::class),
    );

    $order->refresh();

    expect($order->status)->toBe('PARTIAL')
        ->and((float) $order->filled_qty)->toBe(0.01);
});

it('reconciles binance wallet snapshots through the shared exchange client factory', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Wallet Workspace',
        'timezone' => 'UTC',
    ]);

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Testnet',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => app(IntegrationManager::class)->encryptSecrets([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]),
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'USDT', 'free' => '120.50', 'locked' => '10.00'],
            ],
        ], 200),
    ]);

    (new WalletReconcileJob($integration->id))->handle(
        app(IntegrationManager::class),
        app(ExchangeClientFactory::class),
    );

    expect(WalletSnapshot::query()->where('integration_id', $integration->id)->exists())->toBeTrue()
        ->and((float) WalletSnapshot::query()->where('integration_id', $integration->id)->first()->total)->toBe(130.5);
});

it('reconciles binance trade history through the shared exchange client factory', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Trades Workspace',
        'timezone' => 'UTC',
    ]);

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Testnet',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => app(IntegrationManager::class)->encryptSecrets([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]),
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
        'quantity' => 0.05,
        'qty' => 0.05,
        'requested_qty' => 0.05,
        'price' => 29800,
        'status' => 'filled',
        'exchange_order_id' => '999',
        'idempotency_key' => 'trade-order-1',
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/myTrades*' => Http::response([
            [
                'id' => 4242,
                'price' => '29800.00',
                'qty' => '0.05000000',
                'commission' => '0.02',
                'commissionAsset' => 'USDT',
                'isBuyer' => true,
            ],
        ], 200),
    ]);

    (new TradesReconcileJob($integration->id))->handle(
        app(IntegrationManager::class),
        app(ExchangeClientFactory::class),
    );

    expect(Trade::query()->where('integration_id', $integration->id)->where('exchange_trade_id', '4242')->exists())->toBeTrue();
});
