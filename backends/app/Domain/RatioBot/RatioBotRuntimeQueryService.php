<?php

namespace App\Domain\RatioBot;

use App\Domain\Exchange\BinanceSpotClient;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\MarketData\CanonicalQuoteService;
use App\Domain\MarketData\Enums\FeedState;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketRecoveryManager;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Domain\Trading\TradingIntegrationResolver;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Models\ExchangeConnection;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\Order;
use App\Models\Position;
use App\Models\RatioBotBrainSnapshot;
use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Trade;
use Throwable;

class RatioBotRuntimeQueryService
{
    public function __construct(
        private readonly TradingModuleSettingsService $tradingSettings,
        private readonly TradingIntegrationResolver $integrations,
        private readonly LiveTradeProviderRegistry $providers,
        private readonly OperationalBreakerService $breakers,
        private readonly ExchangeClientFactory $exchangeClients,
        private readonly CanonicalQuoteService $quotes,
        private readonly MarketSnapshotService $snapshots,
        private readonly MarketRecoveryManager $recovery,
    ) {
    }

    public function snapshot(RatioBotConfig $config): array
    {
        return [
            'config' => $config->fresh('state'),
            'feed' => $this->feedHealth($config),
            'last_signal' => $this->lastSignal($config),
            'last_order' => $this->lastOrder($config),
            'position' => $this->currentPosition($config),
            'pnl' => $this->pnlSummary($config),
            'stage_plan' => $this->stagePlanSummary($config),
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
        // Scoped strictly to THIS bot's own orders — the previous `orWhere
        // meta->market_type` fallback matched any order on the same
        // symbol/market_type placed by a different ratio bot, Quick Trade,
        // or Robot Trade, misattributing someone else's order as this
        // config's "last order".
        $order = Order::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('asset_code', $config->normalizedSymbol())
            ->where('meta->ratio_bot_config_id', (string) $config->id)
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
                'direction' => $row->normalizedDirection(),
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
                'bias' => $this->normalizeForecastBias($row->forecast_bias),
                'confidence' => (float) $row->forecast_confidence,
                'current_price' => (float) $row->price_at_evaluation,
                'expected_mid_price' => (float) $row->expected_mid_price,
                'expected_low' => (float) $row->expected_low,
                'expected_high' => (float) $row->expected_high,
                'atr_pct' => (float) $row->atr_pct,
            ],
        ];
    }

    private function normalizeForecastBias(mixed $bias): string
    {
        return match (strtolower((string) $bias)) {
            'buy', 'bullish' => 'bullish',
            'sell', 'bearish' => 'bearish',
            default => 'neutral',
        };
    }

    public function pnlSummary(RatioBotConfig $config): array
    {
        $position = $this->currentPosition($config);
        $todayRealized = Trade::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('asset_code', $config->normalizedSymbol())
            ->whereDate('ts', now()->toDateString())
            ->sum('realized_pnl');

        // Estimated slippage cost + fees (spec §55): computed from ACTUAL
        // filled decisions (evaluated_price vs executed_price divergence,
        // and executed notional * fee_bps) — never fabricated. Fee is
        // charged on both legs of a round trip (buy+sell), matching
        // executionCostRatio()'s fee_bps*2 convention elsewhere.
        $feeBps = max(0.0, (float) ($config->fee_bps ?? 0));
        $fills = \App\Models\RatioBotDecision::query()
            ->where('ratio_bot_config_id', $config->id)
            ->whereIn('decision', [\App\Models\RatioBotDecision::DECISION_BUY, \App\Models\RatioBotDecision::DECISION_SELL])
            ->where('execution_status', \App\Models\RatioBotDecision::EXEC_FILLED)
            ->get(['evaluated_price', 'executed_price', 'executed_qty']);

        $estimatedSlippageCost = 0.0;
        $totalFees = 0.0;
        foreach ($fills as $fill) {
            $executedPrice = (float) ($fill->executed_price ?? 0);
            $evaluatedPrice = (float) ($fill->evaluated_price ?? $executedPrice);
            $qty = (float) ($fill->executed_qty ?? 0);
            $estimatedSlippageCost += abs($executedPrice - $evaluatedPrice) * $qty;
            $totalFees += $executedPrice * $qty * ($feeBps / 10000);
        }

        $realizedPnl = (float) ($config->state?->realized_pnl ?? 0);

        return [
            'today_realized_pnl' => (float) $todayRealized,
            'unrealized_pnl' => (float) ($position['unrealized_pnl'] ?? $config->state?->unrealized_pnl ?? 0),
            'current_position_qty' => (float) ($position['qty'] ?? $position['quantity'] ?? $config->state?->last_position_qty ?? 0),
            'current_position_side' => (string) ($config->state?->last_position_side ?? 'flat'),
            'realized_pnl' => $realizedPnl,
            'estimated_slippage_cost' => round($estimatedSlippageCost, 8),
            'total_fees' => round($totalFees, 8),
            'net_realized_pnl' => app(\App\Domain\Trading\ExchangeExecutionCostReconciliationService::class)->netPnl($realizedPnl, $totalFees),
        ];
    }

    /**
     * All lots (open + closed), newest first — the per-lot cost-basis ledger
     * (spec §17/§57), including the RECOVERY tag so the UI can label a
     * Stage 4 lot distinctly from normal Stage 1-3 lots.
     */
    public function lots(RatioBotConfig $config): array
    {
        return RatioBotLot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->orderByDesc('bought_at')
            ->get()
            ->map(fn (RatioBotLot $lot) => [
                'id' => $lot->id,
                'stage' => $lot->buy_stage_index,
                'lot_type' => $lot->lot_type,
                'buy_price' => (float) $lot->buy_price,
                'original_qty' => (float) $lot->original_qty,
                'remaining_qty' => (float) $lot->remaining_qty,
                'spent_quote' => (float) $lot->spent_quote,
                'allocation_pct' => (float) $lot->allocation_pct,
                'status' => $lot->status,
                'realized_pnl' => (float) $lot->realized_pnl,
                'sell_fills' => $lot->sell_fills,
                'bought_at' => optional($lot->bought_at)->toIso8601String(),
                'closed_at' => optional($lot->closed_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Live exchange balance for both sides of the bot's trading pair, using
     * the workspace's active exchange integration (the same resolution
     * LiveWalletController::exchangeBalance() uses) — reused rather than
     * duplicated so Ratio Bot's wallet panel never disagrees with the
     * platform's other "live balance" surfaces.
     */
    public function walletBalances(RatioBotConfig $config): array
    {
        [$base, $quote] = $config->splitSymbolAssets();
        $state = $this->tradingSettings->tradingState((string) $config->workspace_id);
        $result = [
            'symbol' => $config->normalizedSymbol(),
            'base_asset' => $base,
            'quote_asset' => $quote,
            'mode' => $state['mode'] ?? 'paper',
            'base' => null,
            'quote' => null,
        ];

        if (($state['mode'] ?? 'paper') !== 'live' || ! $base || ! $quote) {
            return $result;
        }

        try {
            if ($config->marketType() !== 'futures') {
                if (! $config->connection_id) {
                    throw new \RuntimeException('Spot ratio bot does not have a selected exchange connection.');
                }

                $connection = ExchangeConnection::query()
                    ->where('workspace_id', $config->workspace_id)
                    ->whereKey($config->connection_id)
                    ->first();

                if (! $connection) {
                    throw new \RuntimeException('Selected spot exchange connection was not found.');
                }
                if (! $connection->is_active) {
                    throw new \RuntimeException('Selected spot exchange connection is not active.');
                }

                $balances = $this->exchangeClients->makeFromConnection($connection)->fetchBalances();
                $result['environment'] = $connection->is_testnet ? 'testnet' : 'live';
                $result['connection_id'] = (string) $connection->id;
                $result['connection_name'] = $connection->name;
                $result['quote'] = $this->balanceForAsset($balances, $quote);
                $result['base'] = $this->balanceForAsset($balances, $base);

                return $result;
            }

            $provider = (string) ($state['preferred_provider'] ?? 'binance');
            $integration = $this->integrations->resolve((string) $config->workspace_id, $provider, $state['preferred_integration_id'] ?? null);
            $client = $this->providers->for($provider);
            $result['environment'] = $state['exchange_environment'] ?? ($integration->environment ?? 'live');
            $result['integration_id'] = (string) $integration->id;
            $result['integration_name'] = $integration->display_name;
            $result['quote'] = $client->availableBalance($integration, $quote);
            $result['base'] = $client->availableBalance($integration, $base);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Pre-Start readiness gate (spec §2): validate actual balance for the
     * asset the configured starting_entry needs — BUY needs the quote asset,
     * SELL needs the base asset. Fails open (ready=true) when starting_entry
     * isn't set or the bot isn't in live mode, since sandbox/paper bots don't
     * spend a real balance.
     */
    public function startingEntryReadiness(RatioBotConfig $config): array
    {
        $entry = $config->startingEntry();
        if ($entry === null) {
            return ['ready' => true, 'checked' => false, 'reason' => 'no starting_entry configured'];
        }

        $wallet = $this->walletBalances($config);
        if (($wallet['mode'] ?? 'paper') !== 'live') {
            return ['ready' => true, 'checked' => false, 'reason' => 'sandbox/paper mode does not require a live balance check'];
        }

        $side = $entry === 'BUY' ? 'quote' : 'base';
        $asset = $entry === 'BUY' ? $wallet['quote_asset'] : $wallet['base_asset'];
        $balance = $wallet[$side] ?? null;

        if ($asset === null) {
            return ['ready' => false, 'checked' => true, 'reason' => "could not determine the {$side} asset for {$config->normalizedSymbol()}"];
        }
        if ($balance === null) {
            return ['ready' => false, 'checked' => true, 'reason' => $wallet['error'] ?? 'could not fetch live balance', 'asset' => $asset];
        }

        $available = (float) ($balance['available'] ?? $balance['balance'] ?? 0);
        if ($available <= 0) {
            return [
                'ready' => false,
                'checked' => true,
                'reason' => "BOT NOT READY: {$entry} selected but insufficient available {$asset}",
                'asset' => $asset,
                'available' => $available,
            ];
        }

        return ['ready' => true, 'checked' => true, 'asset' => $asset, 'available' => $available];
    }

    /**
     * The TRUTHFUL, execution-equivalent feed status (spec §60-61). Reuses
     * the exact same OperationalBreakerService checks
     * RunRatioBotEvaluationJob::healthBlockReason() gates execution on, so
     * this can never say "Fresh" while a breaker that would actually block a
     * trade is active — the historical bug was the UI (MarketSnapshotBar)
     * deriving freshness independently from raw price-cache age only. This
     * is a read-only status derivation (no live price fetch, no side
     * effects) — it reflects the state the last real evaluation cycle left
     * behind, not a fresh network round-trip.
     */
    public function effectiveFeedStatus(RatioBotConfig $config): array
    {
        $state = $config->state;
        $workspaceId = (string) $config->workspace_id;
        $maxAge = $config->staleFeedWindowSeconds();

        if (! $config->usesRealtimeFeed() && $state?->last_price_timestamp) {
            $ageSeconds = max(0, $state->last_price_timestamp->diffInSeconds(now(), true));

            if ($ageSeconds <= $maxAge) {
                return $this->feedStatusResult(
                    'FRESH',
                    'persisted_state_price_timestamp_ok',
                    $state,
                    $maxAge,
                    [
                        'age_seconds' => $ageSeconds,
                        'source' => $state->last_price_source,
                    ]
                );
            }

            return $this->feedStatusResult(
                'STALE_FEED',
                'persisted_state_price_timestamp_stale',
                $state,
                $maxAge,
                [
                    'age_seconds' => $ageSeconds,
                    'source' => $state->last_price_source,
                ]
            );
        }

        $snapshot = $this->snapshots->forRatioBot($config);

        if ((bool) ($snapshot['is_fresh'] ?? false)) {
            $staleFeedState = $this->breakers->staleFeedState($workspaceId, $config->normalizedSymbol());
            if ((bool) ($staleFeedState['active'] ?? false)) {
                $this->breakers->clearStaleFeedBreaker($workspaceId, [
                    'symbol' => $config->normalizedSymbol(),
                    'received_at' => $snapshot['received_at'] ?? null,
                    'age_ms' => $snapshot['age_ms'] ?? null,
                    'reason' => 'selected_symbol_snapshot_fresh',
                ], 'market_snapshot', $config->normalizedSymbol());
            }

            return $this->feedStatusResult(
                'FRESH',
                'selected_symbol_market_snapshot_ok',
                $state,
                $maxAge,
                [
                    'snapshot' => $snapshot,
                    'age_seconds' => $snapshot['age_seconds'] ?? null,
                    'connection_state' => $snapshot['connection_state'] ?? null,
                    'source' => $snapshot['source'] ?? null,
                ]
            );
        }

        $provider = strtolower((string) ($config->exchange ?: 'binance'));
        $this->recovery->requestRecovery($provider, $config->normalizedSymbol(), StreamType::QUOTE, 'ratio_bot_readiness_stale_feed', [
            'config_id' => (string) $config->id,
            'workspace_id' => $workspaceId,
            'snapshot' => [
                'age_ms' => $snapshot['age_ms'] ?? null,
                'connection_state' => $snapshot['connection_state'] ?? null,
                'stale_reason' => $snapshot['stale_reason'] ?? null,
            ],
        ]);

        $staleFeedState = $this->breakers->staleFeedState($workspaceId, $config->normalizedSymbol());
        if ((bool) ($staleFeedState['active'] ?? false)) {
            return $this->feedStatusResult('STALE_FEED', 'stale_feed_breaker_active', $state, $maxAge, ['breaker' => $staleFeedState, 'snapshot' => $snapshot]);
        }

        $exchange = strtolower((string) ($config->exchange ?: 'binance'));
        if ($exchange === 'binance') {
            $providerState = $this->breakers->providerState($workspaceId, 'binance');
            if ((bool) ($providerState['active'] ?? false)) {
                return $this->feedStatusResult('EXCHANGE_UNHEALTHY', 'binance_provider_breaker_active', $state, $maxAge, ['provider_breaker' => $providerState]);
            }
        }

        if ($config->isLiveMode() && $config->connection_id && in_array($config->evaluationModeKey(), ['websocket', 'hybrid', 'tick', 'candle_close'], true)) {
            $connection = ExchangeConnection::query()->where('workspace_id', $workspaceId)->find($config->connection_id);
            if ($connection) {
                $streamState = $this->breakers->streamState($connection);
                $streamStatus = (string) ($streamState['status'] ?? 'idle');
                if (in_array($config->evaluationModeKey(), ['websocket', 'tick', 'candle_close'], true) && in_array($streamStatus, ['error', 'stopped'], true)) {
                    return $this->feedStatusResult('WEBSOCKET_UNHEALTHY', "websocket_stream_{$streamStatus}", $state, $maxAge, ['stream_state' => $streamState]);
                }
            }
        }

        $this->breakers->activateStaleFeedBreaker($workspaceId, [
            'symbol' => $config->normalizedSymbol(),
            'received_at' => $snapshot['received_at'] ?? null,
            'age_ms' => $snapshot['age_ms'] ?? null,
            'max_age_seconds' => $maxAge,
            'connection_state' => $snapshot['connection_state'] ?? FeedState::STALE->value,
            'reason' => $snapshot['stale_reason'] ?? 'selected_symbol_snapshot_not_fresh',
        ], 'market_snapshot', $config->normalizedSymbol());

        return $this->feedStatusResult(
            'STALE_FEED',
            (string) ($snapshot['stale_reason'] ?? 'selected_symbol_snapshot_not_fresh'),
            $state,
            $maxAge,
            ['age_seconds' => $snapshot['age_seconds'] ?? null, 'snapshot' => $snapshot]
        );
    }

    private function feedStatusResult(string $status, string $reason, ?RatioBotState $state, int $maxAge, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'reason' => $reason,
            'fresh' => $status === 'FRESH',
            'price_source' => $state?->last_price_source,
            'last_price_timestamp' => optional($state?->last_price_timestamp)->toIso8601String(),
            'max_age_seconds' => $maxAge,
        ], $extra);
    }

    /**
     * Per-day trade/P&L rollup for Testnet observation (spec §80-81), built
     * only from actual recorded executions (filled decisions + closed lots)
     * — never fabricated. Per-day unrealized P&L and max intraday drawdown
     * are intentionally omitted rather than approximated: neither is
     * tracked per-day historically today (observed_low/high on
     * ratio_bot_states is an all-time running value, not a daily one).
     */
    public function dailyReport(RatioBotConfig $config): array
    {
        $feeBps = max(0.0, (float) ($config->fee_bps ?? 0));
        $byDay = [];

        $decisions = $config->decisions()
            ->whereIn('decision', [\App\Models\RatioBotDecision::DECISION_BUY, \App\Models\RatioBotDecision::DECISION_SELL])
            ->where('execution_status', \App\Models\RatioBotDecision::EXEC_FILLED)
            ->orderBy('executed_at')
            ->get();

        foreach ($decisions as $decision) {
            $day = optional($decision->executed_at)->toDateString();
            if (! $day) {
                continue;
            }
            $byDay[$day] ??= ['date' => $day, 'trades' => 0, 'buy_count' => 0, 'sell_count' => 0, 'fees' => 0.0, 'recovery' => false, 'net_pnl' => 0.0];
            $byDay[$day]['trades']++;
            $byDay[$day][(string) $decision->decision === 'BUY' ? 'buy_count' : 'sell_count']++;
            $notional = (float) ($decision->executed_price ?? 0) * (float) ($decision->executed_qty ?? 0);
            $byDay[$day]['fees'] += $notional * ($feeBps / 10000);
            if ((bool) data_get($decision->metadata, 'recovery', false)) {
                $byDay[$day]['recovery'] = true;
            }
        }

        $lots = RatioBotLot::query()->where('ratio_bot_config_id', $config->id)->whereNotNull('closed_at')->get();
        foreach ($lots as $lot) {
            $day = optional($lot->closed_at)->toDateString();
            if (! $day) {
                continue;
            }
            $byDay[$day] ??= ['date' => $day, 'trades' => 0, 'buy_count' => 0, 'sell_count' => 0, 'fees' => 0.0, 'recovery' => false, 'net_pnl' => 0.0];
            $byDay[$day]['net_pnl'] += (float) $lot->realized_pnl;
            if ($lot->lot_type === RatioBotLot::TYPE_RECOVERY) {
                $byDay[$day]['recovery'] = true;
            }
        }

        ksort($byDay);

        return [
            'days' => array_values($byDay),
            'note' => 'net_pnl is realized profit from lots closed that day. Unrealized P&L and per-day max drawdown are not included — neither is tracked on a per-day basis historically.',
        ];
    }

    /**
     * The latest still-actionable suggestion for advisor/manual_approval mode
     * bots (spec §51): same decision engine as automatic mode, just not
     * auto-executed. Null once it's been approved/rejected or has gone
     * stale — a suggestion older than this window is re-evaluated fresh
     * anyway (RatioBotDecisionService::hasUnresolvedTrade() only guards a
     * 30-minute window), so approving something older than that would be
     * acting on a genuinely outdated read of the market.
     */
    public function pendingApproval(RatioBotConfig $config): ?array
    {
        $decision = \App\Models\RatioBotDecision::query()
            ->where('ratio_bot_config_id', $config->id)
            ->whereIn('decision', [\App\Models\RatioBotDecision::DECISION_BUY, \App\Models\RatioBotDecision::DECISION_SELL])
            ->where('metadata->requires_manual_approval', true)
            ->whereNull('metadata->manual_decision')
            ->where('decided_at', '>=', now()->subMinutes(10))
            ->orderByDesc('decided_at')
            ->first();

        if (! $decision) {
            return null;
        }

        return [
            'id' => $decision->id,
            'decision' => $decision->decision,
            'evaluated_price' => (float) $decision->evaluated_price,
            'anchor_price' => $decision->anchor_price !== null ? (float) $decision->anchor_price : null,
            'computed_ratio' => $decision->computed_ratio !== null ? (float) $decision->computed_ratio : null,
            'reason' => $decision->reason,
            'decided_at' => optional($decision->decided_at)->toIso8601String(),
            'expires_at' => optional($decision->decided_at)->addMinutes(10)->toIso8601String(),
            'metadata' => $decision->metadata,
        ];
    }

    /**
     * The rich Start-readiness checklist (spec §3): "Validate exchange,
     * wallet, market feed, symbol, risk state, exchange filters" — each as
     * its own pass/fail/warn/skip item with a human-readable reason, so an
     * operator can see EXACTLY which check is blocking Start instead of a
     * single opaque error. Every check reuses an existing service — no new
     * validation logic invented, just surfaced together. Never throws: a
     * check that can't complete reports itself as 'warn', not a 500.
     */
    public function startupChecklist(RatioBotConfig $config): array
    {
        $items = [
            $this->checklistExchangeConnection($config),
            $this->checklistWalletBalance($config),
            $this->checklistMarketFeed($config),
            ...$this->checklistSymbolAndFilters($config),
            $this->checklistRiskState($config),
        ];

        return [
            'items' => $items,
            'overall_ready' => ! collect($items)->contains(fn (array $item) => $item['status'] === 'fail'),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function checklistItem(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function balanceForAsset(array $balances, string $asset): array
    {
        $asset = strtoupper($asset);
        foreach ($balances as $balance) {
            if (strtoupper((string) ($balance['asset'] ?? '')) === $asset) {
                $free = (float) ($balance['free'] ?? 0);
                $locked = (float) ($balance['locked'] ?? 0);

                return [
                    'asset' => $asset,
                    'balance' => $free + $locked,
                    'available' => $free,
                    'free' => $free,
                    'locked' => $locked,
                ];
            }
        }

        return [
            'asset' => $asset,
            'balance' => 0.0,
            'available' => 0.0,
            'free' => 0.0,
            'locked' => 0.0,
        ];
    }

    private function checklistExchangeConnection(RatioBotConfig $config): array
    {
        $label = 'Exchange Connection';
        if ($config->isSandbox()) {
            return $this->checklistItem('exchange_connection', $label, 'skip', 'Sandbox mode does not require a live exchange connection.');
        }
        if ($config->marketType() === 'futures') {
            return $this->checklistItem('exchange_connection', $label, $config->integration_id ? 'pass' : 'fail', $config->integration_id ? 'Futures integration selected.' : 'No futures integration selected.');
        }
        if (! $config->connection_id) {
            return $this->checklistItem('exchange_connection', $label, 'fail', 'No spot exchange connection selected.');
        }

        $connection = ExchangeConnection::query()
            ->where('workspace_id', $config->workspace_id)
            ->whereKey($config->connection_id)
            ->first();

        if (! $connection) {
            return $this->checklistItem('exchange_connection', $label, 'fail', 'Selected exchange connection was not found.');
        }
        if (! $connection->is_active) {
            return $this->checklistItem('exchange_connection', $label, 'fail', "Connection \"{$connection->name}\" is not active.");
        }

        return $this->checklistItem('exchange_connection', $label, 'pass', "Connected: {$connection->name}" . ($connection->is_testnet ? ' (TESTNET)' : ''));
    }

    private function checklistWalletBalance(RatioBotConfig $config): array
    {
        $label = 'Wallet Balance';
        $readiness = $this->startingEntryReadiness($config);

        if ($readiness['checked']) {
            return $this->checklistItem(
                'wallet_balance',
                $label,
                $readiness['ready'] ? 'pass' : 'fail',
                $readiness['ready']
                    ? sprintf('%s available: %s', $readiness['asset'] ?? '', number_format((float) ($readiness['available'] ?? 0), 8))
                    : (string) ($readiness['reason'] ?? 'Insufficient balance.'),
            );
        }

        // startingEntryReadiness() only fires when a starting_entry side is
        // declared (it needs to know BUY->quote vs SELL->base) — without one
        // it always skips, which hid real balance data behind an opaque
        // "skip" for every bot that hasn't set that field. Fall back to
        // reporting both the quote and base balances directly so this item
        // still means something.
        if ($config->isSandbox()) {
            return $this->checklistItem('wallet_balance', $label, 'skip', 'Sandbox mode does not require a live balance check.');
        }

        $wallet = $this->walletBalances($config);
        if (($wallet['mode'] ?? 'paper') !== 'live') {
            return $this->checklistItem('wallet_balance', $label, 'skip', 'Workspace trading mode is paper, not live.');
        }

        if ($wallet['quote'] === null && $wallet['base'] === null) {
            return $this->checklistItem('wallet_balance', $label, 'warn', (string) ($wallet['error'] ?? 'Could not fetch live wallet balances.'));
        }

        $quoteAvailable = (float) ($wallet['quote']['available'] ?? 0);
        $baseAvailable = (float) ($wallet['base']['available'] ?? 0);
        $detail = sprintf(
            '%s: %s, %s: %s',
            $wallet['quote_asset'] ?? 'quote',
            number_format($quoteAvailable, 8),
            $wallet['base_asset'] ?? 'base',
            number_format($baseAvailable, 8),
        );
        $hasFunds = $quoteAvailable > 0 || $baseAvailable > 0;

        return $this->checklistItem(
            'wallet_balance',
            $label,
            $hasFunds ? 'pass' : 'warn',
            $hasFunds ? $detail : "No funds detected yet ({$detail}) — fund the account, or set Starting Entry for a stricter check.",
        );
    }

    private function checklistMarketFeed(RatioBotConfig $config): array
    {
        $label = 'Market Feed';
        $feed = $this->effectiveFeedStatus($config);
        $snapshot = (array) ($feed['snapshot'] ?? []);
        $state = (string) ($snapshot['connection_state'] ?? $feed['status'] ?? 'unknown');
        $ageMs = $snapshot['age_ms'] ?? null;
        $age = is_numeric($ageMs) ? sprintf('%.2fs', ((float) $ageMs) / 1000) : 'unknown';
        $source = (string) ($snapshot['source'] ?? $feed['source'] ?? 'none');
        $symbol = (string) ($snapshot['symbol'] ?? $config->normalizedSymbol());

        return $this->checklistItem(
            'market_feed',
            $label,
            ($feed['fresh'] ?? false) ? 'pass' : 'fail',
            sprintf('%s: %s %s via %s, age %s (%s)', (string) ($feed['status'] ?? 'unknown'), $symbol, $state, $source, $age, (string) ($feed['reason'] ?? 'n/a')),
        );
    }

    private function checklistRiskState(RatioBotConfig $config): array
    {
        $label = 'Risk State';
        if ((bool) ($config->kill_switch ?? false)) {
            return $this->checklistItem('risk_state', $label, 'fail', 'Kill switch is active.');
        }

        $state = $config->state;
        if ($state?->isBlocked()) {
            return $this->checklistItem('risk_state', $label, 'fail', 'Bot is currently ' . $state->position_state . ($state->block_reason_detail ? ": {$state->block_reason_detail}" : '.'));
        }

        $maxLoss = (float) ($config->max_daily_loss ?? 0);
        if ($maxLoss > 0 && (float) ($state?->daily_loss_total ?? 0) >= $maxLoss) {
            return $this->checklistItem('risk_state', $label, 'fail', 'Daily loss guard already reached.');
        }

        return $this->checklistItem('risk_state', $label, 'pass', 'No active blocks, kill switch off, within daily loss guard.');
    }

    /**
     * @return array{0: array, 1: array} [symbol_valid, exchange_filters] — one live exchangeInfo fetch, two checklist items.
     */
    private function checklistSymbolAndFilters(RatioBotConfig $config): array
    {
        [$base, $quote] = $config->splitSymbolAssets();
        if (! $base || ! $quote) {
            $fail = $this->checklistItem('symbol_valid', 'Symbol Valid', 'fail', "Could not resolve base/quote assets from \"{$config->symbol}\".");
            return [$fail, $this->checklistItem('exchange_filters', 'Exchange Filters', 'skip', 'Symbol not resolved.')];
        }

        if ($config->isSandbox() || $config->marketType() === 'futures' || ! $config->connection_id) {
            $skip = $this->checklistItem('exchange_filters', 'Exchange Filters', 'skip', 'Only checked live against a spot exchange connection.');
            return [$this->checklistItem('symbol_valid', 'Symbol Valid', 'pass', "{$config->normalizedSymbol()} -> base={$base}, quote={$quote}."), $skip];
        }

        try {
            $connection = ExchangeConnection::query()
                ->where('workspace_id', $config->workspace_id)
                ->whereKey($config->connection_id)
                ->first();
            if (! $connection) {
                throw new \RuntimeException('connection not found');
            }

            $client = $this->exchangeClients->makeFromConnection($connection);
            if (! $client instanceof BinanceSpotClient) {
                $skip = $this->checklistItem('exchange_filters', 'Exchange Filters', 'skip', 'Filter lookup only implemented for Binance.');
                return [$this->checklistItem('symbol_valid', 'Symbol Valid', 'pass', "{$config->normalizedSymbol()} -> base={$base}, quote={$quote} (not verified live)."), $skip];
            }

            $info = $client->fetchExchangeInfo($config->normalizedSymbol());
            $symbolInfo = collect((array) ($info['symbols'] ?? []))->first(fn ($s) => ($s['symbol'] ?? null) === $config->normalizedSymbol());

            if (! $symbolInfo) {
                $fail = $this->checklistItem('symbol_valid', 'Symbol Valid', 'fail', "\"{$config->normalizedSymbol()}\" was not found on the exchange.");
                return [$fail, $this->checklistItem('exchange_filters', 'Exchange Filters', 'skip', 'Symbol not found.')];
            }

            $status = (string) ($symbolInfo['status'] ?? 'UNKNOWN');
            $symbolItem = $this->checklistItem(
                'symbol_valid',
                'Symbol Valid',
                $status === 'TRADING' ? 'pass' : 'fail',
                "{$config->normalizedSymbol()} status={$status}.",
            );

            $filters = collect((array) ($symbolInfo['filters'] ?? []));
            $lotSize = $filters->firstWhere('filterType', 'LOT_SIZE');
            $minNotional = $filters->firstWhere('filterType', 'MIN_NOTIONAL') ?? $filters->firstWhere('filterType', 'NOTIONAL');
            $minNotionalValue = (float) ($minNotional['minNotional'] ?? $minNotional['notional'] ?? 0);
            $budget = (float) ($config->base_quote_amount ?? 0);

            if (! $lotSize || ! $minNotional) {
                $filtersItem = $this->checklistItem('exchange_filters', 'Exchange Filters', 'warn', 'LOT_SIZE/MIN_NOTIONAL filters were not found in the exchange response.');
            } elseif ($minNotionalValue > 0 && $budget > 0 && $budget < $minNotionalValue) {
                $filtersItem = $this->checklistItem('exchange_filters', 'Exchange Filters', 'fail', "Configured base_quote_amount ({$budget}) is below the exchange's minimum notional ({$minNotionalValue}).");
            } else {
                $filtersItem = $this->checklistItem('exchange_filters', 'Exchange Filters', 'pass', "LOT_SIZE step={$lotSize['stepSize']}, min notional={$minNotionalValue}.");
            }

            return [$symbolItem, $filtersItem];
        } catch (Throwable $e) {
            $warn = $this->checklistItem('symbol_valid', 'Symbol Valid', 'warn', 'Could not verify live: ' . $e->getMessage());
            return [$warn, $this->checklistItem('exchange_filters', 'Exchange Filters', 'skip', 'Symbol check failed.')];
        }
    }

    public function stagePlanSummary(RatioBotConfig $config): array
    {
        $state = $config->state;
        $plan = app(RatioBotStagePlanService::class)->plan($config);
        $lots = app(RatioBotLotService::class);

        return [
            'enabled' => app(RatioBotStagePlanService::class)->enabled($config),
            'strategy_health_status' => $state?->strategy_health_status,
            'market_regime' => $state?->market_regime,
            'range_status' => $state?->range_status,
            'current_stage_side' => $state?->current_stage_side,
            'current_stage_index' => $state?->current_stage_index,
            'used_buy_allocation_pct' => (float) ($state?->used_buy_allocation_pct ?? 0),
            'used_sell_allocation_pct' => (float) ($state?->used_sell_allocation_pct ?? 0),
            'reserve_pct' => $plan['reserve_pct'],
            'deployable_quote' => app(RatioBotStagePlanService::class)->deployableQuote($config),
            'buy_stages_total' => count($plan['buy_allocations']),
            'buy_stages_filled' => $lots->buyStagesFilled($config),
            'sell_stages_total' => count($plan['sell_allocations']),
            'sell_stages_completed' => $lots->sellStagesCompleted($config),
            'open_lots' => $lots->openLots($config)->map(fn ($lot) => [
                'id' => $lot->id,
                'stage' => $lot->buy_stage_index,
                'buy_price' => (float) $lot->buy_price,
                'original_qty' => (float) $lot->original_qty,
                'remaining_qty' => (float) $lot->remaining_qty,
                'allocation_pct' => (float) $lot->allocation_pct,
                'status' => $lot->status,
            ])->values()->all(),
        ];
    }
}
