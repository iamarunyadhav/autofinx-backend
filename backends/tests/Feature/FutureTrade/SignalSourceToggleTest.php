<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Signal-source toggle: Binance/Mirror vs Webhook. The paths are mutually exclusive.
 *
 * Mirror mode (default) — backend scanner executes; webhook is rejected.
 * Binance mode         — native backend Binance brain executes; webhook is rejected.
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

test('binance mode rejects Pine webhook because backend brain is authoritative', function () {
    config(['future_trade.pine_webhook_token' => 'unit-test-token']);
    $config = sigSrcConfig('binance');
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
        ->and((string) ($result['reason'] ?? ''))->toBe('webhook_disabled_in_mirror_mode')
        ->and((string) ($result['signalSource'] ?? ''))->toBe('binance');
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

    $updated3 = $bot->updateConfig($updated2->fresh('state'), [
        'settings' => ['signalSource' => 'binance'],
    ]);
    expect((string) ($updated3->mergedSettings()['signalSource'] ?? 'webhook'))->toBe('binance');
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

test('binance mode lets backend scanner execute without mirror fallback toggles', function () {
    $config = sigSrcConfig('binance');
    $settings = array_merge($config->mergedSettings(), [
        'primeMirrorExecutionEnabled' => false,
        'primeMirrorLiveEnabled' => false,
    ]);
    $bot = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'primeMirrorExecutionBlocked');
    $method->setAccessible(true);

    $blocked = $method->invoke($bot, $config->fresh(), $settings, [
        'source' => 'backend_mirror',
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'confidence' => 0.82,
    ]);

    expect($blocked)->toBeFalse();
});

test('binance mode uses Pine v11 native scoring gates for BUY signals', function () {
    $bot = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'scoreTickerPrimeCrypto');
    $method->setAccessible(true);

    $result = $method->invoke($bot, [
        'symbol' => 'BTCUSDT',
        'lastPrice' => '100',
        'openPrice' => '98',
        'highPrice' => '101',
        'lowPrice' => '97',
        'quoteVolume' => '1000000',
        'sequence' => [
            'ema9' => 106,
            'ema21' => 103,
            'ema50' => 95,
            'ema200' => 80,
            'sessionVwap' => 96,
            'rsi14' => 60,
            'adx14' => 28,
            'diPlus14' => 35,
            'diMinus14' => 12,
            'relativeVolume' => 1.5,
            'choppiness14' => 42,
            'donchianBullCount' => 9,
            'donchianBearCount' => 0,
            'bollingerUpper' => 99,
            'bollingerBasis' => 95,
            'bollingerSqueeze' => false,
            'stochK' => 45,
            'stochD' => 35,
            'supertrendDirection' => 1,
            'nearDemand' => true,
            'bullBurst' => true,
            'atr14' => 1.2,
            'atrPercent' => 1.2,
        ],
        'htf' => [
            'trend1h' => 1,
            'trend4h' => 1,
        ],
    ], array_merge(config('future_trade.defaults'), [
        'signalSource' => 'binance',
        'tradeScoreThreshold' => 65,
        'watchScoreThreshold' => 50,
        'tradeDirection' => 'Both',
        'useDayFilter' => false,
        'useHourFilter' => false,
        'useVolume' => true,
        'useChopFilter' => true,
        'useVWAP' => true,
        'cryptoRelVolMin' => 0.75,
        'cryptoChopMax' => 61,
        'adxMin' => 20,
    ]));

    expect($result['signal'])->toBe('BUY')
        ->and($result['debug']['profile'])->toBe('autofinx_prime_v11_native_binance')
        ->and($result['debug']['sourceOfTruth'])->toBe('binance')
        ->and($result['debug']['hardLongFilter'])->toBeTrue();
});

test('binance v11 native hard gates block Bollinger squeeze even with high score', function () {
    $bot = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'scoreTickerPrimeCrypto');
    $method->setAccessible(true);

    $result = $method->invoke($bot, [
        'symbol' => 'BTCUSDT',
        'lastPrice' => '100',
        'openPrice' => '98',
        'highPrice' => '101',
        'lowPrice' => '97',
        'quoteVolume' => '1000000',
        'sequence' => [
            'ema9' => 106,
            'ema21' => 103,
            'ema50' => 95,
            'ema200' => 80,
            'sessionVwap' => 96,
            'rsi14' => 60,
            'adx14' => 28,
            'diPlus14' => 35,
            'diMinus14' => 12,
            'relativeVolume' => 1.5,
            'choppiness14' => 42,
            'donchianBullCount' => 9,
            'bollingerUpper' => 99,
            'bollingerBasis' => 95,
            'bollingerSqueeze' => true,
            'supertrendDirection' => 1,
            'nearDemand' => true,
            'bullBurst' => true,
        ],
        'htf' => [
            'trend1h' => 1,
            'trend4h' => 1,
        ],
    ], array_merge(config('future_trade.defaults'), [
        'signalSource' => 'binance',
        'tradeScoreThreshold' => 65,
        'useDayFilter' => false,
        'useHourFilter' => false,
        'cryptoRelVolMin' => 0.75,
        'cryptoChopMax' => 61,
        'adxMin' => 20,
    ]));

    expect($result['signal'])->toBe('WAIT')
        ->and($result['debug']['hardLongFilter'])->toBeFalse()
        ->and($result['debug']['checks']['bbSqueezeClear'])->toBeFalse();
});
