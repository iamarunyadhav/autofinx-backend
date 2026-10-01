<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Trading\QuickTradeCostModel;
use App\Models\MlModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One training attempt for one symbol × horizon:
 * export mature rows → python walk-forward trainer → CANDIDATE → promotion
 * policy → CHAMPION or REJECTED. Any failure records a FAILED row and leaves
 * the current champion exactly as it was.
 */
class MlShadowTrainer
{
    public function __construct(
        private readonly MlModelRegistry $registry,
        private readonly PromotionPolicy $policy,
        private readonly QuickTradeCostModel $costModel,
    ) {}

    public static function lockKey(string $symbol, int $horizon): string
    {
        return 'ml:shadow:train:'.strtoupper($symbol).':h'.$horizon;
    }

    public function train(string $symbol, int $horizon): MlModel
    {
        $lock = Cache::lock(self::lockKey($symbol, $horizon), (int) config('ml.training.timeout_seconds', 1800) + 300);
        if (! $lock->get()) {
            throw new RuntimeException("Training already running for {$symbol} h{$horizon}.");
        }

        try {
            return $this->trainLocked($symbol, $horizon);
        } finally {
            $lock->release();
        }
    }

    private function trainLocked(string $symbol, int $horizon): MlModel
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $modelId = (string) Str::uuid();
        $directory = rtrim((string) config('ml.training.artifact_dir'), '/\\').DIRECTORY_SEPARATOR.MlModelRegistry::championKey($symbol, $horizon).DIRECTORY_SEPARATOR.$modelId;
        $model = MlModel::query()->create([
            'id' => $modelId, 'symbol' => $symbol, 'horizon_minutes' => $horizon, 'timeframe' => $timeframe,
            'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'status' => 'TRAINING',
            'artifact_dir' => $directory,
        ]);

