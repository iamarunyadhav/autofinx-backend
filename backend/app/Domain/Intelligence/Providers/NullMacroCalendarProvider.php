<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\MacroCalendarProvider;

class NullMacroCalendarProvider implements MacroCalendarProvider
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

