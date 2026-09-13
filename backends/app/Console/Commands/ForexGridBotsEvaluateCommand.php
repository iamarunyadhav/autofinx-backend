<?php

namespace App\Console\Commands;

use App\Jobs\RunForexGridBotEvaluationJob;
use App\Models\ForexGridBotConfig;
use Illuminate\Console\Command;

/**
 * Mirrors RatioBotsEvaluateCommand's dispatch-loop shape exactly, for the
 * brand-new, fully independent Forex Grid module -- one job per enabled
 * config, dispatched onto the separate `forex_grid` queue (see
 * RunForexGridBotEvaluationJob).
 */
class ForexGridBotsEvaluateCommand extends Command
{
    protected $signature = 'forex-grid-bots:evaluate
        {--bot= : Evaluate one forex grid bot config id}
        {--limit=100 : Maximum bots to dispatch}';

    protected $description = 'Dispatch evaluation jobs for enabled Forex Grid bots';

    public function handle(): int
    {
        $botId = trim((string) $this->option('bot'));
        $limit = max(1, min(1000, (int) $this->option('limit')));

        $configs = ForexGridBotConfig::query()
            ->when($botId !== '', fn ($query) => $query->whereKey($botId))
            ->where('enabled', true)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($configs as $config) {
            RunForexGridBotEvaluationJob::dispatch((string) $config->id, false);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} forex grid bot evaluation job(s).");

        return self::SUCCESS;
    }
}
