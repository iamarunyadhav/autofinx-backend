<?php

namespace App\Domain\Ml\Shadow;

/**
 * Fee-aware-first gate for side-classifier candidates. Everything is judged
 * on the NON-overlapping trade simulation (one position at a time, exit at
 * T+h, after fees, slippage and funding) — never on overlapping per-anchor
 * counts and never on MAE (MAE is reported, not gated).
 *
 * Promote only if, on the purged + embargoed walk-forward folds AND the final
 * holdout, all hold (first failing check is the rejection reason):
 *   a) holdout non-overlapping total net > 0
 *   b) at least ml.promotion.classifier.min_trades[horizon] holdout trades
 *   c) folds with net > 0 >= ml.promotion.classifier.min_positive_fold_share
 *   d) strictly beats never-trade, always-BUY and always-SELL on the same
 *      simulation, on the holdout AND across the folds combined — so a model
 *      that only rides the holdout's trend is rejected; a tie is a rejection
 *   e) beats the current champion on the same holdout by the configured margin
 */
final class ClassifierPromotionPolicy
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array{promote:bool, reason:string, checks:array<string, mixed>}
     */
    public function decide(array $metadata, bool $hasChampion, int $horizon): array
    {
        $holdout = (array) data_get($metadata, 'metrics.holdout', []);
        $sim = (array) ($holdout['non_overlapping'] ?? []);
        $baselines = (array) ($holdout['baselines'] ?? []);
        $folds = (array) data_get($metadata, 'metrics.walk_forward.folds', []);

        $net = (float) ($sim['total_net_pct'] ?? 0.0);
        $trades = (int) ($sim['trades'] ?? 0);
        $minTrades = (int) (config("ml.promotion.classifier.min_trades.{$horizon}") ?? config('ml.promotion.classifier.default_min_trades', 20));
        $positiveFolds = count(array_filter($folds, static fn ($f): bool => (float) data_get($f, 'non_overlapping.total_net_pct', 0) > 0
            && (int) data_get($f, 'non_overlapping.trades', 0) > 0));
        $foldShare = $folds === [] ? 0.0 : $positiveFolds / count($folds);
        $foldNet = array_sum(array_map(static fn ($f): float => (float) data_get($f, 'non_overlapping.total_net_pct', 0), $folds));
        $foldBaseline = static fn (string $name): float => array_sum(array_map(static fn ($f): float => (float) data_get($f, "baselines.{$name}.total_net_pct", 0), $folds));

        $checks = [
            'evaluation' => 'non_overlapping_after_fees_slippage_funding',
            'holdout_trades' => $trades, 'holdout_raw_signals' => (int) ($sim['raw_signals'] ?? 0),
            'holdout_net_pct' => $net, 'holdout_net_win_rate_pct' => $sim['net_win_rate_pct'] ?? null,
            'min_trades' => $minTrades, 'folds' => count($folds), 'positive_folds' => $positiveFolds,
            'fold_net_pct' => $foldNet,
            'always_buy_net_pct' => (float) data_get($baselines, 'always_buy.total_net_pct', 0),
            'always_sell_net_pct' => (float) data_get($baselines, 'always_sell.total_net_pct', 0),
            'effective_samples' => $holdout['effective_samples'] ?? null,
            'implied_return_mae_pct' => $holdout['implied_return_mae_pct'] ?? null,
            'baseline_zero_mae_pct' => $holdout['baseline_zero_mae_pct'] ?? null,
            'has_champion' => $hasChampion,
        ];

        if (count($folds) < (int) config('ml.promotion.min_walk_forward_folds', 3)) {
            return $this->reject('insufficient_walk_forward_folds', $checks);
        }
        if ($net <= 0) {
            return $this->reject('holdout_net_not_positive_after_costs', $checks);
        }
        if ($trades < $minTrades) {
            return $this->reject('too_few_non_overlapping_trades', $checks);
        }
        if ($foldShare < (float) config('ml.promotion.classifier.min_positive_fold_share', 0.67)) {
            return $this->reject('net_not_positive_across_folds', $checks);
        }
        foreach (['always_buy' => 'does_not_beat_always_buy', 'always_sell' => 'does_not_beat_always_sell'] as $name => $reason) {
            if ($net <= (float) data_get($baselines, "{$name}.total_net_pct", 0) || $foldNet <= $foldBaseline($name)) {
                return $this->reject($reason, $checks);
            }
        }

        if (! $hasChampion) {
            return ['promote' => true, 'reason' => 'classifier_passed_fee_aware_gate', 'checks' => $checks];
        }
        $champion = data_get($metadata, 'metrics.champion_holdout');
        if (! is_array($champion) || ! (bool) data_get($metadata, 'metrics.champion_comparable', false)) {
            $checks['champion_not_comparable_reason'] = data_get($metadata, 'metrics.champion_not_comparable_reason');

            return $this->reject('champion_not_comparable_on_same_holdout', $checks);
        }
        $championNet = (float) ($champion['total_net_pct'] ?? 0.0);
        $checks['champion_net_pct'] = $championNet;
        if ($net < $championNet + max(0.0, (float) config('ml.promotion.classifier.min_net_improvement_vs_champion_pct', 0.5))) {
            return $this->reject('not_better_than_champion', $checks);
        }

        return ['promote' => true, 'reason' => 'classifier_beats_champion_fee_aware', 'checks' => $checks];
    }

    /** @return array{promote:false, reason:string, checks:array<string, mixed>} */
    private function reject(string $reason, array $checks): array
    {
        return ['promote' => false, 'reason' => $reason, 'checks' => $checks];
    }
}
