<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Trading\QuickTradeCostModel;
use App\Models\MlModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One training attempt for one symbol × horizon × candidate type:
 * export mature rows (+ per-row funding) → python trainer → CANDIDATE →
 * type-specific promotion policy → CHAMPION or REJECTED. Any failure records
 * a FAILED row and leaves the current champion exactly as it was.
 */
class MlShadowTrainer
{
    public const TYPE_REGRESSION = 'regression';

    public const TYPE_SIDE_CLASSIFIER = 'side_classifier';

    public const TYPES = [self::TYPE_REGRESSION, self::TYPE_SIDE_CLASSIFIER];

    public function __construct(
        private readonly MlModelRegistry $registry,
        private readonly PromotionPolicy $policy,
        private readonly ClassifierPromotionPolicy $classifierPolicy,
        private readonly QuickTradeCostModel $costModel,
        private readonly FundingRateService $funding,
    ) {}

    public static function lockKey(string $symbol, int $horizon): string
    {
        return 'ml:shadow:train:'.strtoupper($symbol).':h'.$horizon;
    }

    /** @return list<string> */
    public static function configuredTypes(): array
    {
        $types = array_values(array_intersect((array) config('ml.training.candidate_types', [self::TYPE_SIDE_CLASSIFIER]), self::TYPES));

        return $types === [] ? [self::TYPE_SIDE_CLASSIFIER] : $types;
    }

