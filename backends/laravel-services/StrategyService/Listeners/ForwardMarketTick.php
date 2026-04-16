<?php

namespace Services\StrategyService\Listeners;

use Services\MarketService\Events\MarketTickGenerated;
use Services\StrategyService\Jobs\EvaluateStrategies;

class ForwardMarketTick
{
    public function handle(MarketTickGenerated $event): void
    {
        EvaluateStrategies::dispatch(
            $event->symbol,
            $event->price,
            $event->timestamp
        );
    }
}
