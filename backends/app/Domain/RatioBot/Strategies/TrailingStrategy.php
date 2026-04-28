<?php

namespace App\Domain\RatioBot\Strategies;

class TrailingStrategy extends RatioThresholdStrategy
{
    public function key(): string
    {
        return 'trailing_ratio';
    }
}
