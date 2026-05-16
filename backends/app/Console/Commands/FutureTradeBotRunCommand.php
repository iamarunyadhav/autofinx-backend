<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBotService;
use Illuminate\Console\Command;

class FutureTradeBotRunCommand extends Command
{
    protected $signature = 'future-trade:run {workspace_id} {--sleep=3}';
    protected $description = 'Run the Future Trade Bot scanner loop using the configured execution mode.';

    public function handle(FutureTradeBotService $bot): int
    {
        $config = $bot->getOrCreateConfig((string) $this->argument('workspace_id'));
        $sleep = max(1, (int) $this->option('sleep'));

        $this->info('Future Trade Bot loop started for workspace ' . $config->workspace_id);
        $this->warn('Uses configured execution mode and existing live risk blockers. Prefer future-trade:tick for shared hosting cron.');

        while (true) {
            $config = $config->fresh('state');
            if ($config->enabled) {
                $bot->scan($config, true);
                $this->line(now()->format('H:i:s') . ' scanned ' . ($config->state?->current_symbol ?? 'none') . ' signal=' . ($config->state?->current_signal ?? 'WAITING'));
            }

            sleep($sleep);
        }
    }
}
