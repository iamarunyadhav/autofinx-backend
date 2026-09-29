<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionShadowPublisher;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshModelPublishShadowCommand extends Command
{
    protected $signature = 'prediction:fresh-model:publish-shadow {--apply : Copy quality-passed artifacts into the separate shadow directory}';
    protected $description = 'Publish registered quality-passed models to the shadow directory; never changes execution or predictor config.';

    public function handle(FreshPredictionShadowPublisher $publisher): int
    {
        try {
            $plan = $publisher->plan();
            if ($plan === []) {
                $this->line('No SHADOW_ONLY fresh model candidates are registered.');
                return self::SUCCESS;
            }
            foreach ($plan as $entry) {
                $this->line(($this->option('apply') ? 'Publishing' : 'Would publish')." {$entry['candidate_id']} -> {$entry['target']}");
            }
            if (! $this->option('apply')) {
                $this->line('Dry run only. Re-run with --apply after reviewing artifacts.');
                return self::SUCCESS;
            }
            $publisher->publish();
            $this->info('Fresh models published to the separate shadow directory. Predictor configuration was not changed.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
