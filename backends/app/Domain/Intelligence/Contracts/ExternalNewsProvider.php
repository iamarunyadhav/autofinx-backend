<?php

namespace App\Domain\Intelligence\Contracts;

interface ExternalNewsProvider
{
    public function name(): string;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $options = []): array;
}

