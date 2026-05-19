<?php

namespace App\Domain\FutureTrade;

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;

/**
 * Single source of truth for realized / unrealized PnL.
 *
 * Realized PnL is computed from closed logical trades, plus pre-logical legacy
 * close rows for installs that already had trade history before logical trades
 * were introduced. Open positions, raw event rows, and entry rows are never
 * summed.
 */
class FutureTradePnLService
{
    /**
     * Realized PnL by closed logical trades plus unmatched legacy close rows.
     *
     * Returns:
     *  - botProfit:  sum(net_pnl) over CLOSED trades where net_pnl > 0
     *  - botLoss:    abs(sum(net_pnl)) over CLOSED trades where net_pnl < 0
     *  - totalProfit:botProfit - botLoss
     *  - openPositions: count of OPEN/CLOSING logical trades
     *  - reconciliationRequired: count of trades flagged for reconciliation
     */
    public function realizedSummary(FutureTradeBotConfig $config, ?string $mode = null): array
    {
        $firstLogicalAt = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->min('created_at');

        $query = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_CLOSED)
            ->whereNotNull('net_pnl');

        if ($mode !== null) {
            $query->where('mode', $mode);
        }

        $trades = $query->get(['net_pnl']);
        $legacyCloseRows = $config->trades()
            ->whereNotNull('net_pnl')
            ->where(function ($q) {
                $q->where('status', 'CLOSED')
                    ->orWhere('side', 'like', '%CLOSE%')
                    ->orWhere('side', 'like', 'CLOSE_%');
            })
            ->when($mode !== null, fn ($q) => $q->where('mode', $mode))
            ->when($firstLogicalAt !== null, fn ($q) => $q->where('created_at', '<', $firstLogicalAt))
            ->get(['net_pnl']);

        $realizedRows = $trades->concat($legacyCloseRows);
        $profit = (float) $realizedRows->filter(fn ($t) => (float) $t->net_pnl > 0)->sum(fn ($t) => (float) $t->net_pnl);
        $loss = (float) $realizedRows->filter(fn ($t) => (float) $t->net_pnl < 0)->sum(fn ($t) => abs((float) $t->net_pnl));

        $openCount = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
            ->count();

        $reconCount = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED)
            ->count();

        return [
            'closedCount' => $realizedRows->count(),
            'logicalClosedCount' => $trades->count(),
            'legacyClosedCount' => $legacyCloseRows->count(),
            'botProfit' => round($profit, 8),
            'botLoss' => round($loss, 8),
            'totalProfit' => round($profit - $loss, 8),
            'openPositions' => $openCount,
            'reconciliationRequired' => $reconCount,
        ];
    }

    /**
     * Convenience for dashboard rows: closed logical trades only.
     */
    public function closedTradesList(FutureTradeBotConfig $config, int $limit = 200): array
    {
        return FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_CLOSED)
            ->orderByDesc('closed_at')
            ->limit($limit)
            ->get()
            ->all();
    }
}
