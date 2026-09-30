<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshModelCandidate;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs an isolated, sealed-snapshot training attempt.
 *
 * This service never publishes a model, changes predictor configuration, or
 * influences an execution decision. Publication remains a separate explicit
 * shadow-only operation.
 */
final class FreshPredictionModelTrainer
{
    public function __construct(
        private readonly FreshPredictionDatasetExportService $exports,
        private readonly FreshPredictionModelRegistryService $registry,
    ) {}

    public function train(PredictionFreshDataset $dataset, string $symbol, string $timeframe, int $horizon = 12): PredictionFreshModelCandidate
    {
        $symbol = strtoupper(trim($symbol));
        $timeframe = strtolower(trim($timeframe));
        $export = $this->exports->export($dataset, $symbol, $timeframe);
        $directory = storage_path('app/prediction-fresh-models/'.$dataset->id);
        $artifact = $directory.'/'.$symbol.'-'.$timeframe.'.txt';
        $python = (string) config('prediction_fresh_data.python_bin');
        $script = base_path('external-predictive-engine/train_lightgbm.py');

        if (! is_file($python) || ! is_file($script)) {
            throw new RuntimeException('Fresh trainer is not installed; check AUTOFINX_FRESH_PYTHON_BIN and external-predictive-engine deployment.');
        }

        $process = new Process([
            $python, $script, '--input', $export['path'], '--output', $artifact,
            '--timeframe', $timeframe, '--horizon', (string) max(1, min(720, $horizon)),
            '--fee-bps-per-leg', (string) config('prediction_fresh_data.costs.fee_bps_per_leg', 5),
            '--slippage-bps', (string) config('prediction_fresh_data.costs.slippage_bps_round_trip', 4),
            '--spread-bps', (string) config('prediction_fresh_data.costs.spread_bps_round_trip', 2),
            '--funding-bps-per-interval', (string) config('prediction_fresh_data.costs.funding_bps_per_8h', 0),
            '--minimum-edge-bps', (string) config('prediction_fresh_data.costs.minimum_edge_bps', 2),
        ], base_path(), null, null, max(60, (int) config('prediction_fresh_data.training_timeout_seconds', 600)));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Fresh model training failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $metadataPath = preg_replace('/\\.txt$/', '.json', $artifact);
        if (! is_string($metadataPath) || ! is_file($metadataPath)) {
            throw new RuntimeException('Fresh model training did not produce metadata.');
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        // Artifact identity is immutable and model-specific. The live shadow
        // outcome report keys on this value so a replacement model can never
        // inherit accuracy observations from its predecessor.
        $metadata['model_version'] = 'fresh-'.substr((string) hash_file('sha256', $artifact), 0, 16);
        file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $this->registry->record($dataset, $symbol, $timeframe, $artifact, $metadata);
    }
}
