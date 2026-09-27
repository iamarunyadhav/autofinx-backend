<?php

use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Providers\ExternalMlPredictionProvider;
use App\Domain\Prediction\Providers\MarketStructurePredictionProvider;
use App\Domain\Prediction\Providers\PineStrategyPredictionProvider;

function predictionRequest(array $candles = []): PredictionRequest
{
    return new PredictionRequest('BTCUSDT', 'futures', '5m', 600, 100.0, $candles);
}

it('returns explicit unavailable results for providers that are not configured', function () {
    foreach ([new ExternalMlPredictionProvider(), new PineStrategyPredictionProvider()] as $provider) {
        $result = $provider->predict(predictionRequest());
        expect($result->available)->toBeFalse()
            ->and($result->reason)->not->toBeNull()
            ->and($result->toArray()['horizon_seconds'])->toBe(600);
    }
});

it('uses only supplied candle context for the market structure baseline', function () {
    $candles = array_map(fn (int $i) => ['open' => 100 + $i, 'high' => 102 + $i, 'low' => 99 + $i, 'close' => 101 + $i], range(0, 9));
    $result = (new MarketStructurePredictionProvider())->predict(predictionRequest($candles));

    expect($result->available)->toBeTrue()
        ->and($result->direction)->toBe('BUY')
        ->and($result->evidence['input_basis'])->toBe('closed_candles')
        ->and($result->horizonSeconds)->toBe(600);
});
