<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Trading\QuickTradeCostModel;
use App\Models\MlFeatureRow;
use App\Models\MlModel;
use App\Models\MlShadowPrediction;

/**
 * Produces and stores one shadow prediction per symbol × horizon × anchor.
 *
 * Shadow only: nothing in any order path reads ml_shadow_predictions. Every
 * number shown as "Python ML" comes from the python champion for exactly the
 * requested horizon — never from the Primary/Unified forecast, and never a
 * different horizon's model relabelled.
 */
class ShadowPredictionService
{
    public const COST_EXCEEDS_MOVE_MESSAGE = 'No model for this expiry: trading costs exceed the typical price move at this horizon.';

    public function __construct(
        private readonly MlModelRegistry $registry,
        private readonly PythonShadowClient $python,
        private readonly ShadowDecision $decision,
        private readonly ClassifierDecision $classifierDecision,
        private readonly QuickTradeCostModel $costModel,
        private readonly FundingRateService $funding,
        private readonly ShadowDecisionDiagnostics $diagnostics,
    ) {}

    /** @return array{status:string, prediction:?MlShadowPrediction} */
    public function predictLatest(string $symbol, int $horizon): array
    {
        $champion = $this->registry->champion($symbol, $horizon);
        if (! $champion) {
            return ['status' => 'NO_CHAMPION', 'prediction' => null];
        }
        // Serve the champion the schema it was trained on (an older champion
        // keeps getting its own inputs while newer candidates train alongside).
        $schema = (string) $champion->feature_schema_version;
        if (! array_key_exists($schema, ShadowFeatureBuilder::SCHEMAS)) {
            return ['status' => 'FEATURE_SCHEMA_MISMATCH', 'prediction' => null];
        }

        $timeframe = (string) config('ml.base_timeframe', '5m');
        $row = MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('feature_schema_version', $schema)
            ->orderByDesc('anchor_time_ms')->first();
        if (! $row) {
            return ['status' => 'NO_FEATURES', 'prediction' => null];
        }
        if ($this->isStale((int) $row->anchor_time_ms)) {
            return ['status' => 'DATA_STALE', 'prediction' => null];
        }

        $existing = MlShadowPrediction::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->where('anchor_time_ms', $row->anchor_time_ms)->first();
        if ($existing) {
            return ['status' => 'EXISTS', 'prediction' => $existing];
        }

        // Matched by name, not key order: MySQL's JSON type re-sorts keys.
        $stored = (array) $row->features;
        $features = [];
        foreach (ShadowFeatureBuilder::features($schema) as $name) {
            if (! is_numeric($stored[$name] ?? null)) {
                return ['status' => 'FEATURE_SCHEMA_MISMATCH', 'prediction' => null];
            }
            $features[$name] = (float) $stored[$name];
        }
        if (count($stored) !== count($features)) {
            return ['status' => 'FEATURE_SCHEMA_MISMATCH', 'prediction' => null];
        }
        $result = $this->python->predict($symbol, $horizon, $features, (string) $champion->model_version, $schema);
        if (($result['status'] ?? null) !== 'ready') {
            return ['status' => 'PREDICTOR_'.strtoupper((string) ($result['status'] ?? 'unavailable')).':'.($result['reason'] ?? 'unknown'), 'prediction' => null];
        }
        $isClassifier = ($champion->model_type ?? MlShadowTrainer::TYPE_REGRESSION) === MlShadowTrainer::TYPE_SIDE_CLASSIFIER;
        $valuesOk = $isClassifier
            ? is_numeric($result['p_buy'] ?? null) && is_numeric($result['p_sell'] ?? null) && ($result['model_type'] ?? null) === MlShadowTrainer::TYPE_SIDE_CLASSIFIER
            : is_numeric($result['predicted_return'] ?? null);
        if (($result['model_version'] ?? null) !== $champion->model_version
            || ($result['feature_schema_version'] ?? null) !== $schema || ! $valuesOk) {
            return ['status' => 'MODEL_VERSION_MISMATCH', 'prediction' => null];
        }

        $costs = $this->costs();
        $anchor = (int) $row->anchor_time_ms;
        $current = (float) $row->anchor_close;
        // Future funding is unknown at T: settlements in (T, T+h] × latest rate.
        $fundingBuy = $this->funding->estimateBuyCostPct($symbol, $anchor, $horizon * 60_000);
        $common = [
            'target_time_ms' => $anchor + $horizon * 60_000,
            'model_id' => $champion->id, 'model_version' => $champion->model_version,
            'model_type' => $isClassifier ? MlShadowTrainer::TYPE_SIDE_CLASSIFIER : MlShadowTrainer::TYPE_REGRESSION,
            'feature_schema_version' => $schema, 'current_price' => $current,
            'funding_estimate_buy_pct' => $fundingBuy, 'cost_snapshot' => $costs, 'status' => 'PENDING',
        ];

        if ($isClassifier) {
            $params = (array) data_get($champion->metrics, 'decision_params', []);
            if (! isset($params['buy'], $params['sell'])) {
                return ['status' => 'MODEL_DECISION_PARAMS_MISSING', 'prediction' => null];
            }
            $pBuy = (float) $result['p_buy'];
            $pSell = (float) $result['p_sell'];
            $decided = $this->classifierDecision->decide($pBuy, $pSell, $params, $costs, $fundingBuy);
            $values = $common + [
                'p_buy' => round($pBuy, 6), 'p_sell' => round($pSell, 6),
                'expected_net_buy_pct' => $decided['expected_net_buy_pct'], 'expected_net_sell_pct' => $decided['expected_net_sell_pct'],
                'buy_net_pct' => $decided['expected_net_buy_pct'], 'sell_net_pct' => $decided['expected_net_sell_pct'],
                'decision' => $decided['decision'], 'decision_reason' => $decided['decision_reason'],
            ];
        } else {
            $decided = $this->decision->decide(
                $current,
                (float) $result['predicted_return'],
                is_numeric($result['predicted_return_q10'] ?? null) ? (float) $result['predicted_return_q10'] : null,
                is_numeric($result['predicted_return_q90'] ?? null) ? (float) $result['predicted_return_q90'] : null,
                $costs,
                $fundingBuy,
            );
            $values = $common + [
                'predicted_return' => (float) $result['predicted_return'],
                'predicted_price' => $decided['predicted_price'], 'predicted_low' => $decided['predicted_low'], 'predicted_high' => $decided['predicted_high'],
                'buy_net_pct' => $decided['buy_net_pct'], 'sell_net_pct' => $decided['sell_net_pct'],
                'decision' => $decided['decision'], 'decision_reason' => $decided['decision_reason'],
            ];
        }

        $prediction = MlShadowPrediction::query()->firstOrCreate([
            'symbol' => $symbol, 'horizon_minutes' => $horizon, 'anchor_time_ms' => $anchor,
        ], $values);

        return ['status' => 'CREATED', 'prediction' => $prediction];
    }

