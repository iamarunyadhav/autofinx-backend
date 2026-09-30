<?php

namespace App\Jobs;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Domain\Prediction\FreshData\FreshPredictionModelTrainer;
use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshModelCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queue-only sealed snapshot trainer. It has no live trading dependencies and
 * cannot publish, enable influence, or place an order.
 */
final class TrainFreshPredictionModelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $datasetId,
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly int $horizon = 12,
    ) {
        $this->onQueue((string) config('prediction_fresh_data.training_queue', 'analytics'));
    }

    public function uniqueId(): string
    {
        return implode(':', ['fresh-prediction-train', $this->datasetId, strtoupper($this->symbol), strtolower($this->timeframe)]);
    }

    public function backoff(): array
    {
        return [120];
    }

    public function handle(FreshPredictionDatasetAuditService $audits, FreshPredictionModelTrainer $trainer): void
    {
        $dataset = PredictionFreshDataset::query()->find($this->datasetId);
        if (! $dataset || $dataset->status !== 'SEALED') {
            return;
        }

        $symbol = strtoupper($this->symbol);
        $timeframe = strtolower($this->timeframe);
        $pair = collect($audits->report($dataset)['pairs'])
            ->first(fn (array $item): bool => $item['symbol'] === $symbol && $item['timeframe'] === $timeframe);
        if (($pair['state'] ?? null) !== 'READY_FOR_TRAINING') {
            throw new RuntimeException('Sealed training pair no longer passes its quality audit.');
        }

        if (PredictionFreshModelCandidate::query()->where([
            'dataset_id' => $dataset->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'provider' => 'lightgbm',
        ])->exists()) {
            return;
        }

        $candidate = $trainer->train($dataset, $symbol, $timeframe, $this->horizon);
        Log::info('[FreshPredictionTraining] sealed snapshot evaluated', [
            'dataset_id' => $dataset->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'candidate_id' => $candidate->id,
            'status' => $candidate->status,
            'execution' => false,
            'influence' => false,
        ]);
    }
}
