<?php

namespace App\Domain\Intelligence;

use App\Domain\Market\Indicators\Indicators;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Risk\WorkspaceLiveRiskService;
use App\Domain\Trading\KlineBrainService;
use App\Domain\Trading\TradingIntegrationResolver;
use App\Models\MarketCandle;
use App\Models\Order;
use App\Models\OrderFill;
use App\Models\Position;
use App\Models\Trade;
use App\Models\TradeSignal;
use App\Models\WalletAccount;
use Illuminate\Support\Collection;

class AiContextBuilderService
{
    public function __construct(
        private readonly TradingIntegrationResolver $integrations,
        private readonly MarketPriceCacheService $prices,
        private readonly WorkspaceLiveRiskService $workspaceRisk,
        private readonly KillSwitchService $killSwitch,
        private readonly OperationalBreakerService $breakers,
        private readonly KlineBrainService $klineBrain,
    ) {
    }

    public function build(string $workspaceId, string $symbol, array $options = []): array
    {
        $symbol = strtoupper(trim($symbol));
        $provider = strtolower((string) ($options['exchange'] ?? 'binance'));
        $quoteCurrency = strtoupper((string) ($options['quote_currency'] ?? config('intelligence.ai_trading.default_quote_currency', 'USDT')));
        $blockers = [];

        try {
            $integration = $this->integrations->resolve($workspaceId, $provider, $options['integration_id'] ?? null);
        } catch (\Throwable $e) {
            $integration = null;
            $blockers[] = $e->getMessage();
        }

        $priceRow = $this->prices->getLatestPrice($provider, $symbol);
        $timeframe = (string) ($options['timeframe'] ?? '15m');
        [$candles, $candleSource] = $this->recentCandles($symbol, $timeframe);
        if ($priceRow === null && $candles->isEmpty()) {
            $blockers[] = 'No stored price or candle history is available for this symbol.';
        }

        $latestPrice = (float) ($priceRow['price'] ?? $candles->last()['close'] ?? 0);
        $indicatorSnapshot = $this->indicatorSnapshot($candles);

        $higherTimeframe = (string) ($options['higher_timeframe'] ?? '1h');
        $htfSnapshot = null;
        if ($higherTimeframe !== '' && $higherTimeframe !== $timeframe) {
            [$htfCandles] = $this->recentCandles($symbol, $higherTimeframe);
            if ($htfCandles->isNotEmpty()) {
                $htfSnapshot = $this->indicatorSnapshot($htfCandles);
            }
        }
        $positions = $this->positionSnapshot($workspaceId, $symbol);
        $orders = $this->orderSnapshot($workspaceId, $symbol, $provider);
        $balances = $this->balanceSnapshot($workspaceId, $quoteCurrency);
        if ($balances['capital_base'] <= 0) {
            $blockers[] = 'No available quote balance or capital base is available.';
        }

        $recentFills = $this->recentFillSnapshot($workspaceId, $symbol);
        $pnl = $this->pnlSnapshot($workspaceId, $symbol);
        $riskRules = $this->workspaceRisk->resolveRules($workspaceId, [
            'symbol' => $symbol,
            'provider' => $provider,
        ]);
        $signalHints = $this->signalHints($workspaceId, $symbol, $provider);
        $killState = $this->killSwitch->state($workspaceId);
        $providerBreaker = $this->breakers->providerState($workspaceId, $provider);
        $staleFeed = $this->breakers->staleFeedState($workspaceId);

        if ((bool) ($killState['active'] ?? false)) {
            $blockers[] = 'Kill switch is active.';
        }
        if ((bool) ($providerBreaker['active'] ?? false)) {
            $blockers[] = 'Provider breaker is active.';
        }
        if ((bool) ($staleFeed['active'] ?? false)) {
            $blockers[] = 'Stale feed breaker is active.';
        }

        return [
            'workspace_id' => $workspaceId,
            'exchange' => $provider,
            'market_type' => 'spot',
            'symbol' => $symbol,
            'quote_currency' => $quoteCurrency,
            'integration' => $integration ? [
                'id' => $integration->id,
                'provider_key' => strtolower((string) $integration->provider_key),
                'status' => (string) $integration->status,
                'environment' => (string) data_get($integration->config_json, 'environment', 'live'),
            ] : null,
            'price' => [
                'latest' => $latestPrice,
                'source' => $priceRow['source'] ?? ($candles->isEmpty() ? null : $candleSource),
                'timestamp_ms' => $priceRow['timestamp_ms'] ?? null,
            ],
            'candles' => [
                'timeframe' => $timeframe,
                'source' => $candles->isEmpty() ? null : $candleSource,
                'count' => $candles->count(),
                'latest_open' => (float) ($candles->last()['open'] ?? 0),
                'latest_close' => (float) ($candles->last()['close'] ?? 0),
                'recent_closes' => $candles->pluck('close')->map(fn ($value) => (float) $value)->values()->all(),
            ],
            'indicators' => $indicatorSnapshot,
            'indicators_by_timeframe' => array_filter([
                $timeframe => $indicatorSnapshot,
                $higherTimeframe => $htfSnapshot,
            ]),
            'positions' => $positions,
            'orders' => $orders,
            'balances' => $balances,
            'recent_fills' => $recentFills,
            'pnl' => $pnl,
            'risk' => [
                'rules' => $riskRules,
                'symbol_allowed' => $this->symbolAllowed($symbol, $riskRules),
            ],
            'ops' => [
                'kill_switch' => $killState,
                'provider_breaker' => $providerBreaker,
                'stale_feed_breaker' => $staleFeed,
            ],
            'signal_hints' => $signalHints,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * Prefer the live WS-fed kline cache (up to 250 closed candles); fall back to the
     * legacy market_candles table when the cache is cold for this symbol/timeframe.
     *
     * @return array{0: Collection<int, array{open: float, high: float, low: float, close: float, volume: float, ts: mixed}>, 1: string}
     */
    private function recentCandles(string $symbol, string $timeframe): array
    {
        $cached = $this->klineBrain->getCachedCandles($symbol, $timeframe);
        if (count($cached) >= 30) {
            $normalized = collect($cached)->map(fn (array $candle) => [
                'open' => (float) ($candle['open'] ?? 0),
                'high' => (float) ($candle['high'] ?? 0),
                'low' => (float) ($candle['low'] ?? 0),
                'close' => (float) ($candle['close'] ?? 0),
                'volume' => (float) ($candle['volume'] ?? 0),
                'ts' => $candle['ts'] ?? null,
            ])->values();

            return [$normalized, 'kline_cache'];
        }

        $rows = MarketCandle::query()
            ->whereHas('symbol', fn ($query) => $query->where('symbol', $symbol))
            ->where('timeframe', $timeframe)
            ->orderBy('opens_at')
            ->limit(200)
            ->get(['open', 'high', 'low', 'close', 'volume', 'opens_at'])
            ->map(fn (MarketCandle $candle) => [
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) $candle->volume,
                'ts' => optional($candle->opens_at)->getTimestampMs(),
            ])
            ->values();

        return [$rows, 'market_candles'];
    }

    private function indicatorSnapshot(Collection $candles): array
    {
        $candleArrays = $candles->values()->all();
        $closes = $candles->pluck('close')->map(fn ($value) => (float) $value)->values()->all();

        $volatilityPct = 0.0;
        if (count($closes) >= 2) {
            $returns = [];
            for ($i = 1; $i < count($closes); $i++) {
                $previous = $closes[$i - 1] ?: 1;
                $returns[] = (($closes[$i] - $previous) / $previous) * 100;
            }
            if ($returns !== []) {
                $mean = array_sum($returns) / count($returns);
                $variance = array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $returns)) / count($returns);
                $volatilityPct = round(sqrt($variance), 4);
            }
        }

