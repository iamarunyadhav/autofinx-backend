<?php

namespace App\Domain\Nexus;

interface NexusResearchProvider
{
    public function research(string $context, string $model): array;
}
