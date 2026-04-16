<?php

namespace App\Domain\Trading;

use App\Models\Position;
use App\Models\PositionExitState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExitMonitoringService
{
    public function __construct(
        private readonly PositionExitStateService $states,
        private readonly ExitOrchestratorService $orchestrator,
    ) {
    }

    public function runAll(): array
    {
        $workspaceIds = Position::query()
            ->whereRaw('ABS(COALESCE(qty, quantity, 0)) > 0')
            ->where('provider', 'binance')
            ->where('market_type', 'spot')
            ->distinct()
            ->pluck('workspace_id')
            ->filter()
            ->values();

        $summary = [
            'workspaces' => 0,
            'positions' => 0,
            'executed' => 0,
            'blocked' => 0,
            'review_required' => 0,
        ];

        foreach ($workspaceIds as $workspaceId) {
            $result = $this->runWorkspace((string) $workspaceId);
            $summary['workspaces']++;
            $summary['positions'] += (int) ($result['positions'] ?? 0);
            $summary['executed'] += (int) ($result['executed'] ?? 0);
            $summary['blocked'] += (int) ($result['blocked'] ?? 0);
            $summary['review_required'] += (int) ($result['review_required'] ?? 0);
        }

        return $summary;
    }

    public function runWorkspace(string $workspaceId): array
    {
        $lock = Cache::lock('exit-monitor-workspace:' . $workspaceId, 20);
        if (! $lock->get()) {
            return [
                'workspace_id' => $workspaceId,
                'positions' => 0,
                'executed' => 0,
                'blocked' => 0,
                'review_required' => 0,
                'locked' => true,
            ];
        }

        try {
            $this->states->backfillWorkspace($workspaceId);

            $states = PositionExitState::query()
                ->with(['position.symbol', 'activeExitOrder'])
                ->where('workspace_id', $workspaceId)
                ->where('status', PositionExitState::STATUS_OPEN)
                ->whereIn('managed_mode', [
                    PositionExitState::MODE_ARMED,
                    PositionExitState::MODE_BLOCKED,
                ])
                ->orderBy('symbol')
                ->get();

            $summary = [
                'workspace_id' => $workspaceId,
                'positions' => $states->count(),
                'executed' => 0,
                'blocked' => 0,
                'review_required' => 0,
            ];

            foreach ($states as $state) {
                $positionLock = Cache::lock('exit-monitor-position:' . $state->position_id, 15);
                if (! $positionLock->get()) {
                    continue;
                }

                try {
                    $result = $this->orchestrator->evaluate($state);
                    if (! empty($result['executed'])) {
                        $summary['executed']++;
                    }
                    if (! empty($result['blocked'])) {
                        $summary['blocked']++;
                    }
                    if (! empty($result['requires_review'])) {
                        $summary['review_required']++;
                    }
                } finally {
                    $positionLock->release();
                }
            }

            Log::info('exit_monitoring.workspace_completed', $summary);

            return $summary;
        } finally {
            $lock->release();
        }
    }
}
