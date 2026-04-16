<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        $tz = config('app.timezone', 'Asia/Colombo');
        return CarbonImmutable::now($tz);
    }
}
