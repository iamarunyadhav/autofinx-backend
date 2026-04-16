<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\ExchangeStatusProvider;

class NullExchangeStatusProvider implements ExchangeStatusProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function fetch(array $options = []): array
    {
        return [];
    }
}

