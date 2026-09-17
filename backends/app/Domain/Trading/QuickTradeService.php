<?php

namespace App\Domain\Trading;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\TradingEnvironmentGuard;
use App\Domain\Ops\TradingRuntimeHealthService;
use App\Models\Order;
use App\Models\QuickTrade;
use App\Models\RobotSession;
use App\Models\Trade;
use App\Support\RiskGuardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class QuickTradeService
{
    public function __construct(
        private readonly PaperWalletService $wallets,
        private readonly LiveWalletService $liveWallets,
        private readonly TradingModuleSettingsService $settings,
        private readonly TradingMarketService $market,
        private readonly SignalPreviewService $signals,
        private readonly LiveTimedTradeService $liveTimedTrades,
        private readonly TimeframeRegistry $timeframes,
        private readonly RiskGuardService $riskGuard,
        private readonly TradingIntegrationResolver $integrations,
        private readonly TradingEnvironmentGuard $modeGuard,
        private readonly MarketPriceCacheService $prices,
    ) {
    }

    public function place(array $payload, ?string $userId = null): QuickTrade
    {
        $workspaceId = (string) $payload['workspace_id'];
        $config = $this->settings->quickTradeConfig($workspaceId);
        $timeframe = $this->timeframes->normalize((string) ($payload['timeframe'] ?? $config['default_timeframe'] ?? '1m'));
        $context = $this->executionContext($workspaceId, $payload, true);
        $mode = $context['mode'];
        $providerKey = $context['provider'];
        $integrationId = $context['integration_id'];
        $expirySeconds = (int) ($payload['expiry_seconds'] ?? $config['default_duration'] ?? 60);
        $stake = round((float) ($payload['stake_amount'] ?? 0), 8);
        $symbol = strtoupper((string) $payload['symbol']);
        $side = strtolower((string) $payload['side']);
        $source = (string) ($payload['source'] ?? 'manual_quick_trade');
        $robotSessionId = $payload['robot_session_id'] ?? null;
        $requestIdempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        // Optional per-trade stop-loss — live mode only. Paper trades have no
        // real position to protect, so any value submitted for a paper trade
        // is silently ignored (stored as null / feature off) rather than
        // rejected, to keep the paper request path unchanged.
        $stopLossPct = null;
        $rawStopLossPct = $payload['stop_loss_pct'] ?? null;
        if ($mode === 'live' && $rawStopLossPct !== null && $rawStopLossPct !== '') {
            $stopLossPct = (float) $rawStopLossPct;
        }
        // Generated here (rather than at its original ~118 call site) purely
        // so the Audit Research instrumentation below has a single stable
        // correlation_id to reuse across every entry gate, including the
        // ones that can reject the trade before the rest of place() would
        // otherwise have created one. Still a fresh random uuid either way —
        // moving the generation earlier changes nothing about its value.
        $traceId = (string) Str::uuid();

        if (! in_array($side, ['buy', 'sell'], true)) {
            throw new RuntimeException('Trade side must be buy or sell.');
        }
        $minStake = max(10.0, (float) ($config['min_stake'] ?? 10));
        if ($mode === 'live' && $providerKey === 'binance') {
            $minStake = max($minStake, FuturesOrderSizingService::BINANCE_MIN_OPEN_NOTIONAL);
        }
        if ($stake < $minStake) {
            throw new RuntimeException("Stake too small for {$symbol}. Minimum required: \${$minStake}.");
        }
        if ($stake > (float) ($config['max_stake'] ?? 500)) {
            throw new RuntimeException('Stake amount is outside the permitted quick-trade range.');
        }
        if (! in_array($expirySeconds, $this->settings->durationsForMode($workspaceId, $mode, $providerKey), true)) {
            throw new RuntimeException('Selected duration is not enabled for quick trading.');
        }
        if ($stopLossPct !== null && $stopLossPct >= 0) {
            throw new RuntimeException('stop_loss_pct must be a negative percentage (e.g. -2.00 for a 2% stop).');
        }

        try {
            $this->riskGuard->enforceOrFail($workspaceId);
        } catch (\Throwable $e) {
            $this->auditEntryGateFailure($traceId, $robotSessionId, $workspaceId, $symbol, $mode, 'risk_guard', $e->getMessage());
            throw $e;
        }

        if ($requestIdempotencyKey !== '') {
            $cacheKey = 'quick_trade:place:idempotency:'.sha1($workspaceId.'|'.$requestIdempotencyKey);
            if (! Cache::add($cacheKey, 1, now()->addMinutes(10))) {
                throw new RuntimeException('Duplicate quick-trade request ignored. Please wait for the first order result.');
            }
        }

        $activeCount = QuickTrade::where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->count();
        if ($activeCount >= (int) ($config['max_active_trades'] ?? 5)) {
            throw new RuntimeException('Quick-trade active limit reached for this workspace.');
        }

        if ($mode === 'live' && (bool) config('autofinx.runtime.block_duplicate_symbol_live', true)) {
            $duplicate = QuickTrade::query()
                ->where('workspace_id', $workspaceId)
                ->where('symbol', $symbol)
                ->where('mode', 'live')
                ->where('status', 'open')
                ->first();

            if ($duplicate) {
                $this->auditEntryGateFailure($traceId, $robotSessionId, $workspaceId, $symbol, $mode, 'duplicate_live_position', 'duplicate_live_position_detected: Another live position is already open for this symbol.');
                throw new RuntimeException('duplicate_live_position_detected: Another live position is already open for this symbol.');
            }
        }

        if ($mode === 'live' && (bool) config('autofinx.runtime.require_green_for_live', false)) {
            $runtime = app(TradingRuntimeHealthService::class)->snapshot($workspaceId);
            if (($runtime['quick_trade']['settler'] ?? 'red') !== 'green') {
                throw new RuntimeException('quick_trade_settler_unhealthy: Quick Trade settler is not running. Timed live trades may not close reliably.');
            }
            if (($runtime['overall'] ?? 'red') === 'red') {
                throw new RuntimeException('runtime_health_red: Live trading is blocked because required runtime workers or feeds are unhealthy.');
            }
        }

        try {
            $this->assertFreshLiveFeedForEntry($mode, $providerKey, $symbol, $workspaceId);
        } catch (\Throwable $e) {
            $this->auditEntryGateFailure($traceId, $robotSessionId, $workspaceId, $symbol, $mode, 'live_feed_freshness', $e->getMessage());
            throw $e;
        }

        // Audit Research instrumentation — every entry gate above has now
        // passed. Additive only, never throws back into the trading path.
        // Reuses $traceId (generated above) as the correlation_id; uses the
        // robot session id as bot_instance_id when this placement came from
        // one (a natural session-scoped id already flowing through this
        // call), otherwise null for a manual/direct placement.
        $auditEvent = \App\Domain\AuditResearch\AuditEventRecorder::record(
            eventType: 'quick_trade_entry_gates_passed',
            category: \App\Models\AuditEvent::CATEGORY_DECISION,
            context: [
                'bot_type' => 'quick_trade',
                'bot_instance_id' => $robotSessionId,
                'environment' => $mode,
                'symbol' => $symbol,
                'metadata' => [
                    'side' => $side,
                    'stake_amount' => $stake,
                    'source' => $source,
                ],
            ],
            correlationId: $traceId,
        );
        \App\Domain\AuditResearch\RuleEvaluationRecorder::recordGeneric(
            passedRules: [
                ['rule' => 'risk_guard', 'detail' => 'within configured daily-loss/drawdown limits'],
                ['rule' => 'duplicate_live_position_check', 'detail' => 'no duplicate open live position for this symbol'],
                ['rule' => 'live_feed_freshness', 'detail' => 'feed fresh or not applicable (paper mode)'],
            ],
            failedRules: [],
            finalDecision: 'PLACED',
            finalReason: 'entry gates satisfied',
            diagnosisCategory: \App\Domain\AuditResearch\RootCauseCategory::CORRECT_BEHAVIOR,
            diagnosisConfidence: 'high',
            expectedBehavior: 'Place the quick trade once risk/duplicate/feed-freshness gates all pass.',
            actualBehavior: 'Placed — all entry gates satisfied.',
            auditEventId: $auditEvent?->id,
            correlationId: $traceId,
        );

        $symbolMeta = $this->settings->symbolMeta($workspaceId, $symbol, $mode, $providerKey);
        $marketPayload = $this->market->latestQuote($symbol, $timeframe, $context);
        $signalPayload = $payload['signal_payload'] ?? $this->signals->preview(
            $this->market->candles($symbol, $timeframe, 80, $context),
            $timeframe,
            (array) ($payload['indicator_set'] ?? [])
        );
        $entryAt = CarbonImmutable::now();
        $walletAccountId = $payload['wallet_account_id'] ?? null;
        $entryIdempotencyKey = $requestIdempotencyKey !== ''
            ? substr('qt-'.str_replace('-', '', $requestIdempotencyKey), 0, 36)
            : (string) Str::uuid();

        if ($mode === 'live') {
            return $this->placeLiveTrade(
                payload: $payload,
                context: $context,
                symbolMeta: $symbolMeta,
                signalPayload: $signalPayload,
                marketPayload: $marketPayload,
                traceId: $traceId,
                entryAt: $entryAt,
                walletAccountId: $walletAccountId,
                timeframe: $timeframe,
                expirySeconds: $expirySeconds,
                source: $source,
                robotSessionId: $robotSessionId,
                stake: $stake,
                symbol: $symbol,
                side: $side,
                userId: $userId,
                entryIdempotencyKey: $entryIdempotencyKey,
                stopLossPct: $stopLossPct,
            );
        }

        return DB::transaction(function () use (
            $workspaceId,
            $stake,
            $symbol,
            $side,
            $mode,
            $providerKey,
            $integrationId,
            $source,
            $robotSessionId,
            $symbolMeta,
            $marketPayload,
            $signalPayload,
            $traceId,
            $entryAt,
            $expirySeconds,
            $walletAccountId,
            $timeframe,
            $userId
        ) {
            $account = $this->wallets->resolveAccount($workspaceId, $walletAccountId);
            $this->wallets->debitStake($workspaceId, $account->id, $stake, [
                'source' => $source,
                'symbol' => $symbol,
                'trace_id' => $traceId,
            ]);

            $symbolModel = $this->market->resolveSymbol($symbol);
            $order = Order::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'symbol_id' => $symbolModel->id,
                'side' => $side,
                'type' => 'expiry',
                'quantity' => $stake,
                'qty' => $side === 'buy' ? $stake : -1 * $stake,
                'price' => $marketPayload['price'],
                'limit_price' => $marketPayload['price'],
                'status' => 'OPEN',
                'meta' => [
                    'source' => $source,
                    'mode' => $mode,
                    'stake_amount' => $stake,
                    'expiry_seconds' => $expirySeconds,
                    'indicator_set' => $signalPayload['indicator_values'] ?? [],
                    'symbol_display' => $symbolMeta['display'] ?? $symbol,
                ],
                'idempotency_key' => (string) Str::uuid(),
                'trace_id' => $traceId,
                'asset_class' => strtoupper((string) ($symbolMeta['asset_class'] ?? 'METALS')),
                'asset_code' => $symbol,
                'quote_currency' => 'USD',
                'provider' => 'paper',
                'placed_ts' => now(),
            ]);

            $quickTrade = QuickTrade::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'order_id' => $order->id,
                'robot_session_id' => $robotSessionId,
                'wallet_account_id' => $account->id,
                'created_by' => $userId,
                'symbol' => $symbol,
                'asset_class' => strtoupper((string) ($symbolMeta['asset_class'] ?? 'METALS')),
                'side' => $side,
                'stake_amount' => $stake,
                'quantity' => $stake,
                'timeframe' => $timeframe,
                'expiry_seconds' => $expirySeconds,
                'payout_ratio' => (float) ($symbolMeta['payout_rate'] ?? 0.91),
                'mode' => $mode,
                'source' => $source,
                'provider_key' => $providerKey,
                'integration_id' => $integrationId,
                'quote_currency' => 'USD',
                'entry_price' => $marketPayload['price'],
                'confidence' => $signalPayload['confidence'] ?? null,
                'status' => 'open',
                'signal_payload' => $signalPayload,
                'indicator_snapshot' => $signalPayload['indicator_values'] ?? [],
                'entry_at' => $entryAt,
                'expires_at' => $entryAt->addSeconds($expirySeconds),
            ]);

            if ($robotSessionId) {
                RobotSession::whereKey($robotSessionId)->update([
                    'last_trade_at' => now(),
                ]);
            }

            return $quickTrade->fresh();
        });
    }

    public function settleExpired(int $limit = 100): array
    {
        $settled = [];

        QuickTrade::where('status', 'open')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (QuickTrade $trade) use (&$settled): void {
                $settled[] = $this->settleTrade($trade)->toArray();
            });

        return $settled;
    }

    public function settleTrade(QuickTrade $trade): QuickTrade
    {
        if ($trade->status !== 'open') {
            return $trade->fresh();
        }

        return DB::transaction(function () use ($trade) {
            $locked = QuickTrade::whereKey($trade->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open') {
                return $locked->fresh();
            }

            if ($locked->mode === 'live') {
                return $this->settleLiveTrade($locked);
            }

            $exitPrice = $this->market->priceAtOrAfter(
                $locked->symbol,
                CarbonImmutable::parse($locked->expires_at),
                $locked->timeframe,
                $this->executionContext($locked->workspace_id, [
                    'mode' => $locked->mode,
                    'provider' => $locked->provider_key,
                    'integration_id' => $locked->integration_id,
                ], false)
            );
            $result = $this->resultForTrade($locked, $exitPrice);
            $creditAmount = $result['credit_amount'];

            if ($creditAmount > 0) {
                $this->wallets->creditSettlement($locked->workspace_id, $locked->wallet_account_id, $creditAmount, [
                    'source' => $locked->source,
                    'symbol' => $locked->symbol,
                    'quick_trade_id' => $locked->id,
                    'trace_id' => (string) Str::uuid(),
                    'result' => $result['status'],
                ]);
            }

            $tradeRow = Trade::create([
                'id' => (string) Str::uuid(),
                'order_id' => $locked->order_id,
                'workspace_id' => $locked->workspace_id,
                'price' => $exitPrice,
                'quantity' => $locked->side === 'buy' ? $locked->stake_amount : -1 * $locked->stake_amount,
                'qty' => $locked->side === 'buy' ? $locked->stake_amount : -1 * $locked->stake_amount,
                'avg_price' => $locked->entry_price,
                'fee' => 0,
                'fees' => 0,
                'realized_pnl' => $result['profit_loss'],
                'executed_at' => now(),
                'ts' => now(),
                'meta' => [
                    'source' => $locked->source,
                    'quick_trade_id' => $locked->id,
                    'result' => $result['status'],
                    'entry_price' => (float) $locked->entry_price,
                    'exit_price' => $exitPrice,
                    'expires_at' => CarbonImmutable::parse($locked->expires_at)->toIso8601String(),
                ],
                'asset_class' => $locked->asset_class,
                'asset_code' => $locked->symbol,
                'quote_currency' => 'USD',
                'provider' => 'paper',
            ]);

            if ($locked->order_id) {
                Order::whereKey($locked->order_id)->update([
                    'status' => strtoupper($result['status']),
                    'filled_ts' => now(),
                    'meta' => array_merge((array) $locked->order?->meta, [
                        'result' => $result['status'],
                        'profit_loss' => $result['profit_loss'],
                        'quick_trade_id' => $locked->id,
                        'trade_id' => $tradeRow->id,
                    ]),
                ]);
            }

            $locked->update([
                'exit_price' => $exitPrice,
                'status' => $result['status'],
                'profit_loss' => $result['profit_loss'],
                'payout_amount' => $creditAmount,
                'settled_at' => now(),
            ]);

            if ($locked->robot_session_id) {
                app(RobotSessionService::class)->refreshPerformance($locked->robotSession);
            }

            $this->auditSettlement($locked, $result['status'], $result['profit_loss']);

            return $locked->fresh();
        });
    }

    /**
     * Audit Research instrumentation for a settlement — additive only,
     * never throws back into the trading path. The entry-side $traceId
     * generated in place() is never reused here (per this session's own
     * research, it isn't carried through to settlement); the QuickTrade
     * row's own stable id links the entry and settlement phases instead.
     */
    private function auditSettlement(QuickTrade $trade, string $status, float $profitLoss): void
    {
        $auditEvent = \App\Domain\AuditResearch\AuditEventRecorder::record(
            eventType: 'quick_trade_settled',
            category: \App\Models\AuditEvent::CATEGORY_EXECUTION,
            context: [
                'bot_type' => 'quick_trade',
                'bot_instance_id' => $trade->robot_session_id,
                'environment' => $trade->mode,
                'symbol' => $trade->symbol,
                'market_price' => (float) ($trade->exit_price ?? 0),
                'metadata' => [
                    'quick_trade_id' => $trade->id,
                    'status' => $status,
                    'profit_loss' => $profitLoss,
                ],
            ],
            correlationId: $trade->id,
        );
        \App\Domain\AuditResearch\RuleEvaluationRecorder::recordGeneric(
            passedRules: [
                ['rule' => 'settlement_price_resolved', 'detail' => 'exit price resolved and wallet settled'],
            ],
            failedRules: [],
            finalDecision: strtoupper($status),
            finalReason: 'settled: ' . $status,
            diagnosisCategory: \App\Domain\AuditResearch\RootCauseCategory::CORRECT_BEHAVIOR,
            diagnosisConfidence: 'high',
            expectedBehavior: 'Settle the trade against the resolved exit price once it expires.',
            actualBehavior: 'Settled — result: ' . $status,
            auditEventId: $auditEvent?->id,
            correlationId: $trade->id,
        );
    }

    public function active(string $workspaceId, ?string $source = null, ?string $robotSessionId = null)
    {
        return $this->tradeQuery($workspaceId, $source, $robotSessionId)
            ->where('status', 'open')
            ->orderBy('expires_at')
            ->get();
    }

    public function history(string $workspaceId, ?string $source = null, ?string $robotSessionId = null, int $limit = 100)
    {
        return $this->tradeQuery($workspaceId, $source, $robotSessionId)
            ->where('status', '!=', 'open')
            ->orderByDesc('settled_at')
            ->limit($limit)
            ->get();
    }

    public function chart(string $workspaceId, string $symbol, string $timeframe, string $chartType = 'area', array $indicatorSet = [], array $contextOverrides = []): array
    {
        $context = $this->executionContext($workspaceId, $contextOverrides, false);
        $candles = $this->market->candles($symbol, $timeframe, 120, $context);
        $series = $chartType === 'heikin_ashi'
            ? \App\Domain\Market\Indicators\Indicators::heikinAshi($candles)
            : $candles;
        $signal = $this->signals->preview($candles, $timeframe, $indicatorSet);

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'chart_type' => $chartType,
            'candles' => $series,
            'quote' => $this->market->latestQuote($symbol, $timeframe, $context),
            'signal_preview' => $signal,
            'settings' => array_merge($this->settings->quickTradeConfig($workspaceId), [
                'trading_state' => $this->settings->tradingState($workspaceId),
            ]),
        ];
    }

    private function resultForTrade(QuickTrade $trade, float $exitPrice): array
    {
        $entryPrice = (float) $trade->entry_price;
        $stake = (float) $trade->stake_amount;
        $status = 'draw';
        $profitLoss = 0.0;
        $creditAmount = $stake;

        if ($trade->side === 'buy' && $exitPrice > $entryPrice) {
            $status = 'won';
            $profitLoss = round($stake * (float) $trade->payout_ratio, 8);
            $creditAmount = round($stake + $profitLoss, 8);
        } elseif ($trade->side === 'sell' && $exitPrice < $entryPrice) {
            $status = 'won';
            $profitLoss = round($stake * (float) $trade->payout_ratio, 8);
            $creditAmount = round($stake + $profitLoss, 8);
        } elseif ($exitPrice === $entryPrice) {
            $status = 'draw';
            $profitLoss = 0.0;
            $creditAmount = $stake;
        } else {
            $status = 'lost';
            $profitLoss = round(-1 * $stake, 8);
            $creditAmount = 0.0;
        }

        return [
            'status' => $status,
            'profit_loss' => $profitLoss,
            'credit_amount' => $creditAmount,
        ];
    }

    private function tradeQuery(string $workspaceId, ?string $source, ?string $robotSessionId)
    {
        return QuickTrade::query()
            ->where('workspace_id', $workspaceId)
            ->when($source, fn ($query) => $query->where('source', $source))
            ->when($robotSessionId, fn ($query) => $query->where('robot_session_id', $robotSessionId));
    }

    private function executionContext(string $workspaceId, array $payload, bool $enforceMode): array
    {
        $state = $this->settings->tradingState($workspaceId);
        $mode = strtolower((string) ($payload['mode'] ?? ($state['mode'] ?? 'paper')));
        $provider = strtolower((string) ($payload['provider'] ?? ($state['preferred_provider'] ?? 'binance')));
        $integrationId = is_string($payload['integration_id'] ?? null)
            ? (string) $payload['integration_id']
            : (is_string($state['preferred_integration_id'] ?? null) ? (string) $state['preferred_integration_id'] : null);

        if ($enforceMode && $workspaceId !== '') {
            $this->modeGuard->assertWorkspaceMode($workspaceId, $mode === 'live');
        }

        return [
            'workspace_id' => $workspaceId,
            'mode' => $mode,
            'provider' => $provider,
            'integration_id' => $integrationId,
            'live_ready' => (bool) ($state['live_ready'] ?? false),
        ];
    }

    private function assertFreshLiveFeedForEntry(string $mode, string $providerKey, string $symbol, string $workspaceId): void
    {
        if ($mode !== 'live' || $providerKey !== 'binance') {
            return;
        }

        $maxAge = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds($workspaceId);
        $feed = $this->prices->getLiveFeedSnapshot('binance_futures', $symbol, $maxAge);

        if (($feed['fresh'] ?? false) === true && (float) ($feed['price'] ?? 0) > 0) {
            return;
        }

        $age = $feed['age_seconds'] ?? null;
        $reason = $feed === null
            ? 'No fresh Binance websocket tick exists for this symbol.'
            : "Binance websocket feed is stale for {$age}s; max allowed {$maxAge}s.";

        throw new RuntimeException('live_feed_stale: '.$reason);
    }

    private function placeLiveTrade(
        array $payload,
        array $context,
        array $symbolMeta,
        array $signalPayload,
        array $marketPayload,
        string $traceId,
        CarbonImmutable $entryAt,
        ?string $walletAccountId,
        string $timeframe,
        int $expirySeconds,
        string $source,
        ?string $robotSessionId,
        float $stake,
        string $symbol,
        string $side,
        ?string $userId,
        string $entryIdempotencyKey,
        ?float $stopLossPct = null,
    ): QuickTrade {
        $workspaceId = (string) $payload['workspace_id'];
        $integration = $this->integrations->resolve($workspaceId, $context['provider'], $context['integration_id']);

        $walletAccount = null;
        $execution = null;

        try {
            $walletAccount = $this->liveWallets->reserveStake(
                $workspaceId,
                $integration,
                $stake,
                $walletAccountId,
                'USDT',
                [
                    'trace_id' => $traceId,
                    'source' => $source,
                    'symbol' => $symbol,
                ],
            );

            $execution = $this->liveTimedTrades->executeEntry(
                $integration,
                $symbol,
                $side,
                $stake,
                $entryIdempotencyKey,
            );

            return DB::transaction(function () use (
                $workspaceId,
                $integration,
                $payload,
                $execution,
                $traceId,
                $source,
                $symbolMeta,
                $stake,
                $symbol,
                $side,
                $timeframe,
                $expirySeconds,
                $entryAt,
                $walletAccount,
                $robotSessionId,
                $userId,
                $signalPayload,
                $stopLossPct
            ) {
                $booked = $this->liveTimedTrades->bookEntry(
                    $integration,
                    [
                        'workspace_id' => $workspaceId,
                        'symbol' => $symbol,
                        'side' => $side,
                        'stake_amount' => $stake,
                        'source' => $source,
                        'expiry_seconds' => $expirySeconds,
                        'timeframe' => $timeframe,
                        'asset_class' => strtoupper((string) ($symbolMeta['asset_class'] ?? 'CRYPTO')),
                        'symbol_display' => $symbolMeta['display'] ?? $symbol,
                        'idempotency_key' => (string) Str::uuid(),
                    ],
                    $execution,
                    $traceId
                );
                $entryFee = round((float) ($execution['estimated_fee'] ?? $booked['order']->fee_amount ?? 0), 8);
                $entrySlippage = $execution['slippage_pct'] ?? $booked['order']->slippage_pct ?? null;

                $quickTrade = QuickTrade::create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'order_id' => $booked['order']->id,
                    'entry_trade_id' => $booked['trade']->id,
                    'robot_session_id' => $robotSessionId,
                    'wallet_account_id' => $walletAccount?->id,
                    'created_by' => $userId,
                    'symbol' => $symbol,
                    'asset_class' => strtoupper((string) ($symbolMeta['asset_class'] ?? 'CRYPTO')),
                    'side' => $side,
                    'stake_amount' => $stake,
                    'quantity' => (float) ($execution['quantity'] ?? 0),
                    'timeframe' => $timeframe,
                    'expiry_seconds' => $expirySeconds,
                    'payout_ratio' => 0,
                    'stop_loss_pct' => $stopLossPct,
                    'mode' => 'live',
                    'source' => $source,
                    'provider_key' => strtolower((string) $integration->provider_key),
                    'integration_id' => $integration->id,
                    'quote_currency' => strtoupper((string) ($execution['quote_currency'] ?? 'USDT')),
                    'entry_price' => (float) ($execution['price'] ?? 0),
                    'confidence' => $signalPayload['confidence'] ?? null,
                    'status' => 'open',
                    'fees_total' => $entryFee,
                    'signal_payload' => array_merge($signalPayload, [
                        'provider' => strtolower((string) $integration->provider_key),
                        'integration_id' => $integration->id,
                        'execution_cost' => [
                            'fee_model' => 'estimated_binance_futures_taker',
                            'entry_fee' => $entryFee,
                            'exit_fee' => 0,
                            'total_fees' => $entryFee,
                            'gross_pnl' => 0,
                            'net_pnl' => 0,
                            'entry_slippage_pct' => $entrySlippage,
                            'exit_slippage_pct' => null,
                            'entry_reference_price' => $execution['reference_price'] ?? null,
                            'entry_notional' => $execution['notional'] ?? null,
                            'exit_reference_price' => null,
                            'exit_notional' => null,
                            'fee_rate' => $execution['estimated_fee_rate'] ?? null,
                        ],
                    ]),
                    'indicator_snapshot' => $signalPayload['indicator_values'] ?? [],
                    'entry_at' => $entryAt,
                    'expires_at' => $entryAt->addSeconds($expirySeconds),
                ]);

                if ($robotSessionId) {
                    RobotSession::whereKey($robotSessionId)->update([
                        'last_trade_at' => now(),
                    ]);
                }

                return $quickTrade->fresh();
            });
        } catch (\Throwable $exception) {
            if ($execution && (float) ($execution['quantity'] ?? 0) > 0) {
                try {
                    $this->liveTimedTrades->executeClose(
                        $integration,
                        $symbol,
                        $side,
                        (float) ($execution['quantity'] ?? 0),
                        (string) Str::uuid(),
                        (string) $integration->provider_key,
                    );
                } catch (\Throwable) {
                }
            }

            if ($walletAccount) {
                try {
                    $this->liveWallets->cancelReservation(
                        $workspaceId,
                        $walletAccount->id,
                        $stake,
                        [
                            'trace_id' => $traceId,
                            'source' => $source,
                            'symbol' => $symbol,
                        ],
                    );
                } catch (\Throwable) {
                }
            }

            throw $exception;
        }
    }

    /**
     * Resolve real-vs-estimated fees for a live settlement. Only ever
     * attempted for Binance (the only provider with a real-fee lookup
     * today); every other provider key falls straight through to the
     * pre-existing estimate-only behaviour. This method itself never
     * throws — `LiveTimedTradeService::realFeeForOrder()`/`realFundingFee()`
     * already swallow every failure and return null, so a lookup failure
     * here only ever means "use the estimate", never a blocked settlement.
     *
     * @return array{0: float, 1: float, 2: float, 3: ?float, 4: ?float, 5: float, 6: string}
     *   [entryFee, exitFee, fundingFee, entryFeeActual, exitFeeActual, fundingFeeActual, feeSource]
     */
    private function reconcileLiveFees(
        QuickTrade $trade,
        \App\Models\Integration $integration,
        array $exitExecution,
        float $entryFeeEstimate,
        float $exitFeeEstimate,
    ): array {
        $providerKey = strtolower((string) ($trade->provider_key ?: $integration->provider_key));
        $quoteCurrency = strtoupper((string) ($trade->quote_currency ?: 'USDT'));

        $entryFeeActual = null;
        $exitFeeActual = null;
        $fundingFeeActual = null;

        if ($providerKey === 'binance') {
            $entryOrderId = (string) ($trade->order?->exchange_order_id ?? '');
            $exitOrderId = (string) data_get($exitExecution, 'response.orderId', '');

            $entryLookup = $this->liveTimedTrades->realFeeForOrder(
                $integration,
                $providerKey,
                $trade->symbol,
                $entryOrderId,
                $quoteCurrency,
            );
            $exitLookup = $this->liveTimedTrades->realFeeForOrder(
                $integration,
                $providerKey,
                $trade->symbol,
                $exitOrderId,
                $quoteCurrency,
            );

            if ($entryLookup !== null) {
                $entryFeeActual = round((float) $entryLookup['commission'], 8);
            }
            if ($exitLookup !== null) {
                $exitFeeActual = round((float) $exitLookup['commission'], 8);
            }

            // Binance's `income` sign is negative when the account was
            // *charged* funding and positive when it was *credited* — the
            // opposite of this platform's fee convention (positive = cost).
            // Invert here so `funding_fee_actual`/`fundingFee` stay on the
            // same "positive = cost" convention as entry/exit commission.
            $rawFundingIncome = $this->liveTimedTrades->realFundingFee(
                $integration,
                $providerKey,
                $trade->symbol,
                CarbonImmutable::parse($trade->entry_at),
                CarbonImmutable::now(),
            );
            if ($rawFundingIncome !== null) {
                $fundingFeeActual = round(-1 * $rawFundingIncome, 8);
            }
        }

        $commissionReal = $entryFeeActual !== null && $exitFeeActual !== null;
        $fundingReal = $fundingFeeActual !== null;

        $entryFee = $commissionReal ? $entryFeeActual : $entryFeeEstimate;
        $exitFee = $commissionReal ? $exitFeeActual : $exitFeeEstimate;
        $fundingFee = $fundingReal ? $fundingFeeActual : 0.0;

        $feeSource = match (true) {
            $commissionReal && $fundingReal => 'real',
            $commissionReal || $fundingReal => 'partial',
            default => 'estimated',
        };

        return [$entryFee, $exitFee, $fundingFee, $entryFeeActual, $exitFeeActual, $fundingFeeActual, $feeSource];
    }

    private function settleLiveTrade(QuickTrade $trade): QuickTrade
    {
        $integration = $this->integrations->resolve(
            $trade->workspace_id,
            $trade->provider_key ?: null,
            $trade->integration_id ?: $trade->order?->integration_id
        );

        $execution = $this->liveTimedTrades->executeExit($integration, $trade, (string) Str::uuid());
        $exitPrice = (float) ($execution['price'] ?? 0);
        $grossProfitLoss = $this->liveTimedTrades->pnlFor($trade, $exitPrice);
        $entryFeeEstimate = round((float) ($trade->fees_total ?? $trade->order?->fee_amount ?? 0), 8);
        $exitFeeEstimate = round((float) ($execution['estimated_fee'] ?? 0), 8);

        // Real-fee reconciliation: never allowed to fail/delay settlement.
        // `realFeeForOrder`/`realFundingFee` on LiveTimedTradeService already
        // catch everything internally and return null on any failure — this
        // block only ever chooses between "real" and the estimate already
        // computed above, it never introduces a new way for settlement to
        // fail.
        [$entryFee, $exitFee, $fundingFee, $entryFeeActual, $exitFeeActual, $fundingFeeActual, $feeSource] =
            $this->reconcileLiveFees($trade, $integration, $execution, $entryFeeEstimate, $exitFeeEstimate);

        $feesTotal = round($entryFee + $exitFee + $fundingFee, 8);
        $profitLoss = round($grossProfitLoss - $feesTotal, 8);
        $result = $this->liveResultForPnl($trade, $profitLoss);
        $traceId = (string) Str::uuid();

        $bookedExit = $this->liveTimedTrades->bookExit($trade, $integration, $execution, $traceId, $profitLoss);
        $signalPayload = (array) ($trade->signal_payload ?? []);
        $executionCost = array_merge((array) ($signalPayload['execution_cost'] ?? []), [
            'fee_model' => $feeSource === 'estimated'
                ? 'estimated_binance_futures_taker'
                : 'binance_futures_real_commission',
            'fee_source' => $feeSource,
            'entry_fee' => $entryFee,
            'exit_fee' => $exitFee,
            'funding_fee' => $fundingFee,
            'total_fees' => $feesTotal,
            'gross_pnl' => $grossProfitLoss,
            'net_pnl' => $profitLoss,
            'exit_slippage_pct' => $execution['slippage_pct'] ?? null,
            'exit_reference_price' => $execution['reference_price'] ?? null,
            'exit_notional' => $execution['notional'] ?? null,
            'fee_rate' => $execution['estimated_fee_rate'] ?? data_get($signalPayload, 'execution_cost.fee_rate'),
        ]);

        $this->liveWallets->settleReservation(
            $trade->workspace_id,
            $trade->wallet_account_id,
            (float) $trade->stake_amount,
            $profitLoss,
            [
                'quick_trade_id' => $trade->id,
                'trace_id' => $traceId,
                'source' => $trade->source,
                'symbol' => $trade->symbol,
                'provider' => $trade->provider_key,
                'integration_id' => $trade->integration_id,
            ]
        );

        if ($trade->order_id) {
            Order::whereKey($trade->order_id)->update([
                'meta' => array_merge((array) ($trade->order?->meta ?? []), [
                    'quick_trade_status' => $result['status'],
                    'quick_trade_profit_loss' => $profitLoss,
                    'quick_trade_gross_profit_loss' => $grossProfitLoss,
                    'quick_trade_fees_total' => $feesTotal,
                    'close_order_id' => $bookedExit['order']->id,
                    'close_trade_id' => $bookedExit['trade']->id,
                    'closed_at' => now()->toIso8601String(),
                ]),
            ]);
        }

        $trade->update([
            'close_order_id' => $bookedExit['order']->id,
            'close_trade_id' => $bookedExit['trade']->id,
            'exit_price' => $exitPrice,
            'status' => $result['status'],
            'profit_loss' => $profitLoss,
            'payout_amount' => $result['credit_amount'],
            'fees_total' => $feesTotal,
            'gross_pnl' => $grossProfitLoss,
            'entry_fee_actual' => $entryFeeActual,
            'exit_fee_actual' => $exitFeeActual,
            'funding_fee_actual' => $fundingFeeActual,
            'fee_source' => $feeSource,
            'signal_payload' => array_merge($signalPayload, [
                'execution_cost' => $executionCost,
            ]),
            'settled_at' => now(),
        ]);

        if ($trade->robot_session_id) {
            app(RobotSessionService::class)->refreshPerformance($trade->robotSession);
        }

        $this->auditSettlement($trade, $result['status'], $profitLoss);

        return $trade->fresh();
    }

    /**
     * Audit Research instrumentation for an entry gate that just rejected
     * this placement. Additive only, never throws back into the trading
     * path — the caller always rethrows the original exception itself right
     * after calling this, this method never changes that outcome.
     */
    private function auditEntryGateFailure(
        string $traceId,
        ?string $robotSessionId,
        string $workspaceId,
        string $symbol,
        string $mode,
        string $rule,
        string $detail,
    ): void {
        $auditEvent = \App\Domain\AuditResearch\AuditEventRecorder::record(
            eventType: 'quick_trade_entry_gate_blocked',
            category: \App\Models\AuditEvent::CATEGORY_RISK,
            context: [
                'bot_type' => 'quick_trade',
                'bot_instance_id' => $robotSessionId,
                'environment' => $mode,
                'symbol' => $symbol,
                'metadata' => [
                    'rule' => $rule,
                    'detail' => $detail,
                    'workspace_id' => $workspaceId,
                ],
            ],
            correlationId: $traceId,
            severity: \App\Models\AuditEvent::SEVERITY_MEDIUM,
        );
        \App\Domain\AuditResearch\RuleEvaluationRecorder::recordGeneric(
            passedRules: [],
            failedRules: [
                ['rule' => $rule, 'detail' => $detail],
            ],
            finalDecision: 'BLOCKED',
            finalReason: $detail,
            diagnosisCategory: $rule === 'live_feed_freshness'
                ? \App\Domain\AuditResearch\RootCauseCategory::DATA_STALE
                : \App\Domain\AuditResearch\RootCauseCategory::RISK_BLOCK,
            diagnosisConfidence: 'high',
            expectedBehavior: 'Block this placement while the ' . $rule . ' gate is failing.',
            actualBehavior: 'Blocked as expected.',
            auditEventId: $auditEvent?->id,
            correlationId: $traceId,
        );
    }

    private function liveResultForPnl(QuickTrade $trade, float $profitLoss): array
    {
        $stake = (float) $trade->stake_amount;
        $creditAmount = round(max(0, $stake + $profitLoss), 8);
        $status = 'draw';

        if ($profitLoss > 0.00000001) {
            $status = 'won';
        } elseif ($profitLoss < -0.00000001) {
            $status = 'lost';
        }

        return [
            'status' => $status,
            'profit_loss' => round($profitLoss, 8),
            'credit_amount' => $creditAmount,
        ];
    }
}
