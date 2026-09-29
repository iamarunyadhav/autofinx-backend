<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetService;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshDatasetStartCommand extends Command
{
    protected $signature = 'prediction:fresh-dataset:start {name} {--symbols=BTCUSDT,ETHUSDT,SOLUSDT,BNBUSDT} {--timeframes=5m,15m,1h}';
    protected $description = 'Start an isolated, Binance Futures, closed-candle-only prediction dataset.';

    public function handle(FreshPredictionDatasetService $datasets): int
    {
        try {
            $dataset = $datasets->start($this->argument('name'), explode(',', (string) $this->option('symbols')), explode(',', (string) $this->option('timeframes')));
            $this->info("Started {$dataset->name} ({$dataset->id}) at {$dataset->started_at->toIso8601String()}");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
