<?php

namespace App\Console\Commands;

use App\Models\FutureTradeOrderAudit;
use Illuminate\Console\Command;

class FutureTradeAuditOrdersCommand extends Command
{
    protected $signature = 'future-trade:audit-orders {--limit=100}';

    protected $description = 'Show recent Binance Futures order audit rows.';

    public function handle(): int
    {
        $rows = FutureTradeOrderAudit::query()
            ->latest('order_request_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get();

        $this->table(['request_at', 'workspace', 'symbol', 'side', 'pos', 'qty', 'reduce', 'status', 'order_id', 'error'], $rows->map(fn ($row) => [
            optional($row->order_request_at)->toIso8601String(),
            $row->workspace_id,
            $row->binance_symbol,
            $row->side,
            $row->position_side,
            $row->quantity,
            $row->reduce_only ? 'yes' : 'no',
            $row->execution_status,
            $row->binance_order_id,
            $row->error_message,
        ])->all());

        return self::SUCCESS;
    }
}
