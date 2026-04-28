<?php

namespace App\Domain\RatioBot;

use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\Order;
use App\Models\Position;
use App\Models\RatioBotConfig;
use App\Models\Trade;

class RatioBotRuntimeQueryService
{
    public function snapshot(RatioBotConfig $config): array
    {
        return [
            'config' => $config->fresh('state'),
            'feed' => $this->feedHealth($config),
            'last_signal' => $this->lastSignal($config),
            'last_order' => $this->lastOrder($config),
            'position' => $this->currentPosition($config),
            'pnl' => $this->pnlSummary($config),
        ];
    }

    public function feedHealth(RatioBotConfig $config): ?array
    {
        $state = $config->state ?? null;
        $row = MarketFeedStatus::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('exchange', $config->exchangeKey())
            ->where('market_type', $config->marketType())
            ->where('symbol', $config->normalizedSymbol())
            ->latest('updated_at')
            ->first();

        if (! $row) {
            return null;
        }

        return $row->toArray() + [
                'state_feed_status' => $state?->feed_status,
                'state_feed_status_updated_at' => optional($state?->feed_status_updated_at)?->toIso8601String(),
            ];
    }

    public function recentCandles(RatioBotConfig $config, int $limit = 200): array
    {
        return MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->where('timeframe', $config->timeFrame())
            ->orderByDesc('ts')
            ->limit(max(1, min(500, $limit)))
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    public function lastSignal(RatioBotConfig $config): ?array
    {
        $decision = $config->decisions()
            ->orderByDesc('decided_at')
            ->first();

        return $decision?->toArray();
    }

    public function lastOrder(RatioBotConfig $config): ?array
    {
        $order = Order::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('asset_code', $config->normalizedSymbol())
            ->where(function ($query) use ($config): void {
                $query->where('meta->ratio_bot_config_id', (string) $config->id)
                    ->orWhere('meta->market_type', $config->marketType());
            })
            ->orderByDesc('created_at')
            ->first();

        return $order?->toArray();
    }

    public function currentPosition(RatioBotConfig $config): ?array
    {
        $position = Position::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('asset_code', $config->normalizedSymbol())
            ->where(function ($query) use ($config): void {
                if ($config->marketType() === 'futures') {
                    $query->where('market_type', 'futures');
                } else {
                    $query->whereNull('market_type')
                        ->orWhere('market_type', 'spot');
                }
            })
            ->orderByDesc('updated_at')
            ->first();

        return $position?->toArray();
    }

    public function pnlSummary(RatioBotConfig $config): array
    {
        $position = $this->currentPosition($config);
        $todayRealized = Trade::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('asset_code', $config->normalizedSymbol())
            ->whereDate('ts', now()->toDateString())
            ->sum('realized_pnl');

        return [
            'today_realized_pnl' => (float) $todayRealized,
            'unrealized_pnl' => (float) ($position['unrealized_pnl'] ?? $config->state?->unrealized_pnl ?? 0),
            'current_position_qty' => (float) ($position['qty'] ?? $position['quantity'] ?? $config->state?->last_position_qty ?? 0),
            'current_position_side' => (string) ($config->state?->last_position_side ?? 'flat'),
        ];
    }
}
