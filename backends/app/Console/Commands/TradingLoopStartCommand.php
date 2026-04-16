<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\StrategyInstance;
use App\Domain\Scheduler\ScheduleGate;
use App\Jobs\EvaluateStrategyInstanceJob;
use App\Support\Clock\Clock;

class TradingLoopStartCommand extends Command
{
    protected $signature = 'trading:loop-start {--once} {--interval=300}';
    protected $description = 'Run the trading evaluation loop (paper demo)';

    public function handle(ScheduleGate $gate, Clock $clock): int
    {
        $interval = (int) ($this->option('interval') ?? env('TRADING_LOOP_INTERVAL_SECONDS', 300));
        $once = $this->option('once');

        $this->info('Starting trading loop...');
        do {
            $now = $clock->now();
            $instances = StrategyInstance::where('mode', 'paper')
                ->where('status', 'running')
                ->get();

            foreach ($instances as $instance) {
                $result = $gate->canEvaluateStrategyInstance($instance, $now);
                if (! $result->allowed) {
                    $this->line("Skip {$instance->name}: {$result->reason}");
                    continue;
                }
                EvaluateStrategyInstanceJob::dispatch($instance->id);
            }

            if ($once) {
                break;
            }
            sleep(max(1, $interval));
        } while (true);

        return self::SUCCESS;
    }
}
