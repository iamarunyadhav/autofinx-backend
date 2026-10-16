<?php

namespace App\Domain\StrategyLab;

use App\Models\RobotSession;
use App\Models\StrategyLabApproval;
use App\Models\StrategyLabRun;
use App\Models\StrategyLabVersion;

/**
 * Auto execution is an explicit opt-in and requires research evidence. This
 * gate is deliberately fail-closed; owner approval is permission to promote,
 * not proof by itself.
 */
final class StrategyActivationGate
{
    /** @return array{allowed: bool, reasons: list<string>, version_id: ?string} */
    public function check(RobotSession $session): array
    {
        $mode = strtoupper((string) ($session->execution_mode ?? 'MANUAL'));
        if ($mode !== 'AUTO') {
            return ['allowed' => true, 'reasons' => [], 'version_id' => $session->strategy_lab_version_id ?? null];
        }

        $reasons = [];
        $versionId = $session->strategy_lab_version_id;
        $version = $versionId ? StrategyLabVersion::query()->find($versionId) : null;
        if (! $version) {
            $reasons[] = 'strategy_lab_version_required';
        } else {
            if (! in_array((string) $version->audit_status, [PineAuditor::CAUSAL, PineAuditor::CAUSAL_IF_LAGGED], true)) {
                $reasons[] = 'pine_audit_not_causal';
            }
            if (! $version->adapter) {
                $reasons[] = 'strategy_adapter_missing';
            }
            $deep = StrategyLabRun::query()->where('version_id', $version->id)->where('kind', StrategyLabRun::DEEP)
                ->where('status', StrategyLabRun::COMPLETED)->latest('finished_at')->first();
            if (! $deep) {
                $reasons[] = 'completed_deep_test_required';
            } else {
                $summary = (array) ($deep->summary ?? []);
                if (data_get($summary, 'evidence.auto_trade_eligible') !== true) {
                    $reasons[] = 'positive_out_of_sample_evidence_required';
                }
                $approved = StrategyLabApproval::query()->where('run_id', $deep->id)->where('scope', StrategyLabApproval::EXPERIMENT)
                    ->where('decision', 'APPROVE')->exists();
                if (! $approved) {
                    $reasons[] = 'owner_experiment_approval_required';
                }
            }
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons, 'version_id' => $versionId];
    }
}
