<?php

namespace App\Console\Commands;

use App\Models\FutureTradeWebhookAudit;
use Illuminate\Console\Command;

class FutureTradeAuditWebhooksCommand extends Command
{
    protected $signature = 'future-trade:audit-webhooks {--limit=100}';

    protected $description = 'Show recent TradingView/Future Trade webhook audit rows.';

    public function handle(): int
    {
        $rows = FutureTradeWebhookAudit::query()
            ->latest('received_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get(['received_at', 'workspace_id', 'symbol', 'normalized_signal', 'validation_status', 'reject_reason', 'decision', 'decision_reason', 'total_latency_ms']);

        $this->table(['received_at', 'workspace', 'symbol', 'signal', 'validation', 'reject', 'decision', 'reason', 'ms'], $rows->map(fn ($row) => [
            optional($row->received_at)->toIso8601String(),
            $row->workspace_id,
            $row->symbol,
            $row->normalized_signal,
            $row->validation_status,
            $row->reject_reason,
            $row->decision,
            $row->decision_reason,
            $row->total_latency_ms,
        ])->all());

        return self::SUCCESS;
    }
}
