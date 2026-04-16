<?php

namespace App\Console\Commands;

use App\Domain\Ops\MaintenanceConfidenceService;
use App\Domain\Ops\OperationalDriftDetectionService;
use App\Domain\Ops\OperationalTrendService;
use App\Models\Workspace;
use Illuminate\Console\Command;

class OperationalMaturityCycleCommand extends Command
{
    protected $signature = 'ops:maturity-cycle {--workspace=* : Optional workspace id filter}';

    protected $description = 'Capture long-running operational trends, detect drift, and update maintenance confidence.';

    public function handle(
        OperationalTrendService $trends,
        OperationalDriftDetectionService $drift,
        MaintenanceConfidenceService $confidence
    ): int {
        $workspaceIds = array_values(array_filter((array) $this->option('workspace')));
        $query = Workspace::query()->select('id', 'name');

        if ($workspaceIds !== []) {
            $query->whereIn('id', $workspaceIds);
        }

        $workspaces = $query->get();
        if ($workspaces->isEmpty()) {
            $this->warn('No workspaces matched the operational maturity cycle.');
            return self::SUCCESS;
        }

        foreach ($workspaces as $workspace) {
            $trend = $trends->capture((string) $workspace->id, 'scheduled');
            $driftResult = $drift->evaluate((string) $workspace->id, null, 'scheduled');
            $confidenceSnapshot = $confidence->capture((string) $workspace->id, 'scheduled');
            $this->line(sprintf(
                '[%s] trend=%s drift=%d confidence=%s',
                $workspace->name ?? $workspace->id,
                $trend->state,
                count($driftResult['detections'] ?? []),
                $confidenceSnapshot->confidence_score
            ));
        }

        $trends->prune();
        $drift->prune();
        $confidence->prune();

        return self::SUCCESS;
    }
}

