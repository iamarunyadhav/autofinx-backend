<?php

use App\Domain\Trading\AccountFeeRateService;
use App\Domain\Trading\QuickTradeResearchService;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Real account fee rate, pre-trade (`quick_trade.use_account_fee_rate`,
 * `robot_trade.use_account_fee_rate`, both default OFF).
 *
 * AccountFeeRateService reads Binance USDⓈ-M `GET /fapi/v1/commissionRate`
 * through BinanceTimedFuturesProvider's existing signed-request client and
 * must never throw into a decision path. Only Binance HTTP is faked.
 *
 * Helpers are prefixed `afr` to avoid colliding with other files' globals.
 */
const AFR_KEY = 'afr-api-key-DO-NOT-LOG';
const AFR_SECRET = 'afr-api-secret-DO-NOT-LOG';

function afrWorkspace(string $environment = 'live'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance '.$environment,
        'status' => 'active',
        'config_json' => ['environment' => $environment],
        'secrets_encrypted' => Crypt::encryptString(json_encode([
            'api_key' => AFR_KEY, 'api_secret' => AFR_SECRET,
        ])),
    ]);
    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integration->id,
        'venue_environment' => $environment,
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
        );
    }

    return [$user, $workspace, $integration];
}

/** @param  callable(ClientRequest): mixed  $commission */
function afrFakeBinance(callable $commission): void
{
    Http::fake(function (ClientRequest $request) use ($commission) {
        if (str_contains($request->url(), '/fapi/v1/commissionRate')) {
            return $commission($request);
        }

        return Http::response([], 200);
    });
}

function afrCommissionCalls(): int
{
    return count(Http::recorded(fn (ClientRequest $r) => str_contains($r->url(), '/fapi/v1/commissionRate')));
}

/** 60 closed 5m candles (+1 forming) with real OPEN timestamps. */
function afrForecastCandles(): array
{
    $tf = 300000;
    $nowBucket = intdiv((int) floor(microtime(true) * 1000), $tf) * $tf;
    $rows = [];
    for ($i = 0; $i < 61; $i++) {
        $open = 2000 + ($i * 1.5);
        $rows[] = [
            'timestamp' => $nowBucket - ((60 - $i) * $tf),
            'open' => $open, 'high' => $open + 6, 'low' => $open - 5, 'close' => $open + 1.2, 'volume' => 100,
        ];
    }

    return $rows;
}

beforeEach(function () {
    Cache::flush();
});

// ── Service: success, caching, signing ──────────────────────────────────

test('both account-fee flags ship OFF by default', function () {
    expect(config('quick_trade.use_account_fee_rate'))->toBeFalse()
        ->and(config('robot_trade.use_account_fee_rate'))->toBeFalse();
});

test('a valid commissionRate response is used as the account taker rate, through the existing signed client', function () {
    [, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response([
        'symbol' => 'ETHUSDT', 'makerCommissionRate' => '0.00018', 'takerCommissionRate' => '0.00036',
    ], 200));

    $result = app(AccountFeeRateService::class)->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ethusdt');

    expect($result)->toBe([
        'taker_rate' => 0.00036, 'maker_rate' => 0.00018, 'source' => 'account', 'reason' => null, 'cached' => false,
    ]);
    Http::assertSent(function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://fapi.binance.com/fapi/v1/commissionRate')
            && $request->method() === 'GET'
            && $request->hasHeader('X-MBX-APIKEY', AFR_KEY)
            && ($query['symbol'] ?? null) === 'ETHUSDT'
            && isset($query['timestamp'], $query['recvWindow'])
            && preg_match('/^[a-f0-9]{64}$/', (string) ($query['signature'] ?? '')) === 1;
    });
});

test('a successful lookup is cached per integration and symbol, so the next decision makes no HTTP call', function () {
    [, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'makerCommissionRate' => '0.0002', 'takerCommissionRate' => '0.0004'], 200));
    $service = app(AccountFeeRateService::class);

    $first = $service->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');
    $second = $service->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');

    expect($first['cached'])->toBeFalse()
        ->and($second)->toBe(['taker_rate' => 0.0004, 'maker_rate' => 0.0002, 'source' => 'account', 'reason' => null, 'cached' => true])
        ->and(afrCommissionCalls())->toBe(1);

    // A different symbol is a different cache entry.
    $service->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'BTCUSDT');
    expect(afrCommissionCalls())->toBe(2);
});

