<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\QuickTradeService;
use App\Jobs\CheckQuickTradeStopLossJob;
use App\Models\AuditEvent;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Verifies CheckQuickTradeStopLossJob: the new per-trade, optional stop-loss
 * for live-mode Quick Trade. Confirms it triggers only off a fresh live
 * price, calls the real (already fee-reconciling) `settleTrade()` path — not
 * a new/duplicate close mechanism — and that one trade's failure never
 * blocks the others in the same tick.
 */
function stopLossWorkspace(string $symbol = 'ETHUSDT'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Live',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => Crypt::encryptString(json_encode([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ])),
    ]);

    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integration->id,
        'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
        );
    }

    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        $symbol,
        2000.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => strtolower($symbol).'@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    return [$user, $workspace, $integration];
}

function fakeBinanceLiveForStopLoss(string $symbol = 'ETHUSDT', string $exitPrice = '1980.00'): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($symbol, $exitPrice) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
            return Http::response([], 200);
        }
        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([
                ['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00'],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => $symbol, 'price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response([
                'symbols' => [[
                    'symbol' => $symbol,
                    'filters' => [[
                        'filterType' => 'MARKET_LOT_SIZE',
                        'stepSize' => '0.001',
                        'minQty' => '0.001',
                    ], [
                        'filterType' => 'MIN_NOTIONAL',
                        'notional' => '20.00000000',
                    ]],
                ]],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            $isClose = str_contains((string) $request->body(), 'reduceOnly');

            return Http::response([
                'orderId' => $isClose ? '9002' : '9001',
                'avgPrice' => $isClose ? $exitPrice : '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });
}

function placeLiveQuickTradeWithStopLoss(
    string $workspaceId,
    string $integrationId,
    string $userId,
    ?float $stopLossPct,
    string $symbol = 'ETHUSDT',
): QuickTrade {
    $payload = [
        'workspace_id' => $workspaceId,
        'symbol' => $symbol,
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integrationId,
        'indicator_set' => ['MACD'],
    ];
    if ($stopLossPct !== null) {
        $payload['stop_loss_pct'] = $stopLossPct;
    }

    return app(QuickTradeService::class)->place($payload, $userId);
}

test('a trade whose live price breaches its stop_loss_pct is settled early and audited', function () {
    [$user, $workspace, $integration] = stopLossWorkspace();
    fakeBinanceLiveForStopLoss();

    $trade = placeLiveQuickTradeWithStopLoss($workspace->id, $integration->id, $user->id, -1.00);

    // Price drops from 2000 to 1980 (a $20 move on 0.01 qty = -$0.20, i.e.
    // -1% of the $20 stake) — right at the configured -1% threshold.
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        1980.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->not->toBe('open');
    expect($fresh->stop_loss_triggered_at)->not->toBeNull();

    $auditEvent = AuditEvent::where('event_type', 'quick_trade_stop_loss_triggered')
        ->where('symbol', 'ETHUSDT')
        ->latest('created_at')
        ->first();
    expect($auditEvent)->not->toBeNull();
    expect($auditEvent->metadata['quick_trade_id'] ?? null)->toBe($trade->id);
});

test('a trade within its stop-loss threshold is left untouched', function () {
    [$user, $workspace, $integration] = stopLossWorkspace();
    fakeBinanceLiveForStopLoss();

    $trade = placeLiveQuickTradeWithStopLoss($workspace->id, $integration->id, $user->id, -5.00);

    // Small move, well within the -5% threshold.
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        1999.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->stop_loss_triggered_at)->toBeNull();
});

test('a trade with stop_loss_pct null is never considered even if price would otherwise breach it', function () {
    [$user, $workspace, $integration] = stopLossWorkspace();
    fakeBinanceLiveForStopLoss();

    $trade = placeLiveQuickTradeWithStopLoss($workspace->id, $integration->id, $user->id, null);
    expect($trade->stop_loss_pct)->toBeNull();

    // A 4% adverse move (kept under the price-cache service's own >5%
    // single-step anti-spoof guard) that would trigger almost any realistic
    // stop-loss threshold if one were configured.
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        1920.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->stop_loss_triggered_at)->toBeNull();
});

test('a stale price snapshot causes the check to be skipped, never a trigger off stale data', function () {
    [$user, $workspace, $integration] = stopLossWorkspace();
    fakeBinanceLiveForStopLoss();

    $trade = placeLiveQuickTradeWithStopLoss($workspace->id, $integration->id, $user->id, -0.01);

    $this->partialMock(MarketPriceCacheService::class, function ($mock) {
        $mock->shouldReceive('getLiveFeedSnapshot')
            ->andReturn(['fresh' => false, 'price' => 1000.0, 'age_seconds' => 999]);
    });

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->stop_loss_triggered_at)->toBeNull();
});

test('one trade throwing an internal error does not prevent other open trades from being checked', function () {
    [$userA, $workspaceA, $integrationA] = stopLossWorkspace('ETHUSDT');
    [$userB, $workspaceB, $integrationB] = stopLossWorkspace('BNBUSDT');
    fakeBinanceLiveForStopLoss('ETHUSDT');

    $tradeA = placeLiveQuickTradeWithStopLoss($workspaceA->id, $integrationA->id, $userA->id, -1.00, 'ETHUSDT');
    // Corrupt trade A's integration reference so settleTrade() throws inside
    // TradingIntegrationResolver::resolve() when the job tries to settle it.
    $tradeA->update(['integration_id' => (string) Str::uuid()]);

    fakeBinanceLiveForStopLoss('BNBUSDT', '1920.00');
    $tradeB = placeLiveQuickTradeWithStopLoss($workspaceB->id, $integrationB->id, $userB->id, -1.00, 'BNBUSDT');

    // Both moves are 4% drops — comfortably clears the -1% stop threshold
    // while staying under the price-cache service's own >5% single-step
    // anti-spoof guard (which would otherwise silently discard the update).
    app(MarketPriceCacheService::class)->setLatestPrice('binance_futures', 'ETHUSDT', 1920.0, (int) floor(microtime(true) * 1000), []);
    app(MarketPriceCacheService::class)->setLatestPrice('binance_futures', 'BNBUSDT', 1920.0, (int) floor(microtime(true) * 1000), []);

    CheckQuickTradeStopLossJob::dispatchSync();

    // A's settlement failed internally — it must remain open, not half-updated.
    expect($tradeA->fresh()->status)->toBe('open');
    // B was unaffected by A's failure and still got settled in the same tick.
    expect($tradeB->fresh()->status)->not->toBe('open');
    expect($tradeB->fresh()->stop_loss_triggered_at)->not->toBeNull();
});
