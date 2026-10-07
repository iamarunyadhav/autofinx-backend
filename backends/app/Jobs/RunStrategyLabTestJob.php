<?php

namespace App\Jobs;

use App\Domain\StrategyLab\StrategyLabRunner;
use App\Models\StrategyLabRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue-only Strategy Lab test (Quick or Deep) for one run row. Research only: it can write a run result and
 * advance a research candidate's lifecycle, nothing else. Unique per run; a run that is no longer QUEUED
 * (cancelled, already taken) is skipped.
 */
final class RunStrategyLabTestJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 7200;

    public function __construct(public readonly string $runId)
    {
        $this->timeout = (int) max(config('strategy_lab.timeout_seconds.quick', 900), config('strategy_lab.timeout_seconds.deep', 2200)) + 120;
        $this->onConnection((string) config('strategy_lab.queue.connection', 'ml_training'));
        $this->onQueue((string) config('strategy_lab.queue.name', 'strategy_lab'));
    }

    public function uniqueId(): string
    {
        return 'strategy-lab-run:'.$this->runId;
    }

    public function handle(StrategyLabRunner $runner): void
    {
        $run = StrategyLabRun::query()->find($this->runId);
        if ($run === null || $run->status !== StrategyLabRun::QUEUED) {
            return;
        }
        $runner->execute($run);
    }
}
