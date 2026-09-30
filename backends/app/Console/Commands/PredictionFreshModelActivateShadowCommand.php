<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionShadowLifecycleService;
use App\Domain\Prediction\FreshData\FreshPredictionShadowPublisher;
use App\Models\PredictionFreshModelCandidate;
use Illuminate\Console\Command;
use Throwable;

final class PredictionFreshModelActivateShadowCommand extends Command
{
    protected $signature = 'prediction:fresh-model:activate-shadow {candidate : Quality-passed candidate UUID} {--apply : Update the shadow pointer and publish this exact artifact}';
    protected $description = 'Review, activate, or roll back one quality-passed candidate in the shadow-only predictor; never enables execution.';

    public function handle(FreshPredictionShadowLifecycleService $lifecycle, FreshPredictionShadowPublisher $publisher): int
    {
        $candidate = PredictionFreshModelCandidate::query()->find((string) $this->argument('candidate'));
        if (! $candidate) {
            $this->error('Fresh model candidate not found.');
            return self::FAILURE;
        }

        try {
            $this->line("Candidate {$candidate->id}: {$candidate->symbol} {$candidate->timeframe} · {$candidate->status}");
            $this->line('Shadow-only activation; execution and influence remain false.');
            if (! $this->option('apply')) {
                $this->line('Dry run only. Re-run with --apply after reviewing candidate metrics.');
                return self::SUCCESS;
            }
            $lifecycle->activate($candidate);
            $publisher->publishCandidate($candidate->id);
            $this->info('Shadow candidate activated and published atomically for its symbol/timeframe. No execution configuration changed.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
