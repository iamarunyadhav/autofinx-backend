<?php

namespace App\Console\Commands;

use App\Domain\Trading\QuickTradeService;
use App\Models\QuickTrade;
use Illuminate\Console\Command;

class QuickTradesReconcileCommand extends Command
{
    protected $signature = 'quick-trades:reconcile {--workspace=} {--limit=100}';

    protected $description = 'Repair expired open Quick Trades by running retry-safe settlement.';

    public function handle(QuickTradeService $quickTrades): int
    {
        $workspaceId = $this->option('workspace') ? (string) $this->option('workspace') : null;
        $limit = max(1, min(500, (int) $this->option('limit')));

        $trades = QuickTrade::query()
            ->where('status', 'open')
            ->where('expires_at', '<=', now())
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $settled = 0;
        $failed = 0;

        foreach ($trades as $trade) {
            try {
                $quickTrades->settleTrade($trade);
                $settled++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf(
                    'Failed to settle quick trade %s (%s): %s',
                    $trade->id,
                    $trade->symbol,
                    $e->getMessage()
                ));
            }
        }

        $this->info("Quick trade reconcile complete. settled={$settled} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
