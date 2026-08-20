<?php

namespace App\Domain\RatioBot;

use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\Order;
use App\Models\Position;
use App\Models\RatioBotBrainSnapshot;
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

    /**
     * Shaped to match the frontend's SignalPreview + ForecastPayload types
     * (see useQuickTrade.ts) so QuickTrade's existing SignalSummaryCard /
     * NearTermForecastCard components can render it unmodified.
     */
    public function brainSnapshot(RatioBotConfig $config): array
    {
        $row = RatioBotBrainSnapshot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->latest('created_at')
            ->first();

        if (! $row) {
            return ['enabled' => (bool) $config->brain_enabled, 'signal' => null, 'forecast' => null];
        }

        return [
            'enabled' => (bool) $config->brain_enabled,
            'evaluated_at' => optional($row->created_at)->toIso8601String(),
            'signal' => [
                'direction' => $row->direction,
                'confidence' => (int) $row->confidence,
                'bullish_score' => (float) $row->bullish_score,
                'bearish_score' => (float) $row->bearish_score,
                'neutral_score' => (float) $row->neutral_score,
                'supporting_indicators' => [],
                'conflicting_indicators' => [],
                'reasons' => (array) $row->reasons,
                'indicator_values' => [],
                'timeframe' => $row->timeframe,
            ],
            'forecast' => [
                'available' => true,
                'horizon_seconds' => $row->horizon_seconds,
                'bias' => $row->forecast_bias,
                'confidence' => (float) $row->forecast_confidence,
                'current_price' => (float) $row->price_at_evaluation,
                'expected_mid_price' => (float) $row->expected_mid_price,
                'expected_low' => (float) $row->expected_low,
                'expected_high' => (float) $row->expected_high,
                'atr_pct' => (float) $row->atr_pct,
            ],
        ];
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
