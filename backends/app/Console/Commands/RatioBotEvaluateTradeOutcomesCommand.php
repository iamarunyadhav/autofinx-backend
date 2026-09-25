<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\RatioBotTradeOutcomeService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotTradeOutcome;
use Illuminate\Console\Command;

/**
 * Manual (deliberately NOT scheduled) sweep of due Ratio Bot trade-outcome
 * rows. The normal path is the lazy sweep inside RunRatioBotEvaluationJob;
 * this exists for bots that are stopped (they get no evaluation ticks) or
 * when ratio_bot_outcomes.evaluate_in_loop is turned off. Read-only with
 * respect to trading: it only writes ratio_bot_trade_outcomes.
 */
class RatioBotEvaluateTradeOutcomesCommand extends Command
{
    protected $signature = 'ratio-bot:evaluate-trade-outcomes
        {--bot= : Only this ratio bot config id}
        {--limit=500 : Maximum rows per bot}';

    protected $description = 'Score due Ratio Bot trade-accuracy rows (observation only)';

    public function handle(RatioBotTradeOutcomeService $outcomes): int
    {
        $botId = trim((string) $this->option('bot'));
        $limit = max(1, min(5000, (int) $this->option('limit')));

        $configIds = RatioBotTradeOutcome::query()
            ->where('evaluation_status', RatioBotTradeOutcome::STATUS_PENDING)
            ->when($botId !== '', fn ($q) => $q->where('ratio_bot_config_id', $botId))
            ->distinct()
            ->pluck('ratio_bot_config_id');

        $total = 0;
        foreach (RatioBotConfig::query()->whereIn('id', $configIds)->get() as $config) {
            $total += $outcomes->evaluateDueForConfig($config, force: true, limit: $limit);
        }

        $this->info("Processed {$total} due trade-outcome row(s).");

        return self::SUCCESS;
    }
}
