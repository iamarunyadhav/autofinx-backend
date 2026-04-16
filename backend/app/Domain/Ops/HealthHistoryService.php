<?php

namespace App\Domain\Ops;

class HealthHistoryService
{
    public function __construct(private readonly OperationalTrendService $trends)
    {
    }

    public function history(string $workspaceId, int $limit = 30): array
    {
        return $this->trends->history($workspaceId, $limit);
    }
}

