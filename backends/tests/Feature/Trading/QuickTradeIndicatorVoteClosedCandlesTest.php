<?php

use App\Domain\Trading\QuickTradeResearchService;
use App\Domain\Trading\QuickTradeService;
use App\Domain\Trading\TradingMarketService;
use App\Models\User;
use App\Models\Workspace;

/**
 * `quick_trade.indicator_vote_closed_candles_only` (default true): the
 * indicator vote (SignalPreviewService::preview(), the 25% signal weight of
 * the forecast) is computed on closed candles only, both at
 * /quick-trade/chart (whose signal_preview the page sends back to the
 * forecast) and at placement (the vote behind the A5 guard's research
 * forecast). The chart's DISPLAY series still includes the forming bar.
 *
 * Also covers `trading_forecast.decision_candle_count` at placement.
 *
 * The fixture is an uptrend whose still-forming last bar is a 40-point drop:
 * MACD over all 100 bars votes SELL, over the 99 closed bars votes BUY.
 * Helpers are prefixed `qtvc`.
 */
function qtvcCandles(): array
{
    $rows = [];
    $nowMinuteMs = intdiv((int) floor(microtime(true) * 1000), 60000) * 60000;
    for ($i = 0; $i < 100; $i++) {
        $open = 1701 + ($i * 3);
        $close = $i === 99 ? $open - 40 : $open + 2;
        $rows[] = [
            'timestamp' => $nowMinuteMs - ((99 - $i) * 60000),
            'open' => (float) $open,
            'high' => (float) (max($open, $close) + 20),
            'low' => (float) (min($open, $close) - 20),
            'close' => (float) $close,
            'volume' => 100.0,
        ];
    }

    return $rows;
}

/** @param  array<int, int>  $limits  receives every `candles()` limit requested */
function qtvcMockMarket(array &$limits): void
{
    test()->partialMock(TradingMarketService::class, function ($mock) use (&$limits) {
        $mock->shouldReceive('candles')->andReturnUsing(function ($symbol, $timeframe, $limit) use (&$limits) {
            $limits[] = (int) $limit;

            return qtvcCandles();
        });
        $mock->shouldReceive('latestQuote')->andReturn(['symbol' => 'BTCUSDT', 'price' => 1998.0, 'occurred_at' => now()->toIso8601String()]);
    });
}

// ── /quick-trade/chart ──────────────────────────────────────────────────

test('the closed-candle indicator vote ships ON by default', function () {
    expect(config('quick_trade.indicator_vote_closed_candles_only'))->toBeTrue();
});

test('chart: the vote is computed on closed candles while the display series keeps the forming bar', function () {
    $limits = [];
    qtvcMockMarket($limits);

    $chart = app(QuickTradeService::class)->chart('', 'BTCUSDT', '1m', 'candles', ['MACD']);

    expect($chart['candles'])->toHaveCount(100)
        // The forming bar (open 1998, close 1958) is still drawn.
        ->and(end($chart['candles'])['close'])->toEqual(1958.0)
        ->and($chart['signal_preview']['direction'])->toBe('buy')
        ->and($chart['signal_preview_input'])->toBe([
            'closed_candles_only' => true, 'forming_bar_dropped' => true, 'candles_used' => 99,
        ]);
});

test('chart: with the flag off the vote includes the forming bar exactly as before', function () {
    config()->set('quick_trade.indicator_vote_closed_candles_only', false);
    $limits = [];
    qtvcMockMarket($limits);

    $chart = app(QuickTradeService::class)->chart('', 'BTCUSDT', '1m', 'candles', ['MACD']);

    expect($chart['candles'])->toHaveCount(100)
        ->and($chart['signal_preview']['direction'])->toBe('sell')
        ->and($chart['signal_preview_input'])->toBe([
            'closed_candles_only' => false, 'forming_bar_dropped' => false, 'candles_used' => 100,
        ]);
});

// ── placement (the vote behind the A5 guard's research forecast) ────────

/**
 * Drives a real paper QuickTradeService::place() up to the research capture
 * and stops there, returning what capture() was handed.
 *
 * @return array{signal: array, candles: array, limits: array<int,int>}
 */
function qtvcPlaceUntilCapture(): array
{
    $limits = [];
    qtvcMockMarket($limits);
    $seen = [];
    test()->partialMock(QuickTradeResearchService::class, function ($mock) use (&$seen) {
        $mock->shouldReceive('capture')->andReturnUsing(function ($candles, $signal) use (&$seen) {
            $seen = ['signal' => $signal, 'candles' => $candles];
            throw new RuntimeException('stop after capture');
        });
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    try {
        app(QuickTradeService::class)->place([
            'workspace_id' => $workspace->id, 'symbol' => 'BTCUSDT', 'side' => 'buy', 'stake_amount' => 20,
            'timeframe' => '1m', 'expiry_seconds' => 60, 'mode' => 'paper', 'indicator_set' => ['MACD'],
        ], $user->id);
    } catch (RuntimeException) {
        // capture() throws on purpose; place() itself swallows research
        // failures, so reaching here or returning are both fine.
    }

    return array_merge($seen, ['limits' => $limits]);
}

test('placement: the indicator vote uses closed candles; the research capture still receives the raw candles', function () {
    $seen = qtvcPlaceUntilCapture();

    expect($seen['signal']['direction'])->toBe('buy')
        ->and($seen['candles'])->toHaveCount(100);
});

test('placement: with the flag off the vote includes the forming bar exactly as before', function () {
    config()->set('quick_trade.indicator_vote_closed_candles_only', false);

    $seen = qtvcPlaceUntilCapture();

    expect($seen['signal']['direction'])->toBe('sell');
});

test('placement fetches trading_forecast.decision_candle_count candles (80 by default, unchanged)', function () {
    expect(qtvcPlaceUntilCapture()['limits'])->toContain(80);

    config()->set('trading_forecast.decision_candle_count', 90);
    expect(qtvcPlaceUntilCapture()['limits'])->toContain(90);
});