        return [
            'sma_9' => Indicators::sma($closes, 9),
            'sma_21' => Indicators::sma($closes, 21),
            'ema_12' => Indicators::ema($closes, 12),
            'ema_26' => Indicators::ema($closes, 26),
            'ema_20' => Indicators::ema($closes, 20),
            'ema_50' => Indicators::ema($closes, 50),
            'ema_200' => Indicators::ema($closes, 200),
            'rsi_14' => Indicators::rsi($closes, 14),
            'bollinger' => Indicators::bollinger($closes, 20),
            'macd' => Indicators::macd($closes),
            'atr_14' => Indicators::atr($candleArrays, 14),
            'adx_14' => Indicators::adx($candleArrays, 14),
            'volatility_pct' => $volatilityPct,
            'candle_count' => count($closes),
            'has_minimum_history' => count($closes) >= 26,
        ];
    }

    private function positionSnapshot(string $workspaceId, string $symbol): array
    {
        $open = Position::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('symbol', fn ($query) => $query->where('symbol', $symbol))
            ->where(function ($query): void {
                $query->whereNull('market_type')->orWhere('market_type', 'spot')->orWhere('market_type', '');
            })
            ->get();

        $qty = $open->sum(fn (Position $position) => abs((float) ($position->qty ?? 0)));
        $notional = $open->sum(fn (Position $position) => abs((float) ($position->qty ?? 0)) * (float) ($position->last_mark_price ?? $position->avg_entry ?? 0));

        return [
            'open_count' => $open->count(),
            'symbol_qty' => round($qty, 8),
            'symbol_notional' => round($notional, 8),
            'open' => $open->map(fn (Position $position) => [
                'id' => $position->id,
                'qty' => (float) ($position->qty ?? 0),
                'avg_entry' => (float) ($position->avg_entry ?? 0),
                'last_mark_price' => (float) ($position->last_mark_price ?? 0),
                'unrealized_pnl' => (float) ($position->unrealized_pnl ?? 0),
            ])->all(),
        ];
    }

    private function orderSnapshot(string $workspaceId, string $symbol, string $provider): array
    {
        $openQuery = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->where('asset_code', $symbol)
            ->where(function ($query): void {
                $query->whereNull('meta->market_type')
                    ->orWhere('meta->market_type', 'spot')
                    ->orWhere('meta->market_type', 'live')
                    ->orWhere('meta->market_type', '');
            })
            ->whereNotIn('status', [
                Order::STATUS_FILLED,
                Order::STATUS_FAILED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
                strtoupper(Order::STATUS_FILLED),
                strtoupper(Order::STATUS_FAILED),
                strtoupper(Order::STATUS_CANCELLED),
                strtoupper(Order::STATUS_REJECTED),
            ]);

        return [
            'open_count' => $openQuery->count(),
            'open' => $openQuery->latest()->limit(10)->get()->map(fn (Order $order) => [
                'id' => $order->id,
                'side' => $order->side,
                'type' => $order->type,
                'qty' => (float) ($order->qty ?? $order->quantity ?? 0),
                'status' => $order->status,
                'idempotency_key' => $order->idempotency_key,
            ])->all(),
        ];
    }

    private function balanceSnapshot(string $workspaceId, string $quoteCurrency): array
    {
        $cash = WalletAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('currency', $quoteCurrency)
            ->where('type', 'cash')
            ->first();

        $available = (float) ($cash?->available ?? $cash?->balance ?? 0);
        $locked = (float) ($cash?->locked_balance ?? 0);
        $total = (float) ($cash?->balance ?? 0);

        return [
            'currency' => $quoteCurrency,
            'available_quote' => round($available, 8),
            'locked_quote' => round($locked, 8),
            'total_quote' => round($total, 8),
            'capital_base' => round(max($available, $total), 8),
        ];
    }

    private function recentFillSnapshot(string $workspaceId, string $symbol): array
    {
        return OrderFill::query()
            ->whereHas('order', function ($query) use ($workspaceId, $symbol): void {
                $query->where('workspace_id', $workspaceId)->where('asset_code', $symbol);
            })
            ->latest('fill_ts')
            ->limit(10)
            ->get()
            ->map(fn (OrderFill $fill) => [
                'id' => $fill->id,
                'fill_price' => (float) $fill->fill_price,
                'fill_qty' => (float) $fill->fill_qty,
                'fee' => (float) $fill->fee,
                'fee_asset' => $fill->fee_asset,
                'fill_ts' => optional($fill->fill_ts)->toIso8601String(),
            ])
            ->all();
    }

    private function pnlSnapshot(string $workspaceId, string $symbol): array
    {
        $cutoff = now()->subDay();
        $realized = (float) Trade::query()
            ->where('workspace_id', $workspaceId)
            ->where('asset_code', $symbol)
            ->where('executed_at', '>=', $cutoff)
            ->sum('realized_pnl');

        $tradeCount = Trade::query()
            ->where('workspace_id', $workspaceId)
            ->where('asset_code', $symbol)
            ->where('executed_at', '>=', $cutoff)
            ->count();

        $unrealized = (float) Position::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('symbol', fn ($query) => $query->where('symbol', $symbol))
            ->sum('unrealized_pnl');

        return [
            'realized_24h' => round($realized, 8),
            'unrealized_open' => round($unrealized, 8),
            'trade_count_24h' => $tradeCount,
        ];
    }

    private function signalHints(string $workspaceId, string $symbol, string $provider): array
    {
        $latest = TradeSignal::query()
            ->where('workspace_id', $workspaceId)
            ->where('asset_code', $symbol)
            ->where('provider', $provider)
            ->latest('decision_ts')
            ->first();

        return [
            'latest' => $latest ? [
                'id' => $latest->id,
                'side' => $latest->side,
                'decision_price' => (float) $latest->decision_price,
                'decision_ts' => optional($latest->decision_ts)->toIso8601String(),
                'status' => $latest->status,
                'qty' => (float) data_get($latest->meta_json, 'qty', 0),
            ] : null,
        ];
    }

    private function symbolAllowed(string $symbol, array $rules): bool
    {
        $allowed = array_values((array) ($rules['allowed_symbols'] ?? []));
        $blocked = array_values((array) ($rules['blocked_symbols'] ?? []));

        if ($allowed !== [] && ! in_array($symbol, $allowed, true)) {
            return false;
        }

        return ! in_array($symbol, $blocked, true);
    }
}
