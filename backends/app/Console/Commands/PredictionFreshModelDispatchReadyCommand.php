<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Jobs\TrainFreshPredictionModelJob;
use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshModelCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class PredictionFreshModelDispatchReadyCommand extends Command
{
    protected $signature = 'prediction:fresh-model:dispatch-ready {--dataset= : Optional sealed dataset UUID or name} {--horizon=12}';
    protected $description = 'Queue missing model evaluations for audit-approved sealed fresh snapshots; never publishes or activates a model.';

    public function handle(FreshPredictionDatasetAuditService $audits): int
    {
        $lock = Cache::lock('prediction:fresh-model:dispatch-ready', 300);
        if (! $lock->get()) {
            $this->line('Fresh model dispatch already running; skipped.');
            return self::SUCCESS;
        }

        try {
            $query = PredictionFreshDataset::query()->where('status', 'SEALED')->orderBy('started_at');
            if ($token = trim((string) $this->option('dataset'))) {
                $query->where(fn ($builder) => $builder->where('id', $token)->orWhere('name', $token));
            }

            $queued = 0;
            foreach ($query->get() as $dataset) {
                foreach ($audits->report($dataset)['pairs'] as $pair) {
                    if ($pair['state'] !== 'READY_FOR_TRAINING') {
                        continue;
                    }
                    $exists = PredictionFreshModelCandidate::query()->where([
                        'dataset_id' => $dataset->id,
                        'symbol' => $pair['symbol'],
                        'timeframe' => $pair['timeframe'],
                        'provider' => 'lightgbm',
                    ])->exists();
                    if ($exists) {
                        continue;
                    }

                    TrainFreshPredictionModelJob::dispatch(
                        $dataset->id,
                        $pair['symbol'],
                        $pair['timeframe'],
                        max(1, min(720, (int) $this->option('horizon'))),
                    );
                    $queued++;
                }
            }

            $this->info("Queued {$queued} sealed fresh-model evaluation job(s). Shadow-only; no publication or execution change.");
            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
