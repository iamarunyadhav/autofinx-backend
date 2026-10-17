<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlShadowPrediction;

/** Read-only diagnostics for separating a healthy WAIT from a WAIT-all failure. */
final class ShadowDecisionDiagnostics
{
    /** @return array<string, mixed> */
    public function forScope(string $symbol, int $horizon, int $limit = 200): array
    {
        $rows = MlShadowPrediction::query()
            ->where('symbol', $symbol)
            ->where('horizon_minutes', $horizon)
            ->latest('anchor_time_ms')
            ->limit(max(1, min($limit, 1000)))
            ->get(['decision', 'decision_reason', 'status']);

        $counts = [
            'VALID_TRADEABLE' => 0,
            'WAIT_SIGNAL' => 0,
            'COST_BLOCKED' => 0,
            'MODEL_FAILED' => 0,
            'DATA_INVALID' => 0,
        ];
        $failureBuckets = ['DATA' => 0, 'MODEL' => 0, 'COST' => 0, 'REGIME_OR_UNKNOWN' => 0];

        foreach ($rows as $row) {
            $status = $this->classify((string) $row->decision, (string) ($row->decision_reason ?? ''), (string) $row->status);
            $counts[$status]++;
            $failureBuckets[$this->failureBucket((string) $row->decision, (string) ($row->decision_reason ?? ''), (string) $row->status)]++;
        }

        $total = $rows->count();
        $directional = $counts['VALID_TRADEABLE'];
        $waitLike = $counts['WAIT_SIGNAL'] + $counts['COST_BLOCKED'];
        $waitRatio = $total > 0 ? $waitLike / $total : null;

        return [
            'sample_count' => $total,
            'counts' => $counts,
            'failure_buckets' => $failureBuckets,
            'rates_pct' => array_map(static fn (int $count): float => $total > 0 ? round($count * 100 / $total, 4) : 0.0, $counts),
            'directional_rate_pct' => $total > 0 ? round($directional * 100 / $total, 4) : 0.0,
            'wait_like_rate_pct' => $waitRatio === null ? null : round($waitRatio * 100, 4),
            'alert' => $total >= (int) config('ml.diagnostics.min_samples', 30)
                && ($waitRatio ?? 0.0) >= (float) config('ml.diagnostics.wait_like_alert_ratio', 0.90)
                ? 'WAIT_LIKE_COLLAPSE'
                : null,
            'meaning' => 'Diagnostics only; no decision or model promotion is changed.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function history(string $symbol, int $horizon, int $limit = 40): array
    {
        return MlShadowPrediction::query()
            ->where('symbol', $symbol)
            ->where('horizon_minutes', $horizon)
            ->latest('anchor_time_ms')
            ->limit(max(1, min($limit, 200)))
            ->get(['anchor_time_ms', 'decision', 'decision_reason', 'status'])
            ->map(function ($row): array {
                $decision = (string) $row->decision;
                $reason = (string) ($row->decision_reason ?? '');
                $predictionStatus = (string) $row->status;

                return [
                    'anchor_time_ms' => (int) $row->anchor_time_ms,
                    'decision' => $decision,
                    'decision_reason' => $reason,
                    'prediction_status' => $predictionStatus,
                    'decision_status' => $this->classify($decision, $reason, $predictionStatus),
                ];
            })
            ->values()
            ->all();
    }

    public function classify(string $decision, string $reason, string $predictionStatus = 'PENDING'): string
    {
        if ($predictionStatus !== 'PENDING' && $predictionStatus !== 'SCORED') {
            return 'DATA_INVALID';
        }
        if ($decision === 'BUY' || $decision === 'SELL') {
            return 'VALID_TRADEABLE';
        }
        if ($reason === 'expected_net_not_positive_after_costs' || $reason === 'expected_move_below_required_move') {
            return 'COST_BLOCKED';
        }

        return 'WAIT_SIGNAL';
    }

    private function failureBucket(string $decision, string $reason, string $predictionStatus): string
    {
        if ($predictionStatus !== 'PENDING' && $predictionStatus !== 'SCORED') {
            return 'DATA';
        }
        if ($reason === 'expected_net_not_positive_after_costs' || $reason === 'expected_move_below_required_move') {
            return 'COST';
        }
        if (str_contains($reason, 'model') || str_contains($reason, 'probability') || $reason === 'no_side_probability_above_floor') {
            return 'MODEL';
        }
        if ($decision === 'WAIT' || $decision === '') {
            return 'REGIME_OR_UNKNOWN';
        }

        return 'REGIME_OR_UNKNOWN';
    }
}
