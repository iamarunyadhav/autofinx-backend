<?php

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotTrade;
use App\Models\FutureTradeOrderAudit;
use App\Models\FutureTradeWebhookAudit;
use App\Models\MarketOhlc;
use App\Models\StrategyLabShadowSignal;
use App\Models\Workspace;
use App\Models\WorkspaceWebhookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use App\Domain\StrategyLab\StrategyLabShadowService;

uses(RefreshDatabase::class);

function shadowWebhookPayload(array $overrides = []): array
{
    return array_merge([
        'token' => 'workspace-secret', 'strategy_id' => 'ut_unified_v2', 'symbol' => 'BTCUSDT.P',
        'signal' => 'BUY', 'timeframe' => '60', 'time_close' => now()->subSecond()->valueOf(),
        'price' => 80000, 'entry' => 80000, 'sl' => 79200, 'tp1' => 80800, 'tp2' => 81600,
        'strategy_order_id' => 'ut-test-1-buy',
    ], $overrides);
}

function setWorkspaceWebhookToken(string $workspace, string $token): void
{
    WorkspaceWebhookToken::create([
        'workspace_id' => $workspace, 'scope' => WorkspaceWebhookToken::SCOPE_TRADINGVIEW,
        'token_hash' => Hash::make($token), 'token_hint' => substr($token, -8), 'rotated_at' => now(),
    ]);
}

test('invalid token cannot create a future trade config', function () {
    config(['future_trade.allow_global_webhook_token_fallback' => false]);
    $workspace = Workspace::factory()->create();
    setWorkspaceWebhookToken($workspace->id, 'correct-secret');

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", shadowWebhookPayload(['token' => 'wrong']))
        ->assertUnauthorized()->assertJsonPath('reason', 'invalid_or_missing_webhook_token');

    expect(FutureTradeBotConfig::count())->toBe(0)
        ->and(FutureTradeWebhookAudit::count())->toBe(1)
        ->and(FutureTradeWebhookAudit::first()->future_trade_bot_config_id)->toBeNull();
});

test('legacy tradingview webhook refuses to guess a workspace', function () {
    $this->postJson('/api/v1/future-trade/webhook/tradingview', shadowWebhookPayload(['workspace_id' => null]))
        ->assertStatus(422)->assertJsonPath('reason', 'workspace_required');
});

test('workspace token is isolated from another workspace', function () {
    config(['future_trade.allow_global_webhook_token_fallback' => false]);
    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();
    setWorkspaceWebhookToken($workspaceA->id, 'token-a');
    setWorkspaceWebhookToken($workspaceB->id, 'token-b');

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspaceB->id}", shadowWebhookPayload(['token' => 'token-a']))
        ->assertUnauthorized();

    expect(FutureTradeBotConfig::count())->toBe(0);
});

test('strategy lab webhook records a shadow signal and cannot place an order', function () {
    Queue::fake();
    config(['future_trade.allow_global_webhook_token_fallback' => false]);
    $workspace = Workspace::factory()->create();
    setWorkspaceWebhookToken($workspace->id, 'workspace-secret');

    $this->postJson("/api/v1/strategy-lab/webhook/{$workspace->id}", shadowWebhookPayload())
        ->assertStatus(202)->assertJsonPath('accepted', true)->assertJsonPath('executed', false)
        ->assertJsonPath('shadow_only', true);

    $signal = StrategyLabShadowSignal::first();
    expect($signal)->not->toBeNull()
        ->and($signal->timeframe)->toBe('1h')
        ->and($signal->payload)->not->toHaveKey('token')
        ->and(FutureTradeBotTrade::count())->toBe(0)
        ->and(FutureTradeOrderAudit::count())->toBe(0)
        ->and(FutureTradeBotConfig::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('strategy lab webhook only allows the frozen timeframes', function () {
    config(['future_trade.allow_global_webhook_token_fallback' => false]);
    $workspace = Workspace::factory()->create();
    setWorkspaceWebhookToken($workspace->id, 'workspace-secret');

    $this->postJson("/api/v1/strategy-lab/webhook/{$workspace->id}", shadowWebhookPayload(['timeframe' => '5m']))
        ->assertUnprocessable();
    expect(StrategyLabShadowSignal::count())->toBe(0);
});

test('shadow report forward evaluates saved signals with costs and no execution', function () {
    $workspace = Workspace::factory()->create();
    $signal = StrategyLabShadowSignal::create([
        'workspace_id' => $workspace->id, 'strategy_id' => 'ut_unified_v2', 'strategy_order_id' => 'report-1',
        'symbol' => 'BTCUSDT', 'timeframe' => '1h', 'signal' => 'BUY', 'entry' => 100, 'sl' => 98,
        'tp1' => 102, 'tp2' => 104, 'time_close_ms' => now()->subHours(2)->valueOf(), 'latency_ms' => 1000,
        'payload' => [], 'received_at' => now()->subHours(2),
    ]);
    MarketOhlc::create([
        'exchange' => 'binance', 'symbol' => 'BTCUSDT', 'timeframe' => '1h', 'open' => 100,
        'high' => 104.5, 'low' => 99.5, 'close' => 104, 'volume' => 10, 'ts' => now()->subHour(),
    ]);

    $report = app(StrategyLabShadowService::class)->report($workspace->id, 'ut_unified_v2');
    $signal->refresh();

    expect($report['orders_placed'])->toBe(0)
        ->and($report['summary']['evaluated'])->toBe(1)
        ->and($signal->exit_reason)->toBe('TP2')
        ->and(round($signal->gross_return_pct, 2))->toBe(4.0)
        ->and(round($signal->net_return_pct, 2))->toBe(3.88)
        ->and(FutureTradeBotTrade::count())->toBe(0)
        ->and(FutureTradeOrderAudit::count())->toBe(0);
});
