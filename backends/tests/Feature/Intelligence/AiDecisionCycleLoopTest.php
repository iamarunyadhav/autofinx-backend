<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Intelligence\AiDecisionCycleService;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\AiTradingConfig;
use App\Models\Bot;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\MarketCandle;
use App\Models\ModuleSetting;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RiskProfile;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\Trade;
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

function phaseTwoAiWorkspace(): array
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

function phaseTwoEnableLiveMode(string $workspaceId): void
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

function phaseTwoCreateIntegration(string $workspaceId): Integration
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

function phaseTwoSeedBalances(string $workspaceId, string $userId): void
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

function phaseTwoSeedMarket(string $workspaceId, string $userId, string $symbolCode = 'BTCUSDT'): string
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
        'name' => 'AI Loop Bot',
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

function phaseTwoRiskProfile(string $workspaceId, array $rules = []): RiskProfile
{
    return RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'name' => 'Default',
        'is_default' => true,
        'rules' => array_merge([
            'daily_loss_pct' => 10,
            'drawdown_pct' => 15,
            'slippage_bps' => 50,
        ], $rules),
    ]);
}

function phaseTwoConfig(string $workspaceId, array $overrides = []): AiTradingConfig
{
    return AiTradingConfig::updateOrCreate(
        ['workspace_id' => $workspaceId],
        array_merge([
            'id' => (string) Str::uuid(),
            'enabled' => true,
            'mode' => 'advisor',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'provider' => 'heuristic',
            'enabled_symbols' => ['BTCUSDT'],
            'allowed_symbols' => ['BTCUSDT'],
            'blocked_symbols' => [],
            'max_trade_size_pct' => 1.0,
            'min_confidence' => 0.5,
            'decision_interval_minutes' => 5,
            'max_decisions_per_hour' => 60,
            'cooldown_minutes_after_trade' => 0,
            'cooldown_minutes_after_loss' => 0,
            'max_pending_decisions_per_symbol' => 1,
            'max_decisions_per_hour_per_symbol' => 12,
            'require_stop_loss' => true,
            'require_take_profit' => true,
            'require_time_stop' => true,
            'auto_trade_enabled' => false,
            'auto_pause_on_daily_loss' => true,
            'auto_pause_on_drawdown' => true,
            'auto_pause_on_order_failure_streak' => 3,
            'auto_pause_on_slippage_spike' => true,
            'auto_pause_on_reconciliation_mismatch' => true,
            'manual_resume_required' => false,
        ], $overrides)
    );
}

it('creates an auditable scheduled decision cycle', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, ['mode' => 'advisor']);

    $result = app(AiDecisionCycleService::class)->run($workspace->id, 'BTCUSDT', [
        'manual_trigger' => false,
        'integration_id' => $integration->id,
    ]);

    expect($result['cycle']->trigger_type)->toBe('scheduled')
        ->and(AiDecisionCycle::count())->toBe(1)
        ->and(AiDecision::count())->toBe(1);
});

it('advisor mode records a proposal without execution', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, ['mode' => 'advisor']);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_NO_TRADE)
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_RECORDED);

    expect(Order::count())->toBe(0);
});

it('manual approval mode creates pending approvals', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, ['mode' => 'manual_approval']);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_PENDING_APPROVAL)
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_PENDING_APPROVAL);
});

it('restricted auto mode dispatches only when all constraints pass', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'ai-cycle-order-1',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '30350',
            'fills' => [],
        ], 200),
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_EXECUTED)
        ->assertJsonPath('decision.final_status', AiDecision::STATUS_EXECUTED);

    expect(Order::count())->toBe(1);
});

it('manual pause and resume api updates trading state', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/pause", [
        'symbol' => 'BTCUSDT',
        'reason' => 'manual_pause',
    ])->assertOk()
        ->assertJsonPath('status', \App\Models\AiTradingState::STATUS_PAUSED);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/resume", [
        'symbol' => 'BTCUSDT',
    ])->assertOk()
        ->assertJsonPath('status', \App\Models\AiTradingState::STATUS_ACTIVE);
});

it('pause state prevents auto execution even with a valid proposal', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/pause", [
        'symbol' => 'BTCUSDT',
        'reason' => 'manual_pause',
    ])->assertOk();

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_PAUSED);

    expect(Order::count())->toBe(0);
});

