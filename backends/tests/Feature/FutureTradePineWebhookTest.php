<?php

use App\Models\FutureTradeBotConfig;
use App\Models\Workspace;
use App\Domain\FutureTrade\FutureTradeBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function createPrimeWebhookConfig(string $workspaceId, array $settings = []): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId,
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'signalProfile' => 'autofinx_prime_v6_1_7_crypto',
            'marketProfile' => 'Crypto',
            'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'],
            'timeframe' => '15m',
            'tradeScoreThreshold' => 72,
            'autoExecutePaperSignals' => true,
            // These tests assert webhook ingestion specifically — flip the new
            // signalSource toggle to 'webhook' so the no-conflicts gate accepts
            // the incoming payload instead of rejecting it.
            'signalSource' => 'webhook',
        ], $settings),
    ]);

    $config->state()->create([
        'status' => 'Running',
        'state' => 'SCANNING',
        'connection_status' => 'Connected',
        'trading_day' => now()->toDateString(),
        'trade_count_today' => 0,
    ]);

    return $config->fresh('state');
}

function createPrimeWebhookLiveConfig(string $workspaceId, array $settings = []): FutureTradeBotConfig
{
    $config = createPrimeWebhookConfig($workspaceId, array_merge([
        'liveTrading' => true,
        'paperTrading' => false,
        'forceTestnet' => true,
        'envTestnetEnabled' => true,
        'testnetApiUrl' => 'https://testnet.binancefuture.local',
        'testnetApiKey' => 'test-key',
        'testnetApiSecret' => 'test-secret',
        'allowLiveWithoutUserStream' => true,
    ], $settings));

    $config->update([
        'paper_trading' => false,
        'enable_live_trading' => true,
    ]);

    return $config->fresh('state');
}

function pineWebhookPayload(array $overrides = []): array
{
    return array_merge([
        'token' => 'pine-secret',
        'profile' => 'autofinx_prime_v6_1_7_crypto',
        'symbol' => 'ETHUSDT.P',
        'timeframe' => '15m',
        'signal' => 'BUY',
        'price' => 2500.25,
        'score' => 82,
        'longScore' => 82,
        'shortScore' => 41,
        'bar_confirmed' => true,
        'candle_open_time' => '2026-05-19T10:15:00Z',
        'candle_close_time' => '2026-05-19T10:30:00Z',
    ], $overrides);
}

test('pine webhook rejects missing or invalid token', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['token' => 'wrong']))
        ->assertStatus(401)
        ->assertJsonPath('reason', 'invalid_or_missing_webhook_token');
});

test('pine webhook rejects non 15m or unconfirmed candles', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['timeframe' => '5m']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'timeframe_must_be_15m');

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['bar_confirmed' => false]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'bar_not_confirmed');
});

test('pine webhook rejects wrong payload profile', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['profile' => 'old_weighted_ai']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'payload_profile_not_prime_v617');
});

test('pine webhook wait signal is accepted but does not open a trade', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['signal' => 'WAIT', 'score' => 55]))
        ->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('executed', false)
        ->assertJsonPath('decision', 'WAIT');

    expect((float) ($config->state()->first()?->quantity ?? 0))->toBe(0.0)
        ->and($config->logicalTrades()->count())->toBe(0);
});

test('pine webhook close signal is accepted as log only and does not open reverse trade', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['signal' => 'CLOSE', 'score' => 82]))
        ->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('executed', false)
        ->assertJsonPath('decision', 'CLOSE');

    expect((float) ($config->state()->first()?->quantity ?? 0))->toBe(0.0)
        ->and($config->logicalTrades()->count())->toBe(0)
        ->and($config->logs()->where('event', 'PINE_WEBHOOK_CLOSE')->exists())->toBeTrue();
});