    /**
     * API payload for the Quick Trade "Python ML Shadow" card.
     *
     * @return array<string, mixed>
     */
    public function payload(string $symbol, int $durationSeconds, ShadowEvaluationService $evaluation): array
    {
        $horizons = app(FeatureLabelService::class)->horizons();
        $base = [
            'mode' => 'shadow_only', 'execution' => false, 'influence' => false, 'source' => 'python_ml_shadow',
            'symbol' => $symbol, 'duration_seconds' => $durationSeconds, 'supported_horizons_minutes' => $horizons,
        ];
        if ($durationSeconds <= (int) config('ml.cost_exceeds_move_max_seconds', 900)) {
            return $base + ['status' => 'COST_EXCEEDS_MOVE', 'horizon_minutes' => null, 'message' => self::COST_EXCEEDS_MOVE_MESSAGE];
        }
        // Each expiry maps to its own horizon model only (3600→60, 7200→120,
        // 14400→240, 86400→1440); anything else has no model — never borrowed.
        if ($durationSeconds % 60 !== 0 || ! in_array(intdiv($durationSeconds, 60), $horizons, true)) {
            return $base + ['status' => 'UNSUPPORTED_DURATION', 'horizon_minutes' => null];
        }
        $horizon = intdiv($durationSeconds, 60);
        $base['horizon_minutes'] = $horizon;
        $champion = $this->registry->champion($symbol, $horizon);
        $live = $evaluation->metrics($symbol, $horizon);
        $base['model'] = $champion ? $this->modelSummary($champion) : null;
        $base['live_evaluation'] = $live;
        $base['decision_diagnostics'] = $this->diagnostics->forScope($symbol, $horizon);

        $prediction = MlShadowPrediction::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)
            ->orderByDesc('anchor_time_ms')->first();
        if (! $prediction) {
            return $base + ['status' => $champion ? 'NO_PREDICTION_YET' : 'NO_CHAMPION'];
        }

