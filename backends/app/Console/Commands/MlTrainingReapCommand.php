<?php

namespace App\Console\Commands;

use App\Domain\Ml\Training\TrainingReaper;

final class MlTrainingReapCommand extends MlShadowCommand
{
    protected $signature = 'ml:training:reap {--force : Run even when ml.enabled is false}';

    protected $description = 'Every 2 minutes: end training runs with no heartbeat (INTERRUPTED), release their locks, and re-queue transient failures with backoff.';

    public function handle(TrainingReaper $reaper): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        $result = $reaper->run();
        $this->line(sprintf('interrupted %d, stopped %d, auto-retried %d', $result['interrupted'], $result['stopped'], $result['retried']));

        return self::SUCCESS;
    }
}
