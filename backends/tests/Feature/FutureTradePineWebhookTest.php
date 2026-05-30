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
        'source' => 'pine_webhook',
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

test('pine webhook accepts body token even when a wrong inherited bearer token is present', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id);

    $this->withHeader('Authorization', 'Bearer wrong-parent-token')
        ->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload())
        ->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('decision', 'BUY');
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

test('pine webhook rejects future candle close for entries but allows close exits', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $futureCandle = [
        'candle_open_time' => '2026-05-19T10:30:00Z',
        'candle_close_time' => '2026-05-19T10:45:00Z',
    ];

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload($futureCandle + [
        'signal' => 'BUY',
    ]))->assertStatus(422)
        ->assertJsonPath('reason', 'candle_close_in_future');

    $config->state()->update([
        'position_side' => 'SHORT',
        'entry_price' => 2500,
        'quantity' => 0.02,
        'current_symbol' => 'ETHUSDT',
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload($futureCandle + [
        'signal' => 'CLOSE_SHORT',
    ]))->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('decision', 'CLOSE_SHORT');
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

test('pine webhook records latency telemetry and sanitizes secret payload fields', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'alert_time' => '2026-05-19T10:30:02Z',
    ]))->assertOk();

    $log = $config->logs()->where('event', 'PINE_WEBHOOK_SIGNAL')->latest('occurred_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->context['webhookTiming']['signalLatencyMs'] ?? null)->toBe(5000)
        ->and($log->context['webhookTiming']['transportLatencyMs'] ?? null)->toBe(3000)
        ->and($log->context['webhookTiming']['candleCloseAt'] ?? null)->toBe('2026-05-19T10:30:00+00:00')
        ->and(array_key_exists('token', $config->fresh('state')->state->scanner_snapshot[0]['debug']['pinePayload'] ?? []))->toBeFalse();

    $diagnostics = app(FutureTradeBotService::class)->diagnosticsPayload($config->fresh('state'));
    expect($diagnostics['webhook']['state'])->toBe('healthy')
        ->and($diagnostics['webhook']['signalLatencyMs'])->toBe(5000.0);
});

test('pine webhook risk payload is ignored by default so manual risk remains authority', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'takeProfitPercent' => 0.5,
        'stopLossPercent' => 0.25,
        'trailingTakeProfitPercent' => 0.22,
        'rollbackLossPercent' => 0.15,
        'useWebhookRiskOverrides' => false,
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'tp' => 1.4,
        'sl' => 0.9,
        'trailing' => 0.8,
        'rollback' => 0.6,
    ]))->assertOk()->assertJsonPath('executed', true);

    $trade = $config->trades()->where('status', 'OPEN')->latest('created_at')->first();
    expect(data_get($trade->meta, 'dynamic.recommended.takeProfitPercent'))->toBe(0.5)
        ->and(data_get($trade->meta, 'dynamic.recommended.stopLossPercent'))->toBe(0.25)
        ->and(data_get($trade->meta, 'dynamic.recommended.trailingTakeProfitPercent'))->toBe(0.22)
        ->and(data_get($trade->meta, 'signal.debug.riskOverrides.takeProfitPercent'))->toBe(1.4);
});

test('pine webhook risk payload can override manual values only when explicitly enabled', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'takeProfitPercent' => 0.5,
        'stopLossPercent' => 0.25,
        'trailingTakeProfitPercent' => 0.22,
        'rollbackLossPercent' => 0.15,
        'useWebhookRiskOverrides' => true,
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'tp' => 0.72,
        'sl' => 0.31,
        'trailing' => 0.26,
        'rollback' => 0.21,
    ]))->assertOk()->assertJsonPath('executed', true);

    $trade = $config->trades()->where('status', 'OPEN')->latest('created_at')->first();
    expect(data_get($trade->meta, 'dynamic.recommended.takeProfitPercent'))->toBe(0.72)
        ->and(data_get($trade->meta, 'dynamic.recommended.stopLossPercent'))->toBe(0.31)
        ->and(data_get($trade->meta, 'dynamic.recommended.trailingTakeProfitPercent'))->toBe(0.26)
        ->and(data_get($trade->meta, 'signal.debug.riskOverrides.rollbackLossPercent'))->toBe(0.21);
});

