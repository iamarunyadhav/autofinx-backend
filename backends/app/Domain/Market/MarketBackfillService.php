<?php

namespace App\Domain\Market;

use App\Models\MarketCandle;
use App\Models\Symbol;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

class MarketBackfillService
{
    public function backfill(string $symbolCode, ?string $from = null, ?string $to = null, string $timeframe = '1m'): int
    {
        $symbol = Symbol::where('symbol', $symbolCode)->first();
        if (!$symbol) {
            return 0;
        }
        $start = $from ? CarbonImmutable::parse($from) : CarbonImmutable::now()->subDay();
        $end = $to ? CarbonImmutable::parse($to) : CarbonImmutable::now();

        $cursor = $start->copy();
        $count = 0;
        $price = 100;
        while ($cursor->lt($end)) {
            $delta = $price * (mt_rand(-20, 20) / 10000);
            $price = max(0.0001, $price + $delta);
            MarketCandle::updateOrCreate(
                [
                    'symbol_id' => $symbol->id,
                    'timeframe' => $timeframe,
                    'opens_at' => $cursor->startOfMinute(),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'open' => $price,
                    'high' => $price * 1.002,
                    'low' => $price * 0.998,
                    'close' => $price,
                    'volume' => mt_rand(1, 50),
                ]
            );
            $count++;
            $cursor = $cursor->addMinute();
        }
        return $count;
    }
}
