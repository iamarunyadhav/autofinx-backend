<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FutureTradeQueueHealthCommand extends Command
{
    protected $signature = 'future-trade:queue-health';
    protected $description = 'Show queue health for Future Trade shared-host operations.';

    private const QUEUES = [
        'critical', 'execution', 'reconcile', 'monitoring', 'market', 'signals',
        'strategies', 'ratio_bot', 'ops', 'intelligence', 'risk', 'billing',
        'copytrading', 'analytics', 'notifications', 'default', 'monitoring_safe',
    ];

    public function handle(): int
    {
        $rows = [];
        foreach (self::QUEUES as $queue) {
            $pending = Schema::hasTable('jobs')
                ? DB::table('jobs')->where('queue', $queue)->count()
                : 0;
            $oldest = Schema::hasTable('jobs')
                ? DB::table('jobs')->where('queue', $queue)->min('created_at')
                : null;
            $oldestAge = $oldest ? max(0, time() - (int) $oldest) : null;
            $failed = Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('queue', $queue)->count()
                : 0;
            $rows[] = [$queue, $pending, $failed, $oldestAge];
        }

        $this->table(['Queue', 'Pending', 'Failed', 'Oldest age sec'], $rows);
        Log::info('[QUEUE HEALTH]', ['queues' => $rows]);

        return self::SUCCESS;
    }
}