test('testnet integrations hit the testnet host', function () {
    [, $workspace, $integration] = afrWorkspace('testnet');
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'makerCommissionRate' => '0.0002', 'takerCommissionRate' => '0.0005'], 200));

    app(AccountFeeRateService::class)->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');

    Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://testnet.binancefuture.com/fapi/v1/commissionRate'));
});

// ── Service: every failure falls back to config, never throws ───────────

test('a 4xx from Binance falls back to the config rate and is negatively cached', function () {
    [, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401));
    $service = app(AccountFeeRateService::class);

    $result = $service->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');
    $again = $service->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');

    expect($result)->toBe(['taker_rate' => null, 'maker_rate' => null, 'source' => 'config', 'reason' => 'request_failed', 'cached' => false])
        ->and($again['cached'])->toBeTrue()
        ->and($again['source'])->toBe('config')
        ->and(afrCommissionCalls())->toBe(1);
});

test('a connection timeout falls back to config and never logs the key, secret or signature', function () {
    [, $workspace, $integration] = afrWorkspace();
    Log::spy();
    $seenSignature = null;
    afrFakeBinance(function (ClientRequest $request) use (&$seenSignature) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $seenSignature = $query['signature'] ?? null;

        // Same shape Guzzle produces: the message embeds the full signed URI.
        return Create::rejectionFor(new ConnectException(
            'cURL error 28: Operation timed out after 3001 milliseconds with 0 bytes received for '.$request->toPsrRequest()->getUri(),
            $request->toPsrRequest(),
        ));
    });

    $result = app(AccountFeeRateService::class)->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');

    expect($result['source'])->toBe('config')
        ->and($result['taker_rate'])->toBeNull()
        ->and($result['reason'])->toBe('request_failed')
        ->and($seenSignature)->not->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($seenSignature) {
        $flat = json_encode($context);

        return $message === 'account_fee_rate_lookup_failed'
            && $context['reason'] === 'request_failed'
            && str_contains($flat, 'signature=[redacted]')
            && ! str_contains($flat, (string) $seenSignature)
            && ! str_contains($flat, AFR_KEY)
            && ! str_contains($flat, AFR_SECRET);
    })->once();
});

test('malformed or implausible bodies fall back to config', function (array $body) {
    [, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response($body, 200));

    $result = app(AccountFeeRateService::class)->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');

    expect($result['source'])->toBe('config')
        ->and($result['taker_rate'])->toBeNull()
        ->and($result['reason'])->toBe('malformed_response');
})->with([
    'empty body' => [[]],
    'missing taker' => [['symbol' => 'ETHUSDT', 'makerCommissionRate' => '0.0002']],
    'non-numeric taker' => [['symbol' => 'ETHUSDT', 'takerCommissionRate' => 'abc']],
    'negative taker' => [['symbol' => 'ETHUSDT', 'takerCommissionRate' => '-0.0004']],
    'implausible 50% taker' => [['symbol' => 'ETHUSDT', 'takerCommissionRate' => '0.5']],
    'other symbol' => [['symbol' => 'BTCUSDT', 'takerCommissionRate' => '0.0004']],
]);

test('a non-JSON body falls back to config', function () {
    [, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response('<html>gateway</html>', 200));

    $result = app(AccountFeeRateService::class)->resolveForWorkspace($workspace->id, 'binance', $integration->id, 'ETHUSDT');

    expect($result['source'])->toBe('config')->and($result['reason'])->toBe('malformed_response');
});

test('no usable integration, a non-Binance provider or missing credentials all fall back without an HTTP call', function () {
    Http::fake();
    $service = app(AccountFeeRateService::class);
    $workspace = Workspace::factory()->create();

    expect($service->resolveForWorkspace($workspace->id, 'binance', null, 'ETHUSDT')['reason'])->toBe('integration_unavailable')
        ->and($service->resolveForWorkspace($workspace->id, 'oanda', null, 'ETHUSDT')['reason'])->toBe('provider_not_supported');

    [, $ws2, $integration] = afrWorkspace();
    $integration->update(['secrets_encrypted' => null]);
    expect($service->resolveForWorkspace($ws2->id, 'binance', $integration->id, 'ETHUSDT')['reason'])->toBe('request_failed');

    Http::assertNothingSent();
});

