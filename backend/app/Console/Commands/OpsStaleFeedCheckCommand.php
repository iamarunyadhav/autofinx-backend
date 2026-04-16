<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\DetectStaleFeedsJob;

class OpsStaleFeedCheckCommand extends Command
{
    protected $signature = 'ops:stale-feed-check {--once} {--threshold=60}';
    protected $description = 'Detect stale market feeds and log risk events';

    public function handle(): int
    {
        $once = $this->option('once');
        $threshold = (int) $this->option('threshold');
        if ($once) {
            DetectStaleFeedsJob::dispatchSync($threshold);
            $this->info('Stale feed check executed once.');
            return self::SUCCESS;
        }

        while (true) {
            DetectStaleFeedsJob::dispatchSync($threshold);
            $this->info('Stale feed check cycle completed.');
            sleep(max(10, $threshold));
        }
    }
}
