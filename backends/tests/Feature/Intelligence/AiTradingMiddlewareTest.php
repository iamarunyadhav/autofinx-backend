<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\KillSwitchService;
use App\Jobs\ExpireAiDecisionsJob;
use App\Models\AiDecision;
use App\Models\AiDecisionEvent;
use App\Models\AiTradingConfig;
use App\Models\Bot;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\MarketCandle;
use App\Models\ModuleSetting;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\TradeSignal;
use App\Models\User;
use App\Models\WalletAccount;
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
        'intelligence.ai_trading.provider' => 'heuristic',
        'services.binance.api_url' => 'https://api.binance.local',
        'services.binance.ws_url' => 'wss://api.binance.local/ws',
        'services.binance.testnet_api_url' => 'https://testnet.binance.local',
        'services.binance.testnet_ws_url' => 'wss://testnet.binance.local/ws',
    ]);
});

function seedAiTradingWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    return [$user, $workspace];
}

function enableAiTradingWorkspaceLiveMode(string $workspaceId): void
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

function createAiTradingLiveIntegration(string $workspaceId): Integration
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

function seedAiTradingBalances(string $workspaceId, string $userId): void
{
    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'balance' => 100000,
    ]);

    WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
        'currency' => 'USDT',
        'type' => 'cash',
        'name' => 'USDT Wallet',
        'status' => 'active',
        'balance' => 5000,
        'available' => 5000,
        'locked_balance' => 0,
    ]);
}

function seedAiTradingBullishMarketContext(string $workspaceId, string $userId, string $symbolCode = 'BTCUSDT'): string
{
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => $symbolCode,
        'type' => 'crypto',
    ]);

    $bot = Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
        'name' => 'AI Middleware Bot',
        'exchange' => 'binance',
        'symbol' => $symbolCode,
        'asset_class' => 'CRYPTO',
        'asset_code' => $symbolCode,
        'quote_currency' => 'USDT',
        'provider' => 'binance',
        'interval_sec' => 60,
        'is_live' => true,
        'status' => 'RUNNING',
        'strategy_type' => 'threshold',
        'strategy_params' => [],
    ]);

    $close = 30000.0;
    $deltas = [12, -8, 10, -6, 11, -7, 9, -5];
    foreach (range(1, 30) as $index) {
        $open = $close;
        $close = $open + $deltas[($index - 1) % count($deltas)];

        MarketCandle::create([
            'id' => (string) Str::uuid(),
            'symbol_id' => $symbol->id,
            'timeframe' => '1m',
            'opens_at' => now()->subMinutes(31 - $index),
            'open' => $open,
            'high' => max($open, $close) + 2,
            'low' => min($open, $close) - 2,
            'close' => $close,
            'volume' => 100 + $index,
        ]);
    }

    app(MarketPriceCacheService::class)->setLatestPrice('binance', $symbolCode, $close);

    TradeSignal::create([
        'id' => (string) Str::uuid(),
        'bot_id' => $bot->id,
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
        'asset_class' => 'CRYPTO',
        'asset_code' => $symbolCode,
        'quote_currency' => 'USDT',
        'provider' => 'binance',
        'symbol' => $symbolCode,
        'side' => 'BUY',
        'decision_price' => $close,
        'decision_ts' => now(),
        'max_slippage_pct' => 0.2,
        'ttl_ms' => 30000,
        'fill_timeout_ms' => 15000,
        'exec_policy' => 'market',
        'status' => 'PENDING',
        'trace_id' => (string) Str::uuid(),
        'meta_json' => ['qty' => 0.01],
    ]);

    return $symbolCode;
}

function upsertAiTradingConfig(string $workspaceId, array $overrides = []): AiTradingConfig
{
    return AiTradingConfig::updateOrCreate(
        ['workspace_id' => $workspaceId],
        array_merge([
            'id' => (string) Str::uuid(),
            'enabled' => true,
            'mode' => 'advisor',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'allowed_symbols' => ['BTCUSDT'],
            'blocked_symbols' => [],
            'max_trade_size_pct' => 1.0,
            'min_confidence' => 0.5,
            'require_stop_loss' => true,
            'require_take_profit' => true,
            'require_time_stop' => true,
            'auto_trade_enabled' => false,
        ], $overrides)
    );
}

function fakeAiTradingExchangeInfo(): array
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

it('creates and stores an ai proposal', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id);

    $response = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->assertCreated();

    $decision = AiDecision::first();

    expect($response->json('decision.id'))->toBe($decision->id)
        ->and($decision->symbol)->toBe($symbol)
        ->and($decision->validated_proposal_json)->not->toBeEmpty();
});

it('stores ai proposals as pending approval in manual mode', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'manual_approval',
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_PENDING_APPROVAL);
});