// ── Quick Trade: /quick-trade/forecast preview ──────────────────────────

function afrForecastRequest(array $overrides = []): array
{
    return array_merge([
        'candles' => afrForecastCandles(),
        'signal' => ['direction' => 'buy', 'confidence' => 90],
        'timeframe' => '5m',
        'expiry_seconds' => 3600,
        'stake_amount' => 50,
        'symbol' => 'ETHUSDT',
        'exchange' => 'binance_futures',
        'mode' => 'live',
    ], $overrides);
}

test('flag off: the live preview never looks up the account fee and keeps the config rate', function () {
    [$user, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'takerCommissionRate' => '0.0002'], 200));

    $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id,
    ]))->assertOk();

    expect($response->json('forecast.trade_decision.fee_rate_source'))->toBe('config')
        ->and($response->json('forecast.trade_decision.cost_pct'))->toEqual(0.14)
        ->and($response->json('forecast'))->not->toHaveKey('account_fee_rate')
        ->and(afrCommissionCalls())->toBe(0);
});

test('flag on: the live preview is priced off the account taker rate and says so', function () {
    config()->set('quick_trade.use_account_fee_rate', true);
    [$user, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'makerCommissionRate' => '0.0002', 'takerCommissionRate' => '0.0004'], 200));

    $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id, 'durations' => [3600],
    ]))->assertOk();

    // cost = taker 0.0004 x 2 legs x 100 + slippage 0.04 = 0.12; required = x2 multiplier.
    expect($response->json('forecast.trade_decision.fee_rate_source'))->toBe('account')
        ->and($response->json('forecast.trade_decision.cost_pct'))->toEqual(0.12)
        ->and($response->json('forecast.trade_decision.required_move_pct'))->toEqual(0.24)
        ->and($response->json('forecast.cost_model.fee_rate_source'))->toBe('account')
        ->and($response->json('forecast.duration_availability.0.required_move_pct'))->toEqual(0.24)
        ->and($response->json('forecast.account_fee_rate.source'))->toBe('account')
        ->and($response->json('net_edge.fee_rate'))->toEqual(0.0004)
        ->and($response->json('net_edge.fee_rate_source'))->toBe('account')
        ->and($response->json('net_edge.required_move_pct'))->toEqual(0.24);
});

test('flag on: a failed lookup keeps the config rate and reports why', function () {
    config()->set('quick_trade.use_account_fee_rate', true);
    [$user, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['code' => -1022, 'msg' => 'Signature for this request is not valid.'], 400));

    $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id,
    ]))->assertOk();

    expect($response->json('forecast.trade_decision.fee_rate_source'))->toBe('config')
        ->and($response->json('forecast.trade_decision.cost_pct'))->toEqual(0.14)
        ->and($response->json('forecast.account_fee_rate.reason'))->toBe('request_failed');
});

test('flag on: paper previews and previews that name no workspace never look up a fee', function () {
    config()->set('quick_trade.use_account_fee_rate', true);
    [$user, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'takerCommissionRate' => '0.0004'], 200));

    $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id, 'mode' => 'paper',
    ]))->assertOk();
    $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest())->assertOk();

    expect(afrCommissionCalls())->toBe(0);
});

test('naming a workspace the caller does not belong to is refused before any lookup', function () {
    config()->set('quick_trade.use_account_fee_rate', true);
    [, $workspace, $integration] = afrWorkspace();
    $stranger = User::factory()->create();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'takerCommissionRate' => '0.0004'], 200));

    $this->actingAs($stranger)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspace->id, 'integration_id' => $integration->id,
    ]))->assertForbidden();

    expect(afrCommissionCalls())->toBe(0);
});

