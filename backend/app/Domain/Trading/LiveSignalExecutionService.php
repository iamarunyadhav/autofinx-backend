<?php

namespace App\Domain\Trading;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Intelligence\SignalAdvisorService;
use App\Domain\Ops\LiveExecutionGuard;
use App\Domain\Risk\BotRiskEngine;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Domain\Venue\TradingVenueClientInterface;
use App\Models\Bot;
use App\Models\Order;
use App\Models\Position;
use App\Models\Symbol;
use App\Models\TradeSignal;
use App\Models\WalletAccount;
use App\Domain\Trading\TradingModuleSettingsService;
use Illuminate\Support\Str;
use RuntimeException;

class LiveSignalExecutionService
{
    public function __construct(
        private readonly TradingVenueClientFactory $venues,
        private readonly MarketPriceCacheService $prices,
        private readonly LiveExecutionGuard $guard,
        private readonly BotRiskEngine $risk,
        private readonly SignalAdvisorService $advisor,
        private readonly ReconciliationService $reconciliation,
        private readonly BotLogService $logs,
        private readonly TradingModuleSettingsService $settings,
    ) {
    }

    public function executeSignal(TradeSignal $signal): ?Order
    {
        if ($signal->status !== TradeSignal::STATUS_PENDING) {
            return null;
        }

        $bot = Bot::find($signal->bot_id);
        if (! $bot || $bot->status !== 'RUNNING' || ! $bot->is_live) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            return null;
        }

