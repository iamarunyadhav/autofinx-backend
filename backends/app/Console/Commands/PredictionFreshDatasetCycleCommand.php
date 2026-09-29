<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionCandleCollector;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PredictionFreshDatasetCycleCommand extends Command
{
    protected $signature = 'prediction:fresh-dataset:cycle {dataset : Dataset UUID or name} {--limit=3 : Bounded candles fetched per pair} {--repair-max-gaps=10 : Bounded internal gaps repaired per cycle}';
    protected $description = 'One lock-protected fresh dataset collection, repair and audit cycle; never trains or activates a model.';

    public function handle(FreshPredictionCandleCollector $collector, FreshPredictionDatasetAuditService $audits): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) {
            $this->error('Fresh prediction dataset not found.');
            return self::FAILURE;
        }
        $lock = Cache::lock('prediction:fresh-dataset:cycle:'.$dataset->id, 240);
        if (! $lock->get()) {
            $this->line('Fresh dataset cycle already running; skipped.');
            return self::SUCCESS;
        }

        try {
            $collected = $collector->collect($dataset, max(1, min(1000, (int) $this->option('limit'))));
            $repaired = $collector->repairGaps($dataset, max(1, min(100, (int) $this->option('repair-max-gaps'))));
            $report = $audits->report($dataset);
            $this->info("Collected {$collected['written']} closed candles; repaired {$repaired['repaired']} gaps; ready pairs {$report['ready_pairs']}/{$report['total_pairs']}.");
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Fresh dataset cycle failed: '.$exception->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
