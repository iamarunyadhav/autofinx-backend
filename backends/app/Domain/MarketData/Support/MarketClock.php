<?php

namespace App\Domain\MarketData\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MarketClock
{
    public function isMarketExpectedOpen(string $provider, string $instrument, ?CarbonInterface $at = null): bool
    {
        $at = $at ? Carbon::instance(\DateTimeImmutable::createFromInterface($at))->utc() : now()->utc();
        $marketType = app(InstrumentMapper::class)->marketType($instrument);
        $schedule = (string) data_get(config('market_data.profiles', []), "{$marketType}.trading_schedule", 'continuous');

        if ($schedule === 'continuous') {
            return true;
        }

        return ! in_array((int) $at->dayOfWeekIso, [6, 7], true);
    }

    public function session(string $provider, string $instrument, ?CarbonInterface $at = null): string
    {
        $at = $at ? Carbon::instance(\DateTimeImmutable::createFromInterface($at))->utc() : now()->utc();
        if (! $this->isMarketExpectedOpen($provider, $instrument, $at)) {
            return 'MARKET_CLOSED';
        }

        $hour = (int) $at->hour;
        if ($hour >= 12 && $hour < 16) {
            return 'LONDON_NEW_YORK_OVERLAP';
        }
        if ($hour >= 7 && $hour < 16) {
            return 'LONDON';
        }
        if ($hour >= 12 && $hour < 21) {
            return 'NEW_YORK';
        }

        return 'ASIA';
    }
}
