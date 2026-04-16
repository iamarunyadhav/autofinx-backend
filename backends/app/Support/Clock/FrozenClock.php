<?php

namespace App\Support\Clock;

use Carbon\CarbonImmutable;

class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(?CarbonImmutable $frozen = null)
    {
        $this->now = $frozen ?? CarbonImmutable::now(config('app.timezone', 'Asia/Colombo'));
    }

    public function setNow(CarbonImmutable $now): void
    {
        $this->now = $now;
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }
}
