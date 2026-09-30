<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshModelCandidate;
use App\Models\PredictionFreshModelRegistry;
use RuntimeException;

final class FreshPredictionShadowPublisher
{
    /** @return list<array{candidate_id:string, source:string, target:string, metadata_source:string, metadata_target:string}> */
    public function plan(): array
    {
        $directory = (string) config('prediction_fresh_data.shadow_model_dir');
        return PredictionFreshModelRegistry::query()->where('status', 'SHADOW_ONLY')->get()->map(function (PredictionFreshModelRegistry $entry) use ($directory): array {
            $candidate = PredictionFreshModelCandidate::query()->findOrFail($entry->candidate_id);
            $source = $candidate->artifact_path;
            if (! is_file($source) || hash_file('sha256', $source) !== $candidate->artifact_sha256) {
                throw new RuntimeException("Candidate artifact integrity check failed: {$candidate->id}");
            }
            $metadata = preg_replace('/\.txt$/', '.json', $source);
            if (! is_string($metadata) || ! is_file($metadata)) {
                throw new RuntimeException("Candidate metadata is missing: {$candidate->id}");
            }
            $filename = strtoupper($candidate->symbol).'-'.strtolower($candidate->timeframe).'.txt';
            return [
                'candidate_id' => $candidate->id,
                'source' => $source,
                'target' => rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename,
                'metadata_source' => $metadata,
                'metadata_target' => preg_replace('/\.txt$/', '.json', rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename),
            ];
        })->all();
    }

    /** @return list<array{candidate_id:string, source:string, target:string, metadata_source:string, metadata_target:string}> */
    public function publish(): array
    {
        $plan = $this->plan();
        foreach ($plan as $entry) {
            $directory = dirname($entry['target']);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create fresh shadow model directory.');
            }
            $this->copyAtomically($entry['source'], $entry['target']);
            $this->copyAtomically($entry['metadata_source'], $entry['metadata_target']);
        }
        return $plan;
    }

    /** @return list<array{candidate_id:string, source:string, target:string, metadata_source:string, metadata_target:string}> */
    public function publishCandidate(string $candidateId): array
    {
        $plan = array_values(array_filter($this->plan(), static fn (array $entry): bool => $entry['candidate_id'] === $candidateId));
        if ($plan === []) {
            throw new RuntimeException('Candidate is not the active shadow registry entry.');
        }
        foreach ($plan as $entry) {
            $directory = dirname($entry['target']);
            if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create fresh shadow model directory.');
            }
            $this->copyAtomically($entry['source'], $entry['target']);
            $this->copyAtomically($entry['metadata_source'], $entry['metadata_target']);
        }
        return $plan;
    }

    private function copyAtomically(string $source, string $target): void
    {
        $temporary = $target.'.tmp';
        if (! copy($source, $temporary) || ! rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish fresh shadow model artifact.');
        }
    }
}
