<?php

use App\Domain\Trading\TradeForecastService;

/**
 * TradeForecastService::computeAtrAvgPct() -- "average ATR as % of price over
 * the last 20 periods". Before `trading_forecast.atr_latest_bars` it read the
 * OLDEST 20 true ranges of whatever array it was handed. These tests use a
 * synthetic regime shift (old bars and new bars with clearly different
 * volatility, flat price so the divisor is the same) to prove which bars
 * are actually averaged under each setting.
 *
 * Helpers are prefixed `atrlb` to avoid colliding with other test files'
 * global helper functions.
 */
function atrlbRegimeShift(int $oldBars, float $oldRange, int $newBars, float $newRange, float $price = 1000.0): array
{
    $candles = [];
    foreach ([[$oldBars, $oldRange], [$newBars, $newRange]] as [$count, $range]) {
        for ($i = 0; $i < $count; $i++) {
            // Flat close: each bar's true range is exactly its high-low.
            $candles[] = [
                'open' => $price,
                'high' => $price + $range / 2,
                'low' => $price - $range / 2,
                'close' => $price,
            ];
        }
    }

    return $candles;
}

it('ships the latest-bars fix ON by default', function () {
    expect(config('trading_forecast.atr_latest_bars'))->toBeTrue();
});

it('averages the LATEST 20 true ranges, not the oldest, on a long window', function () {
    // 60 old volatile bars (range 10 = 1.0% of 1000), then 40 calm bars
    // (range 1 = 0.1%). Latest 20 true ranges are all calm bars.
    $candles = atrlbRegimeShift(60, 10.0, 40, 1.0);

    $atr = app(TradeForecastService::class)->computeAtrAvgPct($candles);

    expect(round($atr, 6))->toBe(0.1);
});

it('reproduces the legacy oldest-bars average when the flag is off (config-only rollback)', function () {
    config()->set('trading_forecast.atr_latest_bars', false);
    $candles = atrlbRegimeShift(60, 10.0, 40, 1.0);

    $atr = app(TradeForecastService::class)->computeAtrAvgPct($candles);

    // Legacy reads bars 1..20 -- all from the old volatile regime -- so a
    // market that has gone quiet still reports 10x its current volatility.
    expect(round($atr, 6))->toBe(1.0);
});

it('reacts to a volatility spike in the latest bars that the legacy read ignored', function () {
    // The mirror case: calm history, the latest 20 bars just turned volatile.
    $candles = atrlbRegimeShift(80, 1.0, 20, 10.0);
    $service = app(TradeForecastService::class);

    expect(round($service->computeAtrAvgPct($candles), 6))->toBe(1.0);

    config()->set('trading_forecast.atr_latest_bars', false);
    expect(round($service->computeAtrAvgPct($candles), 6))->toBe(0.1);
});

it('keeps 21 candles so exactly the latest 20 true ranges are averaged', function () {
    // 30 bars at 1000, then 21 bars at 1010. Index 30 is the +10 gap bar
    // (its TR vs the previous 1000 close would be 10.5). The latest-21 slice
    // is indices 30..50: index 30 only supplies the previous close for the
    // first true range, so all 20 averaged TRs (indices 31..50) are 1.0.
    $candles = atrlbRegimeShift(30, 1.0, 0, 0.0, 1000.0);
    for ($i = 0; $i < 21; $i++) {
        $candles[] = ['open' => 1010.0, 'high' => 1010.5, 'low' => 1009.5, 'close' => 1010.0];
    }
    $atr = app(TradeForecastService::class)->computeAtrAvgPct($candles);

    expect(round($atr, 6))->toBe(round(1.0 / 1010.0 * 100, 6));
});

it('is identical under both settings for inputs of 21 candles or fewer', function () {
    $service = app(TradeForecastService::class);
    foreach ([5, 20, 21] as $count) {
        $candles = array_slice(atrlbRegimeShift(10, 10.0, 20, 1.0), 0, $count);

        config()->set('trading_forecast.atr_latest_bars', true);
        $fixed = $service->computeAtrAvgPct($candles);
        config()->set('trading_forecast.atr_latest_bars', false);
        $legacy = $service->computeAtrAvgPct($candles);

        expect($fixed)->toBe($legacy);
    }
});

it('feeds the corrected ATR into netEdge() and the forecast()', function () {
    // Old volatile regime, calm now. Legacy ATR made netEdge() call a calm
    // market tradeable; the corrected read does not.
    $candles = atrlbRegimeShift(60, 30.0, 40, 1.0);
    $service = app(TradeForecastService::class);

    $fixedEdge = $service->netEdge($candles, 100.0);
    $fixedForecast = $service->forecast($candles, '1m', 60);
    config()->set('trading_forecast.atr_latest_bars', false);
    $legacyEdge = $service->netEdge($candles, 100.0);

    expect($legacyEdge['atr_pct'])->toBe(3.0)
        ->and($legacyEdge['tradeable'])->toBeTrue()
        ->and($fixedEdge['atr_pct'])->toBe(0.1)
        ->and($fixedEdge['tradeable'])->toBeFalse()
        // forecast() slices its own horizon window (20 bars for 1m/60s)
        // before calling the helper, so it was already reading recent bars.
        ->and($fixedForecast['atr_pct'])->toBe(0.1);
});
