<?php

namespace App\Console\Commands;

use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Console\Command;

class RatioBotsEvaluateCommand extends Command
{
    protected $signature = 'ratio-bots:evaluate
        {--bot= : Evaluate one ratio bot config id}
        {--limit=100 : Maximum bots to dispatch}
        {--force : Ignore interval due checks}';

    protected $description = 'Dispatch due stateful ratio-bot evaluations';

    public function handle(): int
    {
        $botId = trim((string) $this->option('bot'));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $force = (bool) $this->option('force');

        $configs = RatioBotConfig::query()
            ->with('state')
            ->when($botId !== '', fn ($query) => $query->whereKey($botId))
            ->where('enabled', true)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($configs as $config) {
            if (! $force && ! $this->isDue($config)) {
                continue;
            }

            RunRatioBotEvaluationJob::dispatch((string) $config->id, false);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} ratio bot evaluation job(s).");

        return self::SUCCESS;
    }

    private function isDue(RatioBotConfig $config): bool
    {
        $state = $config->state;
        if (! $state) {
            return true;
        }

        if ($state->position_state === RatioBotState::STATE_AWAITING_EXECUTION) {
            return false;
        }

        if (! $state->last_evaluated_at) {
            return true;
        }

        $interval = $this->intervalSeconds($config);

        return $state->last_evaluated_at->copy()->addSeconds($interval)->isPast();
    }

    private function intervalSeconds(RatioBotConfig $config): int
    {
        if ($config->usesRealtimeFeed()) {
            return max(1, (int) ($config->min_seconds_between_evaluations ?? 1));
        }

        return max(1, (int) ($config->poll_interval_seconds ?? 300));
    }
}