        $ageSeconds = max(0, intdiv(now('UTC')->getTimestampMs() - (int) $prediction->anchor_time_ms, 1000));
        $stale = $this->isStale((int) $prediction->anchor_time_ms);
        $model = $prediction->model_id ? MlModel::query()->find($prediction->model_id) : null;

        return $base + [
            'status' => $stale ? 'DATA_STALE' : 'READY',
            'prediction' => [
                'anchor_time' => gmdate('c', intdiv((int) $prediction->anchor_time_ms, 1000)),
                'target_time' => gmdate('c', intdiv((int) $prediction->target_time_ms, 1000)),
                'data_age_seconds' => $ageSeconds,
                'model_type' => $prediction->model_type ?? MlShadowTrainer::TYPE_REGRESSION,
                'current_price' => $prediction->current_price,
                'predicted_expiry_price' => $prediction->predicted_price,
                'predicted_low' => $prediction->predicted_low,
                'predicted_high' => $prediction->predicted_high,
                'interval' => $prediction->predicted_low !== null ? 'p10_p90_expiry_price' : null,
                'expected_move_pct' => $prediction->predicted_return !== null ? round($prediction->predicted_return * 100, 6) : null,
                'p_buy' => $prediction->p_buy,
                'p_sell' => $prediction->p_sell,
                'probability_floor' => data_get($model?->metrics, 'decision_params.probability_floor'),
                'buy_net_pct' => $prediction->buy_net_pct,
                'sell_net_pct' => $prediction->sell_net_pct,
                'funding_estimate_buy_pct' => $prediction->funding_estimate_buy_pct,
                'cost_pct' => data_get($prediction->cost_snapshot, 'cost_pct'),
                'required_move_pct' => data_get($prediction->cost_snapshot, 'required_move_pct'),
                // Stale data never yields a BUY/SELL, whatever was stored.
                'decision' => $stale ? 'WAIT' : $prediction->decision,
                'decision_status' => $stale ? 'DATA_INVALID' : $this->decisionStatus($prediction->decision, $prediction->decision_reason, $prediction->buy_net_pct, $prediction->sell_net_pct),
                'decision_reason' => $stale ? 'DATA_STALE' : $prediction->decision_reason,
                'model_version' => $prediction->model_version,
                'training_samples' => $model?->train_samples,
                'feature_schema_version' => $prediction->feature_schema_version,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function modelSummary(MlModel $model): array
    {
        $holdout = (array) data_get($model->metrics, 'holdout', []);
        $type = $model->model_type ?? MlShadowTrainer::TYPE_REGRESSION;

        return [
            'model_version' => $model->model_version,
            'model_type' => $type,
            'status' => $model->status,
            'trained_at' => $model->trained_at?->toIso8601String(),
            'promoted_at' => $model->promoted_at?->toIso8601String(),
            'training_samples' => $model->train_samples,
            'holdout_samples' => $model->holdout_samples,
            'training_window_from' => $model->training_window_from_ms ? gmdate('c', intdiv((int) $model->training_window_from_ms, 1000)) : null,
            'training_window_to' => $model->training_window_to_ms ? gmdate('c', intdiv((int) $model->training_window_to_ms, 1000)) : null,
            'holdout' => [
                'mae_pct' => data_get($holdout, 'mae_pct') ?? data_get($holdout, 'implied_return_mae_pct'),
                'baseline_zero_mae_pct' => data_get($holdout, 'baseline_zero_mae_pct'),
                'direction_hit_pct' => data_get($holdout, 'direction_hit_pct'),
                'fee_aware' => data_get($holdout, 'fee_aware'),
                // One position at a time, after fees + slippage + funding.
                'non_overlapping' => data_get($holdout, 'non_overlapping'),
                'baselines' => data_get($holdout, 'baselines'),
                'effective_samples' => data_get($holdout, 'effective_samples'),
            ],
            'calibration' => $type === MlShadowTrainer::TYPE_SIDE_CLASSIFIER
                ? $this->classifierCalibration((array) data_get($holdout, 'calibration', []))
                : $this->intervalCalibration(data_get($holdout, 'interval_coverage_pct')),
            'decision_reason' => $model->decision_reason,
        ];
    }

    /** Regression: share of real outcomes inside the p10–p90 band (target 80%). */
    private function intervalCalibration(mixed $coverage): array
    {
        return [
            'state' => $coverage === null ? 'unknown' : (abs((float) $coverage - 80.0) <= 10.0 ? 'holdout_calibrated' : 'holdout_miscalibrated'),
            'holdout_interval_coverage_pct' => $coverage,
            'target_coverage_pct' => 80.0,
        ];
    }

    /**
     * Classifier: on unseen holdout rows, did "P >= 0.5" happen about as often
     * as predicted, and does the probability beat a constant base rate (Brier)?
     */
    private function classifierCalibration(array $calibration): array
    {
        $sides = [];
        foreach (['buy', 'sell'] as $side) {
            $c = (array) ($calibration[$side] ?? []);
            $rows = (int) ($c['high_confidence_rows'] ?? 0);
            $observed = $c['high_confidence_observed_rate'] ?? null;
            $predicted = $c['high_confidence_mean_predicted'] ?? null;
            $sides[$side] = [
                'brier' => $c['brier'] ?? null,
                'brier_climatology' => $c['brier_climatology'] ?? null,
                'high_confidence_rows' => $rows,
                'high_confidence_observed_rate' => $observed,
                'high_confidence_mean_predicted' => $predicted,
                'state' => $rows < 30 || $observed === null || $predicted === null ? 'insufficient_high_confidence_rows'
                    : (abs((float) $observed - (float) $predicted) <= 0.10 ? 'holdout_calibrated' : 'holdout_miscalibrated'),
            ];
        }

        return ['state' => $sides['buy']['state'] === 'holdout_calibrated' && $sides['sell']['state'] === 'holdout_calibrated' ? 'holdout_calibrated' : $sides['buy']['state'], 'sides' => $sides];
    }

    /** @return array<string, mixed> */
    public function costs(): array
    {
        return $this->costModel->costs($this->costModel->resolveFeeModelForMode('live'));
    }

    private function isStale(int $anchorTimeMs): bool
    {
        return now('UTC')->getTimestampMs() - $anchorTimeMs > (int) config('ml.data.max_anchor_age_seconds', 720) * 1000;
    }

    private function decisionStatus(string $decision, ?string $reason, mixed $buyNet, mixed $sellNet): string
    {
        if ($decision === 'BUY' || $decision === 'SELL') {
            return 'VALID_TRADEABLE';
        }
        if ($reason === 'expected_net_not_positive_after_costs' || $reason === 'expected_move_below_required_move') {
            return 'COST_BLOCKED';
        }
        if ($reason === 'no_side_probability_above_floor') {
            return 'WAIT_SIGNAL';
        }

        return 'WAIT_SIGNAL';
    }
}
