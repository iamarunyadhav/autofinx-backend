<?php

namespace App\Console\Commands;

use App\Domain\Trading\BotLogService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Jobs\EvaluateBotJob;
use App\Models\Bot;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class BotsEvaluateCommand extends Command
{
    protected $signature = 'bots:evaluate {--bot=} {--limit=200}';

    protected $description = 'Dispatch EvaluateBotJob for running bots based on each bot interval.';

    public function handle(TradingModuleSettingsService $settings, BotLogService $logs): int
    {
        $botId = $this->option('bot');
        $limit = max(1, (int) $this->option('limit'));

        $query = Bot::query()
            ->where('status', 'RUNNING')
            ->where(function ($q): void {
                $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
            });

        if ($botId) {
            $query->where('id', $botId);
        }

        $bots = $query->limit($limit)->get();
        $dispatched = 0;
        foreach ($bots as $bot) {
            if (! $settings->automationEnabled((string) $bot->workspace_id)) {
                $bot->status = 'PAUSED';
                $bot->last_error = 'Auto trading is disabled for this workspace.';
                $bot->save();
                $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Bot paused because auto trading is disabled for this workspace.', [], (string) Str::uuid());
                continue;
            }

            $interval = max(1, (int) $bot->interval_sec);
            $last = $bot->last_evaluated_at;
            if ($last && $last->diffInSeconds(now()) < $interval) {
                continue;
            }

            EvaluateBotJob::dispatch($bot->id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} bot evaluations.");

        return self::SUCCESS;
    }
}
