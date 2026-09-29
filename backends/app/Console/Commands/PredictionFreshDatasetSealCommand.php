<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetService;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshDatasetSealCommand extends Command
{
    protected $signature = 'prediction:fresh-dataset:seal {dataset : Dataset UUID or name}';
    protected $description = 'Freeze a fully-audited fresh dataset before it can be exported for training.';

    public function handle(FreshPredictionDatasetService $datasets, FreshPredictionDatasetAuditService $audits): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->error('Fresh prediction dataset not found.');
            return self::FAILURE;
        }
        try {
            $sealed = $datasets->seal($dataset, $audits->report($dataset));
            $this->info("Sealed {$sealed->name} ({$sealed->id}). Collection is now locked for this dataset version.");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