    public function train(string $symbol, int $horizon, string $type = self::TYPE_REGRESSION): MlModel
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown candidate type: {$type}");
        }
        $lock = Cache::lock(self::lockKey($symbol, $horizon), (int) config('ml.training.timeout_seconds', 1800) + 300);
        if (! $lock->get()) {
            throw new RuntimeException("Training already running for {$symbol} h{$horizon}.");
        }

        try {
            return $this->trainLocked($symbol, $horizon, $type);
        } finally {
            $lock->release();
        }
    }

    private function trainLocked(string $symbol, int $horizon, string $type): MlModel
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $modelId = (string) Str::uuid();
        $directory = rtrim((string) config('ml.training.artifact_dir'), '/\\').DIRECTORY_SEPARATOR.MlModelRegistry::championKey($symbol, $horizon).DIRECTORY_SEPARATOR.$modelId;
        $model = MlModel::query()->create([
            'id' => $modelId, 'symbol' => $symbol, 'horizon_minutes' => $horizon, 'timeframe' => $timeframe,
            'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => $type, 'status' => 'TRAINING',
            'artifact_dir' => $directory,
        ]);

        try {
            $export = $this->export($symbol, $horizon, $timeframe, $modelId);
            $champion = $this->registry->champion($symbol, $horizon);
            $metadata = $this->runTrainer($export, $directory, $champion, $type);
            if (($metadata['feature_schema_version'] ?? null) !== ShadowFeatureBuilder::SCHEMA_VERSION
                || ($metadata['features'] ?? null) !== ShadowFeatureBuilder::FEATURES
                || ($metadata['model_type'] ?? self::TYPE_REGRESSION) !== $type
                || (int) ($metadata['horizon_minutes'] ?? 0) !== $horizon || ($metadata['symbol'] ?? null) !== $symbol) {
                throw new RuntimeException('Trained model schema/scope/type does not match the current feature builder, symbol, horizon and candidate type.');
            }
            $model = $this->registry->registerCandidate($model, $metadata);

            $decision = $type === self::TYPE_SIDE_CLASSIFIER
                ? $this->classifierPolicy->decide($metadata, $champion !== null, $horizon)
                : $this->policy->decide($metadata, $champion !== null);
            $comparison = $decision['checks'] + ['champion_model_version' => $champion?->model_version, 'champion_model_type' => $champion?->model_type];
            $result = $decision['promote']
                ? $this->registry->promote($model, $decision['reason'], $comparison)
                : $this->registry->reject($model, $decision['reason'], $comparison);

            Log::info('ml.shadow.training_decision', [
                'symbol' => $symbol, 'horizon_minutes' => $horizon, 'model_type' => $type, 'model_version' => $result->model_version,
                'status' => $result->status, 'reason' => $decision['reason'], 'champion_model_version' => $champion?->model_version,
            ]);
            @unlink($export['data']);

            return $result;
        } catch (Throwable $exception) {
            $model->forceFill(['status' => 'FAILED', 'decision_reason' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            Log::warning('ml.shadow.training_failed', ['symbol' => $symbol, 'horizon_minutes' => $horizon, 'model_type' => $type, 'error' => $exception->getMessage()]);

            throw $exception;
        }
    }

    /**
     * Experiment only: train the side classifier once per fitting window
     * (months; 0 = all history) on ONE export, so every window is scored on
     * the SAME newest-rows holdout and walk-forward folds. Nothing is
     * registered or promoted; the champion is untouched. The report is
     * written to storage/app/ml-shadow/experiments and returned.
     *
     * @param  list<int>  $windows
     * @return array<string, mixed>
     */
    public function compareWindows(string $symbol, int $horizon, array $windows, ?float $halfLifeDays = null): array
    {
        $lock = Cache::lock(self::lockKey($symbol, $horizon), (int) config('ml.training.timeout_seconds', 1800) * max(1, count($windows)) + 300);
        if (! $lock->get()) {
            throw new RuntimeException("Training already running for {$symbol} h{$horizon}.");
        }
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $runId = 'experiment-'.Str::uuid();
        $export = null;
        $halfLife = max(0.0, $halfLifeDays ?? (float) config('ml.training.recency_half_life_days', 180));
        try {
            $export = $this->export($symbol, $horizon, $timeframe, $runId);
            $manifest = json_decode((string) file_get_contents($export['manifest']), true, 512, JSON_THROW_ON_ERROR);
            $results = [];
            foreach ($windows as $window) {
                $window = max(0, (int) $window);
                $directory = (string) config('ml.training.export_dir').DIRECTORY_SEPARATOR.$runId.'-w'.$window;
                $variantManifest = $directory.'.manifest.json';
                file_put_contents($variantManifest, json_encode(['window_months' => $window, 'recency_half_life_days' => $halfLife] + $manifest, JSON_THROW_ON_ERROR));
                try {
                    $metadata = $this->runTrainer(['manifest' => $variantManifest] + $export, $directory, null, self::TYPE_SIDE_CLASSIFIER);
                    $decision = $this->classifierPolicy->decide($metadata, false, $horizon);
                    $folds = (array) data_get($metadata, 'metrics.walk_forward.folds', []);
                    $results[] = [
                        'window_months' => $window,
                        'fitted_from' => gmdate('c', intdiv((int) ($metadata['training_window_from_ms'] ?? 0), 1000)),
                        'holdout' => array_intersect_key((array) data_get($metadata, 'metrics.holdout.non_overlapping', []),
                            array_flip(['trades', 'total_net_pct', 'average_net_pct', 'net_win_rate_pct', 't_stat'])),
                        'positive_folds' => count(array_filter($folds, static fn ($f) => (float) data_get($f, 'non_overlapping.total_net_pct', 0) > 0)).'/'.count($folds),
                        'would_pass_gate' => (bool) $decision['promote'],
                        'gate_reason' => $decision['reason'],
                    ];
                } catch (Throwable $exception) {
                    $results[] = ['window_months' => $window, 'error' => mb_substr($exception->getMessage(), 0, 500)];
                } finally {
                    @unlink($variantManifest);
                    File::deleteDirectory($directory);
                }
            }
            $report = [
                'symbol' => $symbol, 'horizon_minutes' => $horizon, 'recency_half_life_days' => $halfLife,
                'rows' => $export['rows'], 'run_at' => now('UTC')->toIso8601String(),
                'note' => 'Same holdout and folds for every window. Experiment only: nothing registered or promoted.',
                'results' => $results,
            ];
            $reportDir = storage_path('app/ml-shadow/experiments');
            File::ensureDirectoryExists($reportDir);
            file_put_contents($reportDir.DIRECTORY_SEPARATOR.sprintf('%s_h%d_%s.json', $symbol, $horizon, now('UTC')->format('Ymd_His')),
                json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report;
        } finally {
            if ($export !== null) {
                @unlink($export['data']);
                @unlink($export['manifest']);
            }
            $lock->release();
        }
    }

    /**
     * MATURE labels that have a feature row of $schema at the same anchor.
     * Same symbol, same horizon, one schema only — never mixed. Labels carry
     * no schema of their own (a future return is the same for every schema).
     */
    public static function matureTrainingRows(string $symbol, int $horizon, string $timeframe, string $schema): Builder
    {
        return DB::table('ml_labels as l')
            ->join('ml_feature_rows as f', function ($join) use ($schema): void {
                $join->on('f.symbol', '=', 'l.symbol')->on('f.timeframe', '=', 'l.timeframe')
                    ->on('f.anchor_time_ms', '=', 'l.anchor_time_ms')->where('f.feature_schema_version', '=', $schema);
            })
            ->where('l.symbol', $symbol)->where('l.timeframe', $timeframe)->where('l.horizon_minutes', $horizon)
            ->where('l.status', 'MATURE');
    }

    /**
     * Rows: t (anchor ms), x (features of $schema, inputs only), y (future
     * return, fraction) and fb (actual BUY-side funding over (T, T+h], %).
     * fb/y are outcomes for labels and P&L — never model inputs.
     *
     * @return array{data:string, manifest:string, rows:int}
     */
    public function export(string $symbol, int $horizon, string $timeframe, string $name, string $schema = ShadowFeatureBuilder::SCHEMA_VERSION): array
    {
        $directory = (string) config('ml.training.export_dir');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ML export directory.');
        }
        $names = ShadowFeatureBuilder::features($schema);
        $base = self::matureTrainingRows($symbol, $horizon, $timeframe, $schema);

        $total = (clone $base)->count();
        $max = max(1, (int) config('ml.training.max_training_rows', 150000));
        if ($total > $max) {
            $from = (clone $base)->orderBy('l.anchor_time_ms')->offset($total - $max)->limit(1)->value('l.anchor_time_ms');
            $base->where('l.anchor_time_ms', '>=', (int) $from);
        }

        $anchors = (clone $base)->orderBy('l.anchor_time_ms')->pluck('l.anchor_time_ms')->map(fn ($v) => (int) $v)->all();
        $fundingBuy = $this->funding->buyCostPct($symbol, $anchors, $horizon * 60_000);

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
                    'x' => array_map(static fn (string $f): float => (float) $features[$f], $names),
                    'y' => (float) $row->future_return,
                    'fb' => $fundingBuy[(int) $row->anchor_time_ms] ?? 0.0,
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
            'step_ms' => MlCandleCollector::intervalMs($timeframe),
            'feature_schema_version' => $schema, 'features' => $names, 'rows' => $rows,
            'cost_pct' => $costs['cost_pct'], 'required_move_pct' => $costs['required_move_pct'],
            'probability_floor' => (float) config('ml.classifier.probability_floor', 0.55),
            // Fitting window and recency weighting (side classifier); the
            // holdout is always the newest rows, whatever the window.
            'window_months' => max(0, (int) config('ml.training.window_months', 0)),
            'recency_half_life_days' => max(0.0, (float) config('ml.training.recency_half_life_days', 180)),
            'funding_rows_covered' => count(array_filter($fundingBuy, static fn (float $v): bool => $v !== 0.0)),
            'cost_source' => 'QuickTradeCostModel:'.$costs['fee_model'].':'.$costs['fee_rate_source'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['data' => $dataPath, 'manifest' => $manifestPath, 'rows' => $rows];
    }

    /**
     * @param  array{data:string, manifest:string, rows:int}  $export
     * @return array<string, mixed>
     */
    protected function runTrainer(array $export, string $directory, ?MlModel $champion, string $type = self::TYPE_REGRESSION): array
    {
        $python = (string) config('ml.training.python_bin');
        $script = (string) config($type === self::TYPE_SIDE_CLASSIFIER ? 'ml.training.classifier_script' : 'ml.training.script');
        if (! is_file($python) || ! is_file($script)) {
            throw new RuntimeException("ML trainer is not installed; check AUTOFINX_ML_PYTHON_BIN and {$script}.");
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
