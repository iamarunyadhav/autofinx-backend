<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionModelTrainer;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshModelTrainCommand extends Command
{
    protected $signature = 'prediction:fresh-model:train {dataset : Sealed dataset UUID or name} {symbol} {timeframe} {--horizon=12}';
    protected $description = 'Train an isolated fresh-data model and register it only if chronological quality gates pass.';

    public function handle(FreshPredictionModelTrainer $trainer): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->error('Fresh prediction dataset not found.');
            return self::FAILURE;
        }

        try {
            $candidate = $trainer->train(
                $dataset,
                (string) $this->argument('symbol'),
                (string) $this->argument('timeframe'),
                (int) $this->option('horizon'),
            );
            $this->info("Candidate {$candidate->id}: {$candidate->status}");
            $this->line('Registry entries remain SHADOW_ONLY; this command cannot activate execution or influence.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