it('daily loss breach triggers pause', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id, ['daily_loss_pct' => 5]);
    phaseTwoConfig($workspace->id, [
        'mode' => 'advisor',
        'auto_pause_on_daily_loss' => true,
    ]);

    $order = Order::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'provider' => 'binance',
        'asset_code' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'quantity' => 1,
        'qty' => 1,
        'requested_qty' => 1,
        'price' => 30000,
        'status' => 'filled',
        'trace_id' => (string) Str::uuid(),
    ]);

    Trade::create([
        'id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'workspace_id' => $workspace->id,
        'asset_code' => 'BTCUSDT',
        'price' => 30000,
        'quantity' => 1,
        'fee' => 0,
        'fees' => 0,
        'avg_price' => 30000,
        'realized_pnl' => -400,
        'executed_at' => now(),
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_PAUSED);

    expect(Notification::where('workspace_id', $workspace->id)->where('type', 'ai_cycle')->count())->toBeGreaterThan(0);
});

it('stale feed triggers block or pause in the decision loop', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    app(OperationalBreakerService::class)->activateStaleFeedBreaker($workspace->id, ['test' => true]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_PAUSED);

    expect(Order::count())->toBe(0);
});

it('manual cycle run respects safety controls when kill switch is active', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    app(\App\Domain\Ops\KillSwitchService::class)->activate($workspace->id, $user->id, 'test');

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_PAUSED);
});

it('ai provider failure does not auto submit a trade', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'provider' => 'openai',
        'provider_model' => 'gpt-5.4',
        'auto_trade_enabled' => true,
    ]);

    config(['intelligence.ai_trading.openai.api_key' => null]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_FAILED);

    expect(Order::count())->toBe(0);
});

it('malformed ai proposals fail cleanly without execution', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'provider' => 'openai',
        'provider_model' => 'gpt-5.4',
        'auto_trade_enabled' => true,
    ]);

    config([
        'intelligence.ai_trading.openai.api_key' => 'test-key',
        'intelligence.ai_trading.openai.api_url' => 'https://openai.local/v1/chat/completions',
    ]);

    Http::fake([
        'https://openai.local/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '{"action":"BUY","symbol":"BTCUSDT"}',
                ],
            ]],
        ], 200),
    ]);

    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated()
        ->assertJsonPath('cycle.status', AiDecisionCycle::STATUS_FAILED);

    expect(Order::count())->toBe(0);
});

it('queue retries do not duplicate live execution for the same cycle correlation id', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'auto_trade_enabled' => true,
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'ai-cycle-order-2',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '30350',
            'fills' => [],
        ], 200),
    ]);

    $service = app(AiDecisionCycleService::class);
    $resultA = $service->run($workspace->id, 'BTCUSDT', [
        'manual_trigger' => false,
        'integration_id' => $integration->id,
        'correlation_id' => 'scheduled:' . $workspace->id . ':BTCUSDT:test-window',
    ]);
    $resultB = $service->run($workspace->id, 'BTCUSDT', [
        'manual_trigger' => false,
        'integration_id' => $integration->id,
        'correlation_id' => 'scheduled:' . $workspace->id . ':BTCUSDT:test-window',
    ]);

    expect(Order::count())->toBe(1)
        ->and($resultA['cycle']->id)->toBe($resultB['cycle']->id);
});

it('policy rejection still stores audit records', function () {
    [$user, $workspace] = phaseTwoAiWorkspace();
    phaseTwoEnableLiveMode($workspace->id);
    $integration = phaseTwoCreateIntegration($workspace->id);
    phaseTwoSeedBalances($workspace->id, $user->id);
    phaseTwoSeedMarket($workspace->id, $user->id);
    phaseTwoRiskProfile($workspace->id);
    phaseTwoConfig($workspace->id, [
        'mode' => 'restricted_auto',
        'allowed_symbols' => ['ETHUSDT'],
        'enabled_symbols' => ['BTCUSDT'],
        'auto_trade_enabled' => true,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/ai-trading/cycles/run", [
        'symbol' => 'BTCUSDT',
        'integration_id' => $integration->id,
    ])->assertCreated();

    expect($response->json('cycle.status'))->toBe(AiDecisionCycle::STATUS_REJECTED)
        ->and(AiDecision::count())->toBe(1)
        ->and(AiDecision::first()->final_status)->toBe(AiDecision::STATUS_BLOCKED);
});
