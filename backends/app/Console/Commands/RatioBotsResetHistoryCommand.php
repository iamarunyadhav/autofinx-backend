<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wipe a Ratio Bot's own trading history so it starts fresh, keeping its
 * settings. Shared data is never touched: orders, trades, fills and audit
 * events (the real exchange record every module uses), forecast backtests
 * (also read by Quick Trade, Fast2X and the unified forecast) and brain
 * snapshots stay. Dry run unless --confirm.
 */
class RatioBotsResetHistoryCommand extends Command
{
    protected $signature = 'ratio-bots:reset-history
        {--bot=* : Ratio bot config id(s); default every bot}
        {--confirm : Actually delete (default is a dry run that only counts)}
        {--force : Also reset bots that still hold a position (its cost basis is lost)}';

    protected $description = 'Delete Ratio Bot decision/lot/event history and reset bot state for a fresh start (settings kept).';

    /** Tables holding only this bot's own history, keyed by ratio_bot_config_id. */
    private const TABLES = [
        'ratio_bot_decisions', 'ratio_bot_events', 'ratio_bot_lots', 'ratio_bot_trade_outcomes',
        'ratio_bot_reconciliation_logs', 'ratio_bot_execution_cost_records', 'ratio_bot_recovery_slots',
    ];

    public function handle(): int
    {
        $bots = RatioBotConfig::query()->with('state')
            ->when($this->option('bot'), fn ($q, $ids) => $q->whereKey($ids))->orderBy('name')->get();
        if ($bots->isEmpty()) {
            $this->warn('No ratio bots matched.');

            return self::FAILURE;
        }

        $confirm = (bool) $this->option('confirm');
        $failed = false;
        foreach ($bots as $bot) {
            $this->newLine();
            $this->line("== {$bot->name} | {$bot->normalizedSymbol()} | {$bot->execution_mode}");

            if ($reason = $this->refusal($bot)) {
                $this->error("  skipped: {$reason}");
                $failed = true;

                continue;
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $count = DB::table($table)->where('ratio_bot_config_id', $bot->id)->count();
                $deleted = $confirm ? $this->deleteInChunks($table, (string) $bot->id) : 0;
                $this->line(sprintf('  %-34s %10d rows%s', $table, $count, $confirm ? " -> deleted {$deleted}" : ''));
            }

            if ($confirm) {
                // A fresh state row is created on demand with defaults (idle,
                // no anchors, zero PnL and counters). The bot is left stopped:
                // Start it from the UI to anchor from the current wallet.
                RatioBotState::query()->where('ratio_bot_config_id', $bot->id)->delete();
                $bot->update(['enabled' => false]);
                $this->info('  state reset; bot stopped (press Start in the UI)');
            }
        }

        $this->newLine();
        $this->line($confirm ? 'Done.' : 'Dry run only. Re-run with --confirm to delete.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function refusal(RatioBotConfig $bot): ?string
    {
        $state = $bot->state;
        if ($state?->position_state === RatioBotState::STATE_AWAITING_EXECUTION) {
            return 'an order result is still pending; wait for it to resolve';
        }
        $unresolved = Order::query()->where('workspace_id', $bot->workspace_id)->where('meta->ratio_bot_config_id', (string) $bot->id)
            ->where(fn ($q) => $q->whereIn('status', [Order::STATUS_UNCERTAIN, Order::STATUS_PENDING_PROVIDER, Order::STATUS_NEW, Order::STATUS_PARTIAL])
                ->orWhereIn('reconciliation_state', [Order::RECONCILIATION_UNCERTAIN, Order::RECONCILIATION_MISMATCH]))
            ->where('created_at', '>=', now()->subDays(7))->exists();
        if ($unresolved) {
            return 'it has an open or unresolved order; resolve it on the exchange first';
        }
        $holding = in_array($state?->position_state, [RatioBotState::STATE_HOLDING, RatioBotState::STATE_PARTIAL_HOLDING, RatioBotState::STATE_PARTIAL_SOLD], true)
            && (float) ($state?->last_buy_quantity ?? 0) > 0;
        if ($holding && ! $this->option('force')) {
            return sprintf('it holds %s %s; its cost basis would be lost (use --force if that position is gone or you accept that)',
                $state->last_buy_quantity, $bot->baseAsset());
        }

        return null;
    }

    private function deleteInChunks(string $table, string $botId): int
    {
        $deleted = 0;
        do {
            $ids = DB::table($table)->where('ratio_bot_config_id', $botId)->limit(5000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        return $deleted;
    }
}
