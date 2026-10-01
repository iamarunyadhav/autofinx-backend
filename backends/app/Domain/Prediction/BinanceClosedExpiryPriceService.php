<?php

namespace App\Domain\Prediction;

use App\Domain\Trading\CandleCloseResolver;
use App\Domain\Trading\Providers\BinanceTimedFuturesProvider;
use App\Domain\Trading\TimeframeRegistry;
use App\Models\Integration;
use App\Models\PredictionObservation;
use InvalidArgumentException;
use RuntimeException;

final class BinanceClosedExpiryPriceService
{
    public function __construct(
        private readonly BinanceTimedFuturesProvider $binance,
        private readonly CandleCloseResolver $closeResolver,
        private readonly TimeframeRegistry $timeframes,
    ) {}

    public function resolve(PredictionObservation $observation, Integration $integration): float
    {
        if ($integration->provider_key !== 'binance') {
            throw new RuntimeException('Prediction observation requires a Binance integration.');
        }
        $timeframe = $this->settlementTimeframe((string) $observation->timeframe);
        $candles = $this->binance->candles($integration, $observation->symbol, $timeframe, 3);
        $resolved = $this->closeResolver->resolve($candles, $timeframe);
        $last = $resolved['candles'][array_key_last($resolved['candles'])] ?? null;
        $price = (float) ($last['close'] ?? 0);
        if ($price <= 0 || ! $resolved['timestamps_available']) {
            throw new RuntimeException('No fully closed Binance candle is available.');
        }
        return $price;
    }

    /**
     * Derived observatory timeframes (e.g. 45m = 3 × 15m) have no native
     * Binance interval and are not in the shared TimeframeRegistry. A derived
     * bar's close IS the close of its last source bar, so settle on the
     * closed source-interval candle instead of asking Binance for an interval
     * it does not have (which the provider would silently map to 1m).
     */
    private function settlementTimeframe(string $timeframe): string
    {
        $timeframe = strtolower(trim($timeframe));
        try {
            return $this->timeframes->normalize($timeframe);
        } catch (InvalidArgumentException $exception) {
            $source = config("prediction_observatory.derived_timeframes.{$timeframe}.source");
            if (! is_string($source) || $source === '') {
                throw $exception;
            }

            return $this->timeframes->normalize($source);
        }
    }
}
