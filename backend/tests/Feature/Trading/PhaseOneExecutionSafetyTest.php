<?php

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\LiveExecutionGuard;
use App\Domain\Ops\SafeLiveGate;
use App\Domain\Ops\TradingEnvironmentGuard;
use App\Domain\Trading\BotLogService;
use App\Domain\Trading\LiveSignalExecutionService;
use App\Domain\Trading\ReconciliationService;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Domain\Scheduler\GateResult;
use App\Models\Bot;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\ModuleSetting;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\TradeSignal;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Clock\FrozenClock;
use App\Support\Clock\Clock;
use App\Jobs\EvaluateStrategyInstanceJob;
use Carbon\CarbonImmutable;
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

function phaseOneExchangeInfoPayload(): array
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

function enableWorkspaceLiveMode(string $workspaceId): void
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

function createLiveIntegration(string $workspaceId): Integration
{
    $manager = app(IntegrationManager::class);

    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance',
        'status' => 'active',
        'config_json' => ['environment' => 'testnet'],
        'secrets_encrypted' => $manager->encryptSecrets([
            'api_key' => 'key',
            'api_secret' => 'secret',
            'base_url' => 'https://api.binance.com',
        ]),
    ]);
}

test('paper order route is explicit and still books through paper execution', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    $this->actingAs($user)->postJson('/api/v1/orders', [
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'order_type' => 'limit',
        'mode' => 'paper',
        'quantity' => 0.1,
        'price' => 30000,
    ])->assertCreated()
        ->assertJsonPath('mode', 'paper');

    expect(Order::count())->toBe(1)
        ->and(Order::first()->status)->toBe(Order::STATUS_FILLED)
        ->and(Order::first()->meta['mode'] ?? null)->toBe('paper');
});

test('live orders are blocked when kill switch is active', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enableWorkspaceLiveMode($workspace->id);
    $integration = createLiveIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    app(KillSwitchService::class)->activate($workspace->id, $user->id, 'test');

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Kill switch is active for this workspace.');

    expect(Order::count())->toBe(0);
});

test('live orders are blocked when workspace is not in live mode', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = createLiveIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    $this->actingAs($user)->postJson('/api/v1/live/orders', [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
    ])->assertStatus(422);

    expect(Order::count())->toBe(0);
});

test('live manual order submissions are idempotent', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enableWorkspaceLiveMode($workspace->id);
    $integration = createLiveIntegration($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    Http::fake([
        'https://testnet.binance.local/api/v3/exchangeInfo*' => Http::response(phaseOneExchangeInfoPayload(), 200),
        'https://testnet.binance.local/api/v3/order*' => Http::response([
            'orderId' => 'abc-1',
            'status' => 'NEW',
            'executedQty' => '0',
            'price' => '30000',
            'fills' => [],
        ], 200),
    ]);

    $payload = [
        'workspace_id' => $workspace->id,
        'integration_id' => $integration->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'type' => 'market',
        'qty' => 0.01,
        'price' => 30000,
        'idempotency_key' => 'live-phase1-key',
    ];

    $this->actingAs($user)->postJson('/api/v1/live/orders', $payload)->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/live/orders', $payload)->assertCreated();

    expect(Order::where('idempotency_key', 'live-phase1-key')->count())->toBe(1)
        ->and(Order::first()->status)->toBe(Order::STATUS_NEW);
});

test('live quick trades are disabled explicitly', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Live quick trades are disabled during Phase 1 execution hardening.');
});

test('kill switch api persists state and pauses live automation', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => Strategy::create([
            'id' => (string) Str::uuid(),
            'name' => 'Grid',
            'slug' => 'grid',
        ])->id,
        'name' => 'Live Strategy',
        'mode' => 'live',
        'status' => 'running',
    ]);

    Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
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
    ]);

    $this->actingAs($user)->postJson('/api/v1/ops/kill-switch', [
        'workspace_id' => $workspace->id,
        'enabled' => false,
        'reason' => 'manual_test',
    ])->assertOk()
        ->assertJsonPath('kill_switch_active', true)
        ->assertJsonPath('global_trading_enabled', false);

    expect(app(KillSwitchService::class)->isActive($workspace->id))->toBeTrue()
        ->and(StrategyInstance::first()->fresh()->status)->toBe('paused')
        ->and(Bot::first()->fresh()->status)->toBe('PAUSED');
});

