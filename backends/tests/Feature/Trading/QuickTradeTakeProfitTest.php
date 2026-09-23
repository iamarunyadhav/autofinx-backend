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
 * Feature-level coverage for the optional per-trade `take_profit_pct` field:
 * placement/validation (mirrors QuickTradeStopLossPlacementTest exactly) and
 * the shared exit-conditions job (CheckQuickTradeStopLossJob), which now
 * checks take-profit alongside stop-loss in the same tick.
 */
function takeProfitLiveWorkspace(string $symbol = 'ETHUSDT'): array
{
    // Part A5 (2026-09-24): these tests predate the fee-aware live execution
    // guard and use a live placement purely as the vehicle for testing
    // something else (stop-loss, take-profit, margin/liquidation, settlement).
    // With `quick_trade.block_live_against_decision` at its real default of
    // true, the genuine forecast for these synthetic candle series returns
    // WAIT and would refuse the order before the behaviour under test is ever
    // reached. Opting out here keeps each test focused on its own subject;
    // the guard itself has dedicated coverage in
    // QuickTradeDecisionExecutionGuardTest.
    config()->set('quick_trade.block_live_against_decision', false);
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

function fakeBinanceLiveForTakeProfit(string $symbol = 'ETHUSDT', string $exitPrice = '2020.00'): void
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

function placeLiveQuickTradeWithTakeProfit(
    string $workspaceId,
    string $integrationId,
    string $userId,
    ?float $takeProfitPct,
    ?float $stopLossPct = null,
    string $symbol = 'ETHUSDT',
): QuickTrade {
    $payload = [
        'workspace_id' => $workspaceId,
        'symbol' => $symbol,
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        // 5 candles -- these tests' subject is the take-profit job, not the
        // min-horizon-candles rule (which now ships enforced by default).
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integrationId,
        'indicator_set' => ['MACD'],
    ];
    if ($takeProfitPct !== null) {
        $payload['take_profit_pct'] = $takeProfitPct;
    }
    if ($stopLossPct !== null) {
        $payload['stop_loss_pct'] = $stopLossPct;
    }

    return app(QuickTradeService::class)->place($payload, $userId);
}

test('placing a live quick trade with take_profit_pct set stores it correctly', function () {
    [$user, $workspace, $integration] = takeProfitLiveWorkspace();
    fakeBinanceLiveForTakeProfit();
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
        'take_profit_pct' => 5.0,
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect((float) $trade->take_profit_pct)->toEqualWithDelta(5.0, 0.0001);
    expect($trade->take_profit_triggered_at)->toBeNull();
});

test('placing a live quick trade without take_profit_pct defaults it to null (feature off)', function () {
    [$user, $workspace, $integration] = takeProfitLiveWorkspace();
    fakeBinanceLiveForTakeProfit();
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect($trade->take_profit_pct)->toBeNull();
});

test('a negative or zero take_profit_pct is rejected as invalid', function () {
    [$user, $workspace, $integration] = takeProfitLiveWorkspace();
    fakeBinanceLiveForTakeProfit();
    $this->actingAs($user);

    $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
        'take_profit_pct' => -5.0,
    ])->assertStatus(422);

    $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
        'take_profit_pct' => 0,
    ])->assertStatus(422);
});

test('paper-mode quick trades never store a take_profit_pct even if one is submitted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
        'take_profit_pct' => 5.0,
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect($trade->take_profit_pct)->toBeNull();
});

test('a trade whose live price crosses its take_profit_pct is settled early and audited with a distinct event type', function () {
    [$user, $workspace, $integration] = takeProfitLiveWorkspace();
    fakeBinanceLiveForTakeProfit();

    $trade = placeLiveQuickTradeWithTakeProfit($workspace->id, $integration->id, $user->id, 1.00);

    // Price rises from 2000 to 2020 (a $20 move on 0.01 qty = +$0.20, i.e.
    // +1% of the $20 stake) — right at the configured +1% target.
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        2020.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->not->toBe('open');
    expect($fresh->take_profit_triggered_at)->not->toBeNull();
    expect($fresh->stop_loss_triggered_at)->toBeNull();

    $auditEvent = AuditEvent::where('event_type', 'quick_trade_take_profit_triggered')
        ->where('symbol', 'ETHUSDT')
        ->latest('created_at')
        ->first();
    expect($auditEvent)->not->toBeNull();
    expect($auditEvent->metadata['quick_trade_id'] ?? null)->toBe($trade->id);
});

test('a trade within both its stop-loss and take-profit thresholds is left open', function () {
    [$user, $workspace, $integration] = takeProfitLiveWorkspace();
    fakeBinanceLiveForTakeProfit();

    $trade = placeLiveQuickTradeWithTakeProfit($workspace->id, $integration->id, $user->id, 5.00, -5.00);

    // Small move, well within both the -5% stop and +5% target.
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        2005.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->stop_loss_triggered_at)->toBeNull();
    expect($fresh->take_profit_triggered_at)->toBeNull();
});

test('when both thresholds are configured and only one is met, only that one fires', function () {
    [$user, $workspace, $integration] = takeProfitLiveWorkspace();
    fakeBinanceLiveForTakeProfit();

    // Stop-loss at -1%, take-profit at +5% — price moves to hit the
    // stop-loss only; take-profit must never also fire for the same trade.
    $trade = placeLiveQuickTradeWithTakeProfit($workspace->id, $integration->id, $user->id, 5.00, -1.00);

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
    expect($fresh->take_profit_triggered_at)->toBeNull();

    expect(AuditEvent::where('event_type', 'quick_trade_take_profit_triggered')->where('symbol', 'ETHUSDT')->exists())->toBeFalse();
});
