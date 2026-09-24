<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\QuickTradeResearchService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Part A5 -- the LIVE execution guard, enforced server-side.
 * Part B5 -- chart timeframe must not exceed the trade expiry (live).
 *
 * The live workspace harness mirrors QuickTradeStopLossPlacementTest's
 * exactly (same Binance HTTP fakes, same settings rows) rather than
 * inventing a second one.
 *
 * QuickTradeResearchService::capture() is faked so the fee-aware trade
 * decision under test is deterministic; the guard logic itself
 * (QuickTradeService::assertOrderAgreesWithTradeDecision()) runs for real.
 */
function decisionGuardLiveWorkspace(): array
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
            'api_key' => 'test-key', 'api_secret' => 'test-secret',
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
        'binance_futures', 'ETHUSDT', 2000.0, (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();
        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00']], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response(['symbols' => [[
                'symbol' => 'ETHUSDT',
                'filters' => [
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '20.00000000'],
                ],
            ]]], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response(['orderId' => '9001', 'avgPrice' => '2000.00', 'executedQty' => '0.010'], 200);
        }

        return Http::response([], 200);
    });

    return [$user, $workspace, $integration];
}

function fakeDecisionSnapshot(string $decision, string $direction = 'BUY'): array
{
    return [
        'schema_version' => 1,
        'captured_at' => now()->toIso8601String(),
        'primary' => [
            'available' => true,
            'bias' => $direction,
            'decision_trend' => $decision,
            'horizon_seconds' => 60,
            'current_price' => 2000,
            'predicted_move_pct' => 0.05,
            'direction' => [
                'value' => $direction, 'fee_aware' => false,
                'expiry_score' => 0.34, 'expected_move_pct' => 0.05,
            ],
            'trade_decision' => [
                'value' => $decision, 'fee_aware' => true,
                'required_move_pct' => 0.28, 'max_achievable_move_pct' => 0.141,
                'cost_pct' => 0.14, 'gate_reason' => null, 'expected_move_pct' => 0.05,
            ],
        ],
        'direction_prediction' => ['value' => $direction, 'fee_aware' => false, 'available' => true],
        'trade_decision_prediction' => ['value' => $decision, 'fee_aware' => true, 'available' => true],
    ];
}

function placeGuardedLiveTrade(array $overrides = []): \Illuminate\Testing\TestResponse
{
    [$user, $workspace, $integration] = decisionGuardLiveWorkspace();
    test()->actingAs($user);

    return test()->postJson('/api/v1/quick-trade/place', array_merge([
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        // 5 candles -- the A5 tests below are about the decision-agreement
        // guard, not the min-horizon-candles rule (which now ships on by
        // default and has its own dedicated tests further down using an
        // explicit 1m/60s override).
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ], $overrides));
}

// ── A5: blocked when the guard is on ─────────────────────────────────────

test('a live order against a WAIT trade decision is rejected with 422 and the numeric reason', function () {
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeDecisionSnapshot('WAIT'));
    });

    $response = placeGuardedLiveTrade()->assertStatus(422);

    $message = $response->json('message');
    expect($message)->toContain('quick_trade_blocked_against_decision');
    expect($message)->toContain('WAIT');
    // The numbers the user needs, not a generic refusal.
    expect($message)->toContain('0.280');
    expect($message)->toContain('0.141');
    expect(QuickTrade::count())->toBe(0);
});

test('a live order on the opposite side to a directional trade decision is rejected', function () {
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeDecisionSnapshot('SELL', 'SELL'));
    });

    $response = placeGuardedLiveTrade(['side' => 'buy'])->assertStatus(422);

    expect($response->json('message'))->toContain('quick_trade_blocked_against_decision');
    expect(QuickTrade::count())->toBe(0);
});

test('a live order that agrees with the trade decision is allowed through', function () {
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeDecisionSnapshot('BUY'));
    });

    placeGuardedLiveTrade(['side' => 'buy'])->assertCreated();

    expect(QuickTrade::count())->toBe(1);
});

// ── A5: allowed and flagged when the guard is off ────────────────────────

test('with the guard off a live order against the decision is allowed and flagged as a manual override', function () {
    config()->set('quick_trade.block_live_against_decision', false);
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeDecisionSnapshot('SELL', 'SELL'));
    });

    $response = placeGuardedLiveTrade(['side' => 'buy'])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect($trade->side)->toBe('buy');
    // The order is NOT secretly changed -- it executes as submitted and the
    // disagreement is recorded for research (spec item 61 / A5 manual_override).
    expect($trade->manual_action_disagreed_with_prediction)->toBeTrue();
});

test('a missing prediction never blocks a live order', function () {
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        // No primary forecast at all -- capture failed or was disabled.
        $mock->shouldReceive('capture')->andReturn(['schema_version' => 1, 'primary' => null]);
    });

    placeGuardedLiveTrade()->assertCreated();
});

// ── A5: paper mode is never blocked ──────────────────────────────────────

test('paper mode is never blocked by the live execution guard', function () {
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, function ($mock) {
        $mock->shouldReceive('capture')->andReturn(fakeDecisionSnapshot('WAIT'));
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $this->actingAs($user);

    $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 300,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
    ])->assertCreated();
});

// ── B5: timeframe must not exceed expiry ─────────────────────────────────

