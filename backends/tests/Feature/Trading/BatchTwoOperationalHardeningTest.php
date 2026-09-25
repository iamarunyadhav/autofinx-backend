<?php

use App\Domain\Exchange\BinanceUserStreamService;
use App\Domain\Exchange\BinanceFuturesUserStreamService;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Jobs\DetectStaleFeedsJob;
use App\Jobs\KeepAliveUserStreamsJob;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\MarketTick;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('LIVE_TRADING_ENABLED=true');
    $_ENV['LIVE_TRADING_ENABLED'] = 'true';
    $_SERVER['LIVE_TRADING_ENABLED'] = 'true';
    putenv('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC=60');
    $_ENV['LIVE_TRADING_STALE_FEED_THRESHOLD_SEC'] = '60';
    $_SERVER['LIVE_TRADING_STALE_FEED_THRESHOLD_SEC'] = '60';

    config([
        'services.binance.api_url' => 'https://api.binance.local',
        'services.binance.ws_url' => 'wss://api.binance.local/ws',
        'services.binance.testnet_api_url' => 'https://testnet.binance.local',
        'services.binance.testnet_ws_url' => 'wss://testnet.binance.local/ws',
    ]);
});

function enableOperationalLiveMode(string $workspaceId): void
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

function createOperationalIntegration(string $workspaceId, string $environment = 'testnet'): Integration
{
    $manager = app(IntegrationManager::class);

    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance',
        'status' => 'active',
        'config_json' => ['environment' => $environment],
        'secrets_encrypted' => $manager->encryptSecrets([
            'api_key' => 'key',
            'api_secret' => 'secret',
        ]),
    ]);
}

test('live orders are blocked by the provider outage breaker', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    enableOperationalLiveMode($workspace->id);
    $integration = createOperationalIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    app(OperationalBreakerService::class)->activateProviderOutage($workspace->id, 'binance', 'maintenance');

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Exchange outage breaker active for binance.');
});

test('detect stale feeds activates breaker and blocks live orders', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    enableOperationalLiveMode($workspace->id);
    $integration = createOperationalIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    MarketTick::create([
        'id' => (string) Str::uuid(),
        'symbol_id' => $symbol->id,
        'price' => 30000,
        'bid' => 29999,
        'ask' => 30001,
        'volume' => 1,
        'occurred_at' => now()->subMinutes(5),
    ]);

    RatioBotConfig::create([
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'market_type' => 'spot',
        'execution_mode' => 'sandbox',
        'enabled' => true,
        'sell_threshold_ratio' => 0.02,
        'buy_threshold_ratio' => 0.01,
    ]);

    app()->call([new DetectStaleFeedsJob(60), 'handle']);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response([
            'symbols' => [[
                'symbol' => 'BTCUSDT',
                'status' => 'TRADING',
                'filters' => [
                    ['filterType' => 'LOT_SIZE', 'minQty' => '0.00001000', 'maxQty' => '1000.00000000', 'stepSize' => '0.00001000'],
                    ['filterType' => 'PRICE_FILTER', 'minPrice' => '0.01000000', 'maxPrice' => '1000000.00000000', 'tickSize' => '0.01000000'],
                    ['filterType' => 'MIN_NOTIONAL', 'minNotional' => '10.00000000'],
                ],
            ]],
        ], 200),
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
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', fn (string $message): bool => in_array($message, [
                'Stale feed breaker active for live trading.',
                'Latest market feed is stale for live trading.',
            ], true))
            ->etc()
        );
});

test('live cancel route cancels orders through the canonical backend path', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $integration = createOperationalIntegration($workspace->id);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'BTC/USDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'market',
        'quantity' => 0.01,
        'qty' => 0.01,
        'requested_qty' => 0.01,
        'price' => 30000,
        'status' => Order::STATUS_NEW,
        'exchange_order_id' => 'cancel-1',
        'idempotency_key' => 'cancel-order-1',
        'meta' => [
            'mode' => 'live',
            'client_order_id' => 'cancel-order-1',
            'symbol_normalized' => 'BTCUSDT',
        ],
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'symbol' => 'BTCUSDT',
            'orderId' => 'cancel-1',
            'status' => 'CANCELED',
            'executedQty' => '0',
            'price' => '30000.00',
        ], 200),
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/live/orders/{$order->id}", [
        'workspace_id' => $workspace->id,
    ])->assertOk()
        ->assertJsonPath('order.status', 'CANCELLED');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order'));
});

test('keep alive job restarts failed binance user streams and clears outage state', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $connection = ExchangeConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'is_testnet' => true,
        'is_active' => true,
    ]);

    Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connection_id' => $connection->id,
        'name' => 'Live Bot',
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

    Cache::put("binance:user-stream:{$connection->id}:listen-key", 'stale-listen-key', now()->addMinutes(55));
    app(OperationalBreakerService::class)->activateProviderOutage($workspace->id, 'binance', 'stream_down');

    Http::fake([
        'https://testnet.binance.local/api/v3/userDataStream' => Http::sequence()
            ->push(['msg' => 'listen key expired'], 500)
            ->push(['listenKey' => 'fresh-listen-key'], 200),
    ]);

    $sync = Mockery::mock(BinanceOrderSyncService::class);
    $sync->shouldReceive('syncForConnection')
        ->once()
        ->with(Mockery::on(fn (ExchangeConnection $candidate) => $candidate->id === $connection->id))
        ->andReturn(0);

    (new KeepAliveUserStreamsJob())->handle(
        app(BinanceUserStreamService::class),
        $sync,
        Mockery::mock(BinanceFuturesUserStreamService::class),
        Mockery::mock(FuturesPositionSyncService::class),
        Mockery::mock(FuturesConfigService::class),
    );

    $streamState = app(OperationalBreakerService::class)->streamState($connection);
    $providerState = app(OperationalBreakerService::class)->providerState($workspace->id, 'binance');

    expect($streamState['status'])->toBe('running')
        ->and($streamState['listen_key'])->toBe('fresh-listen-key')
        ->and((int) $streamState['restart_count'])->toBeGreaterThanOrEqual(1)
        ->and($providerState['active'])->toBeFalse();
});
