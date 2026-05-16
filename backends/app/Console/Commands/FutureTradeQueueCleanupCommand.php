<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FutureTradeQueueCleanupCommand extends Command
{
    protected $signature = 'future-trade:queue-cleanup {--safe : Only remove stale non-critical jobs}';
    protected $description = 'Safely clean stale non-critical Future Trade related queue backlog.';

    private const SAFE_QUEUES = ['analytics', 'notifications', 'monitoring_safe'];

    public function handle(): int
    {
        if (! (bool) $this->option('safe')) {
            $this->error('Use --safe. Critical/execution/reconcile queues are never cleaned by this command.');
            return self::FAILURE;
        }

        if (! Schema::hasTable('jobs')) {
            $this->warn('jobs table is not available.');
            return self::SUCCESS;
        }

        $cutoff = time() - 3600;
        $deleted = DB::table('jobs')
            ->whereIn('queue', self::SAFE_QUEUES)
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("[QUEUE CLEANUP] deleted {$deleted} stale non-critical jobs.");
        Log::info('[QUEUE CLEANUP]', [
            'deleted' => $deleted,
            'queues' => self::SAFE_QUEUES,
            'cutoffAgeSeconds' => 3600,
        ]);

        return self::SUCCESS;
    }
}
