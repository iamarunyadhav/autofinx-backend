<?php

namespace App\Domain\Nexus;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NexusObservationService
{
    // Only small, scalar projections; never select metadata/credentials/raw account records.
    public function capture(string $workspace): array
    {
        $result = ['observed_at' => now()->toIso8601String(), 'window_hours' => 24, 'origin' => 'LOCAL_COMPUTATION', 'modules' => []];
        $parents = ['fast2x_trades' => ['fast2x_configs', 'fast2x_config_id'], 'ratio_bot_decisions' => ['ratio_bot_configs', 'ratio_bot_config_id']];
        foreach (['quick_trades', 'robot_sessions', 'future_trade_logical_trades', 'fast2x_trades', 'ratio_bot_decisions', 'forex_grid_bot_configs', 'pepperstone_bot_configs', 'audit_events'] as $table) {
            if (! Schema::hasTable($table)) {
                $result['modules'][$table] = ['status' => 'ADAPTER_REQUIRED'];

                continue;
            }
            $columns = array_values(array_intersect(['id', 'symbol', 'source', 'timeframe', 'expiry_seconds', 'mode', 'market_type', 'status', 'severity', 'decision', 'execution_status', 'net_pnl', 'fees', 'slippage', 'created_at'], Schema::getColumnListing($table)));
            $query = DB::table($table);
            if (Schema::hasColumn($table, 'workspace_id')) {
                $query->where($table.'.workspace_id', $workspace);
            } elseif (isset($parents[$table]) && Schema::hasColumn($parents[$table][0], 'workspace_id')) {
                [$parent, $foreign] = $parents[$table];
                $query->join($parent, $parent.'.id', '=', $table.'.'.$foreign)->where($parent.'.workspace_id', $workspace);
            } else {
                $result['modules'][$table] = ['status' => 'ADAPTER_REQUIRED'];

                continue;
            }
            $rows = $query->where($table.'.created_at', '>=', now()->subDay())
                ->orderByDesc($table.'.created_at')->limit(501)->get(array_map(fn ($column) => $table.'.'.$column, $columns));
            $result['modules'][$table] = ['status' => 'OBSERVED', 'sample_size' => min(500, $rows->count()),
                'truncated' => $rows->count() > 500, 'recent' => $rows->take(500)->map(fn ($r) => (array) $r)->all()];
        }
        // Keep strategy, mode, timeframe, expiry and quote currency separate.
        $result['quick_trade_outcomes'] = DB::table('quick_trades')->where('workspace_id', $workspace)
            ->where('settled_at', '>=', now()->subDay())->whereNotNull('profit_loss')
            ->selectRaw('symbol, source, mode, timeframe, expiry_seconds, quote_currency, COUNT(*) AS samples, SUM(profit_loss) AS booked_net_pnl, SUM(fees_total) AS booked_fees')
            ->groupBy('symbol', 'source', 'mode', 'timeframe', 'expiry_seconds', 'quote_currency')->limit(100)->get()->map(fn ($r) => (array) $r)->all();
        $result['limitations'] = ['Rolling observations are bounded, not a complete historical dataset.',
            'Booked accounting may be awaiting fee reconciliation; no inferred profitability.',
            'Modules lacking a direct workspace column require a reviewed ownership adapter.'];

        return $result;
    }
}
