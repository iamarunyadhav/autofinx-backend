<?php

namespace App\Domain\Trading;

/**
 * Part D -- bucketed accuracy, break-even analysis and confidence
 * reliability for Quick Trade.
 *
 * Deliberately built as an EXTENSION of the existing research exporter, not a
 * parallel one: it consumes exactly the rows
 * QuickTradeResearchService::report() already produces (the same rows
 * /quick-trades/research and /quick-trades/research/summary return), and
 * recomputes nothing about a trade that has already been evaluated.
 *
 * Honesty rules, identical to the rest of the research pipeline:
 *  - a bucket below `quick_trade.bucket_min_samples` is reported with
 *    status `insufficient` and NULL percentages -- never a percentage off a
 *    handful of trades;
 *  - `tradeable` requires BOTH a direction accuracy above the computed
 *    break-even AND at least `quick_trade.tradeable_min_samples` samples;
 *  - unknown/unavailable data is excluded from denominators, never counted
 *    as a failure;
 *  - a NEUTRAL direction is excluded (not_applicable), never a miss.
 *
 * NOTHING here promotes a model or changes a live decision. It is a report.
 */
class QuickTradeAccuracyReport
{
    /** Confidence buckets for the D3 calibration table (percent). */
    private const CONFIDENCE_BUCKETS = [[0, 20], [20, 40], [40, 60], [60, 80], [80, 101]];

    /** D1 expiry-score buckets, exactly as the spec lists them. */
    private const SCORE_BUCKETS = [[0.2, 0.4], [0.4, 0.6], [0.6, 0.8], [0.8, 1.01]];

    public function __construct(private readonly QuickTradeCostModel $costs)
    {
    }