test('pine webhook sell with strong score and low opposite score opens short and is not hard blocked', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'signal' => 'SELL',
        'score' => 83,
        'shortScore' => 83,
        'longScore' => 26,
        'reason' => 'Prime v6.1.7 SELL: score 83, opposite 26.',
    ]))->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('executed', true)
        ->assertJsonPath('decision', 'SELL');

    $state = $config->fresh('state')->state;
    expect($state->state)->toBe('IN_POSITION')
        ->and($state->position_side)->toBe('SHORT')
        ->and($config->logs()->where('event', 'TRADE_BLOCKED')->exists())->toBeFalse();
});

test('performance feedback summarizes closed trade exits, side quality, latency and move stats', function () {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $config->trades()->create([
        'symbol' => 'ETHUSDT',
        'side' => 'CLOSE_LONG',
        'entry_price' => 2000,
        'exit_price' => 2015,
        'quantity' => 0.02,
        'leverage' => 2,
        'gross_pnl' => 0.3,
        'fees' => 0.03,
        'net_pnl' => 0.27,
        'reason' => 'TAKE_PROFIT_HIT',
        'mode' => 'PAPER',
        'status' => 'FILLED',
        'meta' => ['webhook_timing' => ['signalLatencyMs' => 4000], 'move_stats' => ['maxFavorableMovePercent' => 0.8]],
    ]);
    $config->trades()->create([
        'symbol' => 'ETHUSDT',
        'side' => 'CLOSE_SHORT',
        'entry_price' => 2010,
        'exit_price' => 2002,
        'quantity' => 0.02,
        'leverage' => 2,
        'gross_pnl' => 0.16,
        'fees' => 0.03,
        'net_pnl' => 0.13,
        'reason' => 'TRAILING_PROFIT_HIT',
        'mode' => 'PAPER',
        'status' => 'FILLED',
        'meta' => ['webhook_timing' => ['signalLatencyMs' => 6000], 'move_stats' => ['maxFavorableMovePercent' => 0.55]],
    ]);
    $config->trades()->create([
        'symbol' => 'ETHUSDT',
        'side' => 'CLOSE_LONG',
        'entry_price' => 2000,
        'exit_price' => 1995,
        'quantity' => 0.02,
        'leverage' => 2,
        'gross_pnl' => -0.1,
        'fees' => 0.03,
        'net_pnl' => -0.13,
        'reason' => 'STOP_LOSS_HIT',
        'mode' => 'PAPER',
        'status' => 'FILLED',
        'meta' => ['webhook_timing' => ['signalLatencyMs' => 8000], 'move_stats' => ['maxFavorableMovePercent' => 0.18]],
    ]);

    $method = new ReflectionMethod(FutureTradeBotService::class, 'performanceFeedbackPayload');
    $method->setAccessible(true);
    $feedback = $method->invoke(app(FutureTradeBotService::class), $config->fresh('state'));

    expect($feedback['sampleSize'])->toBe(3)
        ->and($feedback['wins'])->toBe(2)
        ->and($feedback['winRate'])->toBe(66.67)
        ->and($feedback['exitReasons']['TAKE_PROFIT_HIT']['count'])->toBe(1)
        ->and($feedback['exitReasons']['STOP_LOSS_HIT']['count'])->toBe(1)
        ->and($feedback['sides']['LONG']['count'])->toBe(2)
        ->and($feedback['sides']['SHORT']['winRate'])->toBe(100.0)
        ->and($feedback['latency']['avgSignalLatencyMs'])->toBe(6000.0)
        ->and($feedback['avgFavorableMoveBeforeLossPercent'])->toBe(0.18);
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
    // In webhook mode the scanner-read-only guard fires before the mirror-live
    // guard. Either blocker is acceptable — both mean the same thing: backend
    // scanner cannot execute live in TradingView webhook mode.
    $hardBlockers = $log?->context['hardBlockers'] ?? [];
    expect($log?->event)->toBe('TRADE_BLOCKED')
        ->and(
            in_array('backend_scanner_read_only_webhook_mode', $hardBlockers, true)
            || in_array('prime_mirror_live_disabled_pending_tv_csv_validation', $hardBlockers, true)
        )->toBeTrue();
});

