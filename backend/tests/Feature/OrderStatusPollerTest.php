<?php

use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\Workspace;
use App\Support\OrderStatusPoller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('polls pending provider orders through the shared binance sync path', function () {
    config([
        'services.binance.testnet_api_url' => 'https://testnet.binance.local',
        'services.binance.testnet_ws_url' => 'wss://testnet.binance.local/ws',
    ]);

    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Test Workspace',
        'timezone' => 'UTC',
    ]);

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    $connection = ExchangeConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'is_testnet' => true,
    ]);

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol_id' => $symbol->id,
        'provider' => 'binance',
        'exchange' => 'binance',
        'asset_class' => 'CRYPTO',
        'asset_code' => 'BTC/USDT',
        'quote_currency' => 'USDT',
        'side' => 'buy',
        'type' => 'market',
        'quantity' => 0.1,
        'qty' => 0.1,
        'requested_qty' => 0.1,
        'price' => 20000,
        'status' => Order::STATUS_PENDING_PROVIDER,
        'meta' => [
            'connection_id' => $connection->id,
            'client_order_id' => 'client-order-1',
        ],
        'idempotency_key' => 'client-order-1',
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'symbol' => 'BTCUSDT',
            'orderId' => 12345,
            'clientOrderId' => 'client-order-1',
            'price' => '20100.00',
            'origQty' => '0.10000000',
            'executedQty' => '0.10000000',
            'status' => 'FILLED',
            'fills' => [
                [
                    'tradeId' => 'fill-1',
                    'price' => '20100.00',
                    'qty' => '0.10000000',
                    'commission' => '0.10',
                    'commissionAsset' => 'USDT',
                    'time' => now()->valueOf(),
                ],
            ],
        ], 200),
    ]);

    $summary = app(OrderStatusPoller::class)->poll(10);

    $order->refresh();

    expect($summary)->toMatchArray([
        'polled' => 1,
        'updated' => 1,
        'failed' => 0,
    ])->and($order->status)->toBe('FILLED')
        ->and($order->exchange_order_id)->toBe('12345')
        ->and((float) $order->filled_qty)->toBe(0.1);
});
