<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\RetrainEligibility;
use App\Jobs\TrainMlShadowModelJob;
use Illuminate\Support\Facades\Log;

final class MlRetrainCheckCommand extends MlShadowCommand
{
    protected $signature = 'ml:retrain:check {--symbol=*} {--dry-run : Report eligibility without dispatching} {--force}';

    protected $description = 'Daily: rebuild features/labels, then queue a training job for each symbol x horizon with enough NEW mature labels.';

    public function handle(FeatureLabelService $features, RetrainEligibility $eligibility): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        foreach ($this->symbols() as $symbol) {
            // Picks up any history a backfill added behind the newest anchor.
            $features->build($symbol, full: true);
            $features->mature($symbol);
            foreach ($features->horizons() as $horizon) {
                $check = $eligibility->check($symbol, $horizon);
                $this->line(sprintf('%s h%d: %s (mature %d, new %d, required %d)', $symbol, $horizon, $check['reason'],
                    $check['mature_total'], $check['new_mature'], $check['required']));
                Log::info('ml.shadow.retrain_check', ['symbol' => $symbol, 'horizon_minutes' => $horizon] + $check);
                if ($check['eligible'] && ! $this->option('dry-run')) {
                    TrainMlShadowModelJob::dispatch($symbol, $horizon);
                    $this->info('  queued TrainMlShadowModelJob on queue '.config('ml.training.queue'));
                }
            }
        }

        return self::SUCCESS;
    }
}
