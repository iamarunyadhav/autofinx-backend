<?php

namespace App\Domain\RatioBot;

/**
 * READ-ONLY model-version comparison (Unified Forecast Engine plan --
 * "model version comparison" task). Never writes to any table, never wired
 * into any live execution path -- every method here composes
 * `ForecastCalibrationQualityService::evaluate()` (read-only) and
 * `ForecastModelPromotionGate::evaluate()` (read-only, documented as such in
 * its own class docblock) and does nothing else.
 *
 * === Why comparison is by (bot_type, bot_instance_id, horizon_seconds)
 * scope, not by a `model_version` field ===
 * Checked before writing this: `TradeForecastService::forecast()` does emit
 * a `model_version` key in its return array, but it is a single hardcoded
 * literal (`'quick-expiry-v1'`) -- not a value that varies across time or
 * is persisted anywhere queryable (`RatioBotForecastBacktest` has no
 * `model_version` column; the migrations
 * (`2026_09_18_...add_bot_type_and_confidence_bucket...`,
 * `2026_09_19_...add_evaluation_period...`) confirm the only identity
 * columns are `bot_type`/`bot_instance_id`/`horizon_seconds`/
 * `confidence_bucket`/`evaluation_period`). There is therefore no real,
 * distinct "model version" signal to compare by yet, and inventing a
 * time-window split (e.g. "before/after date X") on top of a single
 * `model_version` literal would not be comparing two actual models -- it
 * would just be re-slicing one model's own history and calling it two.
 *
 * Instead, this service compares two independently-identified scopes --
 * typically two different `bot_instance_id` values (e.g. a bot running an
 * old brain config vs. one running a new config, or two horizon_seconds
 * values for the same bot) -- exactly the identity keys
 * `ForecastHorizonBacktestService`/`ForecastModelPromotionGate` already use
 * as "the thing being evaluated". If/when a real `model_version` column is
 * ever added, this service's `compare()` signature (two full scopes) still
 * works unchanged -- a caller would simply pass two `bot_instance_id`
 * values that differ only by model version.
 */
class ForecastModelComparisonService
{
    public function __construct(
        private readonly ForecastCalibrationQualityService $calibration,
        private readonly ForecastModelPromotionGate $gate,
    ) {
    }

    /**
     * @param  array{bot_type:string, bot_instance_id:string, horizon_seconds:int, label?:string}  $scopeA
     * @param  array{bot_type:string, bot_instance_id:string, horizon_seconds:int, label?:string}  $scopeB
     * @param  array|null  $holdoutPnlSummaryA  Result of
     *         `ForecastMetricsService::summarize()` scoped to scope A's real,
     *         settled holdout-period trades. Null if no such evidence exists
     *         yet (never fabricated) -- mirrors
     *         `ForecastModelPromotionGate::evaluate()`'s own contract.
     * @param  array|null  $holdoutPnlSummaryB  Same, for scope B.
     * @return array{
     *   model_a:array<string,mixed>, model_b:array<string,mixed>,
     *   deltas:array<string,mixed>, recommendation:string,
     * }
     */
    public function compare(
        array $scopeA,
        array $scopeB,
        ?array $holdoutPnlSummaryA = null,
        ?array $holdoutPnlSummaryB = null,
    ): array {
        $modelA = $this->buildModelSummary($scopeA, $holdoutPnlSummaryA);
        $modelB = $this->buildModelSummary($scopeB, $holdoutPnlSummaryB);

        $deltas = [
            'direction_accuracy_pct' => $this->numericDelta($modelB['direction_accuracy_pct'], $modelA['direction_accuracy_pct']),
            'net_pnl' => $this->numericDelta($modelB['net_pnl'], $modelA['net_pnl']),
            'max_drawdown_pct' => $this->numericDelta($modelB['max_drawdown_pct'], $modelA['max_drawdown_pct']),
            'brier_score' => $this->numericDelta($modelB['brier_score'], $modelA['brier_score']),
        ];

        return [
            'model_a' => $modelA,
            'model_b' => $modelB,
            'deltas' => $deltas,
            'recommendation' => $this->recommend($modelA, $modelB, $deltas),
        ];
    }

    /**
     * @param  array{bot_type:string, bot_instance_id:string, horizon_seconds:int, label?:string}  $scope
     */
    private function buildModelSummary(array $scope, ?array $holdoutPnlSummary): array
    {
        $botType = $scope['bot_type'];
        $botInstanceId = $scope['bot_instance_id'];
        $horizonSeconds = (int) $scope['horizon_seconds'];
        $label = $scope['label'] ?? $botInstanceId;

        $calibration = $this->calibration->evaluate($botType, $botInstanceId, $horizonSeconds, 'holdout');
        $gateResult = $this->gate->evaluate($botType, $botInstanceId, $horizonSeconds, $holdoutPnlSummary);

        return [
            'label' => $label,
            'bot_type' => $botType,
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizonSeconds,
            'direction_accuracy_pct' => $holdoutPnlSummary['direction_accuracy_pct'] ?? null,
            'net_pnl' => $holdoutPnlSummary['net_pnl'] ?? null,
            'max_drawdown_pct' => $holdoutPnlSummary['max_drawdown_pct'] ?? null,
            'holdout_pnl_sample_size' => $holdoutPnlSummary['sample_size'] ?? null,
            'brier_score' => $calibration['brier_score'],
            'overconfident_bucket_count' => count($calibration['overconfident_buckets']),
            'calibration' => $calibration,
            'gate_status' => $gateResult['status'],
            'gate_reasons' => $gateResult['reasons'],
        ];
    }

