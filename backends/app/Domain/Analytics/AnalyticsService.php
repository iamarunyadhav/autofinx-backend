<?php

namespace App\Domain\Analytics;

use App\Models\Ledger;
use App\Models\Order;
use App\Models\ProfitSnapshot;
use Illuminate\Support\Str;

class AnalyticsService
{
    public function snapshot(string $workspaceId): ProfitSnapshot
    {
        $ledger = Ledger::where('workspace_id', $workspaceId)->first();
        $balance = $ledger?->balance ?? 0;
        $orders = Order::where('workspace_id', $workspaceId)->where('status', 'filled')->get();
        $fees = $orders->sum(fn ($o) => $o->meta['fee'] ?? 0);
        $wins = $orders->filter(fn ($o) => ($o->meta['side'] ?? $o->side) === 'sell')->count();
        $count = max(1, $orders->count());
        $winRate = $wins / $count;

        return ProfitSnapshot::updateOrCreate(
            ['workspace_id' => $workspaceId, 'date_bucket' => now()->format('Y-m-d')],
            [
                'id' => (string) Str::uuid(),
                'pnl_realized' => 0,
                'pnl_unrealized' => 0,
                'fees' => $fees,
                'win_rate' => $winRate,
                'drawdown' => 0,
                'trade_count' => $count,
                'per_strategy_json' => [],
            ]
        );
    }
}
