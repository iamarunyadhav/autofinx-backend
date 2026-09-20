<?php

namespace App\Console\Commands;

use App\Jobs\CheckQuickTradeStopLossJob;
use Illuminate\Console\Command;

/**
 * Continuous ticker for the optional per-trade Quick Trade stop-loss.
 * Mirrors OpsStaleFeedCheckCommand's shape: `while(true) { dispatchSync;
 * sleep }` with a `--once` flag for a single manual/testable tick.
 *
 * Default interval is 1 second (not floored at 60s like some other
 * commands in this app) — a Quick Trade can be as short as ~1 minute, so a
 * slow tick would let most of the position's lifetime pass unchecked. 2-3s
 * is short enough to matter for a 1-minute trade without hammering the
 * price cache/DB on every tick (each tick is cheap: a small indexed query,
 * a per-position lock, one Redis read per open+stop-loss-configured trade).
 */
class MonitorQuickTradeStopLossCommand extends Command
{
    protected $signature = 'quick-trade:monitor-stop-loss {--once} {--interval=}';
    protected $description = 'Continuously check open live Quick Trades against their configured per-trade stop-loss and close early on breach';

    public function __construct(private readonly int $intervalSeconds = 1)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $interval = (int) ($this->option('interval') ?: $this->intervalSeconds);
        $interval = max(1, $interval);

        if ($this->option('once')) {
            CheckQuickTradeStopLossJob::dispatchSync();
            $this->info('Quick Trade stop-loss check executed once.');

            return self::SUCCESS;
        }

        while (true) {
            CheckQuickTradeStopLossJob::dispatchSync();
            sleep($interval);
        }
    }
}
