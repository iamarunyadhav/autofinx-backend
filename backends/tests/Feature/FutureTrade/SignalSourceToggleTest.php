<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Signal-source toggle: Mirror vs Webhook. The two paths are mutually exclusive.
 *
 * Mirror mode (default) — backend scanner executes; webhook is rejected.
 * Webhook mode          — backend scanner is read-only; webhook is the only entry path.
 */

function sigSrcConfig(string $source = 'mirror'): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'signalSource' => $source,
            'symbols' => ['ETHUSDT', 'BTCUSDT'],
            'timeframe' => '15m',
        ]),
    ]);
}

// ─── Default behaviour is mirror (no regression) ─────────────────────────────

test('default signalSource is mirror — preserves current behavior', function () {
    $config = sigSrcConfig(); // default 'mirror'
    $merged = $config->mergedSettings();
    expect((string) ($merged['signalSource'] ?? 'mirror'))->toBe('mirror');
});

// ─── Mirror mode rejects webhook (no conflicts) ──────────────────────────────

test('mirror mode rejects an incoming Pine webhook with a clear reason', function () {
    config(['future_trade.pine_webhook_token' => 'unit-test-token']);
    $config = sigSrcConfig('mirror');
    $bot = app(FutureTradeBotService::class);

    $result = $bot->ingestPineWebhook($config, [
        'token' => 'unit-test-token',
        'symbol' => 'ETHUSDT',
        'timeframe' => '15m',
        'signal' => 'BUY',
        'price' => 2300.50,
        'candle_open' => now()->subMinutes(15)->floorMinutes(15)->toIso8601String(),
        'candle_close' => now()->floorMinutes(15)->toIso8601String(),
        'bar_confirmed' => true,
    ]);

    expect($result['accepted'] ?? false)->toBeFalse()
        ->and((string) ($result['reason'] ?? ''))->toBe('webhook_disabled_in_mirror_mode');
});

// ─── Webhook mode rejects nothing it should accept ───────────────────────────

test('webhook mode no longer rejects webhooks with webhook_disabled_in_mirror_mode', function () {
    config(['future_trade.pine_webhook_token' => 'unit-test-token']);
    $config = sigSrcConfig('webhook');
    $bot = app(FutureTradeBotService::class);

    $result = $bot->ingestPineWebhook($config, [
        'token' => 'unit-test-token',
        'symbol' => 'ETHUSDT',
        'timeframe' => '15m',
        'signal' => 'WAIT',
        'price' => 2300.50,
        'candle_open' => now()->subMinutes(15)->floorMinutes(15)->toIso8601String(),
        'candle_close' => now()->floorMinutes(15)->toIso8601String(),
        'bar_confirmed' => true,
    ]);

    // We don't care whether WAIT triggers a trade; we ONLY care that the
    // toggle gate didn't fire.
    expect((string) ($result['reason'] ?? ''))->not->toBe('webhook_disabled_in_mirror_mode');
});

// ─── Settings round-trip ─────────────────────────────────────────────────────

test('signalSource is preserved through updateConfig and mergedSettings', function () {
    $config = sigSrcConfig('mirror');
    $bot = app(FutureTradeBotService::class);

    $updated = $bot->updateConfig($config, [
        'settings' => ['signalSource' => 'webhook'],
    ]);

    expect((string) ($updated->mergedSettings()['signalSource'] ?? 'mirror'))->toBe('webhook');

    // Flip back to mirror and verify
    $updated2 = $bot->updateConfig($updated->fresh('state'), [
        'settings' => ['signalSource' => 'mirror'],
    ]);
    expect((string) ($updated2->mergedSettings()['signalSource'] ?? 'webhook'))->toBe('mirror');
});

// ─── Hint payload is preserved for diagnostics ───────────────────────────────

test('mirror-mode rejection payload includes a hint pointing operators at the UI toggle', function () {
    config(['future_trade.pine_webhook_token' => 'unit-test-token']);
    $config = sigSrcConfig('mirror');
    $bot = app(FutureTradeBotService::class);

    $result = $bot->ingestPineWebhook($config, [
        'token' => 'unit-test-token',
        'symbol' => 'ETHUSDT',
        'timeframe' => '15m',
        'signal' => 'BUY',
        'price' => 2300.50,
        'candle_open' => now()->subMinutes(15)->floorMinutes(15)->toIso8601String(),
        'candle_close' => now()->floorMinutes(15)->toIso8601String(),
        'bar_confirmed' => true,
    ]);

    expect($result['reason'])->toBe('webhook_disabled_in_mirror_mode')
        ->and(isset($result['signalSource']) || isset($result['details']) || isset($result['hint']))->toBeTrue();
});

test('webhook fallback ignores recent WAIT webhooks but respects recent executable webhooks', function () {
    $config = sigSrcConfig('webhook_fallback');
    $settings = array_merge($config->mergedSettings(), [
        'pineWebhookFallbackTimeoutSeconds' => 1200,
    ]);
    $bot = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'pineWebhookRecentlyReceived');
    $method->setAccessible(true);

    $config->logs()->create([
        'event' => 'PINE_WEBHOOK_WAIT',
        'symbol' => 'ETHUSDT',
        'signal' => 'WAIT',
        'confidence' => 0.63,
        'reason' => 'WAIT should not suppress mirror fallback.',
        'context' => [],
        'occurred_at' => now(),
    ]);

    expect($method->invoke($bot, $config->fresh(), $settings))->toBeFalse();

    $config->logs()->create([
        'event' => 'PINE_WEBHOOK_SIGNAL',
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'confidence' => 0.82,
        'reason' => 'Executable webhook should suppress mirror fallback briefly.',
        'context' => [],
        'occurred_at' => now(),
    ]);

    expect($method->invoke($bot, $config->fresh(), $settings))->toBeTrue();
});