test('strategy instance live execution is paused instead of using legacy live path', function () {
    $workspace = Workspace::factory()->create();
    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'Grid',
        'slug' => 'grid',
    ]);
    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'Legacy Live Strategy',
        'mode' => 'live',
        'status' => 'running',
    ]);
    StrategyParam::create([
        'id' => (string) Str::uuid(),
        'instance_id' => $instance->id,
        'key' => 'symbol',
        'value' => 'BTCUSDT',
    ]);
    StrategyParam::create([
        'id' => (string) Str::uuid(),
        'instance_id' => $instance->id,
        'key' => 'base_price',
        'value' => 100,
    ]);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    $market = Mockery::mock(\App\Domain\Market\MarketDataService::class);
    $market->shouldReceive('candles')->andReturn(collect(range(1, 30))->map(fn ($index) => [
        'close' => 99,
    ])->all());
    $market->shouldReceive('latestPrice')->andReturn(99.0);
    app()->instance(\App\Domain\Market\MarketDataService::class, $market);

    $risk = Mockery::mock(\App\Domain\Risk\RiskEngine::class);
    $risk->shouldReceive('assess')->andReturn(new \App\Domain\Risk\DTO\RiskDecision('APPROVE', 'ok', [], []));
    app()->instance(\App\Domain\Risk\RiskEngine::class, $risk);

    $gate = Mockery::mock(\App\Domain\Scheduler\ScheduleGate::class);
    $gate->shouldReceive('canEvaluateStrategyInstance')->andReturn(new GateResult(true));
    $clock = new FrozenClock(CarbonImmutable::parse('2026-03-29 10:00:00'));
    $sizer = Mockery::mock(\App\Domain\Portfolio\PreTradeSizer::class);
    $sizer->shouldReceive('size')->andReturn(0.01);
    $regimeDetector = Mockery::mock(\App\Domain\Regime\RegimeDetectorService::class);
    $regimeDetector->shouldReceive('detect')->andReturn(null);
    $publisher = Mockery::mock(\App\Domain\Signals\SignalPublisherService::class);
    $switcher = Mockery::mock(\App\Domain\Regime\StrategyAutoSwitchService::class);
    $switcher->shouldReceive('allowOrSwitch')->andReturn(true);

    (new EvaluateStrategyInstanceJob($instance->id))->handle(
        $gate,
        $clock,
        $sizer,
        $regimeDetector,
        $publisher,
        $switcher
    );

    expect($instance->fresh()->status)->toBe('paused')
        ->and(Order::count())->toBe(0);
});

test('live signal execution is cancelled when kill switch is active', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    enableWorkspaceLiveMode($workspace->id);

    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 100000,
    ]);

    $bot = Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Guarded Bot',
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

    $signal = TradeSignal::create([
        'id' => (string) Str::uuid(),
        'bot_id' => $bot->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'asset_class' => 'CRYPTO',
        'asset_code' => 'BTCUSDT',
        'quote_currency' => 'USDT',
        'provider' => 'binance',
        'symbol' => 'BTCUSDT',
        'side' => 'BUY',
        'decision_price' => 30000,
        'decision_ts' => now(),
        'max_slippage_pct' => 0.5,
        'ttl_ms' => 8000,
        'fill_timeout_ms' => 3000,
        'exec_policy' => 'SAFE',
        'status' => 'PENDING',
        'trace_id' => (string) Str::uuid(),
        'meta_json' => ['qty' => 0.01],
    ]);

    app(KillSwitchService::class)->activate($workspace->id, $user->id, 'test');

    $service = new LiveSignalExecutionService(
        Mockery::mock(TradingVenueClientFactory::class),
        Mockery::mock(\App\Domain\Market\MarketPriceCacheService::class),
        app(LiveExecutionGuard::class),
        Mockery::mock(\App\Domain\Risk\BotRiskEngine::class),
        Mockery::mock(\App\Domain\Intelligence\SignalAdvisorService::class),
        Mockery::mock(ReconciliationService::class),
        app(BotLogService::class),
    );

    $result = $service->executeSignal($signal);

    expect($result)->toBeNull()
        ->and($signal->fresh()->status)->toBe('CANCELLED')
        ->and($bot->fresh()->status)->toBe('PAUSED');
});
