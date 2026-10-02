<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlChampion;
use App\Models\MlModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Champion/candidate registry for shadow models.
 *
 * Every training attempt is its own immutable ml_models row and artifact
 * directory. Promotion never overwrites an artifact: it stages copies next to
 * the champion slot, verifies their checksums, flips the DB pointer in a
 * transaction, then renames the copies into place — model files first, the
 * manifest JSON (which the python service keys on) last. The replaced
 * champion becomes RETIRED and stays available for rollback.
 */
class MlModelRegistry
{
    public const ARTIFACT_FILES = ['model' => 'model.txt', 'q10' => 'q10.txt', 'q90' => 'q90.txt'];

    public const CLASSIFIER_ARTIFACT_FILES = ['buy' => 'buy.txt', 'sell' => 'sell.txt'];

    /** @return array<string, string> role => file for this model's type */
    public static function artifactFiles(MlModel $model): array
    {
        return ($model->model_type ?? 'regression') === MlShadowTrainer::TYPE_SIDE_CLASSIFIER
            ? self::CLASSIFIER_ARTIFACT_FILES
            : self::ARTIFACT_FILES;
    }

    public function champion(string $symbol, int $horizon): ?MlModel
    {
        $pointer = MlChampion::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->first();

        return $pointer ? MlModel::query()->find($pointer->model_id) : null;
    }

    /** @param array<string, mixed> $metadata */
    public function registerCandidate(MlModel $model, array $metadata): MlModel
    {
        $checksums = [];
        foreach (self::artifactFiles($model) as $role => $file) {
            $path = $model->artifact_dir.DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                throw new RuntimeException("Trainer did not produce {$file}.");
            }
            $checksums[$role] = hash_file('sha256', $path);
        }
        $metadataPath = $model->artifact_dir.DIRECTORY_SEPARATOR.'metadata.json';
        $checksums['metadata'] = hash_file('sha256', $metadataPath);

        $model->forceFill([
            'status' => 'CANDIDATE',
            // Version identity is the artifact content itself: a different
            // model can never inherit another model's live evaluation.
            'model_version' => 'qtml-'.substr($checksums['model'] ?? hash('sha256', $checksums['buy'].$checksums['sell']), 0, 12).'-'.substr(str_replace('-', '', (string) $model->id), 0, 6),
            'training_window_from_ms' => (int) data_get($metadata, 'training_window_from_ms'),
            'training_window_to_ms' => (int) data_get($metadata, 'training_window_to_ms'),
            'total_samples' => (int) data_get($metadata, 'rows', 0),
            'train_samples' => (int) data_get($metadata, 'train_rows', 0),
            'holdout_samples' => (int) data_get($metadata, 'holdout_rows', 0),
            'metrics' => ($metadata['metrics'] ?? []) + [
                'holdout_from_ms' => (int) data_get($metadata, 'holdout_from_ms'),
                'holdout_to_ms' => (int) data_get($metadata, 'holdout_to_ms'),
                'best_iteration' => data_get($metadata, 'best_iteration'),
                'cost_assumptions' => data_get($metadata, 'cost_assumptions'),
                // Side classifiers: expected-value params the live decision uses
                // (calibration maps themselves are served from the manifest).
                'decision_params' => is_array($metadata['sides'] ?? null) ? [
                    'probability_floor' => $metadata['probability_floor'] ?? null,
                    'buy' => array_intersect_key((array) $metadata['sides']['buy'], array_flip(['avg_win_pct', 'avg_other_pct', 'base_rate'])),
                    'sell' => array_intersect_key((array) $metadata['sides']['sell'], array_flip(['avg_win_pct', 'avg_other_pct', 'base_rate'])),
                ] : null,
            ],
            'artifact_checksums' => $checksums,
            'trained_at' => now('UTC'),
        ])->save();

