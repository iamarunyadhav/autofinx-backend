<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
        'services.live_trading_enabled' => true,
    ]);
});

function rejectionTestWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
    foreach ([['trading_mode', 'live'], ['live_enabled', true]] as [$key, $value]) {
        Setting::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key, 'value_json' => $value]);
    }
    ModuleSetting::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'module_key' => 'trading', 'mode' => 'live']);
    Ledger::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'balance' => 100000]);
    $integration = Integration::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'type' => 'exchange', 'provider_key' => 'binance',
        'display_name' => 'Binance Testnet', 'status' => 'active', 'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => app(IntegrationManager::class)->encryptSecrets(['api_key' => 'key', 'api_secret' => 'secret']),
    ]);

    return [$user, $workspace, $integration];
}

function rejectionTestExchangeInfo(): array
{
    return ['timezone' => 'UTC', 'serverTime' => now()->valueOf(), 'symbols' => [[
        'symbol' => 'BTCUSDT', 'status' => 'TRADING', 'baseAsset' => 'BTC', 'quoteAsset' => 'USDT',
        'filters' => [
            ['filterType' => 'LOT_SIZE', 'minQty' => '0.00001000', 'maxQty' => '1000.00000000', 'stepSize' => '0.00001000'],
            ['filterType' => 'PRICE_FILTER', 'minPrice' => '0.01000000', 'maxPrice' => '1000000.00000000', 'tickSize' => '0.01000000'],
            ['filterType' => 'MIN_NOTIONAL', 'minNotional' => '10.00000000'],
        ],
    ]]];
}

function fakeBinanceOrderPost(int $status, array $body): void
{
    Http::fake(function (Request $request) use ($status, $body) {
        if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/exchangeInfo')) {
            return Http::response(rejectionTestExchangeInfo(), 200);
        }
        if ($request->method() === 'POST' && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')) {
            return Http::response($body, $status);
        }
        if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')) {
            return Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400);
        }

        return Http::response([], 404);
    });
}

function orderPostCount(): int
{
    return count(Http::recorded(fn (Request $request) => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')));
}

test('a definite binance rejection is recorded as rejected, not uncertain', function () {
    [$user, $workspace, $integration] = rejectionTestWorkspace();
    fakeBinanceOrderPost(400, ['code' => -2010, 'msg' => 'Account has insufficient balance for requested action.']);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id, 'symbol' => 'BTCUSDT',
        'side' => 'buy', 'type' => 'market', 'qty' => 0.01, 'price' => 30000, 'idempotency_key' => 'reject-1',
    ])->assertStatus(422);

    $order = Order::query()->where('idempotency_key', 'reject-1')->firstOrFail();
    expect($order->status)->toBe(Order::STATUS_REJECTED)
        ->and($order->reconciliation_state)->toBe(Order::RECONCILIATION_ALIGNED)
        ->and($order->uncertain_at)->toBeNull()
        ->and(orderPostCount())->toBe(1);
});

test('an unknown-outcome order post is never retried', function () {
    [$user, $workspace, $integration] = rejectionTestWorkspace();
    fakeBinanceOrderPost(500, ['msg' => 'gateway timeout']);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id, 'symbol' => 'BTCUSDT',
        'side' => 'buy', 'type' => 'market', 'qty' => 0.01, 'price' => 30000, 'idempotency_key' => 'unknown-1',
    ])->assertStatus(202);

    expect(Order::query()->where('idempotency_key', 'unknown-1')->value('status'))->toBe(Order::STATUS_UNCERTAIN)
        ->and(orderPostCount())->toBe(1);
});

function uncertainOrder(Workspace $workspace, Integration $integration, string $clientOrderId, $placedAt): Order
{
    $symbol = Symbol::firstOrCreate(['symbol' => 'BTCUSDT'], ['id' => (string) Str::uuid(), 'type' => 'crypto']);

    return Order::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'integration_id' => $integration->id,
        'symbol_id' => $symbol->id, 'provider' => 'binance', 'exchange' => 'binance', 'asset_class' => 'CRYPTO',
        'asset_code' => 'BTCUSDT', 'quote_currency' => 'USDT', 'side' => 'buy', 'type' => 'market',
        'quantity' => 0.01, 'qty' => 0.01, 'requested_qty' => 0.01, 'price' => 30000,
        'status' => Order::STATUS_UNCERTAIN, 'reconciliation_state' => Order::RECONCILIATION_UNCERTAIN,
        'client_order_id' => $clientOrderId, 'idempotency_key' => $clientOrderId, 'trace_id' => (string) Str::uuid(),
        'placed_ts' => $placedAt, 'meta' => ['mode' => 'live', 'client_order_id' => $clientOrderId],
    ]);
}

test('an uncertain order that binance has no record of resolves as rejected after the grace period', function () {
    [, $workspace, $integration] = rejectionTestWorkspace();
    fakeBinanceOrderPost(500, []);
    $old = uncertainOrder($workspace, $integration, 'never-placed-1', now()->subMinutes(5));
    $recent = uncertainOrder($workspace, $integration, 'just-sent-1', now()->subSeconds(10));

    $synced = app(BinanceOrderSyncService::class)->syncForWorkspace((string) $workspace->id, 'BTCUSDT');

    expect($synced)->toBe(1)
        ->and($old->fresh()->status)->toBe('REJECTED')
        ->and($old->fresh()->reconciliation_state)->not->toBe(Order::RECONCILIATION_UNCERTAIN)
        ->and($recent->fresh()->status)->toBe(Order::STATUS_UNCERTAIN);   // too early to conclude anything
});