    private function numericDelta(mixed $b, mixed $a): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }

        return round(((float) $b) - ((float) $a), 6);
    }

    /**
     * Every branch below reads directly off the two real computed
     * `$modelA`/`$modelB` summaries (gate status, net P&L, drawdown,
     * accuracy) -- never a generic, data-independent template string.
     */
    private function recommend(array $modelA, array $modelB, array $deltas): string
    {
        $labelA = $modelA['label'];
        $labelB = $modelB['label'];
        $statusA = $modelA['gate_status'];
        $statusB = $modelB['gate_status'];
        $validated = ForecastModelPromotionGate::STATUS_VALIDATED;

        $describe = fn (string $label, string $status): string => "{$label} is {$status}";

        // Neither has real, gate-passing evidence yet.
        if ($statusA !== $validated && $statusB !== $validated) {
            return sprintf(
                'Neither model clears the promotion gate yet (%s, %s) -- insufficient or unfavorable holdout '
                .'evidence on both sides. No switch recommended until at least one reaches VALIDATED.',
                $describe($labelA, $statusA),
                $describe($labelB, $statusB),
            );
        }

        // Exactly one is validated -- recommend it, but say why using its
        // own real numbers, and note the other's real status.
        if ($statusA === $validated && $statusB !== $validated) {
            return sprintf(
                '%s is the only model that clears the promotion gate (holdout net P&L %s, max drawdown %s%%); '
                .'%s is %s and cannot yet be recommended.',
                $labelA,
                $modelA['net_pnl'] !== null ? number_format((float) $modelA['net_pnl'], 2) : 'unknown',
                $modelA['max_drawdown_pct'] !== null ? number_format((float) $modelA['max_drawdown_pct'], 2) : 'unknown',
                $labelB,
                $statusB,
            );
        }
        if ($statusB === $validated && $statusA !== $validated) {
            return sprintf(
                '%s is the only model that clears the promotion gate (holdout net P&L %s, max drawdown %s%%); '
                .'%s is %s and cannot yet be recommended.',
                $labelB,
                $modelB['net_pnl'] !== null ? number_format((float) $modelB['net_pnl'], 2) : 'unknown',
                $modelB['max_drawdown_pct'] !== null ? number_format((float) $modelB['max_drawdown_pct'], 2) : 'unknown',
                $labelA,
                $statusA,
            );
        }

        // Both validated -- compare on the real numbers: net P&L first,
        // then check drawdown didn't get meaningfully worse in exchange.
        $pnlDelta = $deltas['net_pnl'];
        $drawdownDelta = $deltas['max_drawdown_pct'];

        if ($pnlDelta === null) {
            return sprintf(
                '%s and %s both clear the promotion gate, but at least one is missing holdout net P&L data, '
                .'so no comparison of profitability can be made yet.',
                $labelA,
                $labelB,
            );
        }

        if ($pnlDelta > 0) {
            $better = $labelB;
            $worse = $labelA;
            $betterPnl = $modelB['net_pnl'];
            $ddDelta = $drawdownDelta;
        } elseif ($pnlDelta < 0) {
            $better = $labelA;
            $worse = $labelB;
            $betterPnl = $modelA['net_pnl'];
            $ddDelta = $drawdownDelta === null ? null : -$drawdownDelta;
        } else {
            return sprintf(
                '%s and %s both clear the promotion gate with identical holdout net P&L (%s); no clear reason '
                .'to switch based on profitability alone.',
                $labelA,
                $labelB,
                number_format((float) $modelA['net_pnl'], 2),
            );
        }

        if ($ddDelta !== null && $ddDelta > 0) {
            return sprintf(
                '%s shows higher holdout net P&L (%s) than %s, but its max drawdown is %s percentage points worse '
                .'-- consider the risk tradeoff before promoting, not profitability alone.',
                $better,
                number_format((float) $betterPnl, 2),
                $worse,
                number_format(abs($ddDelta), 2),
            );
        }

        return sprintf(
            '%s shows higher holdout net P&L (%s) than %s with comparable or better drawdown -- consider promoting %s.',
            $better,
            number_format((float) $betterPnl, 2),
            $worse,
            $better,
        );
    }
}