test('flag on: the shared close-anchored decision cache never serves one account-fee decision to another', function () {
    config()->set('quick_trade.use_account_fee_rate', true);
    [$userA, $workspaceA, $integrationA] = afrWorkspace();
    [$userB, $workspaceB, $integrationB] = afrWorkspace();
    afrFakeBinance(fn (ClientRequest $request) => Http::response([
        'symbol' => 'ETHUSDT',
        // Account A is on a much cheaper tier than account B.
        'takerCommissionRate' => str_contains($request->header('X-MBX-APIKEY')[0] ?? '', 'B') ? '0.0009' : '0.0001',
    ], 200));
    $integrationB->update(['secrets_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'key-B', 'api_secret' => 's']))]);

    $a = $this->actingAs($userA)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspaceA->id, 'integration_id' => $integrationA->id,
    ]))->assertOk();
    $b = $this->actingAs($userB)->postJson('/api/v1/quick-trade/forecast', afrForecastRequest([
        'workspace_id' => $workspaceB->id, 'integration_id' => $integrationB->id,
    ]))->assertOk();

    expect($a->json('forecast.decision_cache.status'))->toBe('computed')
        ->and($b->json('forecast.decision_cache.status'))->toBe('computed')
        ->and($a->json('forecast.trade_decision.required_move_pct'))->toEqual(0.12)
        ->and($b->json('forecast.trade_decision.required_move_pct'))->toEqual(0.44);
});

// ── Quick Trade: placement-time decision (the A5 guard's input) ─────────

test('flag on: a live manual placement prices the research decision off the account fee', function () {
    config()->set('quick_trade.use_account_fee_rate', true);
    [$user, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'takerCommissionRate' => '0.0004'], 200));
    $captured = null;
    $this->partialMock(QuickTradeResearchService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('capture')->andReturnUsing(function ($candles, $signal, $payload, $accountFee = null) use (&$captured) {
            $captured = $accountFee;
            throw new RuntimeException('stop after capture');
        });
    });

    // Fresh server-side tick, so place() reaches the research capture.
    app(\App\Domain\Market\MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures', 'ETHUSDT', 2000.0, (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 20,
        'timeframe' => '5m', 'expiry_seconds' => 3600, 'mode' => 'live', 'provider' => 'binance',
        'integration_id' => $integration->id, 'indicator_set' => ['MACD'],
    ]);

    expect($captured, (string) $response->getContent())->toBe(['taker_rate' => 0.0004, 'maker_rate' => null, 'source' => 'account', 'reason' => null, 'cached' => false]);
});

test('flag off: a live manual placement passes no account fee and makes no commission call', function () {
    [$user, $workspace, $integration] = afrWorkspace();
    afrFakeBinance(fn () => Http::response(['symbol' => 'ETHUSDT', 'takerCommissionRate' => '0.0004'], 200));
    $captured = 'not-called';
    $this->partialMock(QuickTradeResearchService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('capture')->andReturnUsing(function ($candles, $signal, $payload, $accountFee = null) use (&$captured) {
            $captured = $accountFee;
            throw new RuntimeException('stop after capture');
        });
    });
    app(\App\Domain\Market\MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures', 'ETHUSDT', 2000.0, (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 20,
        'timeframe' => '5m', 'expiry_seconds' => 3600, 'mode' => 'live', 'provider' => 'binance',
        'integration_id' => $integration->id, 'indicator_set' => ['MACD'],
    ]);

    expect($captured)->toBeNull()->and(afrCommissionCalls())->toBe(0);
});

test('QuickTradeResearchService::capture prices its primary decision off a supplied account fee', function () {
    $candles = afrForecastCandles();
    $payload = ['symbol' => 'ETHUSDT', 'timeframe' => '5m', 'expiry_seconds' => 3600, 'mode' => 'paper', 'provider' => 'binance'];
    $signal = ['direction' => 'buy', 'confidence' => 90];
    $service = app(QuickTradeResearchService::class);

    $config = $service->capture($candles, $signal, $payload);
    $account = $service->capture($candles, $signal, $payload, ['taker_rate' => 0.0004, 'maker_rate' => null, 'source' => 'account', 'reason' => null, 'cached' => false]);

    expect($config['primary']['trade_decision']['fee_rate_source'])->toBe('config')
        ->and($config['primary']['trade_decision']['cost_pct'])->toEqual(0.14)
        ->and($config)->not->toHaveKey('account_fee_rate')
        ->and($account['primary']['trade_decision']['fee_rate_source'])->toBe('account')
        ->and($account['primary']['trade_decision']['cost_pct'])->toEqual(0.12)
        ->and($account['account_fee_rate']['source'])->toBe('account')
        ->and($account['trade_decision_prediction']['cost_pct'])->toEqual(0.12);
});
