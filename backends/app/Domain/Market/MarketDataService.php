<?php

namespace App\Domain\Market;

use App\Models\MarketCandle;
use App\Models\MarketTick;
use Carbon\CarbonImmutable;

class MarketDataService
{
    public function candles(string $symbol, string $timeframe = '1m', int $limit = 200): array
    {
        $candles = MarketCandle::whereHas('symbol', function ($q) use ($symbol) {
            $q->where('symbol', $symbol);
        })
            ->where('timeframe', $timeframe)
            ->orderByDesc('opens_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($candle) {
                return [
                    'open' => (float) $candle->open,
                    'high' => (float) $candle->high,
                    'low' => (float) $candle->low,
                    'close' => (float) $candle->close,
                    'volume' => (float) $candle->volume,
                    'opens_at' => CarbonImmutable::parse($candle->opens_at),
                ];
            })
            ->toArray();

        return $candles;
    }

    public function latestPrice(string $symbol): ?float
    {
        $tick = MarketTick::whereHas('symbol', function ($q) use ($symbol) {
            $q->where('symbol', $symbol);
        })
            ->orderByDesc('occurred_at')
            ->first();

        return $tick?->price;
    }
}
