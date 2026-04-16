<?php

namespace App\Console\Commands;

use App\Domain\Ops\OperationalReadinessChecklistService;
use App\Models\Workspace;
use Illuminate\Console\Command;

class OperationalReadinessCheckCommand extends Command
{
    protected $signature = 'ops:readiness-check {--workspace=* : Optional workspace id filter}';

    protected $description = 'Evaluate production readiness, persist snapshots, and sync readiness incidents.';

    public function handle(OperationalReadinessChecklistService $checklist): int
    {
        $workspaceIds = array_values(array_filter((array) $this->option('workspace')));
        $query = Workspace::query()->select('id', 'name');

        if ($workspaceIds !== []) {
            $query->whereIn('id', $workspaceIds);
        }

        $workspaces = $query->get();
        if ($workspaces->isEmpty()) {
            $this->warn('No workspaces matched the readiness check criteria.');
            return self::SUCCESS;
        }

        foreach ($workspaces as $workspace) {
            $result = $checklist->evaluate((string) $workspace->id, true, null, 'scheduled');
            $this->line(sprintf(
                '[%s] %s => %s (%s)',
                $workspace->name ?? $workspace->id,
                $workspace->id,
                $result['readiness']['state'],
                $result['readiness']['summary']
            ));
        }

        return self::SUCCESS;
    }
}
