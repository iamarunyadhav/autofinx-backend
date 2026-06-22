<?php

namespace App\Console\Commands;

use App\Models\FutureTradeWebhookAudit;
use Illuminate\Console\Command;

class FutureTradeCompareTvCsvCommand extends Command
{
    protected $signature = 'future-trade:compare-tv-csv {file? : Optional TradingView CSV path} {--limit=200}';

    protected $description = 'Compare persisted TradingView webhook signals against an optional TradingView CSV export.';

    public function handle(): int
    {
        $file = $this->argument('file');
        $recent = FutureTradeWebhookAudit::query()
            ->latest('received_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get();

        $this->info('Persisted webhook audit rows: '.$recent->count());
        $this->line('Executed: '.$recent->where('decision', 'EXECUTE')->count());
        $this->line('Blocked/rejected: '.$recent->filter(fn ($row) => in_array($row->decision, ['BLOCKED', null], true) || $row->validation_status === 'rejected')->count());
        $this->line('WAIT: '.$recent->where('decision', 'WAIT')->count());

        if ($file) {
            $this->warn('CSV parser hook is ready; detailed row matching can be added once the exact TradingView export columns are known.');
            $this->line('CSV path: '.$file);
        }

        return self::SUCCESS;
    }
}
