<?php

namespace App\Domain\Trading;

use App\Domain\Ops\TradingEnvironmentGuard;
use App\Models\Order;
use App\Models\QuickTrade;
use App\Models\RobotSession;
use App\Models\Trade;
use App\Support\RiskGuardService;
use Carbon\CarbonImmutable;
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

        $this->riskGuard->enforceOrFail($workspaceId);

        $activeCount = QuickTrade::where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->count();
        if ($activeCount >= (int) ($config['max_active_trades'] ?? 5)) {
            throw new RuntimeException('Quick-trade active limit reached for this workspace.');
        }

        $symbolMeta = $this->settings->symbolMeta($workspaceId, $symbol, $mode, $providerKey);
        $marketPayload = $this->market->latestQuote($symbol, $timeframe, $context);
        $signalPayload = $payload['signal_payload'] ?? $this->signals->preview(
            $this->market->candles($symbol, $timeframe, 80, $context),
            $timeframe,
            (array) ($payload['indicator_set'] ?? [])
        );
        $traceId = (string) Str::uuid();
        $entryAt = CarbonImmutable::now();
        $walletAccountId = $payload['wallet_account_id'] ?? null;
        $entryIdempotencyKey = (string) Str::uuid();

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

            return $locked->fresh();
        });
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

    public function chart(string $workspaceId, string $symbol, string $timeframe, string $chartType = 'area', array $indicatorSet = []): array
    {
        $context = $this->executionContext($workspaceId, [], false);
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
                $signalPayload
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
                    'mode' => 'live',
                    'source' => $source,
                    'provider_key' => strtolower((string) $integration->provider_key),
                    'integration_id' => $integration->id,
                    'quote_currency' => strtoupper((string) ($execution['quote_currency'] ?? 'USDT')),
                    'entry_price' => (float) ($execution['price'] ?? 0),
                    'confidence' => $signalPayload['confidence'] ?? null,
                    'status' => 'open',
                    'signal_payload' => array_merge($signalPayload, [
                        'provider' => strtolower((string) $integration->provider_key),
                        'integration_id' => $integration->id,
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

    private function settleLiveTrade(QuickTrade $trade): QuickTrade
    {
        $integration = $this->integrations->resolve(
            $trade->workspace_id,
            $trade->provider_key ?: null,
            $trade->integration_id ?: $trade->order?->integration_id
        );

        $execution = $this->liveTimedTrades->executeExit($integration, $trade, (string) Str::uuid());
        $exitPrice = (float) ($execution['price'] ?? 0);
        $profitLoss = $this->liveTimedTrades->pnlFor($trade, $exitPrice);
        $result = $this->liveResultForPnl($trade, $profitLoss);
        $traceId = (string) Str::uuid();

        $bookedExit = $this->liveTimedTrades->bookExit($trade, $integration, $execution, $traceId, $profitLoss);

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
            'fees_total' => 0,
            'settled_at' => now(),
        ]);

        if ($trade->robot_session_id) {
            app(RobotSessionService::class)->refreshPerformance($trade->robotSession);
        }

        return $trade->fresh();
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
