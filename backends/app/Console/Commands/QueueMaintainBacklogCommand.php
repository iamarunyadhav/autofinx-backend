<?php

namespace App\Console\Commands;

use App\Domain\Ops\QueueControlService;
use App\Models\SchedulerRun;
use Illuminate\Console\Command;

class QueueMaintainBacklogCommand extends Command
{
    protected $signature = 'jobs:maintain-backlog';

    protected $description = 'Automatically prune stale safe jobs when queue backlog crosses the configured threshold.';

    public function handle(QueueControlService $queues): int
    {
        $result = $queues->maintainBacklog();

        $this->info((string) ($result['message'] ?? 'Queue maintenance finished.'));
        if (($result['enabled'] ?? true) && array_key_exists('blocking_backlog_before', $result)) {
            $this->line(sprintf(
                'Blocking backlog: %s -> %s | emergency: %s | restarted: %s',
                (string) ($result['blocking_backlog_before'] ?? '?'),
                (string) ($result['blocking_backlog_after'] ?? '?'),
                ! empty($result['emergency']) ? 'yes' : 'no',
                ! empty($result['workers_restarted']) ? 'yes' : 'no'
            ));
        }

        // Prune old scheduler_runs — keep only last 2 hours to prevent unbounded growth
        $pruned = SchedulerRun::where('started_at', '<', now()->subHours(2))->delete();
        if ($pruned > 0) {
            $this->line("Pruned {$pruned} old scheduler run records.");
        }

        return self::SUCCESS;
    }
}
