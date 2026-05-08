<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('fast2x api exposes status live price chart pnl logs and trades with fake Binance data', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    Http::fake([
        'https://api.binance.com/api/v3/ticker/24hr*' => Http::response([
            'symbol' => 'BTCUSDT',
            'lastPrice' => '80000.00',
            'priceChangePercent' => '1.25',
            'volume' => '1200',
            'closeTime' => (int) floor(microtime(true) * 1000),
        ], 200),
        'https://api.binance.com/api/v3/ticker/bookTicker*' => Http::response([
            'symbol' => 'BTCUSDT',
            'bidPrice' => '79999.50',
            'askPrice' => '80000.50',
        ], 200),
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/fast2x/status")
        ->assertOk()
        ->assertJsonPath('botName', 'Fast2X');

    $this->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/fast2x/start")
        ->assertOk()
        ->assertJsonPath('state.status', 'Running');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/fast2x/live-price")
        ->assertOk()
        ->assertJsonPath('currentPrice', 80000);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/fast2x/chart")
        ->assertOk()
        ->assertJsonStructure(['points', 'markers', 'levels', 'autoManagement']);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/fast2x/pnl")
        ->assertOk()
        ->assertJsonStructure(['entryPrice', 'currentPrice', 'unrealizedPnL', 'realizedPnL', 'netPnLAfterFees']);

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/fast2x/logs")
        ->assertOk();

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/fast2x/trades")
        ->assertOk();
});
