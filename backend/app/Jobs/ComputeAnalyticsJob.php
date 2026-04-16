<?php

namespace App\Jobs;

use App\Models\AnalyticsSnapshot;
use App\Models\LedgerTransaction;
use App\Models\Trade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ComputeAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'analytics';

    public function __construct(public string $workspaceId)
    {
    }

    public function handle(): void
    {
        $trades = Trade::where('workspace_id', $this->workspaceId)->get();
        $ltx = LedgerTransaction::whereHas('ledger', function ($q) {
            $q->where('workspace_id', $this->workspaceId);
        })->get();

        $realized = $ltx->where('type', 'order_paper')->sum('amount');
        $fees = $ltx->sum(function ($t) {
            return $t->meta['fee'] ?? 0;
        });
        $win = $trades->filter(fn ($t) => ($t->meta['pl'] ?? 0) > 0)->count();
        $loss = $trades->filter(fn ($t) => ($t->meta['pl'] ?? 0) < 0)->count();
        $winRate = ($win + $loss) > 0 ? $win / ($win + $loss) : 0;

        $metrics = [
            'realized_pnl' => $realized,
            'fees' => $fees,
            'win_rate' => $winRate,
            'trade_count' => $trades->count(),
        ];

        AnalyticsSnapshot::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspaceId,
            'scope_type' => 'workspace',
            'scope_ref' => $this->workspaceId,
            'metrics_json' => $metrics,
            'captured_at' => now(),
        ]);
    }
}
