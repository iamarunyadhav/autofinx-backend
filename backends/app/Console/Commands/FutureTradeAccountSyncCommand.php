<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeAccountSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FutureTradeAccountSyncCommand extends Command
{
    protected $signature = 'future-trade:account-sync
        {--workspace= : Limit to a single workspace}
        {--json : Output JSON}';

    protected $description = 'Sync Binance Futures account balance, margin, open-position summary, and wallet account cache for Future Trade.';

    public function handle(FutureTradeAccountSyncService $sync): int
    {
        $lock = Cache::lock('autofinx_ft:account_sync_lock', 120);
        if (! $lock->get()) {
            $this->warn('[FT ACCOUNT SYNC SKIPPED] another sync is already running');

            return self::SUCCESS;
        }

        try {
            $workspaceId = trim((string) $this->option('workspace'));
            $report = $workspaceId !== ''
                ? $sync->syncWorkspace($workspaceId)
                : $sync->syncAll();

            if ((bool) $this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            foreach ((array) ($report['reports'] ?? []) as $row) {
                $this->line(sprintf(
                    '%s status=%s source=%s wallet=%s available=%s margin=%s unrealized=%s positions=%s reason=%s',
                    (string) ($row['workspace_id'] ?? 'n/a'),
                    (string) ($row['status'] ?? 'UNKNOWN'),
                    (string) ($row['credential_source'] ?? 'n/a'),
                    $row['wallet_balance'] ?? '-',
                    $row['available_balance'] ?? '-',
                    $row['margin_balance'] ?? '-',
                    $row['unrealized_pnl'] ?? '-',
                    $row['open_position_count'] ?? '-',
                    $row['reason'] ?? '-',
                ));
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
