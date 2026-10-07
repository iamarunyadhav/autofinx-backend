<?php

namespace App\Domain\StrategyLab;

use App\Jobs\RunStrategyLabTestJob;
use App\Models\StrategyLabApproval;
use App\Models\StrategyLabCandidate;
use App\Models\StrategyLabRun;
use App\Models\StrategyLabVersion;
use Illuminate\Support\Facades\DB;

/**
 * Strategy Lab control plane (SPEC §105, D-030/D-031). Research only — this class has no dependency on any
 * trading, order or exchange service, and no state it writes is read by one.
 *
 * Gates (owner approval between major stages):
 *  - every version is audited on creation (static text audit, PineAuditor);
 *  - Quick Test needs an audit that is not INVALID / UNSUPPORTED / REPAINT_RISK, a registered translation
 *    (adapter) and a configured research data directory;
 *  - Deep Test needs an owner APPROVE on a COMPLETED Quick Test of the same version;
 *  - "approve experiment" needs a COMPLETED Deep Test; it only records permission (no ML change here).
 */
final class StrategyLabService
{
    public function __construct(private readonly PineAuditor $auditor) {}

    /** @param array<string, mixed> $data */
    public function createCandidate(string $workspaceId, string $userId, array $data): StrategyLabCandidate
    {
        return DB::transaction(function () use ($workspaceId, $userId, $data) {
            $candidate = StrategyLabCandidate::create([
                'workspace_id' => $workspaceId, 'name' => $data['name'], 'description' => $data['description'] ?? null,
                'source_type' => $data['source_type'], 'intended_purpose' => $data['intended_purpose'],
                'original_timeframe' => $data['original_timeframe'] ?? null, 'status' => StrategyLabCandidate::DRAFT, 'created_by' => $userId,
            ]);
            $this->addVersion($candidate, $userId, $data['source'], $data['notes'] ?? null);

            return $candidate->refresh();
        });
    }

    public function addVersion(StrategyLabCandidate $candidate, string $userId, string $source, ?string $notes = null): StrategyLabVersion
    {
        if ($candidate->isClosed()) {
            throw new StrategyLabException('CANDIDATE_CLOSED', 'This candidate is '.$candidate->status.'.');
        }
        $max = (int) config('strategy_lab.max_source_bytes', 120000);
        if (strlen($source) > $max || ! mb_check_encoding($source, 'UTF-8')) {
            throw new StrategyLabException('INVALID_SOURCE', "Source must be UTF-8 text up to {$max} bytes.");
        }
        $parent = StrategyLabVersion::query()->where('candidate_id', $candidate->id)->orderByDesc('version')->first();
        $normalized = PineAuditor::normalize($source);
        $audit = $candidate->source_type === 'PINE'
            ? $this->auditor->audit($source)
            : ['classification' => PineAuditor::UNSUPPORTED, 'findings' => [['classification' => PineAuditor::UNSUPPORTED,
                'construct' => $candidate->source_type, 'detail' => 'Only the PINE adapter exists in this build.']], 'executed' => false];
        $version = StrategyLabVersion::create([
            'candidate_id' => $candidate->id, 'version' => ($parent?->version ?? 0) + 1, 'parent_version_id' => $parent?->id,
            'source' => $source, 'sha256' => hash('sha256', $source), 'normalized_sha256' => hash('sha256', $normalized),
            'adapter' => $this->adapterFor(hash('sha256', $normalized)), 'audit_status' => $audit['classification'], 'audit' => $audit,
            'notes' => $notes, 'created_by' => $userId,
        ]);
        $candidate->advanceTo(StrategyLabCandidate::AUDITED);

        return $version;
    }

    public function adapterFor(string $normalizedSha): ?string
    {
        foreach ((array) config('strategy_lab.adapters', []) as $key => $adapter) {
            if (in_array($normalizedSha, (array) ($adapter['normalized_sha256'] ?? []), true)) {
                return (string) $key;
            }
        }

        return null;
    }

