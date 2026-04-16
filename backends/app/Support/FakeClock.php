<?php

namespace App\Support;

use Carbon\Carbon;

class FakeClock
{
    private static ?Carbon $fakeNow = null;

    public static function freeze(?Carbon $when = null): void
    {
        self::$fakeNow = $when?->copy() ?? Carbon::now();
    }

    public static function travelTo(Carbon $when): void
    {
        self::$fakeNow = $when->copy();
    }

    public static function resume(): void
    {
        self::$fakeNow = null;
    }

    public static function now(?string $tz = null): Carbon
    {
        return (self::$fakeNow?->copy() ?? Carbon::now($tz))->setTimezone($tz ?? config('app.timezone', 'UTC'));
    }
}
