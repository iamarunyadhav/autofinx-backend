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

        $closedLogicalTrades = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_CLOSED)
            ->when($mode !== null, fn ($q) => $q->where('mode', $mode))
            ->orderBy('closed_at')
            ->orderBy('created_at')
            ->get(['id', 'symbol', 'side', 'entry_price', 'exit_price', 'net_pnl', 'opened_at', 'closed_at', 'created_at']);

        $legacyCloseRows = $config->trades()
            ->whereNotNull('net_pnl')
            ->where(function ($q) {
                $q->where('status', 'CLOSED')
                    ->orWhere('side', 'like', '%CLOSE%')
                    ->orWhere('side', 'like', 'CLOSE_%');
            })
            ->when($mode !== null, fn ($q) => $q->where('mode', $mode))
            ->orderBy('created_at')
            ->get(['symbol', 'side', 'entry_price', 'exit_price', 'net_pnl', 'created_at']);

        $usedLegacyIndexes = [];
        $resolvedRows = collect();

        foreach ($closedLogicalTrades as $trade) {
            if ($trade->net_pnl !== null) {
                $resolvedRows->push((object) ['net_pnl' => (float) $trade->net_pnl]);
                continue;
            }

            $matchIndex = $legacyCloseRows->search(function ($legacy, $index) use ($trade, $usedLegacyIndexes) {
                if (in_array($index, $usedLegacyIndexes, true)) {
                    return false;
                }

                if (strtoupper((string) $legacy->symbol) !== strtoupper((string) $trade->symbol)) {
                    return false;
                }

                $legacySide = strtoupper((string) $legacy->side);
                $tradeSide = strtoupper((string) $trade->side);
                if (! str_contains($legacySide, $tradeSide)) {
                    return false;
                }

                $entryDelta = abs((float) $legacy->entry_price - (float) $trade->entry_price);
                $exitDelta = abs((float) ($legacy->exit_price ?? 0) - (float) ($trade->exit_price ?? 0));
                if ($entryDelta > 0.05 || $exitDelta > 0.05) {
                    return false;
                }

                $tradeTime = $trade->closed_at ?? $trade->created_at;
                $legacyTime = $legacy->created_at;
                if ($tradeTime && $legacyTime && abs($tradeTime->diffInSeconds($legacyTime, false)) > 86400) {
                    return false;
                }

                return true;
            });

            if ($matchIndex !== false) {
                $usedLegacyIndexes[] = $matchIndex;
                $resolvedRows->push((object) ['net_pnl' => (float) $legacyCloseRows[$matchIndex]->net_pnl]);
            }
        }

        foreach ($legacyCloseRows as $index => $legacy) {
            if (in_array($index, $usedLegacyIndexes, true)) {
                continue;
            }

            if ($firstLogicalAt !== null && $legacy->created_at >= $firstLogicalAt) {
                continue;
            }

            $resolvedRows->push((object) ['net_pnl' => (float) $legacy->net_pnl]);
        }

        $profit = (float) $resolvedRows->filter(fn ($t) => (float) $t->net_pnl > 0)->sum(fn ($t) => (float) $t->net_pnl);
        $loss = (float) $resolvedRows->filter(fn ($t) => (float) $t->net_pnl < 0)->sum(fn ($t) => abs((float) $t->net_pnl));

        $openCount = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
            ->count();

        $reconCount = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED)
            ->count();

        return [
            'closedCount' => $resolvedRows->count(),
            'logicalClosedCount' => $closedLogicalTrades->whereNotNull('net_pnl')->count(),
            'legacyClosedCount' => $legacyCloseRows->count(),
            'recoveredLogicalCount' => $closedLogicalTrades->whereNull('net_pnl')->count(),
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
