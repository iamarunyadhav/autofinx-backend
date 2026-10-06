<?php

namespace App\Domain\MarketContext\Support;

use Illuminate\Support\Carbon;

/** The app timezone is Asia/Colombo; stored wall time is UTC. Always go through here. */
final class UtcTime
{
    public static function db(Carbon $at): string
    {
        return $at->copy()->utc()->format('Y-m-d H:i:s');
    }

    public static function fromDb(?string $wall): ?Carbon
    {
        return $wall === null || $wall === '' ? null : Carbon::parse($wall, 'UTC');
    }
}
