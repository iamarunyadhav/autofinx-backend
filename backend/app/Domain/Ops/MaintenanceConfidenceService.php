<?php

namespace App\Domain\Ops;

use App\Models\Incident;
use App\Models\MaintenanceConfidenceSnapshot;
use App\Models\OperationalDriftDetection;
use App\Models\OperationalReadinessSnapshot;
use App\Models\RecoveryDrill;
use App\Models\ReleaseRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MaintenanceConfidenceService
{
    public function summary(string $workspaceId): array
    {
        $readiness = OperationalReadinessSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('checked_at')
            ->first();
        $drifts = OperationalDriftDetection::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->get();
        $drills = RecoveryDrill::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();
        $releases = ReleaseRecord::query()
            ->where(function ($query) use ($workspaceId) {
                $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            })
            ->orderByDesc('released_at')
            ->limit(10)
            ->get();
        $incidents = Incident::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['open', 'acknowledged', 'investigating'])
            ->get();

        $score = 100.0;
        $rationale = [];
        $evidence = [
            'readiness_state' => $readiness?->state,
            'open_drift_count' => $drifts->count(),
            'recent_drills' => $drills->count(),
            'recent_releases' => $releases->count(),
            'open_incidents' => $incidents->count(),
        ];

        if (! $readiness) {
            $score -= 25;
            $rationale[] = 'No readiness history exists yet.';
        } elseif ($readiness->state === 'blocked') {
            $score -= 35;
            $rationale[] = 'Latest readiness snapshot is blocked.';
        } elseif ($readiness->state === 'degraded') {
            $score -= 20;
            $rationale[] = 'Latest readiness snapshot is degraded.';
        }

        if ($incidents->count() > 0) {
            $score -= min(30, $incidents->count() * 8);
            $rationale[] = sprintf('%d incident(s) remain open.', $incidents->count());
        }

        $actionRequiredDrifts = $drifts->where('severity', 'action_required')->count();
        if ($actionRequiredDrifts > 0) {
            $score -= min(25, $actionRequiredDrifts * 8);
            $rationale[] = sprintf('%d drift detection(s) require action.', $actionRequiredDrifts);
        }

        $failedDrills = $drills->whereIn('status', ['failed', 'blocked'])->count();
        if ($failedDrills > 0) {
            $score -= min(15, $failedDrills * 4);
            $rationale[] = sprintf('%d recovery drill(s) failed or were blocked.', $failedDrills);
        } elseif ($drills->isNotEmpty()) {
            $score += 5;
            $rationale[] = 'Recent recovery drill practice exists.';
        } else {
            $score -= 5;
            $rationale[] = 'No recent recovery drill practice found.';
        }

        $verifiedReleases = $releases->whereNotNull('verified_at')->count();
        if ($releases->isEmpty()) {
            $score -= 10;
            $rationale[] = 'No recent release history exists.';
        } elseif ($verifiedReleases > 0) {
            $score += 5;
            $rationale[] = 'Recent release verification exists.';
        }

        $confidenceState = $this->stateFromScore($score, $readiness, $drifts, $drills, $releases, $incidents);
        $summary = $this->summaryText($confidenceState, $score, $rationale);
        $score = max(0, min(100, round($score, 2)));

        return [
            'workspace_id' => $workspaceId,
            'state' => $confidenceState,
            'confidence_score' => $score,
            'confidence_in_recovery' => max(0, min(100, round($score - ($actionRequiredDrifts * 4), 2))),
            'summary' => $summary,
            'rationale' => $rationale,
            'evidence' => $evidence,
            'readiness_state' => $readiness?->state,
            'open_drift_count' => $drifts->count(),
            'open_incidents' => $incidents->count(),
        ];
    }

    public function capture(string $workspaceId, string $source = 'scheduled'): MaintenanceConfidenceSnapshot
    {
        $summary = $this->summary($workspaceId);
        $payload = [
            'workspace_id' => $workspaceId,
            'state' => $summary['state'],
            'confidence_score' => $summary['confidence_score'],
            'summary' => $summary['summary'],
            'rationale_json' => $summary['rationale'],
            'evidence_json' => array_merge($summary['evidence'], ['source' => $source]),
            'source_hash' => sha1(json_encode([$summary['state'], $summary['confidence_score'], $summary['rationale']])),
            'observed_at' => now(),
        ];

        $latest = MaintenanceConfidenceSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('observed_at')
            ->first();

        if ($latest && $latest->source_hash === $payload['source_hash'] && $latest->observed_at?->gt(now()->subMinutes(10))) {
            $latest->fill($payload)->save();
            return $latest->fresh();
        }

        return MaintenanceConfidenceSnapshot::create(array_merge(['id' => (string) Str::uuid()], $payload));
    }

    public function history(string $workspaceId, int $limit = 30): array
    {
        return [
            'snapshots' => MaintenanceConfidenceSnapshot::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('observed_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (MaintenanceConfidenceSnapshot $snapshot) => $snapshot->toArray())
                ->values()
                ->all(),
        ];
    }

    public function prune(): array
    {
        $retention = (int) data_get(config('ops.maintenance.retention_days', []), 'drills', 365);
        if ($retention <= 0) {
            return ['deleted' => 0];
        }

        return ['deleted' => MaintenanceConfidenceSnapshot::query()->where('observed_at', '<', now()->subDays($retention))->delete()];
    }

    private function stateFromScore(float $score, ?OperationalReadinessSnapshot $readiness, Collection $drifts, Collection $drills, Collection $releases, Collection $incidents): string
    {
        if (! $readiness && $drifts->isEmpty() && $drills->isEmpty() && $releases->isEmpty() && $incidents->isEmpty()) {
            return 'insufficient_history';
        }

        if ($readiness?->state === 'blocked' || $drifts->where('severity', 'action_required')->isNotEmpty()) {
            return $score < 60 ? 'very_low' : 'low';
        }

        if ($score >= 80 && $drills->isNotEmpty() && $releases->isNotEmpty() && $incidents->isEmpty()) {
            return 'high';
        }

        if ($score >= 60) {
            return 'moderate';
        }

        if ($score >= 40) {
            return 'low';
        }

        return 'very_low';
    }

    private function summaryText(string $state, float $score, array $rationale): string
    {
        return sprintf(
            'Maintenance confidence is %s at %.2f/100. %s',
            $state,
            $score,
            implode(' ', array_slice($rationale, 0, 3))
        );
    }
}
