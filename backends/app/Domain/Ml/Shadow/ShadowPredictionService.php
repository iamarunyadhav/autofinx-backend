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
    public function __construct(
        private readonly MlModelRegistry $registry,
        private readonly PythonShadowClient $python,
        private readonly ShadowDecision $decision,
        private readonly QuickTradeCostModel $costModel,
    ) {}

    /** @return array{status:string, prediction:?MlShadowPrediction} */
    public function predictLatest(string $symbol, int $horizon): array
    {
        $champion = $this->registry->champion($symbol, $horizon);
        if (! $champion) {
            return ['status' => 'NO_CHAMPION', 'prediction' => null];
        }
        // Serve the champion the schema it was trained on (a v1 champion keeps
        // getting v1 inputs while v2 candidates train alongside it).
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
        if (($result['model_version'] ?? null) !== $champion->model_version
            || ($result['feature_schema_version'] ?? null) !== $schema
            || ! is_numeric($result['predicted_return'] ?? null)) {
            return ['status' => 'MODEL_VERSION_MISMATCH', 'prediction' => null];
        }

        $costs = $this->costs();
        $current = (float) $row->anchor_close;
        $decided = $this->decision->decide(
            $current,
            (float) $result['predicted_return'],
            is_numeric($result['predicted_return_q10'] ?? null) ? (float) $result['predicted_return_q10'] : null,
            is_numeric($result['predicted_return_q90'] ?? null) ? (float) $result['predicted_return_q90'] : null,
            $costs,
        );

        $prediction = MlShadowPrediction::query()->firstOrCreate([
            'symbol' => $symbol, 'horizon_minutes' => $horizon, 'anchor_time_ms' => (int) $row->anchor_time_ms,
        ], [
            'target_time_ms' => (int) $row->anchor_time_ms + $horizon * 60_000,
            'model_id' => $champion->id, 'model_version' => $champion->model_version,
            'feature_schema_version' => $schema,
            'current_price' => $current, 'predicted_return' => (float) $result['predicted_return'],
            'predicted_price' => $decided['predicted_price'], 'predicted_low' => $decided['predicted_low'], 'predicted_high' => $decided['predicted_high'],
            'buy_net_pct' => $decided['buy_net_pct'], 'sell_net_pct' => $decided['sell_net_pct'],
            'decision' => $decided['decision'], 'decision_reason' => $decided['decision_reason'],
            'cost_snapshot' => $costs, 'status' => 'PENDING',
        ]);

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
        if ($durationSeconds % 60 !== 0 || ! in_array(intdiv($durationSeconds, 60), $horizons, true)) {
            return $base + ['status' => 'UNSUPPORTED_DURATION', 'horizon_minutes' => null];
        }
        $horizon = intdiv($durationSeconds, 60);
        $base['horizon_minutes'] = $horizon;
        $champion = $this->registry->champion($symbol, $horizon);
        $live = $evaluation->metrics($symbol, $horizon);
        $base['model'] = $champion ? $this->modelSummary($champion) : null;
        $base['live_evaluation'] = $live;

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
                'current_price' => $prediction->current_price,
                'predicted_expiry_price' => $prediction->predicted_price,
                'predicted_low' => $prediction->predicted_low,
                'predicted_high' => $prediction->predicted_high,
                'interval' => $prediction->predicted_low !== null ? 'p10_p90_expiry_price' : null,
                'expected_move_pct' => $prediction->predicted_return !== null ? round($prediction->predicted_return * 100, 6) : null,
                'buy_net_pct' => $prediction->buy_net_pct,
                'sell_net_pct' => $prediction->sell_net_pct,
                'cost_pct' => data_get($prediction->cost_snapshot, 'cost_pct'),
                'required_move_pct' => data_get($prediction->cost_snapshot, 'required_move_pct'),
                // Stale data never yields a BUY/SELL, whatever was stored.
                'decision' => $stale ? 'WAIT' : $prediction->decision,
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
        $coverage = data_get($holdout, 'interval_coverage_pct');

        return [
            'model_version' => $model->model_version,
            'status' => $model->status,
            'trained_at' => $model->trained_at?->toIso8601String(),
            'promoted_at' => $model->promoted_at?->toIso8601String(),
            'training_samples' => $model->train_samples,
            'holdout_samples' => $model->holdout_samples,
            'training_window_from' => $model->training_window_from_ms ? gmdate('c', intdiv((int) $model->training_window_from_ms, 1000)) : null,
            'training_window_to' => $model->training_window_to_ms ? gmdate('c', intdiv((int) $model->training_window_to_ms, 1000)) : null,
            'holdout' => [
                'mae_pct' => data_get($holdout, 'mae_pct'),
                'baseline_zero_mae_pct' => data_get($holdout, 'baseline_zero_mae_pct'),
                'direction_hit_pct' => data_get($holdout, 'direction_hit_pct'),
                'fee_aware' => data_get($holdout, 'fee_aware'),
            ],
            // Calibration = how often the real outcome fell inside the p10–p90
            // band on unseen data (target 80%). Reported, never assumed.
            'calibration' => [
                'state' => $coverage === null ? 'unknown' : (abs((float) $coverage - 80.0) <= 10.0 ? 'holdout_calibrated' : 'holdout_miscalibrated'),
                'holdout_interval_coverage_pct' => $coverage,
                'target_coverage_pct' => 80.0,
            ],
            'decision_reason' => $model->decision_reason,
        ];
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
}
