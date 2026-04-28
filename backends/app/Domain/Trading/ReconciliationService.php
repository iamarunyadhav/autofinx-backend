<?php

namespace App\Domain\Trading;

use App\Domain\Market\FxRateService;
use App\Domain\Risk\BotRiskEngine;
use App\Domain\RatioBot\RatioBotOrderUpdateService;
use App\Jobs\SweepProviderBalanceJob;
use App\Models\Bot;
use App\Models\Order;
use App\Models\OrderFill;
use App\Models\Position;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\TradeSignal;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconciliationService
{
    public function __construct(
        private readonly BotLogService $logs,
        private readonly BotRiskEngine $risk,
        private readonly FxRateService $fx,
        private readonly ExecutionQualityService $quality,
        private readonly LiveExecutionEventService $events,
        private readonly PositionExitStateService $exitStates,
        private readonly RatioBotOrderUpdateService $ratioBots,
    ) {
    }

    public function reconcileExchangeOrder(Order $order, array $exchangePayload, ?string $traceId = null): Order
    {
        $traceId = $traceId ?: ($order->trace_id ?: (string) Str::uuid());
        $source = (string) ($exchangePayload['source'] ?? 'exchange');

        return DB::transaction(function () use ($order, $exchangePayload, $traceId, $source): Order {
            $status = $this->mapExchangeStatus((string) ($exchangePayload['status'] ?? $order->status));
            $executedQty = (float) ($exchangePayload['executedQty'] ?? $exchangePayload['executed_qty'] ?? $order->filled_qty ?? 0);
            $avgPrice = (float) ($exchangePayload['avgPrice'] ?? $exchangePayload['price'] ?? $order->avg_fill_price ?? 0);
            $previousStatus = strtoupper((string) $order->status);
            $previousFilledQty = (float) ($order->filled_qty ?? 0);
            $mismatchDetected = false;
            $mismatchReason = null;

            if ($previousFilledQty > 0 && $executedQty + 1e-8 < $previousFilledQty) {
                $mismatchDetected = true;
                $mismatchReason = 'exchange_filled_qty_regressed';
            }

            $order->status = $status;
            $order->trace_id = $traceId;
            $order->exchange_order_id = (string) ($exchangePayload['orderId'] ?? $order->exchange_order_id);
            $order->client_order_id = (string) (data_get($exchangePayload, 'raw.clientOrderId') ?: data_get($exchangePayload, 'raw.origClientOrderId') ?: $order->client_order_id ?: $order->idempotency_key);
            $order->provider_status = (string) ($exchangePayload['status'] ?? $order->provider_status ?: $status);
            $order->provider_synced_at = now();
            $order->last_reconciled_at = now();
            $order->last_sync_source = $source;
            $order->avg_fill_price = $avgPrice > 0 ? $avgPrice : $order->avg_fill_price;
            $order->raw_json = $exchangePayload;
            if ($status === 'FILLED') {
                $order->filled_ts = now();
            }
            $order->save();

            $fillState = $this->extractFills($order, $exchangePayload, $traceId);
            $allFills = $fillState['all'];
            $newFills = $fillState['new'];
            $duplicateFills = (int) ($fillState['duplicates'] ?? 0);

            if ($newFills !== [] && count($newFills) > 0) {
                $this->bookTradeAndPosition($order, $newFills, $traceId);
                $this->bookWalletLedger($order, $newFills, $traceId);
            }

            $quality = $this->quality->summarize($order, $allFills);
            $order->filled_qty = (float) ($quality['filled_qty'] ?? $executedQty);
            $order->avg_fill_price = (float) ($quality['avg_execution_price'] ?? $order->avg_fill_price ?? 0) ?: $order->avg_fill_price;
            $order->fee_amount = (float) ($quality['fee_total'] ?? $order->fee_amount ?? 0);
            $order->fee_asset = count($allFills) > 0 ? ($allFills[0]->fee_asset ?? $order->fee_asset) : $order->fee_asset;
            $order->slippage_pct = $quality['slippage_pct'] ?? $order->slippage_pct;
            $order->reconciliation_state = $mismatchDetected
                ? Order::RECONCILIATION_MISMATCH
                : ($status === Order::STATUS_UNCERTAIN ? Order::RECONCILIATION_UNCERTAIN : Order::RECONCILIATION_ALIGNED);
            $order->meta = array_merge((array) ($order->meta ?? []), [
                'execution_quality' => $quality,
                'last_exchange_source' => $source,
                'duplicate_fill_count' => $duplicateFills,
                'uncertain_submission' => $status === Order::STATUS_UNCERTAIN ? true : (bool) data_get($order->meta, 'uncertain_submission', false),
            ]);
            $order->save();

            if ($duplicateFills > 0) {
                $this->events->recordOrderEvent($order, 'duplicate_fill_ignored', [
                    'duplicate_count' => $duplicateFills,
                ], 'info', $source, $traceId);
            }

            $this->emitLifecycleEvents($order, $previousStatus, $status, $newFills, $quality, $source, $traceId);
            if ($mismatchDetected) {
                $this->recordMismatch($order, $mismatchReason ?: 'reconciliation_mismatch', $exchangePayload, $traceId);
            }

            if ($order->signal_id && in_array($status, ['FILLED', 'PARTIAL', 'CANCELLED', 'REJECTED'], true)) {
                $signal = TradeSignal::find($order->signal_id);
                if ($signal) {
                    $signal->status = $status === 'FILLED' ? 'EXECUTED' : ($status === 'PARTIAL' ? 'EXECUTED' : 'CANCELLED');
                    if ($status === 'FILLED' || $status === 'PARTIAL') {
                        $signal->executed_at = now();
                    }
                    $signal->save();
                }
            }

            if ($order->bot_id) {
                $bot = Bot::find($order->bot_id);
                if ($bot) {
                    $this->risk->enforceAfterReconcile($bot, $traceId);
                }
            }

            if (in_array($status, ['FILLED', 'PARTIAL'], true)
                && strtolower((string) $order->side) === 'sell'
                && count($newFills) > 0
            ) {
                SweepProviderBalanceJob::dispatch($order->id, $traceId);
            }

            $this->ratioBots->syncFromOrder($order->fresh());

            return $order->refresh();
        });
    }

    public function ingestExchangeEvent(array $payload): ?Order
    {
        $eventType = (string) ($payload['e'] ?? $payload['event'] ?? $payload['eventType'] ?? '');
        if ($eventType === 'executionReport') {
            return $this->reconcileExecutionReport($payload);
        }

        // Futures style payload compatibility: ORDER_TRADE_UPDATE
        if (strtoupper($eventType) === 'ORDER_TRADE_UPDATE' && is_array($payload['o'] ?? null)) {
            $orderPayload = (array) $payload['o'];
            $mapped = [
                'orderId' => (string) ($orderPayload['i'] ?? ''),
                'symbol' => (string) ($orderPayload['s'] ?? ''),
                'status' => (string) ($orderPayload['X'] ?? $orderPayload['x'] ?? ''),
                'executedQty' => (float) ($orderPayload['z'] ?? 0),
                'price' => (float) ($orderPayload['L'] ?? ($orderPayload['p'] ?? 0)),
                'fills' => [
                    [
                        'tradeId' => (string) ($orderPayload['t'] ?? ''),
                        'price' => (float) ($orderPayload['L'] ?? 0),
                        'qty' => (float) ($orderPayload['l'] ?? 0),
                        'commission' => (float) ($orderPayload['n'] ?? 0),
                        'commissionAsset' => (string) ($orderPayload['N'] ?? ''),
                        'time' => (int) ($orderPayload['T'] ?? floor(microtime(true) * 1000)),
                    ],
                ],
            ];

            return $this->reconcileByExchangeOrderId($mapped);
        }

        if (! empty($payload['orderId']) || ! empty($payload['order_id'])) {
            $mapped = [
                'orderId' => (string) ($payload['orderId'] ?? $payload['order_id']),
                'symbol' => (string) ($payload['symbol'] ?? $payload['asset_code'] ?? ''),
                'status' => (string) ($payload['status'] ?? ''),
                'executedQty' => (float) ($payload['executedQty'] ?? $payload['executed_qty'] ?? 0),
                'price' => (float) ($payload['price'] ?? $payload['avgPrice'] ?? $payload['avg_price'] ?? 0),
                'fills' => (array) ($payload['fills'] ?? []),
            ];

            return $this->reconcileByExchangeOrderId($mapped);
        }

        return null;
    }

    public function reconcileExecutionReport(array $event): ?Order
    {
        $eventType = (string) ($event['e'] ?? '');
        if ($eventType !== 'executionReport') {
            return null;
        }

        $exchangeOrderId = (string) ($event['i'] ?? '');
        $symbol = (string) ($event['s'] ?? '');
        if ($exchangeOrderId === '') {
            return null;
        }

        $order = Order::where('exchange_order_id', $exchangeOrderId)->first();
        if (! $order) {
            $clientOrderId = (string) ($event['c'] ?? '');
            if ($clientOrderId !== '') {
                $order = Order::where(function ($query) use ($clientOrderId): void {
                    $query->where('client_order_id', $clientOrderId)
                        ->orWhere('idempotency_key', $clientOrderId)
                        ->orWhere('meta->client_order_id', $clientOrderId);
                })->first();
            }
        }
        if (! $order) {
            return null;
        }

        $payload = [
            'orderId' => $exchangeOrderId,
            'symbol' => $symbol,
            'status' => (string) ($event['X'] ?? ''),
            'executedQty' => (float) ($event['z'] ?? 0),
            'price' => (float) ($event['L'] ?? ($event['p'] ?? 0)),
            'fills' => [
                [
                    'tradeId' => (string) ($event['t'] ?? ''),
                    'price' => (float) ($event['L'] ?? 0),
                    'qty' => (float) ($event['l'] ?? 0),
                    'commission' => (float) ($event['n'] ?? 0),
                    'commissionAsset' => (string) ($event['N'] ?? ''),
                    'time' => (int) ($event['T'] ?? floor(microtime(true) * 1000)),
                ],
            ],
            'raw' => $event,
            'source' => 'user_stream',
        ];

        return $this->reconcileExchangeOrder($order, $payload, $order->trace_id);
    }

    private function reconcileByExchangeOrderId(array $payload): ?Order
    {
        $exchangeOrderId = (string) ($payload['orderId'] ?? '');
        if ($exchangeOrderId === '') {
            return null;
        }

        $order = Order::where('exchange_order_id', $exchangeOrderId)->first();
        if (! $order) {
            return null;
        }

        return $this->reconcileExchangeOrder($order, $payload, $order->trace_id);
    }

    private function extractFills(Order $order, array $exchangePayload, string $traceId): array
    {
        $rawFills = (array) ($exchangePayload['fills'] ?? []);
        if (count($rawFills) === 0) {
            $executedQty = (float) ($exchangePayload['executedQty'] ?? 0);
            $price = (float) ($exchangePayload['price'] ?? 0);
            if ($executedQty > 0 && $price > 0) {
                $rawFills[] = [
                    'tradeId' => (string) ($exchangePayload['tradeId'] ?? $exchangePayload['orderId'] ?? ''),
                    'price' => $price,
                    'qty' => $executedQty,
                    'commission' => (float) ($exchangePayload['commission'] ?? 0),
                    'commissionAsset' => (string) ($exchangePayload['commissionAsset'] ?? ''),
                    'time' => (int) ($exchangePayload['eventTime'] ?? $exchangePayload['transactTime'] ?? floor(microtime(true) * 1000)),
                ];
            }
        }

        $newFills = [];
        $duplicateCount = 0;
        foreach ($rawFills as $fill) {
            $tradeId = (string) ($fill['tradeId'] ?? $fill['id'] ?? '');
            $price = (float) ($fill['price'] ?? 0);
            $qty = (float) ($fill['qty'] ?? $fill['quantity'] ?? 0);
            $time = isset($fill['time']) ? (int) $fill['time'] : floor(microtime(true) * 1000);
            if ($price <= 0 || $qty <= 0) {
                continue;
            }
            if ($tradeId === '') {
                $tradeId = hash('sha1', implode('|', [
                    $order->exchange_order_id ?: $order->id,
                    number_format($price, 8, '.', ''),
                    number_format($qty, 8, '.', ''),
                    $time,
                ]));
            }

            $existing = OrderFill::where('order_id', $order->id)
                ->where('exchange_trade_id', $tradeId)
                ->first();

            if (! $existing) {
                $existing = OrderFill::create([
                    'id' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'exchange_trade_id' => $tradeId !== '' ? $tradeId : null,
                    'fill_price' => $price,
                    'fill_qty' => $qty,
                    'fee' => (float) ($fill['commission'] ?? 0),
                    'fee_asset' => (string) ($fill['commissionAsset'] ?? null),
                    'fill_ts' => now()->setTimestamp((int) floor($time / 1000)),
                    'raw_json' => $fill,
                    'trace_id' => $traceId,
                ]);

                $newFills[] = $existing;
            } else {
                $duplicateCount++;
            }
        }

        return [
            'all' => OrderFill::where('order_id', $order->id)->orderBy('fill_ts')->get()->all(),
            'new' => $newFills,
            'duplicates' => $duplicateCount,
        ];
    }

    private function bookTradeAndPosition(Order $order, array $fills, string $traceId): void
    {
        $totalQty = 0.0;
        $totalNotional = 0.0;
        $totalFees = 0.0;
        foreach ($fills as $fill) {
            $qty = (float) $fill->fill_qty;
            $price = (float) $fill->fill_price;
            $totalQty += $qty;
            $totalNotional += $price * $qty;
            $totalFees += (float) $fill->fee;
        }
        if ($totalQty <= 0) {
            return;
        }

        $avgPrice = $totalNotional / $totalQty;
        $side = strtolower((string) $order->side) === 'sell' ? 'sell' : 'buy';
        $symbolCode = $this->orderSymbolCode($order);
        $symbolModel = $order->symbol_id ? Symbol::find($order->symbol_id) : null;

        $position = Position::firstOrNew([
            'workspace_id' => $order->workspace_id,
            'bot_id' => $order->bot_id,
            'symbol_id' => $symbolModel?->id ?: $order->symbol_id,
        ]);

        $existingQty = (float) ($position->qty ?? $position->quantity ?? 0);
        $existingAvg = (float) ($position->avg_entry ?? $position->avg_price ?? 0);
        $existingRealized = (float) ($position->realized_pnl ?? 0);

        $realizedDelta = 0.0;
        if ($side === 'buy') {
            $newQty = $existingQty + $totalQty;
            $newAvg = $newQty > 0 ? (($existingQty * $existingAvg) + ($totalQty * $avgPrice)) / $newQty : 0;
            $position->qty = $newQty;
            $position->avg_entry = $newAvg;
        } else {
            $closeQty = min(max($existingQty, 0), $totalQty);
            if ($closeQty > 0) {
                $realizedDelta = ($avgPrice - $existingAvg) * $closeQty;
            }
            $newQty = max(0, $existingQty - $totalQty);
            $position->qty = $newQty;
            $position->avg_entry = $newQty > 0 ? $existingAvg : 0;
        }

        $position->id = $position->id ?: (string) Str::uuid();
        $position->realized_pnl = $existingRealized + $realizedDelta;
        $position->last_mark_price = $avgPrice;
        $position->unrealized_pnl = 0;
        $position->quantity = $position->qty;
        $position->avg_price = $position->avg_entry;
        $position->unrealized_pl = $position->unrealized_pnl;
        $position->asset_class = $order->asset_class ?? $position->asset_class ?? 'CRYPTO';
        $position->asset_code = $order->asset_code ?? $symbolCode;
        $position->provider = $order->provider ?? $position->provider;
        $position->quote_currency = $order->quote_currency ?? $position->quote_currency ?? 'USD';
        $position->save();
        $this->exitStates->syncAfterOrderUpdate($position, $order);

        $quoteCurrency = strtoupper((string) ($order->quote_currency ?: 'USD'));
        $fxUsedUsdLkr = $quoteCurrency === 'LKR' ? 1.0 : $this->fx->getRate('USD', 'LKR');
        $pnlLkr = $quoteCurrency === 'LKR' ? $realizedDelta : ($realizedDelta * $fxUsedUsdLkr);

        Trade::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'workspace_id' => $order->workspace_id,
            'integration_id' => $order->integration_id,
            'bot_id' => $order->bot_id,
            'exchange_trade_id' => count($fills) === 1 ? (string) ($fills[0]->exchange_trade_id ?: '') : null,
            'asset_class' => $order->asset_class ?? 'CRYPTO',
            'asset_code' => $order->asset_code ?? $symbolCode,
            'quote_currency' => $quoteCurrency,
            'provider' => $order->provider,
            'symbol' => $symbolCode,
            'side' => strtoupper($side),
            'price' => $avgPrice,
            'avg_price' => $avgPrice,
            'quantity' => $side === 'buy' ? $totalQty : -$totalQty,
            'qty' => $totalQty,
            'fee' => $totalFees,
            'fees' => $totalFees,
            'fee_amount' => $totalFees,
            'fee_asset' => $fills[0]->fee_asset ?? null,
            'realized_pnl' => $realizedDelta,
            'fx_used_usd_lkr' => $fxUsedUsdLkr,
            'pnl_lkr' => $pnlLkr,
            'spread_pct' => $order->spread_pct,
            'executed_at' => now(),
            'ts' => now(),
            'trace_id' => $traceId,
            'meta' => [
                'order_id' => $order->id,
                'fills' => count($fills),
                'exchange_trade_ids' => array_values(array_filter(array_map(
                    fn ($fill) => (string) ($fill->exchange_trade_id ?? ''),
                    $fills
                ))),
            ],
        ]);

        if ($order->bot_id) {
            $this->logs->log($order->bot_id, $order->workspace_id, 'INFO', 'Order reconciled with fills.', [
                'order_id' => $order->id,
                'side' => $side,
                'avg_price' => $avgPrice,
                'qty' => $totalQty,
                'fees' => $totalFees,
                'realized_pnl' => $realizedDelta,
            ], $traceId);
        }
    }

    private function bookWalletLedger(Order $order, array $fills, string $traceId): void
    {
        [$baseAsset, $quoteAsset] = $this->splitSymbol($this->orderSymbolCode($order));
        if (! $baseAsset || ! $quoteAsset) {
            return;
        }

        $side = strtolower((string) $order->side) === 'sell' ? 'sell' : 'buy';
        $qty = array_sum(array_map(fn ($fill) => (float) $fill->fill_qty, $fills));
        $notional = array_sum(array_map(fn ($fill) => (float) $fill->fill_qty * (float) $fill->fill_price, $fills));
        $fees = array_sum(array_map(fn ($fill) => (float) $fill->fee, $fills));

        $baseAccount = $this->walletAccount($order->workspace_id, $baseAsset, $order->user_id ?? null);
        $quoteAccount = $this->walletAccount($order->workspace_id, $quoteAsset, $order->user_id ?? null);

        if ($side === 'buy') {
            $this->applyWalletDelta($baseAccount, $qty);
            $this->applyWalletDelta($quoteAccount, -$notional);
        } else {
            $this->applyWalletDelta($baseAccount, -$qty);
            $this->applyWalletDelta($quoteAccount, $notional);
        }

        WalletLedgerEntry::create([
            'id' => (string) Str::uuid(),
            'account_id' => $quoteAccount->id,
            'type' => $side === 'buy' ? 'TRADE_BUY' : 'TRADE_SELL',
            'amount' => $side === 'buy' ? -$notional : $notional,
                'reference_id' => $order->id,
                'meta_json' => [
                    'symbol' => $this->orderSymbolCode($order),
                    'qty' => $qty,
                    'side' => $side,
                ],
            'ts' => now(),
            'trace_id' => $traceId,
        ]);

        if ($fees > 0) {
            $feeAsset = $fills[0]->fee_asset ?: $quoteAsset;
            $feeAccount = $this->walletAccount($order->workspace_id, $feeAsset, $order->user_id ?? null);
            $this->applyWalletDelta($feeAccount, -$fees);

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $feeAccount->id,
                'type' => 'TRADE_FEE',
                'amount' => -$fees,
                'reference_id' => $order->id,
                'meta_json' => [
                    'symbol' => $this->orderSymbolCode($order),
                    'fee_asset' => $feeAsset,
                ],
                'ts' => now(),
                'trace_id' => $traceId,
            ]);
        }
    }

    private function walletAccount(?string $workspaceId, string $currency, ?string $userId): WalletAccount
    {
        return WalletAccount::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'currency' => strtoupper($currency),
                'type' => 'exchange',
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'name' => strtoupper($currency) . ' Exchange Wallet',
                'status' => 'active',
                'wallet_type' => 'provider',
                'balance' => 0,
                'available' => 0,
                'locked_balance' => 0,
            ]
        );
    }

    private function applyWalletDelta(WalletAccount $account, float $delta): void
    {
        $account->balance = (float) $account->balance + $delta;
        $account->available = (float) $account->available + $delta;
        $account->save();
    }

    private function splitSymbol(string $symbol): array
    {
        $quotes = ['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH', 'EUR', 'LKR'];
        foreach ($quotes as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }

        return [null, null];
    }

    private function mapExchangeStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'NEW' => 'NEW',
            'PARTIALLY_FILLED' => 'PARTIAL',
            'PARTIAL' => 'PARTIAL',
            'FILLED' => 'FILLED',
            'CANCELED', 'CANCELLED', 'EXPIRED' => 'CANCELLED',
            'REJECTED' => 'REJECTED',
            'UNKNOWN', 'PENDING_PROVIDER' => Order::STATUS_PENDING_PROVIDER,
            'UNCERTAIN' => Order::STATUS_UNCERTAIN,
            default => strtoupper($status),
        };
    }

    private function orderSymbolCode(Order $order): string
    {
        $symbolAttr = $order->getAttribute('symbol');
        if (is_string($symbolAttr) && trim($symbolAttr) !== '') {
            return strtoupper(trim($symbolAttr));
        }
        if (is_object($symbolAttr) && property_exists($symbolAttr, 'symbol')) {
            return strtoupper((string) $symbolAttr->symbol);
        }

        if ($order->symbol_id) {
            $symbolModel = Symbol::find($order->symbol_id);
            if ($symbolModel) {
                return (string) $symbolModel->symbol;
            }
        }

        $rawSymbol = data_get($order->raw_json, 'symbol');
        if ($rawSymbol) {
            return (string) $rawSymbol;
        }

        return '';
    }

    private function emitLifecycleEvents(
        Order $order,
        string $previousStatus,
        string $currentStatus,
        array $newFills,
        array $quality,
        string $source,
        string $traceId
    ): void {
        if ($order->exchange_order_id && in_array($previousStatus, [strtoupper(Order::STATUS_PENDING_PROVIDER), Order::STATUS_UNCERTAIN], true) && ! in_array($currentStatus, [strtoupper(Order::STATUS_PENDING_PROVIDER), Order::STATUS_UNCERTAIN], true)) {
            $this->events->recordOrderEvent($order, 'acknowledged', [
                'provider_status' => $order->provider_status,
            ], 'info', $source, $traceId);
        }

        if (count($newFills) > 0) {
            $this->events->recordOrderEvent($order, $currentStatus === strtoupper(Order::STATUS_FILLED) ? 'filled' : 'partial_fill', [
                'new_fill_count' => count($newFills),
                'filled_qty' => $quality['filled_qty'] ?? null,
                'avg_execution_price' => $quality['avg_execution_price'] ?? null,
                'slippage_pct' => $quality['slippage_pct'] ?? null,
                'fill_latency_ms' => $quality['fill_latency_ms'] ?? null,
            ], 'info', $source, $traceId);
        }

        if ($currentStatus === Order::STATUS_CANCELLED && $previousStatus !== Order::STATUS_CANCELLED) {
            $this->events->recordOrderEvent($order, 'cancelled', [
                'provider_status' => $order->provider_status,
            ], 'info', $source, $traceId);
        }

        if ($currentStatus === Order::STATUS_REJECTED && $previousStatus !== Order::STATUS_REJECTED) {
            $this->events->recordOrderEvent($order, 'rejected', [
                'provider_status' => $order->provider_status,
                'reason' => $quality['reject_reason'] ?? null,
            ], 'warning', $source, $traceId);
        }
    }

    private function recordMismatch(Order $order, string $reason, array $exchangePayload, string $traceId): void
    {
        $this->events->recordAnomaly($order, 'reconciliation_mismatch', 'Live order reconciliation mismatch detected.', [
            'reason' => $reason,
            'provider_status' => $exchangePayload['status'] ?? null,
            'exchange_order_id' => $exchangePayload['orderId'] ?? null,
            'source' => $exchangePayload['source'] ?? 'exchange',
        ], 'warning', 'reconciliation');
        $this->events->recordOrderEvent($order, 'reconciliation_mismatch', [
            'reason' => $reason,
            'provider_payload' => $exchangePayload,
        ], 'warning', 'reconciliation', $traceId);
    }
}