        if (! $this->settings->automationEnabled((string) $bot->workspace_id)) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            $bot->status = 'PAUSED';
            $bot->last_error = 'Auto trading is disabled for this workspace.';
            $bot->save();
            $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Signal cancelled because auto trading is disabled for this workspace.', [
                'signal_id' => $signal->id,
            ], $signal->trace_id ?: (string) Str::uuid());

            return null;
        }

        $traceId = $signal->trace_id ?: (string) Str::uuid();
        $signal->trace_id = $traceId;
        $signal->save();

        $ageMs = now()->diffInMilliseconds($signal->decision_ts);
        if ($ageMs > (int) $signal->ttl_ms) {
            $signal->status = TradeSignal::STATUS_EXPIRED;
            $signal->save();
            $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Signal expired before execution.', [
                'signal_id' => $signal->id,
                'ttl_ms' => $signal->ttl_ms,
                'age_ms' => $ageMs,
            ], $traceId);
            return null;
        }

        $assetClass = strtoupper((string) ($signal->asset_class ?: $bot->asset_class ?: 'CRYPTO'));
        $assetCode = strtoupper((string) ($signal->asset_code ?: $bot->asset_code ?: $signal->symbol ?: $bot->symbol));
        $quoteCurrency = strtoupper((string) ($signal->quote_currency ?: $bot->quote_currency ?: 'USD'));
        $provider = strtolower((string) ($signal->provider ?: $bot->provider ?: ($assetClass === 'METALS' ? 'metal_broker' : 'binance')));

        if ($assetCode === '') {
            throw new RuntimeException('Signal asset_code is empty.');
        }

        $params = $bot->strategy_params ?? [];
        $maxRetries = max(0, (int) ($params['max_retries'] ?? 2));
        $qty = $this->resolveQuantity($bot, $signal);
        if ($qty <= 0) {
            throw new RuntimeException('Order quantity resolved to zero.');
        }

        try {
            $workspaceRisk = $this->guard->assertBotCanSubmit(
                $bot,
                $assetCode,
                max(0, (float) $signal->decision_price) * $qty,
                strtolower((string) ($bot->provider ?: $bot->exchange ?: $signal->provider ?: 'binance')),
                strtolower((string) $signal->side),
                (string) $signal->id
            );
        } catch (\Throwable $e) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            $bot->status = 'PAUSED';
            $bot->save();
            $this->logs->log($bot->id, $bot->workspace_id, 'ERROR', 'Execution blocked by live execution guard.', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ], $traceId);

            return null;
        }

        $client = $this->venues->forBot($bot);

        for ($attempt = 1; $attempt <= $maxRetries + 1; $attempt++) {
            try {
                $quote = $this->latestQuote($provider, $assetCode, $client);
                $current = (float) ($quote['mid'] ?? 0);
                if ($current <= 0) {
                    throw new RuntimeException('Current venue quote is unavailable.');
                }
                [$allowed, $guardMeta] = $this->checkSlippageGuard(
                    strtoupper((string) $signal->side),
                    (float) $signal->decision_price,
                    $current,
                    (float) $signal->max_slippage_pct
                );
                if (! $allowed) {
                    $signal->status = TradeSignal::STATUS_CANCELLED;
                    $signal->save();
                    $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Signal blocked by slippage guard.', $guardMeta, $traceId);
                    return null;
                }

                $side = strtoupper((string) $signal->side);
                $spreadPct = (float) ($quote['spread_pct'] ?? 0);
                if (! $this->passesSpreadAndProfitGate($bot, $signal, $side, $spreadPct, $traceId)) {
                    return null;
                }

                $aiDecision = $this->advisor->assess($bot, $signal, [
                    'current_price' => $current,
                    'bid' => (float) ($quote['bid'] ?? $current),
                    'ask' => (float) ($quote['ask'] ?? $current),
                    'spread_pct' => $spreadPct,
                ]);
                $enforceAi = filter_var($params['ai_enforce_approval'] ?? false, FILTER_VALIDATE_BOOL);
                if (! ($aiDecision['approved'] ?? true) && $enforceAi) {
                    $signal->status = TradeSignal::STATUS_CANCELLED;
                    $signal->save();
                    $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Signal cancelled by AI advisor gate.', [
                        'signal_id' => $signal->id,
                        'ai' => $aiDecision,
                    ], $traceId);
                    return null;
                }

                $this->risk->enforceBeforeExecution($bot, $side, $current, $qty, $traceId);

                // Last-millisecond failover guard: refresh quote and re-validate before placing order.
                $preflightQuote = $this->latestQuote($provider, $assetCode, $client);
                $preflightCurrent = (float) ($preflightQuote['mid'] ?? 0);
                if (! $this->preflightRevalidateOrAbort($signal, $preflightCurrent, [
                    'bot' => $bot,
                    'side' => $side,
                    'spread_pct' => (float) ($preflightQuote['spread_pct'] ?? $spreadPct),
                    'trace_id' => $traceId,
                    'strategy_type' => (string) ($bot->strategy_type ?? ''),
                    'strategy_params' => (array) ($bot->strategy_params ?? []),
                ])) {
                    return null;
                }
                $current = $preflightCurrent;
                $spreadPct = (float) ($preflightQuote['spread_pct'] ?? $spreadPct);

                $orderType = strtoupper((string) ($params['order_type'] ?? 'LIMIT'));
                $tif = strtoupper((string) ($params['tif'] ?? 'IOC'));
                if ($assetClass === 'METALS' && ! $client->supportsTimeInForce($tif)) {
                    $tif = 'GTC'; // synthetic IOC for metals if IOC unsupported
                }

                if ($assetClass !== 'METALS' && $side === 'SELL' && ($params['sell_use_oco'] ?? false)) {
                    $orderType = 'OCO';
                    $tif = 'GTC';
                }

                $limitPrice = $this->quoteLimitPrice($side, (float) $signal->decision_price, $current, (float) $signal->max_slippage_pct);
                if ($assetClass === 'METALS' && $orderType === 'LIMIT' && $tif === 'GTC') {
                    // Synthetic IOC fallback for providers without native IOC support.
                    if ($side === 'BUY') {
                        $aggressive = (float) ($preflightQuote['ask'] ?? $current);
                        $maxAccept = (float) $signal->decision_price * (1 + ((float) $signal->max_slippage_pct / 100));
                        $limitPrice = min($maxAccept, $aggressive);
                    } else {
                        $aggressive = (float) ($preflightQuote['bid'] ?? $current);
                        $minAccept = (float) $signal->decision_price * (1 - ((float) $signal->max_slippage_pct / 100));
                        $limitPrice = max($minAccept, $aggressive);
                    }
                }
                $symbolModel = Symbol::firstOrCreate(
                    ['symbol' => $assetCode],
                    ['id' => (string) Str::uuid(), 'type' => strtolower($assetClass), 'exchange' => $provider]
                );

                $order = Order::create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $bot->workspace_id,
                    'bot_id' => $bot->id,
                    'signal_id' => $signal->id,
                    'asset_class' => $assetClass,
                    'asset_code' => $assetCode,
                    'quote_currency' => $quoteCurrency,
                    'provider' => $provider,
                    'symbol_id' => $symbolModel->id,
                    'exchange' => $provider,
                    'side' => strtolower($side),
                    'type' => $orderType,
                    'tif' => $tif,
                    'quantity' => $qty,
                    'qty' => $qty,
                    'price' => $limitPrice,
                    'limit_price' => $limitPrice,
                    'status' => Order::STATUS_NEW,
                    'trace_id' => $traceId,
                    'placed_ts' => now(),
                    'spread_pct' => $spreadPct,
                    'expected_profit_pct' => (float) ($params['expected_profit_pct'] ?? null),
                    'meta' => [
                        'attempt' => $attempt,
                        'exec_policy' => $signal->exec_policy,
                        'decision_price' => $signal->decision_price,
                        'guard' => $guardMeta,
                        'quote' => $quote,
                        'ai' => $aiDecision,
                        'workspace_risk' => [
                            'risk_profile_id' => $workspaceRisk['_risk_profile_id'] ?? null,
                            'risk_profile_name' => $workspaceRisk['_risk_profile_name'] ?? null,
                            'stop_loss_pct' => $workspaceRisk['stop_loss_pct'] ?? null,
                            'take_profit_pct' => $workspaceRisk['take_profit_pct'] ?? null,
                            'trailing_stop_pct' => $workspaceRisk['trailing_stop_pct'] ?? null,
                        ],
                    ],
                ]);

                $startedNs = hrtime(true);
                $request = [
                    'asset_code' => $assetCode,
                    'side' => $side,
                    'type' => $orderType,
                    'tif' => $tif,
                    'qty' => $qty,
                    'price' => $limitPrice,
                    'fill_timeout_ms' => (int) $signal->fill_timeout_ms,
                ];
                if ($orderType === 'OCO') {
                    $tpPct = (float) ($params['tp_pct'] ?? 0.5);
                    $slPct = (float) ($params['sl_pct'] ?? 0.5);
                    $request['take_profit_price'] = (float) $signal->decision_price * (1 + ($tpPct / 100));
                    $request['stop_price'] = (float) $signal->decision_price * (1 - ($slPct / 100));
                    $request['stop_limit_price'] = $request['stop_price'] * 0.999;
                }

                $response = $client->placeOrder($request);
                $latencyMs = (int) round((hrtime(true) - $startedNs) / 1_000_000);

                $order->exchange_order_id = (string) ($response['order_id'] ?? '');
                $order->raw_json = $response['raw'] ?? $response;
                $order->latency_ms = $latencyMs;
                $order->save();

                $order = $this->reconciliation->reconcileExchangeOrder(
                    $order,
                    $this->toReconcilePayload($assetCode, $response),
                    $traceId
                );
                if (in_array(strtoupper((string) $order->status), ['FILLED', 'PARTIAL'], true) && (float) $order->filled_qty > 0) {
                    $signal->status = TradeSignal::STATUS_EXECUTED;
                    $signal->executed_at = now();
                    $signal->save();
                    return $order;
                }

                $timedOrder = $this->waitAndSyncUntilTimeout($order, $signal, $client, $assetCode, $traceId);
                if ($timedOrder && in_array(strtoupper((string) $timedOrder->status), ['FILLED', 'PARTIAL'], true) && (float) $timedOrder->filled_qty > 0) {
                    $signal->status = TradeSignal::STATUS_EXECUTED;
                    $signal->executed_at = now();
                    $signal->save();
                    return $timedOrder;
                }

                if ($order->exchange_order_id) {
                    try {
                        $client->cancelOrder((string) $order->exchange_order_id, $assetCode);
                    } catch (\Throwable) {
                        // ignore race
                    }
                }

                $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Order attempt not filled; retrying with requote.', [
                    'signal_id' => $signal->id,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'order_id' => $order->id,
                ], $traceId);

                if ($attempt <= $maxRetries) {
                    usleep(250_000);
                }
            } catch (\Throwable $e) {
                $this->logs->log($bot->id, $bot->workspace_id, 'ERROR', 'Execution attempt failed.', [
                    'signal_id' => $signal->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ], $traceId);

                if ($attempt <= $maxRetries) {
                    usleep(250_000);
                    continue;
                }
            }
        }

        $signal->status = TradeSignal::STATUS_CANCELLED;
        $signal->save();
        $this->logs->log($bot->id, $bot->workspace_id, 'ERROR', 'Signal cancelled after exhausted retries.', [
            'signal_id' => $signal->id,
            'retries' => $maxRetries,
        ], $traceId);

        return null;
    }

    private function waitAndSyncUntilTimeout(
        Order $order,
        TradeSignal $signal,
        TradingVenueClientInterface $client,
        string $assetCode,
        string $traceId
    ): ?Order {
        $timeoutMs = max(500, (int) $signal->fill_timeout_ms);
        $deadline = (int) floor(microtime(true) * 1000) + $timeoutMs;

        while ((int) floor(microtime(true) * 1000) < $deadline) {
            if (! $order->exchange_order_id) {
                break;
            }

            $latest = $client->fetchOrder((string) $order->exchange_order_id, $assetCode);
            $order = $this->reconciliation->reconcileExchangeOrder(
                $order->fresh(),
                $this->toReconcilePayload($assetCode, $latest),
                $traceId
            );
            if (in_array(strtoupper((string) $order->status), ['FILLED', 'PARTIAL', 'CANCELLED', 'REJECTED'], true)) {
                return $order;
            }

            usleep(250_000);
        }

        if ($order->exchange_order_id) {
            try {
                $latest = $client->fetchOrder((string) $order->exchange_order_id, $assetCode);
                $order = $this->reconciliation->reconcileExchangeOrder(
                    $order->fresh(),
                    $this->toReconcilePayload($assetCode, $latest),
                    $traceId
                );
            } catch (\Throwable) {
                // ignore final sync failure and return last known order state
            }
        }

        return $order->fresh();
    }

    private function resolveQuantity(Bot $bot, TradeSignal $signal): float
    {
        $params = $bot->strategy_params ?? [];
        $side = strtoupper((string) $signal->side);
        $price = max((float) $signal->decision_price, 0.00000001);
        $explicitQty = (float) ($signal->meta_json['qty'] ?? $params['qty'] ?? 0);
        if ($explicitQty > 0) {
            $resolved = $side === 'SELL'
                ? $this->applySellPositionCap($bot, $explicitQty)
                : $this->applyBuyBudgetCap($bot, $signal, $explicitQty, $price);

            return round($resolved, 8);
        }

        if ($side === 'SELL') {
            $openPosition = Position::where('bot_id', $bot->id)
                ->whereRaw('COALESCE(qty, quantity, 0) > 0')
                ->latest('updated_at')
                ->first();
            if ($openPosition) {
                $openQty = (float) ($openPosition->qty ?? $openPosition->quantity ?? 0);
                $sellQtyPct = max(0, min(100, (float) ($params['sell_qty_pct'] ?? 100)));
                $resolved = $openQty * ($sellQtyPct / 100);

                return round(max(0, $resolved), 8);
            }
        }

        $notional = $this->resolveNotionalBudget($bot, $signal, $params);
        if ($notional <= 0) {
            return 0;
        }

        return round($notional / $price, 8);
    }

    private function resolveNotionalBudget(Bot $bot, TradeSignal $signal, array $params): float
    {
        $mode = strtoupper((string) ($bot->allocation_mode ?? $params['allocation_mode'] ?? 'FIXED'));
        $strategyNotional = max(0.0, (float) ($params['order_notional'] ?? 25));
        $fixedBudget = max(0.0, (float) ($bot->trade_allocation_amount ?? $params['trade_allocation_amount'] ?? $strategyNotional));
        $allocationPercent = max(0.0, min(100.0, (float) ($bot->allocation_percent ?? $params['allocation_percent'] ?? 0)));
        $currency = strtoupper((string) ($bot->trade_allocation_currency ?: $signal->quote_currency ?: $bot->quote_currency ?: 'USD'));
        $walletAvailable = $this->walletAvailable($bot, $currency);

        $budget = match ($mode) {
            'PERCENT_WALLET' => $walletAvailable !== null ? ($walletAvailable * ($allocationPercent / 100)) : 0.0,
            'STRATEGY' => $strategyNotional,
            default => $fixedBudget,
        };

        if ($walletAvailable !== null) {
            $budget = min($budget, $walletAvailable);
        }

        return max(0.0, $budget);
    }

    private function applyBuyBudgetCap(Bot $bot, TradeSignal $signal, float $qty, float $price): float
    {
        if (strtoupper((string) $signal->side) !== 'BUY') {
            return $qty;
        }

        $currency = strtoupper((string) ($bot->trade_allocation_currency ?: $signal->quote_currency ?: $bot->quote_currency ?: 'USD'));
        $walletAvailable = $this->walletAvailable($bot, $currency);
        if ($walletAvailable === null) {
            return $qty;
        }

        $maxQty = $walletAvailable / max($price, 0.00000001);
        return max(0.0, min($qty, $maxQty));
    }

    private function applySellPositionCap(Bot $bot, float $qty): float
    {
        $openPosition = Position::where('bot_id', $bot->id)
            ->whereRaw('COALESCE(qty, quantity, 0) > 0')
            ->latest('updated_at')
            ->first();
        if (! $openPosition) {
            return 0.0;
        }

        $openQty = (float) ($openPosition->qty ?? $openPosition->quantity ?? 0);

        return max(0.0, min($qty, $openQty));
    }

    private function walletAvailable(Bot $bot, string $currency): ?float
    {
        $account = WalletAccount::where('workspace_id', $bot->workspace_id)
            ->where('currency', strtoupper($currency))
            ->where('type', 'cash')
            ->first();
        if (! $account) {
            return null;
        }

        return max(0.0, (float) ($account->available ?? $account->balance ?? 0));
    }

    private function latestQuote(string $provider, string $assetCode, TradingVenueClientInterface $client): array
    {
        $cached = $this->prices->getLatestPrice($provider, $assetCode);
        $quote = $client->getLatestPrice($assetCode);
        $mid = (float) ($quote['mid'] ?? ($quote['price'] ?? 0));
        if ($mid <= 0 && $cached) {
            $mid = (float) $cached['price'];
            $quote['mid'] = $mid;
            $quote['bid'] = $quote['bid'] ?? $mid;
            $quote['ask'] = $quote['ask'] ?? $mid;
            $quote['spread_pct'] = (float) ($quote['spread_pct'] ?? 0);
        }
        if ($mid > 0) {
            $this->prices->setLatestPrice($provider, $assetCode, $mid);
        }

        return $quote;
    }

    private function passesSpreadAndProfitGate(Bot $bot, TradeSignal $signal, string $side, float $spreadPct, string $traceId): bool
    {
        $params = $bot->strategy_params ?? [];
        $maxSpread = (float) ($params['max_spread_pct'] ?? 0);
        if ($maxSpread > 0 && $spreadPct > $maxSpread) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Signal cancelled due to spread gate.', [
                'spread_pct' => $spreadPct,
                'max_spread_pct' => $maxSpread,
            ], $traceId);
            return false;
        }

        $minProfit = (float) ($params['min_profit_after_fees_pct'] ?? 0);
        if ($minProfit <= 0) {
            return true;
        }

        $feePct = (float) ($params['fee_pct'] ?? 0.15);
        $expectedMove = $side === 'BUY'
            ? (float) ($params['sell_rise_pct'] ?? $params['expected_move_pct'] ?? 0)
            : (float) ($params['buy_drop_pct'] ?? $params['expected_move_pct'] ?? 0);
        $expectedMove = abs($expectedMove);
        $net = $expectedMove - $feePct - $spreadPct;

        if ($net < $minProfit) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Signal cancelled by min-profit gate.', [
                'expected_move_pct' => $expectedMove,
                'fee_pct' => $feePct,
                'spread_pct' => $spreadPct,
                'net_expected_pct' => $net,
                'min_profit_after_fees_pct' => $minProfit,
            ], $traceId);
            return false;
        }

        return true;
    }

    private function checkSlippageGuard(string $side, float $decisionPrice, float $currentPrice, float $maxSlippagePct): array
    {
        $side = strtoupper($side);
        if ($side === 'SELL') {
            $minAccept = $decisionPrice * (1 - ($maxSlippagePct / 100));
            return [
                $currentPrice >= $minAccept,
                [
                    'side' => $side,
                    'decision_price' => $decisionPrice,
                    'current_price' => $currentPrice,
                    'min_accept' => $minAccept,
                    'max_slippage_pct' => $maxSlippagePct,
                ],
            ];
        }

        $maxAccept = $decisionPrice * (1 + ($maxSlippagePct / 100));
        return [
            $currentPrice <= $maxAccept,
            [
                'side' => $side,
                'decision_price' => $decisionPrice,
                'current_price' => $currentPrice,
                'max_accept' => $maxAccept,
                'max_slippage_pct' => $maxSlippagePct,
            ],
        ];
    }

    private function quoteLimitPrice(string $side, float $decisionPrice, float $currentPrice, float $maxSlippagePct): float
    {
        $side = strtoupper($side);
        if ($side === 'SELL') {
            $minAccept = $decisionPrice * (1 - ($maxSlippagePct / 100));
            return max($minAccept, $currentPrice);
        }

        $maxAccept = $decisionPrice * (1 + ($maxSlippagePct / 100));
        return min($maxAccept, $currentPrice);
    }

    private function toReconcilePayload(string $assetCode, array $response): array
    {
        return [
            'orderId' => (string) ($response['order_id'] ?? ''),
            'symbol' => $assetCode,
            'status' => (string) ($response['status'] ?? 'NEW'),
            'executedQty' => (float) ($response['executed_qty'] ?? 0),
            'price' => (float) ($response['avg_price'] ?? 0),
            'fills' => (array) ($response['fills'] ?? []),
            'raw' => $response['raw'] ?? $response,
        ];
    }

    private function preflightRevalidateOrAbort(TradeSignal $signal, float $latestPriceNow, array $context): bool
    {
        $bot = $context['bot'] ?? null;
        $traceId = (string) ($context['trace_id'] ?? ($signal->trace_id ?: (string) Str::uuid()));
        $side = strtoupper((string) ($context['side'] ?? $signal->side));
        $spreadPct = (float) ($context['spread_pct'] ?? 0);

        if ($latestPriceNow <= 0) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            if ($bot) {
                $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Preflight cancelled: latest quote unavailable.', [
                    'signal_id' => $signal->id,
                ], $traceId);
            }

            return false;
        }

        $ageMs = now()->diffInMilliseconds($signal->decision_ts);
        if ($ageMs > (int) $signal->ttl_ms) {
            $signal->status = TradeSignal::STATUS_EXPIRED;
            $signal->save();
            if ($bot) {
                $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Preflight expired signal before placement.', [
                    'signal_id' => $signal->id,
                    'age_ms' => $ageMs,
                    'ttl_ms' => $signal->ttl_ms,
                ], $traceId);
            }

            return false;
        }

        if (! $this->triggerStillValid($signal, $latestPriceNow, $context)) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            if ($bot) {
                $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Preflight cancelled: trigger no longer valid.', [
                    'signal_id' => $signal->id,
                    'decision_price' => (float) $signal->decision_price,
                    'latest_price' => $latestPriceNow,
                    'side' => $side,
                ], $traceId);
            }

            return false;
        }

        [$allowed, $guardMeta] = $this->checkSlippageGuard(
            $side,
            (float) $signal->decision_price,
            $latestPriceNow,
            (float) $signal->max_slippage_pct
        );
        if (! $allowed) {
            $signal->status = TradeSignal::STATUS_CANCELLED;
            $signal->save();
            if ($bot) {
                $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Preflight cancelled by slippage guard.', [
                    'signal_id' => $signal->id,
                    'guard' => $guardMeta,
                ], $traceId);
            }

            return false;
        }

        if ($bot && ! $this->passesSpreadAndProfitGate($bot, $signal, $side, $spreadPct, $traceId)) {
            return false;
        }

        return true;
    }

    private function triggerStillValid(TradeSignal $signal, float $latestPriceNow, array $context): bool
    {
        $side = strtoupper((string) ($context['side'] ?? $signal->side));
        $decision = (float) $signal->decision_price;
        if ($decision <= 0) {
            return false;
        }

        $params = (array) ($context['strategy_params'] ?? []);
        $tolerancePct = abs((float) ($params['trigger_tolerance_pct'] ?? 0.5));
        $upBound = $decision * (1 + ($tolerancePct / 100));
        $lowBound = $decision * (1 - ($tolerancePct / 100));

        if ($side === 'BUY') {
            return $latestPriceNow <= $upBound;
        }

        return $latestPriceNow >= $lowBound;
    }
}
