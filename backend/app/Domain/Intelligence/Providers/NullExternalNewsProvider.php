<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\ExternalNewsProvider;

class NullExternalNewsProvider implements ExternalNewsProvider
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

