<?php

namespace App\Console\Commands;

use App\Domain\Trading\QuickTradeService;
use Illuminate\Console\Command;

class SettleQuickTradesCommand extends Command
{
    protected $signature = 'quick-trades:settle {--limit=100}';

    protected $description = 'Settle all expired paper quick trades.';

    public function handle(QuickTradeService $quickTrades): int
    {
        $settled = $quickTrades->settleExpired((int) $this->option('limit'));

        $this->info('Settled quick trades: ' . count($settled));

        return self::SUCCESS;
    }
}