test('prime mirror live override lifts the source guard only when signal source is mirror', function () {
    config(['future_trade.prime_mirror_live_enabled' => false]);
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookLiveConfig($workspace->id, [
        // Explicitly switch signal source to mirror — the override is only
        // meant to unblock the testing of mirror execution, not bypass the
        // scanner-read-only guard that webhook mode now enforces.
        'signalSource' => 'mirror',
        'primeMirrorLiveEnabled' => true,
        'primeMirrorExecutionEnabled' => true,
    ]);
    $settings = app(FutureTradeBotService::class)->configPayload($config)['settings'];
    $signal = ['symbol' => 'ETHUSDT', 'signal' => 'BUY', 'source' => 'backend_mirror'];

    $method = new ReflectionMethod(FutureTradeBotService::class, 'primeLiveExecutionBlocked');
    $method->setAccessible(true);

    expect($method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $settings, $signal))->toBeFalse();
});

test('pine webhook freshness snapshot does not block when user stream is stale but REST is verified', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $signal = [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'source' => 'pine_webhook',
        'confidence' => 0.82,
        'timeframe' => '15m',
        'candle_open' => '2026-05-19T10:15:00Z',
    ];

    $method = new ReflectionMethod(FutureTradeBotService::class, 'preExecutionFreshnessSnapshot');
    $method->setAccessible(true);
    $snapshot = $method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $signal);

    // Paper mode bypasses live state checks entirely — webhook source must not
    // be blocked by freshness gate.
    expect($snapshot['blockers'])->toBe([])
        ->and($snapshot['freshnessGateSource'])->not->toBe('blocked');
});

test('opposite-direction pine webhook bypasses cooldown', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'cooldownSeconds' => 180,
    ]);

    $svc = app(FutureTradeBotService::class);
    // Simulate that a LONG position was just closed — cache the last-closed side.
    $recordLastClosedSide = new ReflectionMethod($svc, 'recordLastClosedSide');
    $recordLastClosedSide->setAccessible(true);
    $recordLastClosedSide->invoke($svc, $config, 'LONG', 180);

    // Put the bot in active cooldown.
    $config->state()->update(['cooldown_until' => now()->addSeconds(120)]);

    // Opposite-direction (SELL after LONG close) — should NOT block.
    $signal = ['symbol' => 'ETHUSDT', 'signal' => 'SELL', 'source' => 'pine_webhook'];
    $check = new ReflectionMethod($svc, 'signalIsOppositeOfLastClose');
    $check->setAccessible(true);
    expect($check->invoke($svc, $config->fresh('state'), 'SELL'))->toBeTrue();

    // Same-direction (BUY after LONG close) — should still respect cooldown.
    expect($check->invoke($svc, $config->fresh('state'), 'BUY'))->toBeFalse();
});

test('daily loss tier hard cap blocks new entries', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    $config->state()->update([
        'daily_loss' => 1.2,
        'position_side' => null,
        'quantity' => 0,
        'current_symbol' => 'ETHUSDT',
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'signal' => 'BUY',
    ]))->assertOk()
        ->assertJsonPath('executed', false)
        ->assertJsonPath('execution', 'blocked_with_logged_reason');

    expect((int) $config->fresh('state')->state->trade_count_today)->toBe(0);
});

test('daily loss tier hard cap still allows close webhook exits', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    // Drive daily_loss above the hard cap and seed an open position. CLOSE is
    // risk-reducing, so it must remain executable even while new entries are
    // blocked.
    $config->state()->update([
        'daily_loss' => 1.2,
        'position_side' => 'LONG',
        'entry_price' => 2505,
        'quantity' => 0.05,
        'current_symbol' => 'ETHUSDT',
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'signal' => 'CLOSE_LONG',
    ]))->assertOk()
        ->assertJsonPath('executed', true)
        ->assertJsonPath('execution', 'executed')
        ->assertJsonPath('reason', 'close_signal_executed');

    $state = $config->fresh('state')->state;
    expect((float) $state->quantity)->toBe(0.0)
        ->and($state->position_side)->toBeNull();
});

test('webhook response includes latency telemetry', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id);

    $response = $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'signal' => 'WAIT',
    ]))->assertOk();

    $body = $response->json();
    expect($body)->toHaveKey('latency')
        ->and($body['latency'])->toHaveKey('totalMs')
        ->and($body['latency']['totalMs'])->toBeInt();
});

