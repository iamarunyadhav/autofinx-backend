<?php

namespace App\Domain\Ml\Shadow;

/**
 * Pure champion/candidate decision. A candidate is promoted only when every
 * check passes; the first failing check is the logged rejection reason.
 *
 * Absolute checks (always):
 *   - enough out-of-sample evidence (holdout rows, walk-forward folds)
 *   - holdout MAE beats the zero-return baseline
 *   - fee-aware: the trades its gate would take are not net-negative
 * Relative checks (when a champion exists), on the SAME holdout rows:
 *   - forecast error: MAE improves on the champion by the configured margin
 *   - fee-aware: total net of taken trades is not worse than the champion's
 */
final class PromotionPolicy
{
    /**
     * @param  array<string, mixed>  $metadata  Trainer metadata (metrics.holdout, metrics.champion_holdout, ...)
     * @return array{promote:bool, reason:string, checks:array<string, mixed>}
     */
    public function decide(array $metadata, bool $hasChampion): array
    {
        $holdout = (array) data_get($metadata, 'metrics.holdout', []);
        $champion = data_get($metadata, 'metrics.champion_holdout');
        $folds = (int) data_get($metadata, 'metrics.walk_forward.fold_count', 0);
        $rows = (int) ($holdout['rows'] ?? 0);
        $mae = $this->number($holdout['mae_pct'] ?? null);
        $baseline = $this->number($holdout['baseline_zero_mae_pct'] ?? null);
        $trades = (int) data_get($holdout, 'fee_aware.trade_count', 0);
        $avgNet = $this->number(data_get($holdout, 'fee_aware.average_net_pct'));
        $totalNet = (float) (data_get($holdout, 'fee_aware.total_net_pct') ?? 0.0);

        $checks = [
            'holdout_rows' => $rows, 'walk_forward_folds' => $folds, 'mae_pct' => $mae,
            'baseline_zero_mae_pct' => $baseline, 'fee_aware_trades' => $trades,
            'fee_aware_average_net_pct' => $avgNet, 'fee_aware_total_net_pct' => $totalNet,
            'has_champion' => $hasChampion,
        ];

        if ($rows < (int) config('ml.promotion.min_holdout_rows', 2000)) {
            return $this->reject('insufficient_holdout_rows', $checks);
        }
        if ($folds < (int) config('ml.promotion.min_walk_forward_folds', 3)) {
            return $this->reject('insufficient_walk_forward_folds', $checks);
        }
        if ($mae === null || $baseline === null) {
            return $this->reject('missing_forecast_metrics', $checks);
        }
        $baselineMargin = max(0.0, (float) config('ml.promotion.min_mae_improvement_vs_baseline', 0.0));
        if ($mae >= $baseline * (1 - $baselineMargin)) {
            return $this->reject('mae_not_better_than_zero_return_baseline', $checks);
        }
        if ($trades > 0 && $avgNet !== null && $avgNet < 0) {
            return $this->reject('fee_aware_net_negative_on_holdout', $checks);
        }

        if (! $hasChampion) {
            return ['promote' => true, 'reason' => 'first_champion_passed_absolute_checks', 'checks' => $checks];
        }

        if (! is_array($champion) || ! (bool) data_get($metadata, 'metrics.champion_comparable', false)) {
            $checks['champion_not_comparable_reason'] = data_get($metadata, 'metrics.champion_not_comparable_reason');

            return $this->reject('champion_not_comparable_on_same_holdout', $checks);
        }

        $championMae = $this->number($champion['mae_pct'] ?? null);
        $championTotalNet = (float) (data_get($champion, 'fee_aware.total_net_pct') ?? 0.0);
        $checks['champion_mae_pct'] = $championMae;
        $checks['champion_fee_aware_total_net_pct'] = $championTotalNet;
        $championMargin = max(0.0, (float) config('ml.promotion.min_mae_improvement_vs_champion', 0.005));

        if ($championMae === null || $mae >= $championMae * (1 - $championMargin)) {
            return $this->reject('mae_not_better_than_champion', $checks);
        }
        if ($totalNet < $championTotalNet) {
            return $this->reject('fee_aware_net_worse_than_champion', $checks);
        }

        return ['promote' => true, 'reason' => 'candidate_beats_champion_on_same_holdout', 'checks' => $checks];
    }

    /** @return array{promote:false, reason:string, checks:array<string, mixed>} */
    private function reject(string $reason, array $checks): array
    {
        return ['promote' => false, 'reason' => $reason, 'checks' => $checks];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
