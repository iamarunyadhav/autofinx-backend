<?php

namespace App\Listeners;

use App\Events\StrategySignalEmitted;
use App\Jobs\ExecutePaperOrderJob;

class HandleStrategySignal
{
    public function handle(StrategySignalEmitted $event): void
    {
        if ($event->action === 'HOLD') {
            return;
        }

        ExecutePaperOrderJob::dispatch(
            $event->instanceId,
            $event->action,
            $event->price,
            $event->meta
        );
    }
}
