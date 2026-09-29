<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshModelCandidate;
use App\Models\PredictionFreshModelRegistry;
use InvalidArgumentException;

final class FreshPredictionModelRegistryService
{
    /** @param array<string, mixed> $metadata */
    public function record(PredictionFreshDataset $dataset, string $symbol, string $timeframe, string $artifactPath, array $metadata): PredictionFreshModelCandidate
    {
        if ($dataset->status !== 'SEALED') {
            throw new InvalidArgumentException('A model candidate requires a sealed fresh dataset.');
        }
        if (! is_file($artifactPath)) {
            throw new InvalidArgumentException('Model artifact is missing.');
        }
        $symbol = strtoupper($symbol);
        $timeframe = strtolower($timeframe);
        $passed = (bool) data_get($metadata, 'quality_gate.passed', false);
        $candidate = PredictionFreshModelCandidate::query()->updateOrCreate([
            'dataset_id' => $dataset->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'provider' => 'lightgbm',
        ], [
            'status' => $passed ? 'QUALITY_PASSED' : 'QUALITY_REJECTED',
            'artifact_path' => $artifactPath,
            'artifact_sha256' => hash_file('sha256', $artifactPath),
            'metrics' => $metadata,
        ]);

        if (! $passed) {
            return $candidate;
        }

        PredictionFreshModelRegistry::query()->updateOrCreate([
            'symbol' => $symbol, 'timeframe' => $timeframe, 'provider' => 'lightgbm',
        ], [
            'candidate_id' => $candidate->id,
            'status' => 'SHADOW_ONLY',
            'execution' => false,
            'influence' => false,
        ]);
        $candidate->forceFill(['status' => 'SHADOW_REGISTERED'])->save();

        return $candidate->refresh();
    }
}
