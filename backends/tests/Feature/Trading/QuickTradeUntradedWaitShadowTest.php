<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\QuickTradeWaitShadowService;
use App\Models\QuickTradeWaitShadow;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Phase 1 item 4 -- shadow records for WAIT decisions nobody traded.
 * Observation only; behind quick_trade.shadow_untraded_waits (default OFF).
 */
beforeEach(function () {
    config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
    config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
    config()->set('robot_trade.live_min_edge_cost_multiplier', 2.0);
    config()->set('quick_trade.fee_model', 'taker');
    config()->set('quick_trade.decision_close_anchored_cache', true);
    config()->set('quick_trade.shadow_untraded_waits_reference_notional_usdt', 100);
});

/**
 * Flat, timestamped 5m candles: a tiny expected move that can never clear
 * the 0.28% required move, so the fee-aware decision is WAIT.
 */
function shadowWaitCandles(): array
{
    $tf = 300;
    $lastOpen = intdiv(time(), $tf) * $tf - $tf; // last CLOSED bar's open
    $rows = [];
    for ($i = 39; $i >= 0; $i--) {
        $open = ($lastOpen - $i * $tf) * 1000;
        $close = 2000.0 + (($i % 2 === 0) ? 0.2 : -0.2);
        $rows[] = ['timestamp' => $open, 'open' => 2000.0, 'high' => 2000.5, 'low' => 1999.5, 'close' => $close, 'volume' => 900.0];
    }
    // The forming bar (dropped by the closed-candles-only rule).
    $rows[] = ['timestamp' => ($lastOpen + $tf) * 1000, 'open' => 2000.0, 'high' => 2000.5, 'low' => 1999.5, 'close' => 2000.1, 'volume' => 900.0];

    return $rows;
}

function shadowForecast(array $overrides = []): \Illuminate\Testing\TestResponse
{
    test()->actingAs(User::factory()->create());

    return test()->postJson('/api/v1/quick-trade/forecast', array_merge([
        'candles' => shadowWaitCandles(),
        'timeframe' => '5m',
        'expiry_seconds' => 3600,
        'stake_amount' => 50,
        'symbol' => 'BTCUSDT',
        'exchange' => 'binance_futures',
        'mode' => 'live',
    ], $overrides))->assertOk();
}

function pendingShadow(array $overrides = []): QuickTradeWaitShadow
{
    $referenceAt = CarbonImmutable::now()->subSeconds(3600)->subSeconds(2);

    return QuickTradeWaitShadow::create(array_merge([
        'exchange' => 'binance_futures', 'symbol' => 'BTCUSDT', 'timeframe' => '5m', 'expiry_seconds' => 3600,
        'decision_candle_close_ms' => $referenceAt->getTimestampMs() - 5000,
        'decision_candle_close_at' => $referenceAt->subSeconds(5),
        'reference_at' => $referenceAt,
        'target_ms' => $referenceAt->addSeconds(3600)->getTimestampMs(),
        'mode' => 'live', 'direction' => 'BUY', 'trade_decision' => 'WAIT',
        'reference_price' => 2000.0, 'cost_model' => ['fee_model' => 'taker', 'cost_pct' => 0.14],
        'reference_notional_usdt' => 100, 'status' => 'pending',
    ], $overrides));
}

test('with the flag off (the default) a WAIT forecast records nothing', function () {
    config()->set('quick_trade.shadow_untraded_waits', false);

    $response = shadowForecast();

    expect($response->json('forecast.trade_decision.value'))->toBe('WAIT');
    expect(QuickTradeWaitShadow::count())->toBe(0);
});

