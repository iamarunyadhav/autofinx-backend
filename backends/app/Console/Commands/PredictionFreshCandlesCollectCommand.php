<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionCandleCollector;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshCandlesCollectCommand extends Command
{
    protected $signature = 'prediction:fresh-candles:collect {dataset : Dataset UUID or name} {--limit=1000}';
    protected $description = 'Collect only new, closed Binance Futures candles into an isolated prediction dataset.';

    public function handle(FreshPredictionCandleCollector $collector): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) { $this->error('Fresh prediction dataset not found.'); return self::FAILURE; }
        try {
            $result = $collector->collect($dataset, (int) $this->option('limit'));
            $this->info("Collected {$result['written']} closed candles; skipped {$result['skipped_open']} open; rejected {$result['invalid']} invalid.");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
