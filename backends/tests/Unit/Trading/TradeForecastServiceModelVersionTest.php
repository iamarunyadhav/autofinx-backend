<?php

use App\Domain\Trading\TradeForecastService;

/**
 * Covers TradeForecastService::forecast()'s model_version field: it used to
 * be a hardcoded literal ('quick-expiry-v1') that never varied. It is now a
 * real fingerprint ("v1-{8 hex chars}") of the actual weights/thresholds/
 * config that meaningfully affect forecast()'s output -- this asserts the
 * fingerprint is stable when nothing relevant changes and actually changes
 * when a real config input the model reads is changed.
 */
function modelVersionTestCandles(): array
{
    $candles = [];
    $price = 100.0;
    for ($i = 0; $i < 30; $i++) {
        $close = $price + 1.0;
        $candles[] = [
            'open' => $price,
            'high' => $close + 0.2,
            'low' => $price - 0.2,
            'close' => $close,
            'volume' => 1000,
        ];
        $price = $close;
    }

    return $candles;
}

it('produces a versioned fingerprint, not the old hardcoded literal', function () {
    $result = app(TradeForecastService::class)->forecast(modelVersionTestCandles(), '1m', 60);

    expect($result['model_version'])->not->toBe('quick-expiry-v1');
    expect($result['model_version'])->toMatch('/^v1-[0-9a-f]{8}$/');
});

it('keeps model_version stable across calls when nothing scoring-relevant changes', function () {
    $candles = modelVersionTestCandles();
    $service = app(TradeForecastService::class);

    $a = $service->forecast($candles, '1m', 60);
    $b = $service->forecast($candles, '1m', 60);

    expect($a['model_version'])->toBe($b['model_version']);
});

it('changes model_version when a real config value forecast() actually reads changes', function () {
    $candles = modelVersionTestCandles();
    $service = app(TradeForecastService::class);

    config(['trading.binance_futures_taker_fee_rate' => 0.0005]);
    $before = $service->forecast($candles, '1m', 60)['model_version'];

    config(['trading.binance_futures_taker_fee_rate' => 0.01]);
    $after = $service->forecast($candles, '1m', 60)['model_version'];

    expect($after)->not->toBe($before);
});

it('changes model_version when the slippage-buffer config forecast() reads changes', function () {
    $candles = modelVersionTestCandles();
    $service = app(TradeForecastService::class);

    config(['robot_trade.live_slippage_buffer_pct' => 0.04]);
    $before = $service->forecast($candles, '1m', 60)['model_version'];

    config(['robot_trade.live_slippage_buffer_pct' => 0.5]);
    $after = $service->forecast($candles, '1m', 60)['model_version'];

    expect($after)->not->toBe($before);
});