        return $model->refresh();
    }

    /** @param array<string, mixed> $comparison */
    public function reject(MlModel $model, string $reason, array $comparison = []): MlModel
    {
        $model->forceFill(['status' => 'REJECTED', 'decision_reason' => $reason, 'comparison' => $comparison])->save();

        return $model;
    }

    /** @param array<string, mixed> $comparison */
    public function promote(MlModel $model, string $reason, array $comparison = []): MlModel
    {
        if (! in_array($model->status, ['CANDIDATE', 'RETIRED'], true)) {
            throw new InvalidArgumentException("Only a CANDIDATE or RETIRED model can become champion (got {$model->status}).");
        }
        $this->assertIntegrity($model);
        $staged = $this->stage($model);

        try {
            DB::transaction(function () use ($model, $reason, $comparison): void {
                $pointer = MlChampion::query()->where('symbol', $model->symbol)->where('horizon_minutes', $model->horizon_minutes)->lockForUpdate()->first();
                $previousId = $pointer?->model_id;
                if ($previousId && $previousId !== $model->id) {
                    MlModel::query()->whereKey($previousId)->where('status', 'CHAMPION')
                        ->update(['status' => 'RETIRED', 'retired_at' => now('UTC'), 'updated_at' => now('UTC')]);
                }
                MlChampion::query()->updateOrCreate(
                    ['symbol' => $model->symbol, 'horizon_minutes' => $model->horizon_minutes],
                    ['model_id' => $model->id, 'previous_model_id' => $previousId !== $model->id ? $previousId : $pointer?->previous_model_id],
                );
                $model->forceFill([
                    'status' => 'CHAMPION', 'decision_reason' => $reason, 'comparison' => $comparison ?: $model->comparison,
                    'previous_champion_id' => $previousId !== $model->id ? $previousId : $model->previous_champion_id,
                    'promoted_at' => now('UTC'), 'retired_at' => null,
                ])->save();
            });
        } catch (\Throwable $exception) {
            foreach ($staged as $temporary => $target) {
                @unlink($temporary);
            }
            throw $exception;
        }

        foreach ($staged as $temporary => $target) {
            if (! rename($temporary, $target)) {
                throw new RuntimeException("Unable to publish champion artifact {$target}.");
            }
        }

        return $model->refresh();
    }

    /** Re-instate the previous champion (kept as RETIRED). */
    public function rollback(string $symbol, int $horizon): MlModel
    {
        $pointer = MlChampion::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->first();
        if (! $pointer || ! $pointer->previous_model_id) {
            throw new InvalidArgumentException("No previous champion to roll back to for {$symbol} h{$horizon}.");
        }
        $previous = MlModel::query()->findOrFail($pointer->previous_model_id);
        $current = MlModel::query()->find($pointer->model_id);
        $olderChampionId = $previous->previous_champion_id;
        $promoted = $this->promote($previous, 'manual_rollback');
        if ($current) {
            $current->forceFill(['status' => 'RETIRED', 'retired_at' => now('UTC'), 'decision_reason' => 'rolled_back'])->save();
        }
        // Rolling back walks one step down the champion history; it never
        // bounces straight back to the model that was just rolled back.
        MlChampion::query()->whereKey($pointer->id)->update(['previous_model_id' => $olderChampionId]);
        $promoted->forceFill(['previous_champion_id' => $olderChampionId])->save();

        return $promoted;
    }

    public static function championKey(string $symbol, int $horizon): string
    {
        return strtoupper($symbol).'-h'.$horizon;
    }

    private function assertIntegrity(MlModel $model): void
    {
        foreach (self::artifactFiles($model) + ['metadata' => 'metadata.json'] as $role => $file) {
            $path = $model->artifact_dir.DIRECTORY_SEPARATOR.$file;
            $expected = (string) data_get($model->artifact_checksums, $role);
            if ($expected === '' || ! is_file($path) || hash_file('sha256', $path) !== $expected) {
                throw new RuntimeException("Artifact integrity check failed for {$model->model_version} ({$role}).");
            }
        }
    }

    /** @return array<string, string> temporary path => final path, manifest last */
    private function stage(MlModel $model): array
    {
        $directory = (string) config('ml.training.champion_dir');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create champion directory.');
        }
        $key = self::championKey($model->symbol, (int) $model->horizon_minutes);
        $staged = [];
        $files = [];
        foreach (self::artifactFiles($model) as $role => $file) {
            // Version-specific file names: a reader holding the old manifest
            // keeps resolving the old files until the new manifest lands.
            $name = $key.'.'.$model->model_version.'.'.$file;
            $target = $directory.DIRECTORY_SEPARATOR.$name;
            $temporary = $target.'.tmp';
            if (! copy($model->artifact_dir.DIRECTORY_SEPARATOR.$file, $temporary)
                || hash_file('sha256', $temporary) !== data_get($model->artifact_checksums, $role)) {
                @unlink($temporary);
                throw new RuntimeException("Unable to stage champion artifact {$name}.");
            }
            $staged[$temporary] = $target;
            $files[$role] = ['file' => $name, 'sha256' => data_get($model->artifact_checksums, $role)];
        }

        $metadata = json_decode((string) file_get_contents($model->artifact_dir.DIRECTORY_SEPARATOR.'metadata.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifestPath = $directory.DIRECTORY_SEPARATOR.$key.'.json';
        $manifestTemporary = $manifestPath.'.tmp';
        file_put_contents($manifestTemporary, json_encode([
            'model_version' => $model->model_version,
            'model_id' => $model->id,
            'symbol' => $model->symbol,
            'horizon_minutes' => (int) $model->horizon_minutes,
            'feature_schema_version' => $model->feature_schema_version,
            'features' => $metadata['features'] ?? [],
            'target_unit' => $metadata['target_unit'] ?? 'return_pct',
            'model_type' => $model->model_type ?? 'regression',
            // Classifier serving needs its calibration maps and EV params.
            'sides' => $metadata['sides'] ?? null,
            'probability_floor' => $metadata['probability_floor'] ?? null,
            'files' => $files,
            'execution' => false,
            'influence' => false,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $staged[$manifestTemporary] = $manifestPath;

        return $staged;
    }
}
