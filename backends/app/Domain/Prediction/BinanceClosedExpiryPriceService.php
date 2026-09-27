<?php

namespace App\Domain\Prediction;

use App\Domain\Trading\CandleCloseResolver;
use App\Domain\Trading\Providers\BinanceTimedFuturesProvider;
use App\Models\Integration;
use App\Models\PredictionObservation;
use RuntimeException;

final class BinanceClosedExpiryPriceService
{
    public function __construct(
        private readonly BinanceTimedFuturesProvider $binance,
        private readonly CandleCloseResolver $closeResolver,
    ) {}

    public function resolve(PredictionObservation $observation, Integration $integration): float
    {
        if ($integration->provider_key !== 'binance') {
            throw new RuntimeException('Prediction observation requires a Binance integration.');
        }
        $candles = $this->binance->candles($integration, $observation->symbol, $observation->timeframe, 3);
        $resolved = $this->closeResolver->resolve($candles, $observation->timeframe);
        $last = $resolved['candles'][array_key_last($resolved['candles'])] ?? null;
        $price = (float) ($last['close'] ?? 0);
        if ($price <= 0 || ! $resolved['timestamps_available']) {
            throw new RuntimeException('No fully closed Binance candle is available.');
        }
        return $price;
    }
}
