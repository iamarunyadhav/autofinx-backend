<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionCandleCollector;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshCandlesRepairCommand extends Command
{
    protected $signature = 'prediction:fresh-candles:repair {dataset : Dataset UUID or name} {--max-gaps=50}';
    protected $description = 'Repair internal gaps in an isolated fresh dataset from Binance Futures closed candles.';

    public function handle(FreshPredictionCandleCollector $collector): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->error('Fresh prediction dataset not found.');
            return self::FAILURE;
        }

        try {
            $result = $collector->repairGaps($dataset, max(1, min(500, (int) $this->option('max-gaps'))));
            $this->info("Requested {$result['requested']}; repaired {$result['repaired']}; unavailable {$result['unavailable']}; rejected {$result['invalid']}.");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
