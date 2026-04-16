<?php

namespace App\Support;

use App\Models\MarketCandle;
use App\Models\MarketTick;
use App\Models\Symbol;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

class MarketDemoGenerator
{
    public function generate(int $ticksPerSymbol = 10, string $timeframe = '1m', bool $resetExisting = true): int
    {
        $symbols = Symbol::all();
        $count = 0;

        if ($resetExisting && $symbols->count() > 0) {
            $ids = $symbols->pluck('id');
            MarketTick::whereIn('symbol_id', $ids)->delete();
            // Clean full timeframe to avoid residual duplicates
            MarketCandle::where('timeframe', $timeframe)->delete();
        }

        foreach ($symbols as $symbol) {
            $price = $this->startingPrice($symbol->symbol);
            for ($i = 0; $i < $ticksPerSymbol; $i++) {
                $delta = $price * (mt_rand(-50, 50) / 10000); // +/-0.5%
                $price = max(0.0001, $price + $delta);
                $tick = MarketTick::create([
                    'id' => (string) Str::uuid(),
                    'symbol_id' => $symbol->id,
                    'price' => $price,
                    'bid' => $price * 0.999,
                    'ask' => $price * 1.001,
                    'volume' => mt_rand(1, 100),
                    'occurred_at' => now()->subSeconds(($ticksPerSymbol - $i) * 5),
                    'metadata' => ['demo' => true],
                ]);
                $this->upsertCandle($symbol->id, $timeframe, $tick->occurred_at, $price, $tick->volume);
                $count++;
            }
        }
        return $count;
    }

    public function generateBurst(array $symbols, CarbonImmutable $now, string $timeframe = '1m'): int
    {
        $count = 0;
        foreach ($symbols as $symbolCode) {
            $symbol = Symbol::firstOrCreate(
                ['symbol' => $symbolCode],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'crypto',
                    'exchange' => 'demo',
                    'metadata' => ['auto_created' => true],
                ]
            );

            $last = MarketTick::where('symbol_id', $symbol->id)->orderByDesc('occurred_at')->first();
            $price = $last?->price ?? $this->startingPrice($symbol->symbol);
            $delta = $price * (mt_rand(-50, 50) / 10000);
            $price = max(0.0001, $price + $delta);

            $tick = MarketTick::create([
                'id' => (string) Str::uuid(),
                'symbol_id' => $symbol->id,
                'price' => $price,
                'bid' => $price * 0.999,
                'ask' => $price * 1.001,
                'volume' => mt_rand(1, 100),
                'occurred_at' => $now,
                'metadata' => ['demo' => true],
            ]);
            $this->upsertCandle($symbol->id, $timeframe, $tick->occurred_at, $price, $tick->volume);
            $count++;
        }
        return $count;
    }

    private function startingPrice(string $symbol): float
    {
        return match (true) {
            str_contains($symbol, 'BTC') => 42000,
            str_contains($symbol, 'ETH') => 2200,
            $symbol === 'AAPL' => 180,
            default => 1,
        };
    }

    private function upsertCandle(string $symbolId, string $timeframe, $timestamp, float $price, float $volume): void
    {
        $openedAt = $timestamp->copy()->startOfMinute();
        $candle = MarketCandle::where([
            'symbol_id' => $symbolId,
            'timeframe' => $timeframe,
            'opens_at' => $openedAt,
        ])->first();

        if (! $candle) {
            $candle = new MarketCandle([
                'id' => (string) Str::uuid(),
                'symbol_id' => $symbolId,
                'timeframe' => $timeframe,
                'opens_at' => $openedAt,
                'open' => $price,
                'high' => $price,
                'low' => $price,
                'close' => $price,
                'volume' => $volume,
            ]);
        } else {
            $candle->high = max($candle->high, $price);
            $candle->low = min($candle->low, $price);
            $candle->close = $price;
            $candle->volume = ($candle->volume ?? 0) + $volume;
        }

        $candle->save();
    }
}
