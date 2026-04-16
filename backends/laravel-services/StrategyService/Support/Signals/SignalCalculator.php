<?php

namespace Services\StrategyService\Support\Signals;

use App\Models\StrategyInstance;

class SignalCalculator
{
    public static function for(StrategyInstance $instance): self
    {
        return new self($instance);
    }

    public function __construct(private StrategyInstance $instance)
    {
    }

    public function calculate(float $price, \DateTimeInterface $timestamp): Signal
    {
        // Simple demo logic: random BUY/SELL/HOLD for now
        $rand = rand(0, 100);
        if ($rand > 70) {
            return new Signal('BUY', $price);
        }
        if ($rand < 30) {
            return new Signal('SELL', $price);
        }
        return new Signal('HOLD', $price);
    }
}
