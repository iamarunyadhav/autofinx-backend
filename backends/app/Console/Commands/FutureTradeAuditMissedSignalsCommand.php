<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBotService;
use Illuminate\Console\Command;

class FutureTradeAuditMissedSignalsCommand extends Command
{
    protected $signature = 'future-trade:audit-missed-signals {--workspace=} {--window=15} {--limit=100}';
    protected $description = 'Review pending skipped FutureTrade signals and classify whether they would have worked.';

    public function handle(FutureTradeBotService $bot): int
    {
        $workspace = $this->option('workspace');
        $window = (int) $this->option('window');
        $limit = (int) $this->option('limit');

        $summary = $bot->reviewPendingMissedSignalAudits(
            $workspace ? (string) $workspace : null,
            $window > 0 ? $window : null,
            $limit
        );

        $this->line(sprintf(
            'Reviewed %d pending missed-signal audits. Pending remaining: %d',
            (int) ($summary['reviewed'] ?? 0),
            (int) ($summary['pending'] ?? 0)
        ));

        foreach ((array) ($summary['results'] ?? []) as $result => $count) {
            $this->line(sprintf('- %s: %d', $result, (int) $count));
        }

        return self::SUCCESS;
    }
}
