<?php

use App\Domain\Trading\TradeForecastService;

/**
 * Covers the context-driven safety gates added to
 * TradeForecastService::forecast(): the low-volume/liquidity gate, the
 * stale-quote gate, and the support/resistance caution flag. All three are
 * additive -- new fields only (`gate_reason`, `context_caution`), no
 * existing field renamed/removed -- so a caller ignoring them sees no
 * behavior change.
 */
function bullishCandles(int $count, float $start = 100.0, float $volume = 1000.0): array
{
    $candles = [];
    $price = $start;
    for ($i = 0; $i < $count; $i++) {
        $open = $price;
        $close = $open + 1.0;
        $candles[] = [
            'open' => $open,
            'high' => $close + 0.2,
            'low' => $open - 0.2,
            'close' => $close,
            'volume' => $volume,
        ];
        $price = $close;
    }

    return $candles;
}

it('leaves a confident decision undisturbed under normal, healthy conditions', function () {
    $candles = bullishCandles(40);

    $result = app(TradeForecastService::class)->forecast($candles, '1m', 60, null, null, null);

    expect($result['available'])->toBeTrue();
    // Healthy uniform volume across the window -> "normal", not gated.
    expect($result['market_context']['volume_status'])->toBe('normal');
    // Neither new gate fired -- whatever decision_trend the existing
    // trend/drift/cost math produced is untouched by this change.
    expect($result['gate_reason'])->toBeNull();
    expect($result['context_caution'])->toBeArray();
});

it('forces WAIT with reason low_volume when recent volume has genuinely contracted', function () {
    $candles = bullishCandles(20, 100.0, 1000.0);
    // Last 5 candles (recentVolumes) collapse well below the trailing
    // baseline average -> contextFeatures() reports volume_status=contracting.
    $thin = bullishCandles(5, end($candles)['close'], 50.0);
    $candles = array_merge($candles, $thin);

    $result = app(TradeForecastService::class)->forecast($candles, '1m', 60, null, null, null);

    expect($result['market_context']['volume_status'])->toBe('contracting');
    expect($result['decision_trend'])->toBe('WAIT');
    expect($result['gate_reason'])->toBe('low_volume');
    expect($result['summary'])->toContain('volume');
});

it('forces WAIT with reason stale_data when the live feed snapshot is explicitly non-fresh', function () {
    $candles = bullishCandles(40);

    $result = app(TradeForecastService::class)->forecast(
        $candles, '1m', 60, null, null,
        ['fresh' => false, 'age_seconds' => 45]
    );

    expect($result['decision_trend'])->toBe('WAIT');
    expect($result['gate_reason'])->toBe('stale_data');
    expect($result['summary'])->toContain('stale');
});

it('does not gate on a live-feed snapshot that was never resolved (null), only on an explicitly stale one', function () {
    $candles = bullishCandles(40);

    $withoutSnapshot = app(TradeForecastService::class)->forecast($candles, '1m', 60, null, null, null);
    $withFreshSnapshot = app(TradeForecastService::class)->forecast(
        $candles, '1m', 60, null, null,
        ['fresh' => true, 'age_seconds' => 1]
    );

    expect($withoutSnapshot['gate_reason'])->toBeNull();
    expect($withFreshSnapshot['gate_reason'])->toBeNull();
});

it('uses a supplied fresh canonical quote as the reference without changing candle evidence', function () {
    $candles = bullishCandles(40);
    $quote = 177.25;

    $result = app(TradeForecastService::class)->forecast(
        $candles, '1m', 60, null, null,
        ['fresh' => true, 'age_seconds' => 0], 'BTCUSDT', $quote,
    );

    expect($result['reference_price'])->toBe($quote);
    expect($result['reference_price_source'])->toBe('fresh_canonical_quote');
});

it('surfaces a near_resistance caution when the predicted direction runs into a nearby real resistance level', function () {
    // Same steady uptrend fixture as the "healthy conditions" case above:
    // each candle's high (close + 0.2) sits just ahead of the next candle's
    // close, so the window's max high (resistance) stays within the small
    // buffer of the reference price while the trend/drift is bullish.
    $candles = bullishCandles(40);

    $result = app(TradeForecastService::class)->forecast($candles, '1m', 60, null, null, null);

    // A tight, flat-topped uptrend: the predicted direction is bullish while
    // the already-computed resistance (max high in the window) sits within
    // the small buffer of the reference price -- this must be surfaced as a
    // caution, not silently dropped, and must not force WAIT by itself.
    expect($result['market_context']['resistance'])->not->toBeNull();
    expect($result['context_caution'])->toBeArray();
    expect($result['context_caution'])->toContain('near_resistance');
});