test('pine webhook is accepted in webhook fallback mode', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'signalSource' => 'webhook_fallback',
        'primeMirrorExecutionEnabled' => true,
        'primeMirrorLiveEnabled' => true,
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload(['signal' => 'WAIT', 'score' => 55]))
        ->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('executed', false)
        ->assertJsonPath('decision', 'WAIT');

    expect($config->logs()->where('event', 'PINE_WEBHOOK_WAIT')->exists())->toBeTrue();
});

test('pine webhook buy executes through prime pine mirror without old ai override', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload())
        ->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('executed', true)
        ->assertJsonPath('decision', 'BUY')
        ->assertJsonPath('symbol', 'ETHUSDT')
        ->assertJsonPath('timeframe', '15m');

    $state = $config->state()->first();
    expect($state->state)->toBe('IN_POSITION')
        ->and($state->position_side)->toBe('LONG')
        ->and((int) $state->trade_count_today)->toBe(1)
        ->and($config->logicalTrades()->count())->toBe(1);

    $trade = $config->trades()->latest('created_at')->first();
    expect($trade->meta['algorithm_mode'])->toBe('pine_mirror')
        ->and($trade->meta['signal_profile'])->toBe('autofinx_prime_v6_1_7_crypto')
        ->and($trade->meta['signal']['source'])->toBe('pine_webhook')
        ->and($trade->meta['signal']['debug']['checks']['secondaryAiWeightingDisabled'])->toBeTrue();
});

test('prime scanner buy is blocked for live mode when mirror live is disabled', function () {
    config([
        'future_trade.prime_mirror_live_enabled' => false,
        'future_trade.pine_webhook_live_enabled' => true,
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookLiveConfig($workspace->id);

    $signal = [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2500.25,
        'confidence' => 0.82,
        'timeframe' => '15m',
        'candle_open' => '2026-05-19T10:15:00Z',
        'source' => 'backend_mirror',
        'reason' => 'Prime mirror scanner test signal',
        'debug' => ['profile' => 'autofinx_prime_v6_1_7_crypto'],
    ];

    $method = new ReflectionMethod(FutureTradeBotService::class, 'maybeEnterPaperPosition');
    $method->setAccessible(true);
    $method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $signal);

    $state = $config->fresh('state')->state;
    expect($state->state)->toBe('SCANNING')
        ->and((int) $state->trade_count_today)->toBe(0)
        ->and($config->logicalTrades()->count())->toBe(0);

    $log = $config->logs()->latest('occurred_at')->first();
    expect($log?->event)->toBe('TRADE_BLOCKED')
        ->and($log?->context['hardBlockers'] ?? [])->toContain('prime_mirror_live_disabled_pending_tv_csv_validation');
});

test('prime mirror live override lifts only the source guard for testing', function () {
    config(['future_trade.prime_mirror_live_enabled' => false]);
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookLiveConfig($workspace->id, [
        'primeMirrorLiveEnabled' => true,
        'primeMirrorExecutionEnabled' => true,
    ]);
    $settings = app(FutureTradeBotService::class)->configPayload($config)['settings'];
    $signal = ['symbol' => 'ETHUSDT', 'signal' => 'BUY', 'source' => 'backend_mirror'];

    $method = new ReflectionMethod(FutureTradeBotService::class, 'primeLiveExecutionBlocked');
    $method->setAccessible(true);

    expect($method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $settings, $signal))->toBeFalse();
});

test('prime mirror paper toggle lifts source guard for scanner execution', function () {
    config(['future_trade.prime_mirror_execution_enabled' => false]);
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'signalSource' => 'mirror',
        'primeMirrorExecutionEnabled' => true,
        'primeMirrorLiveEnabled' => false,
    ]);
    $settings = app(FutureTradeBotService::class)->configPayload($config)['settings'];
    $signal = ['symbol' => 'ETHUSDT', 'signal' => 'BUY', 'source' => 'backend_mirror'];

    $method = new ReflectionMethod(FutureTradeBotService::class, 'primeMirrorExecutionBlocked');
    $method->setAccessible(true);

    expect($method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $settings, $signal))->toBeFalse();
});