test('with the flag on a live WAIT is recorded once per symbol/timeframe/expiry/decision candle close', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);

    $first = shadowForecast();
    expect($first->json('forecast.trade_decision.value'))->toBe('WAIT');
    expect($first->json('forecast.decision_cache.status'))->toBe('computed');

    // Same candle close again: cache reused -> no second capture attempt.
    shadowForecast();
    // Even a second process that missed the cache cannot create a duplicate:
    // the unique index + insertOrIgnore make the write idempotent.
    Cache::flush();
    shadowForecast();

    expect(QuickTradeWaitShadow::count())->toBe(1);
    $row = QuickTradeWaitShadow::firstOrFail();
    expect($row->symbol)->toBe('BTCUSDT');
    expect($row->timeframe)->toBe('5m');
    expect($row->expiry_seconds)->toBe(3600);
    expect($row->trade_decision)->toBe('WAIT');
    expect($row->status)->toBe('pending');
    expect($row->required_move_pct)->toEqualWithDelta(0.28, 1e-6);
    expect($row->max_achievable_move_pct)->not->toBeNull();
    expect($row->reference_price)->toBeGreaterThan(0);
    expect($row->decision_candle_close_at)->not->toBeNull();
    expect($row->cost_model['fee_model'])->toBe('taker');
    expect($row->target_ms)->toBe($row->reference_at->getTimestampMs() + 3600 * 1000);

    // A different expiry for the same candle close is its own decision.
    shadowForecast(['expiry_seconds' => 1800]);
    expect(QuickTradeWaitShadow::count())->toBe(2);
});

test('paper-mode forecasts are never recorded', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);

    shadowForecast(['mode' => 'paper']);

    expect(QuickTradeWaitShadow::count())->toBe(0);
});

test('only WAIT decisions freshly computed for a candle close are captured', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    $service = app(QuickTradeWaitShadowService::class);
    $data = ['mode' => 'live', 'symbol' => 'BTCUSDT', 'timeframe' => '5m', 'expiry_seconds' => 3600, 'exchange' => 'binance_futures'];
    $forecast = [
        'available' => true, 'generated_at' => now()->toIso8601String(), 'reference_price' => 2000,
        'decision_cache' => ['status' => 'computed', 'anchor_candle_close_ms' => 1_790_000_000_000],
        'trade_decision' => ['value' => 'BUY'], 'direction' => ['value' => 'BUY'],
    ];

    $service->captureFromForecast($data, $forecast); // BUY
    $service->captureFromForecast($data, array_merge($forecast, [
        'trade_decision' => ['value' => 'WAIT'], 'decision_cache' => ['status' => 'reused', 'anchor_candle_close_ms' => 1_790_000_000_000],
    ])); // WAIT but reused
    $service->captureFromForecast(array_merge($data, ['exchange' => 'coinbase']), array_merge($forecast, ['trade_decision' => ['value' => 'WAIT']]));

    expect(QuickTradeWaitShadow::count())->toBe(0);
});

test('a due shadow is evaluated by the existing research sampler with the A4 net-after-costs rule', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    $shadow = pendingShadow();
    // Expiry price 1990 (-0.5% against a BUY direction), stamped 1s after target.
    app(MarketPriceCacheService::class)->setLatestPrice('binance_futures', 'BTCUSDT', 1990.0, $shadow->target_ms + 1000,
        ['stream' => 'btcusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures']);

    Artisan::call('quick-trades:sample-research');

    $shadow->refresh();
    expect($shadow->status)->toBe('evaluated');
    expect($shadow->expiry_price)->toEqual(1990.0);
    // gross = 100 x -0.5% = -0.50; cost = 100 x 0.14% = 0.14; net = -0.64.
    expect($shadow->gross_move_pct)->toEqualWithDelta(-0.5, 1e-6);
    expect($shadow->hypothetical_net_usdt)->toEqualWithDelta(-0.64, 1e-6);
    expect($shadow->wait_saved_usdt)->toEqualWithDelta(0.64, 1e-6);
    expect($shadow->wait_missed_usdt)->toEqual(0.0);
});

