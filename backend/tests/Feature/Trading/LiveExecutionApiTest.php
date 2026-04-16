<?php

use App\Domain\Integrations\IntegrationManager;
use App\Models\ExecutionAttempt;
use App\Models\Integration;
use App\Models\LiveExecutionEvent;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\OrderFill;
use App\Models\RiskEvent;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.binance.api_url' => 'https://api.binance.local',
        'services.binance.ws_url' => 'wss://api.binance.local/ws',
        'services.binance.testnet_api_url' => 'https://testnet.binance.local',
        'services.binance.testnet_ws_url' => 'wss://testnet.binance.local/ws',
    ]);
});

function enableLiveExecutionApiLiveMode(string $workspaceId): void
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

function createLiveExecutionApiIntegration(string $workspaceId): Integration
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

test('live execution apis expose orders detail fills events and anomalies', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = createLiveExecutionApiIntegration($workspace->id);
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
        'status' => Order::STATUS_PARTIAL,
        'exchange_order_id' => 'api-live-1',
        'client_order_id' => 'api-live-1',
        'idempotency_key' => 'api-live-1',
        'trace_id' => (string) Str::uuid(),
        'meta' => ['mode' => 'live'],
    ]);

    OrderFill::create([
        'id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'exchange_trade_id' => 'api-fill-1',
        'fill_price' => 30250,
        'fill_qty' => 0.01,
        'fee' => 0.01,
        'fee_asset' => 'USDT',
        'fill_ts' => now(),
        'raw_json' => [],
        'trace_id' => $order->trace_id,
    ]);

    ExecutionAttempt::create([
        'id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'attempt_no' => 1,
        'trace_id' => $order->trace_id,
        'client_order_id' => 'api-live-1',
        'provider_order_id' => 'api-live-1',
        'status' => 'acknowledged',
        'request_json' => ['symbol' => 'BTCUSDT'],
        'response_json' => ['status' => 'PARTIALLY_FILLED'],
        'latency_ms' => 180,
    ]);

    LiveExecutionEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'order_id' => $order->id,
        'integration_id' => $integration->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'symbol' => 'BTCUSDT',
        'event_type' => 'partial_fill',
        'severity' => 'info',
        'source' => 'user_stream',
        'correlation_id' => $order->trace_id,
        'payload_json' => ['filled_qty' => 0.01],
        'occurred_at' => now(),
    ]);

    RiskEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'reconciliation_mismatch',
        'severity' => 'warning',
        'reason_json' => ['order_id' => $order->id],
        'triggered_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/live/orders")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $order->id);

    $this->actingAs($user)
        ->getJson("/api/v1/live/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('order.id', $order->id)
        ->assertJsonCount(1, 'fills')
        ->assertJsonCount(1, 'attempts')
        ->assertJsonCount(1, 'events');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/live/fills")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.order_id', $order->id);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/live/execution/events")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.event_type', 'partial_fill');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/live/execution/anomalies")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.type', 'reconciliation_mismatch');
});

test('manual reconcile api repairs live order state and health api exposes the result', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enableLiveExecutionApiLiveMode($workspace->id);
    $integration = createLiveExecutionApiIntegration($workspace->id);
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
        'exchange_order_id' => '12345',
        'client_order_id' => '12345',
        'idempotency_key' => '12345',
        'trace_id' => (string) Str::uuid(),
        'placed_ts' => now()->subSeconds(20),
        'meta' => ['mode' => 'live'],
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'symbol' => 'BTCUSDT',
            'orderId' => 12345,
            'price' => '30250.00',
            'origQty' => '0.02000000',
            'executedQty' => '0.02000000',
            'status' => 'FILLED',
            'clientOrderId' => '12345',
            'fills' => [[
                'tradeId' => 'manual-reconcile-fill-1',
                'price' => '30250.00',
                'qty' => '0.02000000',
                'commission' => '0.05',
                'commissionAsset' => 'USDT',
                'time' => now()->valueOf(),
            ]],
        ], 200),
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/live/orders/{$order->id}/reconcile", [
            'workspace_id' => $workspace->id,
        ])
        ->assertOk()
        ->assertJsonPath('order.status', 'FILLED');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/live/execution/health")
        ->assertOk()
        ->assertJsonPath('rest_health.provider', 'binance')
        ->assertJsonPath('uncertain_orders', 0);
});
