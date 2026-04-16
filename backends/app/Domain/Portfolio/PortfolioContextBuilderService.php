<?php

namespace App\Domain\Portfolio;

use App\Domain\Intelligence\AiContextBuilderService;
use App\Models\AiTradingConfig;
use App\Models\ExchangeSymbolMetadata;
use App\Models\ExternalContextSnapshot;
use App\Models\Order;
use App\Models\PortfolioIntelligenceConfig;
use App\Models\PortfolioIntelligenceRun;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Trade;
use App\Models\TradeSignal;
use App\Models\WalletAccount;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PortfolioContextBuilderService
{
    public function __construct(
        private readonly AiContextBuilderService $symbolContextBuilder,
        private readonly CorrelationHeuristicService $correlations,
    ) {
    }

    public function build(string $workspaceId, array $options = []): array
    {
        $workspace = Workspace::query()->find($workspaceId);
        $aiConfig = $this->aiConfig($workspaceId);
        $portfolioConfig = $this->portfolioConfig($workspaceId);
        $quoteCurrency = strtoupper((string) ($options['quote_currency'] ?? config('intelligence.ai_trading.default_quote_currency', 'USDT')));
        $timeframe = (string) ($options['timeframe'] ?? '1m');
        $maxUniverse = max(1, min(50, (int) ($portfolioConfig->max_universe_symbols ?? 20)));

        $spotPositions = $this->spotPositions($workspaceId);
        $futuresPositions = $this->futuresPositions($workspaceId);
        $spotOpenOrders = $this->spotOpenOrders($workspaceId);
        $futuresOpenOrders = $this->futuresOpenOrders($workspaceId);
        $openOrders = $spotOpenOrders;
        $recentTrades = $this->recentTrades($workspaceId);
        $cashAccounts = $this->cashAccounts($workspaceId, $quoteCurrency);
        $candidateSymbols = $this->candidateSymbols($workspaceId, $aiConfig, $spotPositions, $spotOpenOrders, $recentTrades, $maxUniverse);
        $equityEstimate = $this->equityEstimate($cashAccounts, $spotPositions, $spotOpenOrders);
        $groupExposure = $this->correlations->groupExposure($spotPositions->map(fn (Position $position) => $this->positionPayload($position))->all(), $equityEstimate);
        $symbolExposure = $this->symbolExposure($spotPositions, $equityEstimate);
        $symbolContexts = $this->symbolContexts($workspaceId, $candidateSymbols, $aiConfig, $timeframe, $options, $spotPositions, $spotOpenOrders, $recentTrades, $equityEstimate, $groupExposure, $symbolExposure);
        $latestExternal = $this->latestWorkspaceExternalContext($workspaceId);
        $dailyPnl = $this->dailyPnl($workspaceId);
        $peakEquity = $this->peakEquityEstimate($workspaceId, $equityEstimate);
        $riskProfile = $this->riskProfile($workspaceId);

        return [
            'workspace_id' => $workspaceId,
            'generated_at' => now()->toIso8601String(),
            'quote_currency' => $quoteCurrency,
            'portfolio' => [
                'workspace' => $workspace ? [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'timezone' => $workspace->timezone,
                ] : null,
                'mode' => [
                    'ai_mode' => (string) ($aiConfig->mode ?? 'advisor'),
                    'trading_enabled' => (bool) ($aiConfig->enabled ?? false),
                    'portfolio_intelligence_enabled' => (bool) ($portfolioConfig->enabled ?? false),
                ],
                'capital' => [
                    'currency' => $quoteCurrency,
                    'cash_available' => round($this->cashAvailable($cashAccounts), 8),
                    'cash_locked' => round($this->cashLocked($cashAccounts), 8),
                    'allocated_capital' => round($this->allocatedCapital($spotPositions, $openOrders), 8),
                    'free_capital' => round($this->freeCapital($cashAccounts, $openOrders, $portfolioConfig), 8),
                    'equity_estimate' => round($equityEstimate, 8),
                    'peak_equity_estimate' => round($peakEquity, 8),
                    'daily_realized_pnl' => round($dailyPnl['realized'], 8),
                    'daily_unrealized_pnl' => round($dailyPnl['unrealized'], 8),
                    'daily_total_pnl' => round($dailyPnl['realized'] + $dailyPnl['unrealized'], 8),
                ],
                'exposure' => [
                    'spot_positions' => $spotPositions->count(),
                    'futures_positions' => $futuresPositions->count(),
                    'spot_open_orders' => $spotOpenOrders->count(),
                    'futures_open_orders' => $futuresOpenOrders->count(),
                    'open_orders' => $spotOpenOrders->count() + $futuresOpenOrders->count(),
                    'by_symbol' => $symbolExposure,
                    'by_group' => $groupExposure,
                ],
                'risk' => [
                    'profile' => $riskProfile,
                    'kill_switch' => app(\App\Domain\Ops\KillSwitchService::class)->state($workspaceId),
                    'provider_breaker' => app(\App\Domain\Ops\OperationalBreakerService::class)->providerState($workspaceId, 'binance'),
                    'stale_feed_breaker' => app(\App\Domain\Ops\OperationalBreakerService::class)->staleFeedState($workspaceId),
                    'portfolio_intelligence' => $portfolioConfig->toArray(),
                ],
                'status' => [
                    'insufficient_data' => $spotPositions->isEmpty() && $cashAccounts === [],
                    'open_ai_decisions' => \App\Models\AiDecision::query()->where('workspace_id', $workspaceId)->whereIn('final_status', [\App\Models\AiDecision::STATUS_RECORDED, \App\Models\AiDecision::STATUS_PENDING_APPROVAL, \App\Models\AiDecision::STATUS_APPROVED])->count(),
                    'pending_ai_cycles' => \App\Models\AiDecisionCycle::query()->where('workspace_id', $workspaceId)->whereIn('status', [\App\Models\AiDecisionCycle::STATUS_STARTED, \App\Models\AiDecisionCycle::STATUS_PENDING_APPROVAL])->count(),
                    'recent_rejections' => \App\Models\AiDecision::query()->where('workspace_id', $workspaceId)->whereIn('final_status', [\App\Models\AiDecision::STATUS_REJECTED, \App\Models\AiDecision::STATUS_BLOCKED, \App\Models\AiDecision::STATUS_EXPIRED, \App\Models\AiDecision::STATUS_EXECUTION_FAILED])->count(),
                ],
                'external_context' => $latestExternal,
            ],
            'candidate_symbols' => $candidateSymbols,
            'symbols' => $symbolContexts,
            'futures' => [
                'supported' => true,
                'positions' => $futuresPositions->map(fn (Position $position) => $this->positionPayload($position))->values()->all(),
                'open_orders' => $futuresOpenOrders->map(fn (Order $order) => $this->ordersPayload(collect([$order]))[0])->values()->all(),
            ],
            'signals' => [
                'latest_trade_signals' => $this->signalSnapshot($workspaceId),
            ],
            'risk_profiles' => $riskProfile ? [$riskProfile] : [],
            'portfolio_intelligence_config' => $portfolioConfig->toArray(),
            'ai_trading_config' => $aiConfig->toArray(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidateSymbols(string $workspaceId, AiTradingConfig $aiConfig, Collection $spotPositions, Collection $openOrders, Collection $recentTrades, int $maxUniverse): array
    {
        $symbols = [];

        foreach ($aiConfig->effectiveEnabledSymbols() as $symbol) {
            $this->pushCandidate($symbols, (string) $symbol, 'ai_allowlist', 70);
        }

        foreach ($spotPositions as $position) {
            $this->pushCandidate($symbols, (string) ($position->asset_code ?: $position->symbol?->symbol ?: ''), 'open_position', 100);
        }

        foreach ($openOrders as $order) {
            $this->pushCandidate($symbols, (string) ($order->asset_code ?: $order->symbol?->symbol ?: ''), 'open_order', 90);
        }

        foreach ($recentTrades as $trade) {
            $this->pushCandidate($symbols, (string) ($trade->asset_code ?: $trade->symbol?->symbol ?: ''), 'recent_trade', 80);
        }

        $signals = TradeSignal::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('asset_code')
            ->latest('decision_ts')
            ->limit($maxUniverse * 2)
            ->get();
        foreach ($signals as $signal) {
            $this->pushCandidate($symbols, (string) $signal->asset_code, 'trade_signal', 75);
        }

        $metadata = ExchangeSymbolMetadata::query()
            ->where('exchange', 'binance')
            ->latest('last_synced_at')
            ->limit($maxUniverse * 2)
            ->get();
        foreach ($metadata as $row) {
            $this->pushCandidate($symbols, (string) $row->symbol, 'exchange_metadata', 40);
        }

        uasort($symbols, static function (array $left, array $right): int {
            return ($right['priority'] <=> $left['priority']) ?: strcmp($left['symbol'], $right['symbol']);
        });

        return array_values(array_slice($symbols, 0, $maxUniverse));
    }

    /**
     * @param array<int, array<string, mixed>> $candidateSymbols
     * @return array<int, array<string, mixed>>
     */
    public function symbolContexts(
        string $workspaceId,
        array $candidateSymbols,
        AiTradingConfig $aiConfig,
        string $timeframe,
        array $options,
        Collection $spotPositions,
        Collection $openOrders,
        Collection $recentTrades,
        float $equityEstimate,
        array $groupExposure,
        array $symbolExposure
    ): array {
        $contexts = [];

        foreach ($candidateSymbols as $candidate) {
            $symbol = strtoupper((string) ($candidate['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $symbolContext = $this->symbolContextBuilder->build($workspaceId, $symbol, [
                'integration_id' => $options['integration_id'] ?? null,
                'timeframe' => $timeframe,
                'exchange' => 'binance',
                'market_type' => 'spot',
            ]);

            $position = $this->positionPayload($spotPositions->first(function (Position $position) use ($symbol): bool {
                return strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')) === $symbol;
            }));

            $orders = $this->ordersPayload($openOrders->filter(function (Order $order) use ($symbol): bool {
                return strtoupper((string) ($order->asset_code ?: $order->symbol?->symbol ?: '')) === $symbol;
            })->values());

            $trades = $this->tradesPayload($recentTrades->filter(function (Trade $trade) use ($symbol): bool {
                return strtoupper((string) ($trade->asset_code ?: $trade->symbol?->symbol ?: '')) === $symbol;
            })->values());

            $metadata = $this->metadataPayload($symbol);
            $external = $this->externalPayload($workspaceId, $symbol, $aiConfig);
            $correlation = $this->correlations->classify($symbol, [
                'portfolio' => [
                    'capital' => ['equity_estimate' => $equityEstimate],
                    'exposure_by_group' => $groupExposure,
                    'exposure_by_symbol' => $symbolExposure,
                ],
            ], $metadata);

            $contexts[] = array_merge($symbolContext, [
                'symbol' => $symbol,
                'priority' => (int) ($candidate['priority'] ?? 0),
                'universe_sources' => array_values(array_unique((array) ($candidate['sources'] ?? []))),
                'purpose' => $this->purposeFor($position, $orders),
                'position' => $position,
                'orders' => $orders,
                'recent_trades' => $trades,
                'exchange_metadata' => $metadata,
                'external_context' => $external,
                'correlation' => $correlation,
                'data_quality' => $this->dataQuality($symbolContext, $external),
            ]);
        }

        return $contexts;
    }

    private function aiConfig(string $workspaceId): AiTradingConfig
    {
        return AiTradingConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                ...[
                    'enabled' => false,
                    'mode' => 'advisor',
                    'exchange' => 'binance',
                    'market_type' => 'spot',
                    'provider' => config('intelligence.ai_trading.provider', 'heuristic'),
                    'enabled_symbols' => [],
                    'allowed_symbols' => [],
                    'blocked_symbols' => [],
                    'cooldown_minutes' => 0,
                    'decision_interval_minutes' => 5,
                    'max_pending_decisions_per_symbol' => 1,
                    'max_decisions_per_hour_per_symbol' => 12,
                    'max_decisions_per_hour' => 60,
                    'cooldown_minutes_after_trade' => 0,
                    'cooldown_minutes_after_loss' => 0,
                    'pause_until' => null,
                    'auto_pause_on_daily_loss' => true,
                    'auto_pause_on_drawdown' => true,
                    'auto_pause_on_order_failure_streak' => 3,
                    'auto_pause_on_slippage_spike' => true,
                    'auto_pause_on_reconciliation_mismatch' => true,
                    'manual_resume_required' => false,
                    'auto_trade_enabled' => false,
                    'require_stop_loss' => true,
                    'require_take_profit' => true,
                    'require_time_stop' => true,
                    'use_external_context' => false,
                    'max_external_context_age_minutes' => 60,
                ],
            ]
        );
    }

    private function portfolioConfig(string $workspaceId): PortfolioIntelligenceConfig
    {
        return PortfolioIntelligenceConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                ...PortfolioIntelligenceConfig::defaults(),
            ]
        );
    }

    private function riskProfile(string $workspaceId): ?array
    {
        $profile = RiskProfile::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_default', true)
            ->first();

        return $profile ? $profile->toArray() : null;
    }

    private function spotPositions(string $workspaceId): Collection
    {
        return Position::query()
            ->with(['symbol', 'exitState'])
            ->where('workspace_id', $workspaceId)
            ->where(function ($query): void {
                $query->whereNull('market_type')->orWhere('market_type', 'spot')->orWhere('market_type', '');
            })
            ->whereRaw('ABS(COALESCE(qty, quantity, 0)) > 0')
            ->get();
    }

    private function futuresPositions(string $workspaceId): Collection
    {
        return Position::query()
            ->with(['symbol'])
            ->where('workspace_id', $workspaceId)
            ->where('market_type', 'futures')
            ->whereRaw('ABS(COALESCE(qty, quantity, 0)) > 0')
            ->get();
    }

    private function spotOpenOrders(string $workspaceId): Collection
    {
        return Order::query()
            ->with('symbol')
            ->where('workspace_id', $workspaceId)
            ->where(function ($query): void {
                $query->whereNull('meta->market_type')
                    ->orWhere('meta->market_type', 'spot')
                    ->orWhere('meta->market_type', 'live')
                    ->orWhere('meta->market_type', '');
            })
            ->where(function ($query): void {
                $query->whereNull('reconciliation_state')->orWhereNotIn('status', [
                    Order::STATUS_FILLED,
                    Order::STATUS_FAILED,
                    Order::STATUS_CANCELLED,
                    Order::STATUS_REJECTED,
                    strtoupper(Order::STATUS_FILLED),
                    strtoupper(Order::STATUS_FAILED),
                    strtoupper(Order::STATUS_CANCELLED),
                    strtoupper(Order::STATUS_REJECTED),
                ]);
            })
            ->get();
    }

    private function futuresOpenOrders(string $workspaceId): Collection
    {
        return Order::query()
            ->with('symbol')
            ->where('workspace_id', $workspaceId)
            ->where('meta->market_type', 'futures')
            ->where(function ($query): void {
                $query->whereNull('reconciliation_state')->orWhereNotIn('status', [
                    Order::STATUS_FILLED,
                    Order::STATUS_FAILED,
                    Order::STATUS_CANCELLED,
                    Order::STATUS_REJECTED,
                    strtoupper(Order::STATUS_FILLED),
                    strtoupper(Order::STATUS_FAILED),
                    strtoupper(Order::STATUS_CANCELLED),
                    strtoupper(Order::STATUS_REJECTED),
                ]);
            })
            ->get();
    }

    private function recentTrades(string $workspaceId): Collection
    {
        return Trade::query()
            ->where('workspace_id', $workspaceId)
            ->where('executed_at', '>=', now()->subDay())
            ->latest('executed_at')
            ->limit(200)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cashAccounts(string $workspaceId, string $currency): array
    {
        return WalletAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('currency', $currency)
            ->where('type', 'cash')
            ->get()
            ->map(fn (WalletAccount $account) => [
                'id' => $account->id,
                'currency' => $account->currency,
                'available' => (float) ($account->available ?? $account->balance ?? 0),
                'locked_balance' => (float) ($account->locked_balance ?? 0),
                'balance' => (float) ($account->balance ?? 0),
            ])
            ->all();
    }

    private function cashAvailable(array $cashAccounts): float
    {
        return array_sum(array_map(static fn (array $account): float => (float) ($account['available'] ?? 0), $cashAccounts));
    }

    private function cashLocked(array $cashAccounts): float
    {
        return array_sum(array_map(static fn (array $account): float => (float) ($account['locked_balance'] ?? 0), $cashAccounts));
    }

    private function equityEstimate(array $cashAccounts, Collection $spotPositions, Collection $openOrders): float
    {
        return $this->cashAvailable($cashAccounts)
            + $spotPositions->sum(fn (Position $position) => $this->positionValue($position))
            + $this->openOrdersReserve($openOrders);
    }

    private function openOrdersReserve(Collection $openOrders): float
    {
        return $openOrders->sum(function (Order $order): float {
            $qty = (float) ($order->qty ?? $order->quantity ?? 0);
            $price = (float) ($order->limit_price ?? $order->price ?? 0);

            return max(0.0, $qty * max($price, 0.0));
        });
    }

    private function freeCapital(array $cashAccounts, Collection $openOrders, PortfolioIntelligenceConfig $config): float
    {
        $reservePct = max(0.0, min(100.0, (float) ($config->reserve_cash_buffer_pct ?? 0)));
        $cash = $this->cashAvailable($cashAccounts);
        $reservedCash = $cash * ($reservePct / 100);

        return max(0.0, $cash - $reservedCash - $this->openOrdersReserve($openOrders));
    }

    private function allocatedCapital(Collection $spotPositions, Collection $openOrders): float
    {
        return $spotPositions->sum(fn (Position $position) => $this->positionValue($position))
            + $this->openOrdersReserve($openOrders);
    }

    private function positionValue(Position $position): float
    {
        $qty = abs((float) ($position->qty ?? $position->quantity ?? 0));
        $mark = (float) ($position->last_mark_price ?? $position->avg_entry ?? $position->avg_price ?? 0);

        return round($qty * max($mark, 0), 8);
    }

    private function positionPayload(?Position $position): ?array
    {
        if (! $position) {
            return null;
        }

        return [
            'id' => $position->id,
            'symbol' => strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')),
            'market_type' => strtolower((string) ($position->market_type ?: 'spot')),
            'environment' => strtolower((string) ($position->environment ?: 'live')),
            'qty' => (float) ($position->qty ?? $position->quantity ?? 0),
            'avg_entry' => (float) ($position->avg_entry ?? $position->avg_price ?? 0),
            'last_mark_price' => (float) ($position->last_mark_price ?? 0),
            'market_value' => $this->positionValue($position),
            'unrealized_pnl' => (float) ($position->unrealized_pnl ?? 0),
            'realized_pnl' => (float) ($position->realized_pnl ?? 0),
            'leverage' => (float) ($position->leverage ?? 1),
            'liquidation_price' => $position->liquidation_price !== null ? (float) $position->liquidation_price : null,
            'liquidation_buffer_pct' => $position->liquidation_buffer_pct !== null ? (float) $position->liquidation_buffer_pct : null,
            'mark_price_synced_at' => optional($position->mark_price_synced_at)->toIso8601String(),
            'exit_state' => $position->exitState ? [
                'id' => $position->exitState->id,
                'status' => $position->exitState->status,
                'managed_mode' => $position->exitState->managed_mode,
                'blocked_reason' => $position->exitState->blocked_reason,
                'uncertainty_reason' => $position->exitState->uncertainty_reason,
                'stop_loss_price' => $position->exitState->stop_loss_price,
                'take_profit_price' => $position->exitState->take_profit_price,
                'trailing_stop_price' => $position->exitState->trailing_stop_price,
                'time_stop_at' => optional($position->exitState->time_stop_at)->toIso8601String(),
                'partial_progress' => $position->exitState->partial_progress,
                'last_evaluated_at' => optional($position->exitState->last_evaluated_at)->toIso8601String(),
            ] : null,
        ];
    }

    private function symbolExposure(Collection $positions, float $equityEstimate): array
    {
        $rows = [];
        foreach ($positions as $position) {
            $symbol = strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: ''));
            if ($symbol === '') {
                continue;
            }

            $notional = $this->positionValue($position);
            $rows[$symbol] = [
                'symbol' => $symbol,
                'notional' => round($notional, 8),
                'exposure_pct' => $equityEstimate > 0 ? round(($notional / $equityEstimate) * 100, 4) : null,
                'market_type' => strtolower((string) ($position->market_type ?: 'spot')),
            ];
        }

        return $rows;
    }

    private function latestWorkspaceExternalContext(string $workspaceId): array
    {
        $snapshot = ExternalContextSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('computed_at')
            ->first();

        if (! $snapshot) {
            return [
                'available' => false,
                'freshness_state' => 'unavailable',
                'source_health' => [],
                'news_summary' => [],
                'macro_summary' => [],
                'exchange_summary' => [],
                'event_flags' => [],
                'high_severity_events' => [],
                'recommended_bias' => 'neutral',
                'confidence_adjustment_hint' => 0.0,
                'no_trade_hint' => false,
                'pause_review_hint' => false,
            ];
        }

        $maxAge = max(1, (int) config('intelligence.external_context.max_age_minutes', 60));
        $ageMinutes = $snapshot->computed_at ? $snapshot->computed_at->diffInMinutes(now()) : null;
        $freshness = $ageMinutes !== null && $ageMinutes <= $maxAge ? 'fresh' : 'stale';

        return array_merge($snapshot->summary_json ?? [], [
            'available' => true,
            'freshness_state' => $freshness,
            'age_minutes' => $ageMinutes,
            'source_health' => $snapshot->source_health_json ?? [],
        ]);
    }

    private function peakEquityEstimate(string $workspaceId, float $currentEquity): float
    {
        $peak = PortfolioIntelligenceRun::query()
            ->where('workspace_id', $workspaceId)
            ->max('equity_estimate');

        if ($peak === null || (float) $peak <= 0) {
            return $currentEquity;
        }

        return max($currentEquity, (float) $peak);
    }

    private function dailyPnl(string $workspaceId): array
    {
        $realized = (float) Trade::query()
            ->where('workspace_id', $workspaceId)
            ->where('executed_at', '>=', now()->startOfDay())
            ->sum('realized_pnl');

        $unrealized = (float) Position::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query): void {
                $query->whereNull('market_type')->orWhere('market_type', 'spot')->orWhere('market_type', '');
            })
            ->sum('unrealized_pnl');

        return [
            'realized' => $realized,
            'unrealized' => $unrealized,
        ];
    }

    private function metadataPayload(string $symbol): array
    {
        $row = ExchangeSymbolMetadata::query()
            ->where('exchange', 'binance')
            ->where('symbol', strtoupper($symbol))
            ->first();

        if (! $row) {
            return [];
        }

        return [
            'exchange' => $row->exchange,
            'symbol' => $row->symbol,
            'base_asset' => $row->base_asset,
            'quote_asset' => $row->quote_asset,
            'min_qty' => (float) ($row->min_qty ?? 0),
            'max_qty' => (float) ($row->max_qty ?? 0),
            'step_size' => (float) ($row->step_size ?? 0),
            'min_notional' => (float) ($row->min_notional ?? 0),
            'tick_size' => (float) ($row->tick_size ?? 0),
            'last_synced_at' => optional($row->last_synced_at)->toIso8601String(),
        ];
    }

    private function externalPayload(string $workspaceId, string $symbol, AiTradingConfig $aiConfig): array
    {
        $snapshot = ExternalContextSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('symbol', strtoupper($symbol))
            ->orderByDesc('computed_at')
            ->first();

        if (! $snapshot) {
            return [
                'available' => false,
                'freshness_state' => 'unavailable',
                'source_health' => [],
            ];
        }

        $maxAge = max(1, (int) ($aiConfig->max_external_context_age_minutes ?? config('intelligence.external_context.max_age_minutes', 60)));
        $ageMinutes = $snapshot->computed_at ? $snapshot->computed_at->diffInMinutes(now()) : null;
        $freshness = $ageMinutes !== null && $ageMinutes <= $maxAge ? 'fresh' : 'stale';

        return array_merge($snapshot->summary_json ?? [], [
            'available' => true,
            'freshness_state' => $freshness,
            'age_minutes' => $ageMinutes,
            'source_health' => $snapshot->source_health_json ?? [],
        ]);
    }

    private function ordersPayload(Collection $orders): array
    {
        return $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'side' => $order->side,
            'type' => $order->type,
            'status' => $order->status,
            'qty' => (float) ($order->qty ?? $order->quantity ?? 0),
            'limit_price' => (float) ($order->limit_price ?? $order->price ?? 0),
            'provider_status' => $order->provider_status,
            'reconciliation_state' => $order->reconciliation_state,
            'client_order_id' => $order->client_order_id,
            'exchange_order_id' => $order->exchange_order_id,
        ])->values()->all();
    }

    private function tradesPayload(Collection $trades): array
    {
        return $trades->map(fn (Trade $trade) => [
            'id' => $trade->id,
            'qty' => (float) ($trade->qty ?? $trade->quantity ?? 0),
            'avg_price' => (float) ($trade->avg_price ?? $trade->price ?? 0),
            'realized_pnl' => (float) ($trade->realized_pnl ?? 0),
            'executed_at' => optional($trade->executed_at)->toIso8601String(),
        ])->values()->all();
    }

    private function purposeFor(?array $position, array $orders): string
    {
        if ($position && (float) ($position['qty'] ?? 0) > 0) {
            return 'manage';
        }

        return $orders !== [] ? 'entry' : 'entry';
    }

    private function dataQuality(array $symbolContext, array $external): array
    {
        return [
            'has_price' => (float) data_get($symbolContext, 'price.latest', 0) > 0,
            'has_candles' => (int) data_get($symbolContext, 'candles.count', 0) > 0,
            'has_external_context' => (bool) data_get($external, 'available', false),
            'external_freshness' => (string) data_get($external, 'freshness_state', 'unavailable'),
            'blockers' => array_values(array_filter((array) data_get($symbolContext, 'blockers', []))),
        ];
    }

    private function signalSnapshot(string $workspaceId): array
    {
        return TradeSignal::query()
            ->where('workspace_id', $workspaceId)
            ->latest('decision_ts')
            ->limit(10)
            ->get()
            ->map(fn (TradeSignal $signal) => [
                'id' => $signal->id,
                'symbol' => strtoupper((string) ($signal->asset_code ?: $signal->symbol ?: '')),
                'side' => $signal->side,
                'status' => $signal->status,
                'decision_price' => (float) $signal->decision_price,
                'decision_ts' => optional($signal->decision_ts)->toIso8601String(),
                'provider' => $signal->provider,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $symbols
     */
    private function pushCandidate(array &$symbols, string $symbol, string $source, int $priority): void
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return;
        }

        if (! isset($symbols[$symbol])) {
            $symbols[$symbol] = [
                'symbol' => $symbol,
                'priority' => $priority,
                'sources' => [$source],
            ];

            return;
        }

        $symbols[$symbol]['priority'] = max((int) $symbols[$symbol]['priority'], $priority);
        $symbols[$symbol]['sources'][] = $source;
    }
}