test('an untraded WAIT that would have profited counts as missed', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    $shadow = pendingShadow(['direction' => 'SELL']);
    app(MarketPriceCacheService::class)->setLatestPrice('binance_futures', 'BTCUSDT', 1990.0, $shadow->target_ms + 1000,
        ['stream' => 'btcusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures']);

    Artisan::call('quick-trades:sample-research');

    $shadow->refresh();
    // SELL +0.5% gross = 0.50 - 0.14 cost = 0.36 missed.
    expect($shadow->wait_missed_usdt)->toEqualWithDelta(0.36, 1e-6);
    expect($shadow->wait_saved_usdt)->toEqual(0.0);
});

test('a shadow whose expiry window passed with no quote is marked missing, never guessed', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    $shadow = pendingShadow([
        'reference_at' => CarbonImmutable::now()->subSeconds(3700),
        'target_ms' => CarbonImmutable::now()->subSeconds(100)->getTimestampMs(),
    ]);

    Artisan::call('quick-trades:sample-research');

    $shadow->refresh();
    expect($shadow->status)->toBe('missing_horizon_sample');
    expect($shadow->wait_saved_usdt)->toBeNull();
    expect($shadow->wait_missed_usdt)->toBeNull();
});

test('a NEUTRAL direction is not applicable, never a saving or a miss', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    $shadow = pendingShadow(['direction' => 'NEUTRAL']);
    app(MarketPriceCacheService::class)->setLatestPrice('binance_futures', 'BTCUSDT', 1990.0, $shadow->target_ms + 1000,
        ['stream' => 'btcusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures']);

    Artisan::call('quick-trades:sample-research');

    expect($shadow->refresh()->status)->toBe('not_applicable');
});

test('the flag off leaves pending shadows untouched by the sampler', function () {
    config()->set('quick_trade.shadow_untraded_waits', false);
    $shadow = pendingShadow();

    Artisan::call('quick-trades:sample-research');

    expect($shadow->refresh()->status)->toBe('pending');
});

test('the research summary and accuracy report expose untraded WAIT totals separately from traded WAITs', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    pendingShadow(['status' => 'evaluated', 'wait_saved_usdt' => 0.64, 'wait_missed_usdt' => 0]);
    pendingShadow(['decision_candle_close_ms' => 1, 'status' => 'evaluated', 'wait_saved_usdt' => 0, 'wait_missed_usdt' => 0.36]);
    pendingShadow(['decision_candle_close_ms' => 2]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);

    foreach (['summary', 'accuracy-report'] as $endpoint) {
        $audit = $this->actingAs($user)
            ->getJson("/api/v1/workspaces/{$workspace->id}/quick-trades/research/{$endpoint}")
            ->assertOk()
            ->json('audit_summary');

        // Traded-WAIT totals: this workspace has no trades -> zero.
        expect((float) $audit['wait_decision_saved_usdt'])->toEqual(0.0);
        $untraded = $audit['untraded_wait_shadow'];
        expect($untraded['scope'])->toBe('market_level_untraded_waits');
        expect($untraded['wait_decisions_recorded'])->toBe(3);
        expect($untraded['wait_decisions_evaluated'])->toBe(2);
        expect($untraded['wait_decisions_pending'])->toBe(1);
        expect((float) $untraded['wait_decision_saved_usdt'])->toEqualWithDelta(0.64, 1e-6);
        expect((float) $untraded['wait_decision_missed_usdt'])->toEqualWithDelta(0.36, 1e-6);
        expect((float) $untraded['wait_decision_net_benefit_usdt'])->toEqualWithDelta(0.28, 1e-6);
        expect((float) $untraded['reference_notional_usdt'])->toEqual(100.0);
    }
});

test('retention pruning deletes only rows older than the configured window', function () {
    config()->set('quick_trade.shadow_untraded_waits', true);
    config()->set('quick_trade.shadow_untraded_waits_retention_days', 30);
    $old = pendingShadow();
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $recent = pendingShadow(['decision_candle_close_ms' => 3]);

    expect(app(QuickTradeWaitShadowService::class)->pruneExpired(force: true))->toBe(1);
    expect(QuickTradeWaitShadow::find($old->id))->toBeNull();
    expect(QuickTradeWaitShadow::find($recent->id))->not->toBeNull();
});
