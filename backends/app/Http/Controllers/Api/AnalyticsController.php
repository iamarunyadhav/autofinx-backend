<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analytics\AnalyticsService;
use App\Http\Controllers\Controller;
use App\Models\ProfitSnapshot;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function overview(Request $request, AnalyticsService $service)
    {
        $workspaceId = $request->query('workspace_id');
        abort_unless($workspaceId, 422, 'workspace_id required');
        $snapshot = $service->snapshot($workspaceId);
        return $snapshot;
    }

    public function export(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        abort_unless($workspaceId, 422, 'workspace_id required');
        $snapshots = ProfitSnapshot::where('workspace_id', $workspaceId)->orderBy('date_bucket')->get();
        $rows = $snapshots->map(fn ($s) => [
            $s->date_bucket,
            $s->pnl_realized,
            $s->pnl_unrealized,
            $s->fees,
            $s->win_rate,
            $s->drawdown,
            $s->trade_count,
        ]);
        $csv = "date,pnl_realized,pnl_unrealized,fees,win_rate,drawdown,trade_count\n";
        foreach ($rows as $r) {
            $csv .= implode(',', $r) . "\n";
        }
        return response($csv, 200, ['Content-Type' => 'text/csv']);
    }
}
