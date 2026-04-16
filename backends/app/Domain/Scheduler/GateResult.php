<?php

namespace App\Domain\Scheduler;

use Carbon\CarbonImmutable;

class GateResult
{
    public function __construct(
        public bool $allowed,
        public string $reason = 'allowed',
        public ?CarbonImmutable $nextAllowedAt = null
    ) {
    }
}
