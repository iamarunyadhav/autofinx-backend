<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetExportService;
use App\Domain\Prediction\FreshData\FreshPredictionModelRegistryService;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class PredictionFreshModelTrainCommand extends Command
{
    protected $signature = 'prediction:fresh-model:train {dataset : Sealed dataset UUID or name} {symbol} {timeframe} {--horizon=12}';
    protected $description = 'Train an isolated fresh-data model and register it only if chronological quality gates pass.';

    public function handle(FreshPredictionDatasetExportService $exports, FreshPredictionModelRegistryService $registry): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->error('Fresh prediction dataset not found.');
            return self::FAILURE;
        }

        try {
            $symbol = strtoupper((string) $this->argument('symbol'));
            $timeframe = strtolower((string) $this->argument('timeframe'));
            $export = $exports->export($dataset, $symbol, $timeframe);
            $directory = storage_path('app/prediction-fresh-models/'.$dataset->id);
            $artifact = $directory.'/'.$symbol.'-'.$timeframe.'.txt';
            $python = (string) config('prediction_fresh_data.python_bin');
            $script = base_path('external-predictive-engine/train_lightgbm.py');
            if (! is_file($python) || ! is_file($script)) {
                throw new RuntimeException('Fresh trainer is not installed; check AUTOFINX_FRESH_PYTHON_BIN and external-predictive-engine deployment.');
            }

            $process = new Process([
                $python, $script, '--input', $export['path'], '--output', $artifact,
                '--timeframe', $timeframe, '--horizon', (string) max(1, min(720, (int) $this->option('horizon'))),
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
            $candidate = $registry->record($dataset, $symbol, $timeframe, $artifact, $metadata);
            $this->info("Candidate {$candidate->id}: {$candidate->status}");
            $this->line('Registry entries remain SHADOW_ONLY; this command cannot activate execution or influence.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