        try {
            $export = $this->export($symbol, $horizon, $timeframe, $modelId);
            $champion = $this->registry->champion($symbol, $horizon);
            $metadata = $this->runTrainer($export, $directory, $champion);
            if (($metadata['feature_schema_version'] ?? null) !== ShadowFeatureBuilder::SCHEMA_VERSION
                || ($metadata['features'] ?? null) !== ShadowFeatureBuilder::FEATURES
                || (int) ($metadata['horizon_minutes'] ?? 0) !== $horizon || ($metadata['symbol'] ?? null) !== $symbol) {
                throw new RuntimeException('Trained model schema/scope does not match the current feature builder, symbol and horizon.');
            }
            $model = $this->registry->registerCandidate($model, $metadata);

            $decision = $this->policy->decide($metadata, $champion !== null);
            $comparison = $decision['checks'] + ['champion_model_version' => $champion?->model_version];
            $result = $decision['promote']
                ? $this->registry->promote($model, $decision['reason'], $comparison)
                : $this->registry->reject($model, $decision['reason'], $comparison);

            Log::info('ml.shadow.training_decision', [
                'symbol' => $symbol, 'horizon_minutes' => $horizon, 'model_version' => $result->model_version,
                'status' => $result->status, 'reason' => $decision['reason'], 'champion_model_version' => $champion?->model_version,
            ]);
            @unlink($export['data']);

            return $result;
        } catch (Throwable $exception) {
            $model->forceFill(['status' => 'FAILED', 'decision_reason' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            Log::warning('ml.shadow.training_failed', ['symbol' => $symbol, 'horizon_minutes' => $horizon, 'error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    /** @return array{data:string, manifest:string, rows:int} */
    public function export(string $symbol, int $horizon, string $timeframe, string $name): array
    {
        $schema = ShadowFeatureBuilder::SCHEMA_VERSION;
        $directory = (string) config('ml.training.export_dir');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ML export directory.');
        }

        $base = DB::table('ml_labels as l')
            ->join('ml_feature_rows as f', function ($join): void {
                $join->on('f.symbol', '=', 'l.symbol')->on('f.timeframe', '=', 'l.timeframe')
                    ->on('f.anchor_time_ms', '=', 'l.anchor_time_ms')->on('f.feature_schema_version', '=', 'l.feature_schema_version');
            })
            // Same symbol, same horizon, same schema only — never mixed.
            ->where('l.symbol', $symbol)->where('l.timeframe', $timeframe)->where('l.horizon_minutes', $horizon)
            ->where('l.status', 'MATURE')->where('l.feature_schema_version', $schema);

        $total = (clone $base)->count();
        $max = max(1, (int) config('ml.training.max_training_rows', 150000));
        if ($total > $max) {
            $from = (clone $base)->orderBy('l.anchor_time_ms')->offset($total - $max)->limit(1)->value('l.anchor_time_ms');
            $base->where('l.anchor_time_ms', '>=', (int) $from);
        }

        $dataPath = $directory.DIRECTORY_SEPARATOR.$name.'.jsonl';
        $handle = fopen($dataPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to write ML training export.');
        }
        $rows = 0;
        try {
            foreach ((clone $base)->orderBy('l.anchor_time_ms')->select(['l.anchor_time_ms', 'l.future_return', 'f.features'])->cursor() as $row) {
                $features = json_decode((string) $row->features, true, 512, JSON_THROW_ON_ERROR);
                fwrite($handle, json_encode([
                    't' => (int) $row->anchor_time_ms,
                    'x' => array_map(static fn (string $f): float => (float) $features[$f], ShadowFeatureBuilder::FEATURES),
                    'y' => (float) $row->future_return,
                ], JSON_THROW_ON_ERROR)."\n");
                $rows++;
            }
        } finally {
            fclose($handle);
        }

        $costs = $this->costModel->costs($this->costModel->resolveFeeModelForMode('live'));
        $manifestPath = $directory.DIRECTORY_SEPARATOR.$name.'.manifest.json';
        file_put_contents($manifestPath, json_encode([
            'symbol' => $symbol, 'horizon_minutes' => $horizon, 'timeframe' => $timeframe,
            'horizon_steps' => intdiv($horizon * 60_000, MlCandleCollector::intervalMs($timeframe)),
            'feature_schema_version' => $schema, 'features' => ShadowFeatureBuilder::FEATURES, 'rows' => $rows,
            'cost_pct' => $costs['cost_pct'], 'required_move_pct' => $costs['required_move_pct'],
            'cost_source' => 'QuickTradeCostModel:'.$costs['fee_model'].':'.$costs['fee_rate_source'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['data' => $dataPath, 'manifest' => $manifestPath, 'rows' => $rows];
    }

    /**
     * @param  array{data:string, manifest:string, rows:int}  $export
     * @return array<string, mixed>
     */
    protected function runTrainer(array $export, string $directory, ?MlModel $champion): array
    {
        $python = (string) config('ml.training.python_bin');
        $script = (string) config('ml.training.script');
        if (! is_file($python) || ! is_file($script)) {
            throw new RuntimeException('ML trainer is not installed; check AUTOFINX_ML_PYTHON_BIN and external-predictive-engine/train_shadow_horizon.py.');
        }
        $command = [$python, $script, '--input', $export['data'], '--manifest', $export['manifest'], '--output-dir', $directory];
        if ($champion && is_dir((string) $champion->artifact_dir)) {
            $command[] = '--champion-dir';
            $command[] = $champion->artifact_dir;
        }

        $process = new Process($command, base_path(), null, null, max(60, (int) config('ml.training.timeout_seconds', 1800)));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('ML shadow training failed: '.mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), -1500));
        }

        $metadataPath = $directory.DIRECTORY_SEPARATOR.'metadata.json';
        if (! is_file($metadataPath)) {
            throw new RuntimeException('ML shadow trainer produced no metadata.');
        }
        return json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
    }
}
