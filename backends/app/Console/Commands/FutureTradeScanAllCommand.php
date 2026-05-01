<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;

class FutureTradeScanAllCommand extends Command
{
    protected $signature = 'future-trade:scan-all {--loop} {--sleep-ms=500}';
    protected $description = 'Scan all enabled FutureTrade bot workspaces. Use --loop for continuous daemon mode.';

    public function handle(FutureTradeBotService $bot): int
    {
        $loop = (bool) $this->option('loop');
        $sleepMs = max(100, (int) $this->option('sleep-ms'));

        if ($loop) {
            $this->info("FutureTrade daemon started — scanning every {$sleepMs}ms");
        }

        do {
            $configs = FutureTradeBotConfig::where('enabled', true)
                ->with('state')
                ->get();

            foreach ($configs as $config) {
                try {
                    $bot->scan($config->fresh('state'), true);
                } catch (\Throwable $e) {
                    $this->error('Workspace ' . $config->workspace_id . ': ' . $e->getMessage());
                }
            }

            if ($loop) {
                usleep($sleepMs * 1000);
            }
        } while ($loop);

        return 0;
    }
}