    public function startRun(StrategyLabVersion $version, string $kind, string $userId, ?string $idempotencyKey = null): StrategyLabRun
    {
        $candidate = StrategyLabCandidate::query()->findOrFail($version->candidate_id);
        if ($idempotencyKey !== null) {
            $existing = StrategyLabRun::query()->where('workspace_id', $candidate->workspace_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }
        $active = StrategyLabRun::query()->where('version_id', $version->id)->where('kind', $kind)->whereIn('status', StrategyLabRun::ACTIVE)->first();
        if ($active) {
            return $active;
        }
        $this->assertStartable($candidate, $version, $kind);

        $run = StrategyLabRun::create([
            'workspace_id' => $candidate->workspace_id, 'candidate_id' => $candidate->id, 'version_id' => $version->id,
            'kind' => $kind, 'status' => StrategyLabRun::QUEUED, 'phase' => 'queued', 'progress_pct' => 0,
            'adapter' => $version->adapter, 'sample_policy' => $kind === StrategyLabRun::QUICK ? 'QUICK_V1' : 'DEEP_V1',
            'idempotency_key' => $idempotencyKey, 'requested_by' => $userId, 'queued_at' => StrategyLabRun::utcNow(),
        ]);
        RunStrategyLabTestJob::dispatch($run->id);

        return $run;
    }

    private function assertStartable(StrategyLabCandidate $candidate, StrategyLabVersion $version, string $kind): void
    {
        if (! (bool) config('strategy_lab.enabled', true)) {
            throw new StrategyLabException('DISABLED', 'Strategy Lab is disabled (AUTOFINX_STRATEGY_LAB_ENABLED).');
        }
        if ($candidate->isClosed()) {
            throw new StrategyLabException('CANDIDATE_CLOSED', 'This candidate is '.$candidate->status.'.');
        }
        if (in_array($version->audit_status, [PineAuditor::INVALID, PineAuditor::UNSUPPORTED, PineAuditor::REPAINT_RISK], true)) {
            throw new StrategyLabException('AUDIT_BLOCKED', "Audit is {$version->audit_status}: resolve the findings before testing.");
        }
        if ($version->adapter === null) {
            throw new StrategyLabException('NEEDS_TRANSLATION', 'No reviewed translation exists for this exact source yet. Arbitrary Pine is never executed; ask for a translation (adapter) first.');
        }
        $work = (string) config('strategy_lab.work_dir');
        if ($work === '' || ! is_dir($work)) {
            throw new StrategyLabException('RUNNER_UNAVAILABLE', 'The research data directory is not available on this host (AUTOFINX_STRATEGY_LAB_WORK_DIR). Tests run on the research PC.');
        }
        if ($kind === StrategyLabRun::DEEP) {
            $approved = StrategyLabApproval::query()->where('version_id', $version->id)
                ->where('scope', StrategyLabApproval::DEEP_TEST)->where('decision', 'APPROVE')->exists();
            if (! $approved) {
                throw new StrategyLabException('APPROVAL_REQUIRED', 'A Deep Test needs the owner to approve a completed Quick Test of this version.');
            }
        }
    }

    public function cancel(StrategyLabRun $run): StrategyLabRun
    {
        if ($run->status === StrategyLabRun::QUEUED) {
            $now = StrategyLabRun::utcNow();
            StrategyLabRun::query()->whereKey($run->id)->where('status', StrategyLabRun::QUEUED)->update([
                'status' => StrategyLabRun::CANCELLED, 'error_code' => 'CANCELLED', 'error_message' => 'Cancelled before it started.',
                'finished_at' => $now, 'cancel_requested_at' => $now, 'phase' => 'done', 'updated_at' => $now,
            ]);
        } elseif ($run->status === StrategyLabRun::RUNNING) {
            StrategyLabRun::query()->whereKey($run->id)->whereNull('cancel_requested_at')->update(['cancel_requested_at' => StrategyLabRun::utcNow()]);
        }

        return $run->refresh();
    }

    public function decide(StrategyLabRun $run, string $userId, string $decision, ?string $notes): StrategyLabApproval
    {
        if ($run->status !== StrategyLabRun::COMPLETED) {
            throw new StrategyLabException('RUN_NOT_COMPLETED', 'Only a completed run can be approved or rejected.');
        }
        if (StrategyLabApproval::query()->where('run_id', $run->id)->exists()) {
            throw new StrategyLabException('ALREADY_DECIDED', 'This run already has an owner decision (decisions are immutable).');
        }
        $candidate = StrategyLabCandidate::query()->findOrFail($run->candidate_id);
        if ($candidate->isClosed()) {
            throw new StrategyLabException('CANDIDATE_CLOSED', 'This candidate is '.$candidate->status.'.');
        }
        $scope = $run->kind === StrategyLabRun::QUICK ? StrategyLabApproval::DEEP_TEST : StrategyLabApproval::EXPERIMENT;

        return DB::transaction(function () use ($run, $userId, $decision, $notes, $candidate, $scope) {
            $approval = StrategyLabApproval::create([
                'candidate_id' => $run->candidate_id, 'version_id' => $run->version_id, 'run_id' => $run->id,
                'scope' => $scope, 'decision' => $decision, 'notes' => $notes, 'decided_by' => $userId,
            ]);
            if ($decision === 'REJECT') {
                $candidate->status = StrategyLabCandidate::REJECTED;
                $candidate->save();
            } else {
                $candidate->advanceTo($scope === StrategyLabApproval::DEEP_TEST
                    ? StrategyLabCandidate::OWNER_APPROVED_DEEP_TEST : StrategyLabCandidate::OWNER_APPROVED_EXPERIMENT);
            }

            return $approval;
        });
    }

    public function archive(StrategyLabCandidate $candidate): StrategyLabCandidate
    {
        if (StrategyLabRun::query()->where('candidate_id', $candidate->id)->whereIn('status', StrategyLabRun::ACTIVE)->exists()) {
            throw new StrategyLabException('RUN_ACTIVE', 'Cancel the active run before archiving.');
        }
        $candidate->status = StrategyLabCandidate::ARCHIVED;
        $candidate->save();

        return $candidate;
    }

    public function artifactDir(StrategyLabRun $run): string
    {
        return rtrim((string) config('strategy_lab.artifacts_dir'), '\\/').DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$run->id;
    }
}
