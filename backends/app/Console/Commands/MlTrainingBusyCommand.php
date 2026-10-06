<?php

namespace App\Console\Commands;

use App\Models\MlTrainingRun;
use Illuminate\Console\Command;

/**
 * Exit 0 when a training run is RUNNING (from any channel: CLI, queue worker,
 * dashboard), 1 when none is. ml-train-batch.sh uses it so a batch never starts
 * a model while the queue worker is training one: the worker trains inside its
 * own process, so a process-name check cannot see it.
 */
final class MlTrainingBusyCommand extends Command
{
    protected $signature = 'ml:training:busy';

    protected $description = 'Exit 0 if a training run is RUNNING, 1 if none (used by ml-train-batch.sh).';

    public function handle(): int
    {
        $run = MlTrainingRun::query()->where('status', MlTrainingRun::RUNNING)->orderBy('id')->first(['symbol', 'horizon_minutes', 'source', 'phase']);
        if ($run === null) {
            $this->line('idle');

            return self::FAILURE;
        }
        $this->line("busy: {$run->symbol} h{$run->horizon_minutes} via {$run->source} ({$run->phase})");

        return self::SUCCESS;
    }
}