test('a live combination whose chart timeframe exceeds the expiry is rejected', function () {
    config()->set('quick_trade.reject_timeframe_above_expiry', true);
    config()->set('quick_trade.block_live_against_decision', false);

    $response = placeGuardedLiveTrade(['timeframe' => '15m', 'expiry_seconds' => 60])->assertStatus(422);

    expect($response->json('message'))->toContain('is longer than the 60s expiry');
    expect(QuickTrade::count())->toBe(0);
});

test('the min-horizon-candles guard rejects a live 1m/60s combination when enabled', function () {
    // Ships OFF by default (see config/quick_trade.php for why); this proves
    // the rule itself is implemented and correct when the owner turns it on.
    config()->set('quick_trade.enforce_min_horizon_candles', true);
    config()->set('quick_trade.min_horizon_candles', 3);
    config()->set('quick_trade.block_live_against_decision', false);

    $response = placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 60])->assertStatus(422);

    expect($response->json('message'))->toContain('require at least 3');
    expect(QuickTrade::count())->toBe(0);
});

test('the min-horizon-candles guard allows a live 1m/300s combination when enabled', function () {
    config()->set('quick_trade.enforce_min_horizon_candles', true);
    config()->set('quick_trade.min_horizon_candles', 3);
    config()->set('quick_trade.block_live_against_decision', false);

    placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 300])->assertCreated();
});

test('the min-horizon-candles guard ships enabled by default so a live 1m/60s combination is rejected with no explicit config override', function () {
    // 2026-09-24 owner decision: this rule is now ON out of the box (see
    // config/quick_trade.php). This test used to prove the opposite (the
    // rule was inert unless explicitly enabled) -- it now guards the new
    // shipped default itself, distinct from the tests above/below that
    // explicitly force the flag one way or the other.
    config()->set('quick_trade.block_live_against_decision', false);

    expect(config('quick_trade.enforce_min_horizon_candles'))->toBeTrue();
    $response = placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 60])->assertStatus(422);
    expect($response->json('message'))->toContain('require at least 3');
});

// ── Gap-checklist N4: stop-loss on long live expiries ────────────────────

test('N4 ships with a 1800s long-expiry threshold and enforcement OFF, so a long live trade without a stop still opens', function () {
    config()->set('quick_trade.block_live_against_decision', false);

    expect(config('quick_trade.require_stop_loss_min_expiry_seconds'))->toBe(1800)
        ->and(config('quick_trade.enforce_stop_loss_min_expiry'))->toBeFalse();

    placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 3600])->assertCreated();
    expect(QuickTrade::first()->stop_loss_pct)->toBeNull();
});

test('N4 enforcement, when enabled, rejects a live trade at or above the threshold with Stop Loss off', function () {
    config()->set('quick_trade.block_live_against_decision', false);
    config()->set('quick_trade.enforce_stop_loss_min_expiry', true);
    config()->set('quick_trade.require_stop_loss_min_expiry_seconds', 1800);

    $response = placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 3600])->assertStatus(422);

    expect($response->json('message'))->toContain('stop_loss_required_for_long_expiry')
        ->and($response->json('message'))->toContain('1800s');
    expect(QuickTrade::count())->toBe(0);
});

test('N4 enforcement allows the same long live trade once a Stop Loss is set, and never invents one', function () {
    config()->set('quick_trade.block_live_against_decision', false);
    config()->set('quick_trade.enforce_stop_loss_min_expiry', true);
    config()->set('quick_trade.require_stop_loss_min_expiry_seconds', 1800);

    placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 3600, 'stop_loss_pct' => -2])->assertCreated();
    expect((float) QuickTrade::first()->stop_loss_pct)->toBe(-2.0);
});

test('N4 enforcement does not touch live expiries below the threshold', function () {
    config()->set('quick_trade.block_live_against_decision', false);
    config()->set('quick_trade.enforce_stop_loss_min_expiry', true);
    config()->set('quick_trade.require_stop_loss_min_expiry_seconds', 1800);

    placeGuardedLiveTrade(['timeframe' => '1m', 'expiry_seconds' => 900])->assertCreated();
});

test('quick trade metadata publishes the N4 threshold and only the C1 live presets the provider really offers', function () {
    [$user, $workspace] = decisionGuardLiveWorkspace();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/quick-trade/metadata?workspace_id='.$workspace->id)
        ->assertOk();

    expect($response->json('quick_trade.require_stop_loss_min_expiry_seconds'))->toBe(1800)
        ->and($response->json('quick_trade.enforce_stop_loss_min_expiry'))->toBeFalse()
        ->and($response->json('quick_trade.min_horizon_candles'))->toBe(3);

    // config/quick_trade.php lists 5m/3600 (recommended), 5m/1800, 15m/3600.
    // Binance's live duration catalog (BinanceTimedFuturesProvider::
    // capabilities()) has no 1800s option, so that preset could never be
    // selected -- it is filtered out rather than shown as a dead button.
    $liveDurations = app(\App\Domain\Trading\Providers\BinanceTimedFuturesProvider::class)->capabilities()['durations'];
    expect($liveDurations)->not->toContain(1800);

    $presets = $response->json('quick_trade.live_expiry_presets');
    expect($presets)->toHaveCount(2)
        ->and($presets[0])->toMatchArray(['timeframe' => '5m', 'expiry_seconds' => 3600, 'recommended' => true, 'label' => '5m chart / 60m'])
        ->and($presets[1])->toMatchArray(['timeframe' => '15m', 'expiry_seconds' => 3600, 'recommended' => false])
        ->and(collect($presets)->pluck('expiry_seconds')->all())->not->toContain(1800);
});