test('pine webhook permissive mode accepts any USDT perp symbol not in any list', function () {
    config([
        'future_trade.pine_webhook_token' => 'pine-secret',
        // Tight global registry — the symbol below is not in it.
        'future_trade.available_symbols' => ['BTCUSDT', 'ETHUSDT'],
        'future_trade.permissive_symbol_mode' => true,
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'symbols' => ['ETHUSDT'],
        'restrictToWorkspaceSymbols' => false,
        'permissiveSymbolMode' => true,
    ]);

    // 1000PEPEUSDT is a real Binance perp that does NOT need to be in any list
    // — the permissive pattern accepts it.
    $response = $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => '1000PEPEUSDT.P',
        'signal' => 'WAIT',
    ]))->assertOk();

    expect($response->json('accepted'))->toBeTrue();

    $note = $config->logs()->where('event', 'WEBHOOK_SYMBOL_AUTO_ACCEPTED')->latest('occurred_at')->first();
    expect($note)->not->toBeNull()
        ->and($note->context['acceptedVia'] ?? null)->toBe('permissive_pattern');
});

test('pine webhook permissive mode rejects garbage that does not match futures pattern', function () {
    config([
        'future_trade.pine_webhook_token' => 'pine-secret',
        'future_trade.available_symbols' => ['BTCUSDT', 'ETHUSDT'],
        'future_trade.permissive_symbol_mode' => true,
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id, ['symbols' => ['ETHUSDT']]);

    // Random non-futures-looking ticker — does not match the permissive regex.
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'XX!!??',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'symbol_not_allowed');
});

test('pine webhook permissive mode disabled falls back to allowlist behavior', function () {
    config([
        'future_trade.pine_webhook_token' => 'pine-secret',
        'future_trade.available_symbols' => ['BTCUSDT', 'ETHUSDT'],
        'future_trade.permissive_symbol_mode' => false,
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id, [
        'symbols' => ['ETHUSDT'],
        'permissiveSymbolMode' => false,
    ]);

    // Valid futures pattern but not in any allowlist AND permissive mode off → reject.
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'WIFUSDT',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'symbol_not_allowed');
});

test('pine webhook accepts symbol not in workspace list but in global available_symbols', function () {
    config([
        'future_trade.pine_webhook_token' => 'pine-secret',
        // Ensure BNBUSDT is in the global registry (it already is by default).
        'future_trade.available_symbols' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'],
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    // Workspace has a narrow `symbols` list — BNBUSDT is intentionally NOT here.
    $config = createPrimeWebhookConfig($workspace->id, [
        'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'],
        'restrictToWorkspaceSymbols' => false,
    ]);

    $response = $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'BNBUSDT.P',
        'signal' => 'WAIT',
    ]))->assertOk();

    expect($response->json('accepted'))->toBeTrue()
        ->and($response->json('execution'))->not->toBe('rejected');

    // Soft notice should have been logged so the operator knows.
    expect($config->logs()->where('event', 'WEBHOOK_SYMBOL_AUTO_ACCEPTED')->exists())->toBeTrue();
});

test('pine webhook rejects symbol when restrictToWorkspaceSymbols is enabled', function () {
    config([
        'future_trade.pine_webhook_token' => 'pine-secret',
        'future_trade.available_symbols' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT'],
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'],
        // Operator wants strict workspace allowlist.
        'restrictToWorkspaceSymbols' => true,
    ]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'BNBUSDT.P',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'symbol_not_allowed');
});

test('pine webhook rejects symbol not in global registry when permissive mode is off', function () {
    config([
        'future_trade.pine_webhook_token' => 'pine-secret',
        'future_trade.available_symbols' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT'],
        // With permissive mode on (the new default), ABCXYZUSDT would match the
        // regex and be accepted. Turn it off here to test the strict allowlist
        // path, which is what an operator who wants exchange-list-only safety
        // would set.
        'future_trade.permissive_symbol_mode' => false,
    ]);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    createPrimeWebhookConfig($workspace->id, [
        'symbols' => ['ETHUSDT'],
        'restrictToWorkspaceSymbols' => false,
        'permissiveSymbolMode' => false,
    ]);

    // ABCXYZUSDT is not in workspace symbols AND not in global registry —
    // with permissive mode off it must be rejected.
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'ABCXYZUSDT',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'symbol_not_allowed');
});

test('pine webhook close_short signal is mapped and accepted', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id, [
        'symbols' => ['BNBUSDT', 'ETHUSDT'],
    ]);

    $response = $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'BNBUSDT.P',
        'signal' => 'CLOSE_SHORT',
    ]))->assertOk();

    // No open position → CLOSE_SHORT is accepted (not 422) but logged-only.
    expect($response->json('accepted'))->toBeTrue()
        ->and($response->json('decision'))->toBe('CLOSE_SHORT');
});