    /**
     * @param  iterable<array<string,mixed>>  $rows  QuickTradeResearchService::report() rows
     * @return array<string,mixed>
     */
    public function generate(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        $minBucket = max(1, (int) config('quick_trade.bucket_min_samples', 30));
        $minTradeable = max(1, (int) config('quick_trade.tradeable_min_samples', 100));

        $dimensions = [
            'timeframe' => fn (array $row) => $row['timeframe'] ?? 'unknown',
            'expiry_seconds' => fn (array $row) => (string) ($row['expiry_seconds'] ?? 'unknown'),
            'direction' => fn (array $row) => data_get($row, 'direction_prediction.value') ?? 'unknown',
            'trade_decision' => fn (array $row) => data_get($row, 'trade_decision_prediction.value') ?? 'unknown',
            'expiry_score_bucket' => fn (array $row) => $this->scoreBucketLabel(data_get($row, 'direction_prediction.expiry_score')),
            'volume_status' => fn (array $row) => data_get($row, 'research_snapshot.primary.market_context.volume_status') ?? 'unknown',
            'manual_override' => fn (array $row) => $row['manual_override'] === null
                ? 'unknown' : ($row['manual_override'] ? 'override' : 'followed'),
            'fee_model' => fn (array $row) => data_get($row, 'trade_decision_prediction.fee_model')
                ?? data_get($row, 'research_snapshot.primary.trade_decision.fee_model') ?? 'unknown',
        ];

        $grouped = [];
        foreach ($dimensions as $name => $resolver) {
            $byKey = [];
            foreach ($rows as $row) {
                $byKey[(string) $resolver($row)][] = $row;
            }
            ksort($byKey);
            $grouped[$name] = array_values(array_map(
                fn (string $key) => ['bucket' => $key] + $this->bucketMetrics($byKey[$key], $minBucket, $minTradeable),
                array_keys($byKey),
            ));
        }

        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'sample_size' => count($rows),
            'thresholds' => [
                'bucket_min_samples' => $minBucket,
                'tradeable_min_samples' => $minTradeable,
                'calibration_min_samples' => (int) config('quick_trade.calibration_min_samples', 150),
            ],
            'overall' => $this->bucketMetrics($rows, $minBucket, $minTradeable),
            'buckets' => $grouped,
            'confidence_reliability' => [
                // D3 -- both scores, reported separately. The indicator vote
                // is a VOTE SHARE, not a probability (finding F5); reporting
                // its Brier score is exactly how that gets demonstrated
                // rather than asserted.
                'indicator_vote' => $this->reliability($rows, 'prediction_confidence'),
                'forecast_confidence' => $this->reliability($rows, 'research_snapshot.primary.confidence'),
            ],
            'note' => 'Observational report over settled trades. Buckets under the minimum sample size are `insufficient`, never a percentage. `tradeable` additionally requires direction accuracy above the computed break-even. Nothing here promotes a model or changes a live decision.',
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function bucketMetrics(array $rows, int $minBucket, int $minTradeable): array
    {
        $n = count($rows);

        $directionEvaluated = 0;
        $directionCorrect = 0;
        $decisionEvaluated = 0;
        $decisionCorrect = 0;
        $grossValues = [];
        $netValues = [];
        $absMoves = [];
        $costPcts = [];

        foreach ($rows as $row) {
            if (($row['direction_evaluation_status'] ?? null) === 'evaluated' && $row['direction_correct'] !== null) {
                $directionEvaluated++;
                $directionCorrect += $row['direction_correct'] === true ? 1 : 0;
            }
            if (($row['trade_decision_evaluation_status'] ?? null) === 'evaluated' && $row['trade_decision_correct'] !== null) {
                $decisionEvaluated++;
                $decisionCorrect += $row['trade_decision_correct'] === true ? 1 : 0;
            }
            if (($row['gross_pnl'] ?? null) !== null) {
                $grossValues[] = (float) $row['gross_pnl'];
            }
            if (($row['net_pnl_booked'] ?? null) !== null) {
                $netValues[] = (float) $row['net_pnl_booked'];
            }

            // Realised absolute move, entry -> exit, as a percentage. This is
            // the REAL observed move, not the forecast's estimate.
            $entry = (float) ($row['entry_price'] ?? 0);
            $exit = $row['exit_price'] ?? null;
            if ($entry > 0 && $exit !== null) {
                $absMoves[] = abs(((float) $exit - $entry) / $entry) * 100;
            }

            $costPct = data_get($row, 'trade_decision_prediction.cost_pct');
            if ($costPct !== null) {
                $costPcts[] = (float) $costPct;
            }
        }

        $avgAbsMove = $absMoves === [] ? null : array_sum($absMoves) / count($absMoves);
        $costPct = $costPcts === []
            ? $this->costs->costs()['cost_pct']
            : array_sum($costPcts) / count($costPcts);
        $breakEven = $this->breakEvenAccuracy($avgAbsMove, $costPct);

        $directionAccuracy = $directionEvaluated >= $minBucket
            ? round($directionCorrect / $directionEvaluated * 100, 2) : null;

        $status = $n < $minBucket ? 'insufficient' : 'observed';
        $tradeable = $directionAccuracy !== null
            && $breakEven !== null
            && $directionEvaluated >= $minTradeable
            && $directionAccuracy > $breakEven * 100;

        return [
            'n' => $n,
            'status' => $status,
            'direction_evaluated' => $directionEvaluated,
            'direction_correct' => $directionCorrect,
            'direction_accuracy_pct' => $directionAccuracy,
            'trade_decision_evaluated' => $decisionEvaluated,
            'trade_decision_correct' => $decisionCorrect,
            'trade_decision_accuracy_pct' => $decisionEvaluated >= $minBucket
                ? round($decisionCorrect / $decisionEvaluated * 100, 2) : null,
            'avg_gross_pnl' => $grossValues === [] ? null : round(array_sum($grossValues) / count($grossValues), 8),
            'avg_net_pnl' => $netValues === [] ? null : round(array_sum($netValues) / count($netValues), 8),
            'avg_abs_move_pct' => $avgAbsMove === null ? null : round($avgAbsMove, 6),
            'cost_pct' => round($costPct, 6),
            'break_even_accuracy_pct' => $breakEven === null ? null : round($breakEven * 100, 2),
            'tradeable' => $tradeable,
            'tradeable_reason' => $tradeable
                ? 'Direction accuracy exceeds break-even on a sufficient sample.'
                : $this->notTradeableReason($directionAccuracy, $breakEven, $directionEvaluated, $minTradeable),
        ];
    }

    /**
     * D2 -- the accuracy a coin-flip-sized edge must beat just to pay costs:
     *
     *   p_breakeven = (avg_abs_move + cost) / (2 * avg_abs_move)
     *
     * Returned as a FRACTION (0..1). Null when there is no realised-move
     * evidence to compute it from -- never a guessed 0.5.
     */
    public function breakEvenAccuracy(?float $avgAbsMovePct, float $costPct): ?float
    {
        if ($avgAbsMovePct === null || $avgAbsMovePct <= 0.0) {
            return null;
        }

        return ($avgAbsMovePct + $costPct) / (2 * $avgAbsMovePct);
    }

    private function notTradeableReason(?float $accuracy, ?float $breakEven, int $evaluated, int $minTradeable): string
    {
        if ($breakEven === null) {
            return 'No realised-move evidence yet, so break-even accuracy cannot be computed.';
        }
        if ($accuracy === null) {
            return 'Too few evaluated directions to report an accuracy.';
        }
        if ($evaluated < $minTradeable) {
            return sprintf('Needs %d evaluated directions before it can be called tradeable (has %d).', $minTradeable, $evaluated);
        }

        return sprintf('Direction accuracy %.2f%% does not exceed the %.2f%% break-even.', $accuracy, $breakEven * 100);
    }

    /**
     * D3 -- separation, Brier score and a calibration table for one
     * confidence field.
     *
     * Brier score treats the confidence as a claimed probability that the
     * DIRECTION call is right: mean((p - outcome)^2), lower is better. A
     * vote share masquerading as a probability shows up here as a poor
     * Brier score and a flat calibration table -- which is the point.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function reliability(array $rows, string $confidencePath): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            if (($row['direction_evaluation_status'] ?? null) !== 'evaluated' || $row['direction_correct'] === null) {
                continue;
            }
            $confidence = data_get($row, $confidencePath);
            if ($confidence === null || ! is_numeric($confidence)) {
                continue;
            }
            $pct = (float) $confidence;
            // Accept either 0-1 or 0-100 and normalise to percent.
            $pct = $pct <= 1.0 ? $pct * 100 : $pct;
            $pairs[] = ['confidence_pct' => max(0.0, min(100.0, $pct)), 'correct' => $row['direction_correct'] === true];
        }

        $n = count($pairs);
        $minSamples = max(1, (int) config('quick_trade.calibration_min_samples', 150));

        if ($n === 0) {
            return ['sample_size' => 0, 'status' => 'insufficient', 'calibrated' => false,
                'brier_score' => null, 'avg_confidence_correct' => null, 'avg_confidence_incorrect' => null,
                'separation' => null, 'calibration' => []];
        }

        $correctConfidences = [];
        $incorrectConfidences = [];
        $brierSum = 0.0;
        foreach ($pairs as $pair) {
            $p = $pair['confidence_pct'] / 100;
            $outcome = $pair['correct'] ? 1.0 : 0.0;
            $brierSum += ($p - $outcome) ** 2;
            if ($pair['correct']) {
                $correctConfidences[] = $pair['confidence_pct'];
            } else {
                $incorrectConfidences[] = $pair['confidence_pct'];
            }
        }

        $avgCorrect = $correctConfidences === [] ? null : array_sum($correctConfidences) / count($correctConfidences);
        $avgIncorrect = $incorrectConfidences === [] ? null : array_sum($incorrectConfidences) / count($incorrectConfidences);

        $calibration = [];
        foreach (self::CONFIDENCE_BUCKETS as [$low, $high]) {
            $inBucket = array_values(array_filter(
                $pairs,
                static fn (array $pair) => $pair['confidence_pct'] >= $low && $pair['confidence_pct'] < $high,
            ));
            $bucketN = count($inBucket);
            $hits = count(array_filter($inBucket, static fn (array $pair) => $pair['correct']));
            $calibration[] = [
                'bucket' => sprintf('%d-%d', $low, min(100, $high)),
                'n' => $bucketN,
                'avg_confidence_pct' => $bucketN === 0 ? null
                    : round(array_sum(array_column($inBucket, 'confidence_pct')) / $bucketN, 2),
                // Observed hit rate is only published once the bucket is big
                // enough to mean anything; below that it stays null and the
                // UI keeps calling the score "uncalibrated" (D4).
                'observed_hit_rate_pct' => $bucketN >= (int) config('quick_trade.bucket_min_samples', 30)
                    ? round($hits / $bucketN * 100, 2) : null,
                'raw_hit_rate_pct' => $bucketN === 0 ? null : round($hits / $bucketN * 100, 2),
                'calibrated' => $bucketN >= (int) config('quick_trade.bucket_min_samples', 30),
            ];
        }

        return [
            'sample_size' => $n,
            'status' => $n >= $minSamples ? 'observed' : 'insufficient',
            // D4 -- only above this threshold may the UI show an observed hit
            // rate instead of labelling the number an uncalibrated score.
            'calibrated' => $n >= $minSamples,
            'brier_score' => round($brierSum / $n, 6),
            'avg_confidence_correct' => $avgCorrect === null ? null : round($avgCorrect, 2),
            'avg_confidence_incorrect' => $avgIncorrect === null ? null : round($avgIncorrect, 2),
            'separation' => $avgCorrect === null || $avgIncorrect === null
                ? null : round($avgCorrect - $avgIncorrect, 2),
            'calibration' => $calibration,
            'note' => 'Brier score treats the confidence as a claimed probability that the DIRECTION call is right (lower is better). A vote share is not a probability; a flat calibration table here is evidence of that, not a bug.',
        ];
    }

    private function scoreBucketLabel(mixed $score): string
    {
        if ($score === null || ! is_numeric($score)) {
            return 'unknown';
        }
        $abs = abs((float) $score);
        foreach (self::SCORE_BUCKETS as [$low, $high]) {
            if ($abs >= $low && $abs < $high) {
                return sprintf('%.1f-%.1f', $low, min(1.0, $high));
            }
        }

        return $abs < 0.2 ? 'below_0.2' : 'unknown';
    }
}
