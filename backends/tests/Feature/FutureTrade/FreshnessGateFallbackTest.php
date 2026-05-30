<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function freshnessConfig(array $settings = []): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => false,
        'enable_live_trading' => true,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'liveTrading' => true,
            'paperTrading' => false,
            'forceTestnet' => true,
            'envMode' => 'testnet',
            'envTestnetEnabled' => true,
            'testnetApiKey' => 'test-key',
            'testnetApiSecret' => 'test-secret',
            'testnetApiUrl' => 'https://testnet.binancefuture.local',
        ], $settings),
    ]);
}

function freshnessSignal(string $side = 'BUY'): array
{
    return [
        'symbol' => 'ETHUSDT',
        'signal' => $side,
        'source' => 'pine_webhook',
        'confidence' => 0.83,
        'price' => 2128.69,
    ];
}

function seedRestAccount(FutureTradeBotConfig $config, ?string $syncedAt = null, array $account = []): void
{
    $cacheKey = 'future_trade_account_summary:' . $config->workspace_id . ':' . $config->executionMode();
    Cache::put($cacheKey, array_merge([
        'totalWalletBalance' => '55.3308',
        'availableBalance' => '55.3308',
        'totalMarginBalance' => '55.3308',
        'assets' => [
            [
                'asset' => 'USDT',
                'walletBalance' => '55.3308',
                'availableBalance' => '55.3308',
                'marginBalance' => '55.3308',
            ],
        ],
    ], $account), 300);

    if ($syncedAt !== null) {
        Cache::put($cacheKey . ':meta', ['synced_at' => $syncedAt], 300);
    }
}

function callFreshnessSnapshot(FutureTradeBotService $svc, FutureTradeBotConfig $config, array $signal): array
{
    $method = new ReflectionMethod($svc, 'preExecutionFreshnessSnapshot');
    $method->setAccessible(true);

    /** @var array<string, mixed> $snapshot */
    $snapshot = $method->invoke($svc, $config, $signal);

    return $snapshot;
}

test('user stream stale and REST fresh allows execution via rest fallback', function () {
    $config = freshnessConfig();
    $svc = app(FutureTradeBotService::class);

    seedRestAccount($config, now()->subSeconds(10)->toIso8601String());

    $snapshot = callFreshnessSnapshot($svc, $config, freshnessSignal());

    expect($snapshot['blockers'])->toBe([])
        ->and($snapshot['freshnessGateSource'])->toBe('rest_sync')
        ->and($snapshot['usedRestFallback'])->toBeTrue()
        ->and(data_get($snapshot, 'restSync.fresh'))->toBeTrue();
});

test('user stream stale and REST stale blocks execution', function () {
    $config = freshnessConfig();
    $svc = app(FutureTradeBotService::class);

    seedRestAccount($config, now()->subSeconds(120)->toIso8601String());

    $snapshot = callFreshnessSnapshot($svc, $config, freshnessSignal());

    expect($snapshot['blockers'])->toContain('user_stream_stale_and_state_sync_stale')
        ->and($snapshot['freshnessGateSource'])->toBe('blocked')
        ->and($snapshot['usedRestFallback'])->toBeFalse()
        ->and(data_get($snapshot, 'restSync.fresh'))->toBeFalse();
});

test('fresh user stream allows execution without needing REST fallback', function () {
    $config = freshnessConfig();
    $svc = app(FutureTradeBotService::class);
    $stream = app(FutureTradeUserStreamService::class);

    seedRestAccount($config, now()->subSeconds(10)->toIso8601String());
    $stream->recordHeartbeat($config, 'connect');

    $snapshot = callFreshnessSnapshot($svc, $config, freshnessSignal());

    expect($snapshot['blockers'])->toBe([])
        ->and($snapshot['freshnessGateSource'])->toBe('user_stream')
        ->and($snapshot['usedRestFallback'])->toBeFalse()
        ->and(data_get($snapshot, 'userStream.heartbeatFresh'))->toBeTrue();
});

test('failed Binance keyed request still blocks execution even when user stream is stale', function () {
    Http::fake([
        '*' => Http::response(['code' => -1001, 'msg' => 'Temporary unavailable'], 503),
    ]);

    $config = freshnessConfig();
    $svc = app(FutureTradeBotService::class);

    $snapshot = callFreshnessSnapshot($svc, $config, freshnessSignal());

    expect($snapshot['blockers'])->toContain('binance_state_sync_failed')
        ->and($snapshot['freshnessGateSource'])->toBe('blocked')
        ->and(data_get($snapshot, 'restSync.keyedRequestHealthy'))->toBeFalse();
});