it('approval can trigger the canonical safe live execution path', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'manual_approval',
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(fakeAiTradingExchangeInfo(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'ai-order-1',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '30350',
            'fills' => [],
        ], 200),
    ]);

    $proposal = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->json('decision');

    $decisionId = $proposal['id'];

    $this->actingAs($user)->postJson("/api/v1/ai-trading/decisions/{$decisionId}/approve", [])
        ->assertOk()
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_EXECUTED);

    expect(Order::count())->toBe(1)
        ->and(Order::first()->idempotency_key)->toBe("ai-decision-{$decisionId}");

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://testnet.binance.local/api/v3/order')
        && strtoupper((string) data_get($request->data(), 'type')) === 'MARKET');
});

it('workspace approval endpoints expose the pending queue and support deferred approval', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'manual_approval',
    ]);

    $decision = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->json('decision');

    $this->actingAs($user)
        ->getJson("/api/v1/workspaces/{$workspace->id}/ai-trading/approvals")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $decision['id']);

    $this->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/approvals/{$decision['id']}/approve", [
            'execute_now' => false,
        ])
        ->assertOk()
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_APPROVED)
        ->assertJsonPath('executed', false);

    expect(Order::count())->toBe(0);
});

it('workspace approval endpoint requires an explicit rejection reason', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'manual_approval',
    ]);

    $decision = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->json('decision');

    $this->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/approvals/{$decision['id']}/reject", [
            'reason' => 'confidence_below_operator_threshold',
        ])
        ->assertOk()
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_REJECTED);
});

it('rejected ai proposals never execute', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'manual_approval',
    ]);

    $proposal = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->json('decision');

    $this->actingAs($user)->postJson("/api/v1/ai-trading/decisions/{$proposal['id']}/reject", [
        'reason' => 'operator_rejected',
    ])->assertOk()
        ->assertJsonPath('final_status', AiDecision::STATUS_REJECTED);

    expect(Order::count())->toBe(0);
});

it('ai proposals cannot bypass the live guard', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    app(KillSwitchService::class)->activate($workspace->id, $user->id, 'test');

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', \App\Models\AiDecisionCycle::STATUS_PAUSED);

    expect(Order::count())->toBe(0);
});

it('restricted auto mode can execute immediately when guards allow it', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(fakeAiTradingExchangeInfo(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'ai-order-2',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '30350',
            'fills' => [],
        ], 200),
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_EXECUTED);

    expect(Order::count())->toBe(1);
});

it('records lifecycle events and notifications for manual approval decisions', function () {
    [$user, $workspace] = seedAiTradingWorkspace();
    enableAiTradingWorkspaceLiveMode($workspace->id);
    $integration = createAiTradingLiveIntegration($workspace->id);
    seedAiTradingBalances($workspace->id, $user->id);
    $symbol = seedAiTradingBullishMarketContext($workspace->id, $user->id);
    upsertAiTradingConfig($workspace->id, [
        'mode' => 'manual_approval',
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(fakeAiTradingExchangeInfo(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'ai-order-3',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '30050',
            'fills' => [],
        ], 200),
    ]);

    $decision = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/proposals", [
        'symbol' => $symbol,
        'integration_id' => $integration->id,
    ])->json('decision');

    $decisionId = $decision['id'];

    $events = $this->actingAs($user)
        ->getJson("/api/v1/ai-trading/decisions/{$decisionId}/events")
        ->assertOk()
        ->json();

    expect(collect($events)->pluck('event_type'))->toContain('pending_approval')
        ->and(Notification::where('workspace_id', $workspace->id)->where('type', 'ai_decision')->count())->toBeGreaterThan(0);

    $this->actingAs($user)->postJson("/api/v1/ai-trading/decisions/{$decisionId}/approve", [])
        ->assertOk();

    expect(AiDecisionEvent::where('ai_decision_id', $decisionId)->pluck('event_type')->all())
        ->toContain('approved')
        ->toContain('executed');
});

it('expires stale approvals through the scheduled ai decision expiry job', function () {
    [$user, $workspace] = seedAiTradingWorkspace();

    $decision = AiDecision::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'requested_by' => $user->id,
        'exchange' => 'binance',
        'market_type' => 'spot',
        'operator_mode' => 'manual_approval',
        'symbol' => 'BTCUSDT',
        'action' => 'BUY',
        'confidence' => 0.7,
        'validated_proposal_json' => [
            'action' => 'BUY',
            'symbol' => 'BTCUSDT',
        ],
        'final_status' => AiDecision::STATUS_PENDING_APPROVAL,
        'requires_approval' => true,
        'expires_at' => now()->subMinute(),
    ]);

    app(ExpireAiDecisionsJob::class)->handle(app(\App\Domain\Intelligence\AiDecisionAuditService::class));

    expect($decision->fresh()->final_status)->toBe(AiDecision::STATUS_EXPIRED)
        ->and(AiDecisionEvent::where('ai_decision_id', $decision->id)->pluck('event_type'))->toContain('expired')
        ->and(Notification::where('workspace_id', $workspace->id)->where('type', 'ai_decision')->count())->toBeGreaterThan(0);
});
