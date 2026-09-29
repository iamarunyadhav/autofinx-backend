<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class PredictionFreshDatasetReadinessCommand extends Command
{
    protected $signature = 'prediction:fresh-dataset:readiness {dataset : Dataset UUID or name} {--json : Emit machine-readable readiness}';
    protected $description = 'Read-only readiness check for the fresh candle collection pipeline; never trains or activates models.';

    public function handle(FreshPredictionDatasetAuditService $audits): int
    {
        $tables = ['prediction_fresh_datasets', 'prediction_fresh_dataset_candles', 'prediction_fresh_model_candidates', 'prediction_fresh_model_registry'];
        $missing = array_values(array_filter($tables, static fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $payload = ['ready' => false, 'reason' => 'migration_pending', 'missing_tables' => $missing, 'execution' => false, 'influence' => false];
            $this->emit($payload);
            return self::FAILURE;
        }
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->emit(['ready' => false, 'reason' => 'dataset_not_found', 'execution' => false, 'influence' => false]);
            return self::FAILURE;
        }

        $audit = $audits->report($dataset);
        $python = (string) config('prediction_fresh_data.python_bin');
        $payload = [
            'ready' => true,
            'dataset' => ['id' => $dataset->id, 'name' => $dataset->name, 'status' => $dataset->status],
            'quality' => ['ready_pairs' => $audit['ready_pairs'], 'total_pairs' => $audit['total_pairs'], 'pairs' => $audit['pairs']],
            'trainer_installed' => is_file($python) && is_file(base_path('external-predictive-engine/train_lightgbm.py')),
            'execution' => false,
            'influence' => false,
        ];
        $this->emit($payload);
        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return;
        }
        $this->line('Pipeline: '.(($payload['ready'] ?? false) ? 'READY' : 'NOT READY'));
        $this->line('Reason: '.($payload['reason'] ?? 'readiness_checked'));
        if (isset($payload['dataset'])) {
            $this->line('Dataset: '.$payload['dataset']['name'].' · '.$payload['dataset']['status']);
            $this->line('Quality pairs: '.$payload['quality']['ready_pairs'].'/'.$payload['quality']['total_pairs']);
        }
        $this->line('Execution: false · Influence: false');
    }
}
