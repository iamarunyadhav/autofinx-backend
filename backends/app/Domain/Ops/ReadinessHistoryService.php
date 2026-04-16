<?php

namespace App\Domain\Ops;

use App\Models\OperationalReadinessSnapshot;

class ReadinessHistoryService
{
    public function __construct(private readonly MaintenanceConfidenceService $confidence)
    {
    }

    public function history(string $workspaceId, int $limit = 30): array
    {
        $snapshots = OperationalReadinessSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('checked_at')
            ->limit(max(1, min(100, $limit)))
            ->get();

        return [
            'snapshots' => $snapshots->map(fn (OperationalReadinessSnapshot $snapshot) => $snapshot->toArray())->values()->all(),
            'confidence' => $this->confidence->summary($workspaceId),
        ];
    }
}

