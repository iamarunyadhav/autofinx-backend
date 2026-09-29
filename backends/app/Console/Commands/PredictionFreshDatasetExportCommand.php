<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetExportService;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshDatasetExportCommand extends Command
{
    protected $signature = 'prediction:fresh-dataset:export {dataset : Dataset UUID or name} {symbol} {timeframe}';
    protected $description = 'Export a sealed isolated fresh dataset pair to an immutable training snapshot.';

    public function handle(FreshPredictionDatasetExportService $exports): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->error('Fresh prediction dataset not found.');
            return self::FAILURE;
        }
        try {
            $result = $exports->export($dataset, (string) $this->argument('symbol'), (string) $this->argument('timeframe'));
            $this->info("Exported {$result['rows']} candles to {$result['path']}");
            $this->line("SHA-256: {$result['sha256']}");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
