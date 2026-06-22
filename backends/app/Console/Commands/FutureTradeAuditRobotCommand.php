<?php

namespace App\Console\Commands;

use App\Models\RobotTradeAudit;
use Illuminate\Console\Command;

class FutureTradeAuditRobotCommand extends Command
{
    protected $signature = 'future-trade:audit-robot {--symbol=} {--timeframe=} {--limit=100}';

    protected $description = 'Show recent robot candle-direction audit rows.';

    public function handle(): int
    {
        $symbol = strtoupper(trim((string) $this->option('symbol')));
        $timeframe = trim((string) $this->option('timeframe'));

        $rows = RobotTradeAudit::query()
            ->when($symbol !== '', fn ($q) => $q->where('symbol', $symbol))
            ->when($timeframe !== '', fn ($q) => $q->where('timeframe', $timeframe))
            ->latest()
            ->limit(max(1, min(1000, (int) $this->option('limit'))))
            ->get();

        $this->table(['created_at', 'workspace', 'symbol', 'tf', 'dir', 'prev', 'signal', 'conf', 'noisy', 'reason'], $rows->map(fn ($row) => [
            optional($row->created_at)->toIso8601String(),
            $row->workspace_id,
            $row->symbol,
            $row->timeframe,
            $row->candle_direction,
            $row->previous_candle_direction,
            $row->robot_signal,
            $row->confidence_score,
            $row->noisy_candle ? 'yes' : 'no',
            $row->decision_reason,
        ])->all());

        return self::SUCCESS;
    }
}
