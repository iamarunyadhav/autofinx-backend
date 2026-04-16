<?php

namespace App\Domain\Ops;

use App\Models\PostDeployCheck;
use App\Models\ReleaseRecord;
use App\Models\RollbackRecord;
use Illuminate\Support\Str;

class ReleaseGovernanceService
{
    public function listReleases(string $workspaceId, int $limit = 20): array
    {
        return [
            'releases' => ReleaseRecord::query()
                ->where(function ($query) use ($workspaceId) {
                    $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->orderByDesc('released_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (ReleaseRecord $release) => $release->toArray())
                ->values()
                ->all(),
        ];
    }

    public function listRollbacks(string $workspaceId, int $limit = 20): array
    {
        return [
            'rollbacks' => RollbackRecord::query()
                ->where(function ($query) use ($workspaceId) {
                    $query->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
                })
                ->orderByDesc('requested_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn (RollbackRecord $rollback) => $rollback->toArray())
                ->values()
                ->all(),
        ];
    }

    public function recordRelease(string $workspaceId, array $data, ?string $actorId = null): array
    {
        $release = ReleaseRecord::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'release_key' => $data['release_key'] ?? ('release:' . Str::uuid()),
            'version' => $data['version'] ?? 'unknown',
            'environment' => $data['environment'] ?? (string) data_get(app(DeploymentReadinessService::class)->state($workspaceId), 'environment', 'paper'),
            'status' => $data['status'] ?? 'planned',
            'summary' => $data['summary'] ?? null,
            'change_summary_json' => $data['change_summary_json'] ?? [],
            'metadata_json' => $data['metadata_json'] ?? [],
            'blocked_reason' => $data['blocked_reason'] ?? null,
            'performed_by' => $actorId,
            'released_at' => $data['released_at'] ?? now(),
            'verified_at' => $data['verified_at'] ?? null,
            'rolled_back_at' => $data['rolled_back_at'] ?? null,
            'source_hash' => sha1(json_encode([$workspaceId, $data['release_key'] ?? null, $data['version'] ?? null])),
        ]);

        return ['release' => $release->toArray()];
    }

    public function verifyRelease(string $workspaceId, string $releaseId, array $data, ?string $actorId = null): array
    {
        $release = ReleaseRecord::query()->findOrFail($releaseId);
        abort_unless((string) $release->workspace_id === (string) $workspaceId, 404);

        $check = PostDeployCheck::create([
            'id' => (string) Str::uuid(),
            'release_record_id' => $release->id,
            'workspace_id' => $workspaceId,
            'status' => $data['status'] ?? 'passed',
            'summary' => $data['summary'] ?? null,
            'checklist_json' => $data['checklist_json'] ?? [],
            'evidence_json' => $data['evidence_json'] ?? [],
            'performed_by' => $actorId,
            'checked_at' => $data['checked_at'] ?? now(),
            'source_hash' => sha1(json_encode([$release->id, $data['status'] ?? 'passed', $data['summary'] ?? null])),
        ]);

        $release->fill([
            'status' => $data['status'] ?? 'verified',
            'verified_at' => now(),
        ])->save();

        return [
            'release' => $release->fresh()->toArray(),
            'check' => $check->toArray(),
        ];
    }

    public function recordRollback(string $workspaceId, array $data, ?string $actorId = null): array
    {
        $rollback = RollbackRecord::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'release_record_id' => $data['release_record_id'] ?? null,
            'rollback_key' => $data['rollback_key'] ?? ('rollback:' . Str::uuid()),
            'status' => $data['status'] ?? 'planned',
            'reason' => $data['reason'] ?? null,
            'summary' => $data['summary'] ?? null,
            'blocked_reason' => $data['blocked_reason'] ?? null,
            'details_json' => $data['details_json'] ?? [],
            'evidence_json' => $data['evidence_json'] ?? [],
            'performed_by' => $actorId,
            'requested_at' => $data['requested_at'] ?? now(),
            'executed_at' => $data['executed_at'] ?? null,
            'verified_at' => $data['verified_at'] ?? null,
            'source_hash' => sha1(json_encode([$workspaceId, $data['rollback_key'] ?? null, $data['reason'] ?? null])),
        ]);

        return ['rollback' => $rollback->toArray()];
    }
}

