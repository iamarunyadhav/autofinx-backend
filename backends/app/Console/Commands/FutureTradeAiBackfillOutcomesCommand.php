<?php

namespace App\Console\Commands;

use App\Models\FutureTradeAiDecision;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Console\Command;

/**
 * Fills outcome fields on AI gate decisions whose linked logical trade has
 * closed. Runs as a scheduled sweep because logical trades close in four
 * independent code paths (bot service exits, user stream, reconciliation).
 */
class FutureTradeAiBackfillOutcomesCommand extends Command
{
    protected $signature = 'future-trade:ai-backfill-outcomes {--limit=200}';

    protected $description = 'Backfill trade outcomes onto AI gate decisions whose logical trades have closed.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $pending = FutureTradeAiDecision::query()
            ->whereNotNull('logical_trade_id')
            ->whereNull('outcome')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $filled = 0;
        foreach ($pending as $decision) {
            $trade = FutureTradeLogicalTrade::query()->find($decision->logical_trade_id);
            if ($trade === null || ! $trade->isClosed()) {
                continue;
            }

            $netPnl = (float) $trade->net_pnl;
            $decision->forceFill([
                'outcome' => $netPnl > 0
                    ? FutureTradeAiDecision::OUTCOME_WIN
                    : ($netPnl < 0 ? FutureTradeAiDecision::OUTCOME_LOSS : FutureTradeAiDecision::OUTCOME_BREAKEVEN),
                'outcome_pnl' => $netPnl,
                'outcome_filled_at' => now(),
            ])->save();
            $filled++;
        }

        $this->info(sprintf('Checked %d pending AI decisions, backfilled %d outcomes.', $pending->count(), $filled));

        return self::SUCCESS;
    }
}
