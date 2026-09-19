<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\ForecastMetricsService;
use App\Models\RatioBotForecastBacktest;

/**
 * READ-ONLY "should we trust this model" summary (Unified Forecast Engine
 * plan -- "production-readiness report" task). Combines, for ONE
 * (bot_type, bot_instance_id, horizon_seconds) scope, everything this
 * session's other read-only analytics already compute --
 * `ForecastCalibrationQualityService` (reliability curve / Brier score /
 * overconfidence) and `ForecastModelPromotionGate` (the pass/fail gate) --
 * plus its own aggregation of the underlying `RatioBotForecastBacktest`
 * rows. Never writes to any table, never wired into any live execution
 * path -- both classes it composes are documented read-only, and this class
 * adds no new writes of its own.
 */
class ForecastProductionReadinessReport
{
    public function __construct(
        private readonly ForecastCalibrationQualityService $calibration,
        private readonly ForecastModelPromotionGate $gate,
    ) {
    }

    /**
     * @param  array|null  $holdoutPnlSummary  Result of
     *         `ForecastMetricsService::summarize()` scoped to this bot's
     *         real, settled holdout-period trades. Null if no such evidence
     *         exists yet -- every field this report would derive from it
     *         (direction accuracy, MAE, range hit rate, net P&L, drawdown)
     *         is then reported null, and a matching entry is added to
     *         `known_limitations`, rather than fabricated.
     * @return array<string, mixed>
     */
    public function generate(
        string $botType,
        string $botInstanceId,
        int $horizonSeconds,
        ?array $holdoutPnlSummary = null,
    ): array {
        $limitations = [];

        $rows = RatioBotForecastBacktest::query()
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('horizon_seconds', $horizonSeconds)
            ->get();

        $training = $rows->where('evaluation_period', 'training');
        $holdout = $rows->where('evaluation_period', 'holdout');

        $trainingSampleTotal = (int) $training->sum('sample_size');
        $holdoutSampleTotal = (int) $holdout->sum('sample_size');

        // NOTE: `RatioBotForecastBacktest` only ever stores `computed_at`
        // (when the walk-forward calculation was RUN), never the actual
        // start/end timestamps of the historical candle window it
        // evaluated -- verified against both the model and its migrations
        // before writing this. The "period" reported below is therefore the
        // run timestamp(s), not a true historical coverage window; flagged
        // explicitly in known_limitations rather than presented as more
        // precise than it is.
        $trainingComputedAtMin = $training->min('computed_at');
        $trainingComputedAtMax = $training->max('computed_at');
        $holdoutComputedAtMin = $holdout->min('computed_at');
        $holdoutComputedAtMax = $holdout->max('computed_at');

        $limitations[] = 'Training/holdout "period" reflects when the backtest was last computed '
            .'(computed_at), not the actual start/end dates of the historical candle window it evaluated -- '
            .'that window is not persisted anywhere.';

        if ($trainingSampleTotal === 0) {
            $limitations[] = 'No training-period backtest data exists for this scope yet.';
        }
        if ($holdoutSampleTotal === 0) {
            $limitations[] = 'No holdout-period backtest data exists for this scope yet -- this model has never been evaluated out-of-sample.';
        }

        $calibration = $this->calibration->evaluate($botType, $botInstanceId, $horizonSeconds, 'holdout');
        if ($calibration['brier_score'] !== null) {
            $limitations[] = 'Brier score is a bucket-weighted approximation (per-bucket average confidence '
                .'used as the predicted probability for every prediction in that bucket), not a true '
                .'per-prediction Brier score -- RatioBotForecastBacktest stores bucket aggregates only.';
        } else {
            $limitations[] = sprintf(
                'Brier score is unavailable -- combined calibrated-bucket sample size (%d) is below the trusted floor (%d).',
                $calibration['brier_score_sample_size'],
                ForecastMetricsService::MIN_TRUSTED_SAMPLES,
            );
        }
        if ($calibration['overconfident_buckets'] !== []) {
            $labels = implode(', ', array_column($calibration['overconfident_buckets'], 'confidence_bucket'));
            $limitations[] = "Overconfidence detected in confidence bucket(s) {$labels} -- displayed confidence "
                .'meaningfully exceeds the real, sample-floor-cleared hit rate there.';
        }
        $uncalibratedBuckets = array_filter($calibration['reliability_curve'], fn (array $b): bool => ! $b['calibrated']);
        if ($uncalibratedBuckets !== []) {
            $labels = implode(', ', array_column($uncalibratedBuckets, 'confidence_bucket'));
            $limitations[] = "Confidence bucket(s) {$labels} have not reached the sample-size floor "
                ."(ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE = ".ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE
                .') and are reported as uncalibrated (null hit rate).';
        }

        $gateResult = $this->gate->evaluate($botType, $botInstanceId, $horizonSeconds, $holdoutPnlSummary);

        if ($holdoutPnlSummary === null) {
            $limitations[] = 'No real, settled-trade holdout P&L evidence was supplied -- direction accuracy, '
                .'MAE, range hit rate, net P&L, and max drawdown below are all unavailable.';
        } else {
            $pnlSampleSize = (int) ($holdoutPnlSummary['sample_size'] ?? 0);
            if ($pnlSampleSize < ForecastMetricsService::MIN_TRUSTED_SAMPLES) {
                $limitations[] = sprintf(
                    'Holdout P&L evidence sample size (%d) is below the trusted floor (%d) -- P&L-derived figures may be unreliable.',
                    $pnlSampleSize,
                    ForecastMetricsService::MIN_TRUSTED_SAMPLES,
                );
            }
        }

        if ($gateResult['status'] !== ForecastModelPromotionGate::STATUS_VALIDATED) {
            $reasons = implode(', ', $gateResult['reasons']);
            $limitations[] = "Promotion gate status is {$gateResult['status']} ({$reasons}) -- this model is not currently considered production-ready.";
        }

        return [
            'bot_type' => $botType,
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizonSeconds,
            'data_source' => [
                'training_sample_size' => $trainingSampleTotal,
                'holdout_sample_size' => $holdoutSampleTotal,
                'training_period' => [
                    'computed_at_earliest' => $trainingComputedAtMin?->toIso8601String(),
                    'computed_at_latest' => $trainingComputedAtMax?->toIso8601String(),
                ],
                'holdout_period' => [
                    'computed_at_earliest' => $holdoutComputedAtMin?->toIso8601String(),
                    'computed_at_latest' => $holdoutComputedAtMax?->toIso8601String(),
                ],
            ],
            'performance' => [
                'direction_accuracy_pct' => $holdoutPnlSummary['direction_accuracy_pct'] ?? null,
                'price_mae' => $holdoutPnlSummary['price_mae'] ?? null,
                'range_hit_rate_pct' => $holdoutPnlSummary['range_hit_rate_pct'] ?? null,
                'net_pnl' => $holdoutPnlSummary['net_pnl'] ?? null,
                'max_drawdown_amount' => $holdoutPnlSummary['max_drawdown_amount'] ?? null,
                'max_drawdown_pct' => $holdoutPnlSummary['max_drawdown_pct'] ?? null,
                'holdout_pnl_sample_size' => $holdoutPnlSummary['sample_size'] ?? null,
            ],
            'calibration' => $calibration,
            'promotion_gate' => $gateResult,
            'known_limitations' => $limitations,
        ];
    }
}
