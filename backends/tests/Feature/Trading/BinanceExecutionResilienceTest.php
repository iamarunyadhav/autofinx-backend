<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\ReconciliationService;
use App\Jobs\ReconcileOrdersJob;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\LiveExecutionEvent;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\OrderFill;
use App\Models\RiskEvent;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
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

function enablePhaseThreeResilienceLiveMode(string $workspaceId): void
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

function createPhaseThreeResilienceIntegration(string $workspaceId): Integration
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

function phaseThreeResilienceExchangeInfoPayload(): array
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

test('uncertain binance submissions are preserved and returned for operator review', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    enablePhaseThreeResilienceLiveMode($workspace->id);
    $integration = createPhaseThreeResilienceIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    Http::fake(function ($request) {
        if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/exchangeInfo')) {
            return Http::response(phaseThreeResilienceExchangeInfoPayload(), 200);
        }

        if ($request->method() === 'POST' && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')) {
            return Http::response(['msg' => 'gateway timeout'], 500);
        }

        if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')) {
            return Http::response(['msg' => 'Unknown order sent.'], 404);
        }

        return Http::response([], 404);
    });

    $response = $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
        'idempotency_key' => 'uncertain-submit-1',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('mode', 'live')
        ->assertJsonPath('order.status', Order::STATUS_UNCERTAIN);

    $order = Order::query()->where('idempotency_key', 'uncertain-submit-1')->firstOrFail();

    expect($order->status)->toBe(Order::STATUS_UNCERTAIN)
        ->and($order->reconciliation_state)->toBe(Order::RECONCILIATION_UNCERTAIN)
        ->and($order->uncertain_at)->not->toBeNull()
        ->and(RiskEvent::query()->where('workspace_id', $workspace->id)->where('type', 'execution_uncertain')->count())->toBe(1);
});

test('rest fallback can repair uncertain orders by client order id without duplicate booking', function () {
    $workspace = Workspace::factory()->create();
    $integration = createPhaseThreeResilienceIntegration($workspace->id);
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
        'asset_code' => 'BTCUSDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'limit',
        'quantity' => 0.02,
        'qty' => 0.02,
        'requested_qty' => 0.02,
        'price' => 30250,
        'limit_price' => 30250,
        'status' => Order::STATUS_UNCERTAIN,
        'client_order_id' => 'repair-client-1',
        'idempotency_key' => 'repair-client-1',
        'trace_id' => (string) Str::uuid(),
        'meta' => [
            'mode' => 'live',
            'client_order_id' => 'repair-client-1',
        ],
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'symbol' => 'BTCUSDT',
            'orderId' => 999,
            'price' => '30250.00',
            'origQty' => '0.02000000',
            'executedQty' => '0.02000000',
            'status' => 'FILLED',
            'clientOrderId' => 'repair-client-1',
            'fills' => [[
                'tradeId' => 'repair-fill-1',
                'price' => '30250.00',
                'qty' => '0.02000000',
                'commission' => '0.05',
                'commissionAsset' => 'USDT',
                'time' => now()->valueOf(),
            ]],
        ], 200),
    ]);

    $reconciled = app(BinanceOrderSyncService::class)->sync($order, (string) Str::uuid(), 'rest_sync');

    expect($reconciled)->not->toBeNull()
        ->and($reconciled->status)->toBe('FILLED')
        ->and($reconciled->exchange_order_id)->toBe('999')
        ->and((float) $reconciled->filled_qty)->toBe(0.02)
        ->and($reconciled->last_sync_source)->toBe('rest_sync')
        ->and(OrderFill::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(Trade::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('reconciliation ignores duplicate fills instead of double booking trades and wallet ledger', function () {
    $workspace = Workspace::factory()->create();
    $integration = createPhaseThreeResilienceIntegration($workspace->id);
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
        'asset_code' => 'BTCUSDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'limit',
        'quantity' => 0.02,
        'qty' => 0.02,
        'requested_qty' => 0.02,
        'price' => 30250,
        'limit_price' => 30250,
        'status' => Order::STATUS_NEW,
        'exchange_order_id' => 'reconcile-1',
        'client_order_id' => 'reconcile-1',
        'idempotency_key' => 'reconcile-1',
        'trace_id' => (string) Str::uuid(),
        'placed_ts' => now()->subSeconds(30),
        'meta' => ['mode' => 'live'],
    ]);

    $payload = [
        'orderId' => 'reconcile-1',
        'symbol' => 'BTCUSDT',
        'status' => 'FILLED',
        'executedQty' => '0.02000000',
        'price' => '30250.00',
        'fills' => [[
            'tradeId' => 'fill-dup-1',
            'price' => '30250.00',
            'qty' => '0.02000000',
            'commission' => '0.05',
            'commissionAsset' => 'USDT',
            'time' => now()->valueOf(),
        ]],
        'source' => 'rest_sync',
    ];

    $service = app(ReconciliationService::class);
    $service->reconcileExchangeOrder($order, $payload, (string) Str::uuid());
    $service->reconcileExchangeOrder($order->fresh(), $payload, (string) Str::uuid());

    expect(OrderFill::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(Trade::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(WalletLedgerEntry::query()->where('reference_id', $order->id)->count())->toBe(2)
        ->and(LiveExecutionEvent::query()->where('order_id', $order->id)->where('event_type', 'duplicate_fill_ignored')->count())->toBe(1);
});

test('stale pending live orders are escalated to uncertain instead of silently failing', function () {
    $workspace = Workspace::factory()->create();

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
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
        'status' => Order::STATUS_PENDING_PROVIDER,
        'trace_id' => (string) Str::uuid(),
        'meta' => ['mode' => 'live'],
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    app()->call([new ReconcileOrdersJob(), 'handle']);

    $order->refresh();

    expect($order->status)->toBe(Order::STATUS_UNCERTAIN)
        ->and($order->reconciliation_state)->toBe(Order::RECONCILIATION_UNCERTAIN)
        ->and($order->uncertain_at)->not->toBeNull()
        ->and(RiskEvent::query()->where('workspace_id', $workspace->id)->where('type', 'stale_pending_order')->count())->toBe(1)
        ->and(LiveExecutionEvent::query()->where('order_id', $order->id)->where('event_type', 'stale_pending_order')->count())->toBe(1);
});
