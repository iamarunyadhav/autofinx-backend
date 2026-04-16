<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\MarketResearchProvider;

class NullMarketResearchProvider implements MarketResearchProvider
{
    public function name(): string
    {
        return 'null_market_research';
    }

    public function fetchForSymbol(string $symbol, array $options = []): array
    {
        return [];
    }
}