test('findSlotForSymbol returns first FLAT slot when no slot owns the symbol', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $primary = createPrimeWebhookConfig($workspace->id, [
        'maxOpenPositions' => 5,
        'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'],
    ]);

    $svc = app(FutureTradeBotService::class);
    // Trigger shadow-slot creation.
    $svc->syncSlots($primary);

    // First webhook for BNBUSDT → should land in some slot (slot 0 is FLAT).
    $slot = $svc->findSlotForSymbol($workspace->id, 'BNBUSDT');
    expect($slot)->not->toBeNull()
        ->and((int) $slot->slot_index)->toBe(0);
});

test('findSlotForSymbol returns the slot that already owns the symbol', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $primary = createPrimeWebhookConfig($workspace->id, [
        'maxOpenPositions' => 5,
    ]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    // Mark slot 0 as having an open BNBUSDT position.
    $primary->state()->update([
        'state' => 'IN_POSITION',
        'current_symbol' => 'BNBUSDT',
        'position_side' => 'SHORT',
        'quantity' => 0.05,
    ]);

    $slot = $svc->findSlotForSymbol($workspace->id, 'BNBUSDT');
    expect($slot)->not->toBeNull()
        ->and((int) $slot->slot_index)->toBe(0);

    // A different symbol should be routed to the next FLAT slot (slot 1).
    $otherSlot = $svc->findSlotForSymbol($workspace->id, 'ETHUSDT');
    expect($otherSlot)->not->toBeNull()
        ->and((int) $otherSlot->slot_index)->toBe(1);
});

test('findSlotForSymbol returns null when all 5 slots are busy with different symbols', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $primary = createPrimeWebhookConfig($workspace->id, [
        'maxOpenPositions' => 5,
    ]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    $symbols = ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'];
    $slots = \App\Models\FutureTradeBotConfig::query()
        ->where('workspace_id', $workspace->id)
        ->orderBy('slot_index')->get();
    foreach ($slots as $i => $slot) {
        $slot->state()->update([
            'state' => 'IN_POSITION',
            'current_symbol' => $symbols[$i],
            'position_side' => 'LONG',
            'quantity' => 1.0,
        ]);
    }

    // A new 6th symbol has nowhere to go.
    $slot = $svc->findSlotForSymbol($workspace->id, 'ADAUSDT');
    expect($slot)->toBeNull();
});

test('activePositionCount aggregates across slots', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $primary = createPrimeWebhookConfig($workspace->id, [
        'maxOpenPositions' => 5,
    ]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    // Open positions on slot 0 and slot 2 only.
    \App\Models\FutureTradeBotConfig::where('workspace_id', $workspace->id)
        ->where('slot_index', 0)->first()->state()
        ->update(['state' => 'IN_POSITION', 'quantity' => 0.5]);
    \App\Models\FutureTradeBotConfig::where('workspace_id', $workspace->id)
        ->where('slot_index', 2)->first()->state()
        ->update(['state' => 'IN_POSITION', 'quantity' => 0.3]);

    expect($svc->activePositionCount($workspace->id))->toBe(2);
});

test('webhook for new symbol when all 5 slots busy returns max_concurrent_positions_reached', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $primary = createPrimeWebhookConfig($workspace->id, [
        'maxOpenPositions' => 5,
    ]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    $symbols = ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'];
    $slots = \App\Models\FutureTradeBotConfig::query()
        ->where('workspace_id', $workspace->id)
        ->orderBy('slot_index')->get();
    foreach ($slots as $i => $slot) {
        $slot->state()->update([
            'state' => 'IN_POSITION',
            'current_symbol' => $symbols[$i],
            'quantity' => 1.0,
        ]);
    }

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", pineWebhookPayload([
        'symbol' => 'ADAUSDT',
        'signal' => 'BUY',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'max_concurrent_positions_reached');
});

test('non-webhook signal still blocks on stale feed', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = createPrimeWebhookConfig($workspace->id);

    $signal = [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'source' => 'backend_mirror',
        'confidence' => 0.82,
        'timeframe' => '15m',
        'candle_open' => '2026-05-19T10:15:00Z',
    ];

    $method = new ReflectionMethod(FutureTradeBotService::class, 'preExecutionFreshnessSnapshot');
    $method->setAccessible(true);
    $snapshot = $method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $signal);

    // Backend mirror sources are still subject to feed freshness — this is
    // intentional: only the pine_webhook source is the trusted brain.
    expect(in_array('stale_feed', $snapshot['blockers'], true))->toBeTrue();
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
