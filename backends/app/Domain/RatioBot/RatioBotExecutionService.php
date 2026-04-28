<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\LiveOrderIntentService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesOrderIntentService;
use App\Domain\Trading\UncertainLiveOrderException;
use App\Models\Integration;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Executes ratio-bot BUY / SELL decisions through the shared live execution path.
 */
class RatioBotExecutionService
{
    public function __construct(
        private readonly LiveOrderIntentService $orders,
        private readonly RatioBotStateService $stateService,
        private readonly FuturesOrderIntentService $futuresOrders,
        private readonly FuturesConfigService $futuresConfigs,
    ) {
    }

    public function execute(RatioBotConfig $config, RatioBotDecision $decision): void
    {
        if (! $decision->isTrade()) {
            Log::debug('[RatioBotExec] Non-trade decision skipped', ['decision_id' => $decision->id]);
            return;
        }

        if ($decision->execution_status !== RatioBotDecision::EXEC_PENDING) {
            Log::warning('[RatioBotExec] Decision is not pending; execution skipped', [
                'decision_id' => $decision->id,
                'status' => $decision->execution_status,
            ]);
            return;
        }

        $state = $this->stateService->getOrCreate($config);
        if ($state->position_state === RatioBotState::STATE_AWAITING_EXECUTION || $this->hasSubmittedTrade($config, $decision)) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_DUPLICATE_BLOCKED,
                'reason' => 'duplicate_prevented: another ratio-bot order is pending or awaiting execution result',
            ]);
            Log::warning('[RatioBotExec] Duplicate execution blocked', [
                'config_id' => $config->id,
                'decision_id' => $decision->id,
            ]);
            return;
        }

        $side = $decision->decision;
        $symbol = $config->normalizedSymbol();
        $clientOrderId = substr('rb-' . str_replace('-', '', (string) $decision->id), 0, 36);

        try {
            $qty = $this->resolveQuantity($config, $decision, $side);
        } catch (Throwable $e) {
            $this->stateService->onOrderFailed($config, $decision, $e->getMessage());
            return;
        }

        if ($qty <= 0) {
            $this->stateService->onOrderFailed($config, $decision, 'resolved_order_quantity_is_zero');
            return;
        }

        Log::info('[RatioBotExec] Preparing order', [
            'config_id' => $config->id,
            'decision_id' => $decision->id,
            'symbol' => $symbol,
            'side' => $side,
            'qty' => $qty,
            'client_order_id' => $clientOrderId,
            'execution_mode' => $config->execution_mode,
        ]);

        if ($config->isSandbox()) {
            $this->simulateSandboxExecution($config, $decision, $side, (float) $decision->evaluated_price, $qty, $clientOrderId);
            return;
        }

        if ($this->hasSameSideOpenLiveOrder($config, $side, $clientOrderId)) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_DUPLICATE_BLOCKED,
                'reason' => "duplicate_prevented: same-side {$side} live order is still open or uncertain",
                'client_order_id' => $clientOrderId,
            ]);
            Log::warning('[RatioBotExec] Same-side open live order blocked execution', [
                'config_id' => $config->id,
                'side' => $side,
                'client_order_id' => $clientOrderId,
            ]);
            return;
        }

        $this->stateService->onAwaitingExecution($config, $decision, $clientOrderId);

        try {
            if ($config->marketType() === 'futures') {
                $order = $this->executeFutures($config, $decision, $qty, $clientOrderId);
                $this->handleFuturesOrderResult($config, $decision, $order, $side, $qty, $clientOrderId);
            } else {
                if (! $config->connection_id) {
                    $this->stateService->onOrderFailed($config, $decision, 'live_execution_requires_connection_id');
                    return;
                }

                $order = $this->orders->place([
                    'workspace_id' => (string) $config->workspace_id,
                    'connection_id' => (string) $config->connection_id,
                    'symbol' => $symbol,
                    'side' => strtolower($side),
                    'type' => 'market',
                    'qty' => $qty,
                    'idempotency_key' => $clientOrderId,
                    'meta' => $this->orderMeta($config, $decision),
                ]);

                $this->handleOrderResult($config, $decision, $order, $side, $clientOrderId);
            }
        } catch (UncertainLiveOrderException $e) {
            $this->stateService->errorReviewRequired(
                $config,
                $decision,
                'execution_uncertain',
                $e->getMessage(),
                [
                    'order_id' => $e->order->id,
                    'exchange_order_id' => $e->order->exchange_order_id,
                    'client_order_id' => $clientOrderId,
                ],
            );
        } catch (Throwable $e) {
            $this->handleOrderException($config, $decision, $e, $clientOrderId);
        }
    }

    private function executeFutures(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $qty,
        string $clientOrderId,
    ): Order {
        $integration = $this->resolveFuturesIntegration($config);
        $workspaceConfig = $this->futuresConfigs->effective((string) $config->workspace_id);
        $positionMode = strtolower((string) ($config->position_mode ?: ($workspaceConfig['allowed_position_modes'][0] ?? 'one_way')));
        $decisionSide = strtoupper((string) $decision->decision);
        $reduceOnly = in_array($decisionSide, [RatioBotDecision::DECISION_EXIT_LONG, RatioBotDecision::DECISION_EXIT_SHORT], true);

        return $this->futuresOrders->place((string) $config->workspace_id, [
            'integration_id' => (string) $integration->id,
            'symbol' => $config->normalizedSymbol(),
            'side' => $this->futuresOrderSide($decisionSide),
            'type' => 'market',
            'qty' => $qty,
            'leverage' => (float) ($config->leverage ?: ($workspaceConfig['max_leverage'] ?? 3)),
            'margin_mode' => strtolower((string) ($config->margin_type ?: ($workspaceConfig['allowed_margin_modes'][0] ?? 'isolated'))),
            'position_mode' => $positionMode,
            'position_side' => $this->futuresPositionSide($decisionSide, $positionMode),
            'reduce_only' => $reduceOnly && (bool) ($config->reduce_only_exit_orders ?? true),
            'protective_exit' => $reduceOnly,
            'idempotency_key' => $clientOrderId,
            'source' => 'ratio_bot',
            'entry_price' => (float) ($decision->evaluated_price ?? 0),
            'confidence' => data_get($decision->metadata, 'confidence'),
            'meta' => $this->orderMeta($config, $decision),
        ], $integration, $workspaceConfig);
    }

    private function handleOrderResult(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        Order $order,
        string $side,
        string $clientOrderId,
    ): void {
        $status = strtoupper((string) $order->status);
        if (in_array($status, ['FILLED', 'PARTIAL'], true)) {
            $executedPrice = $this->avgFillPrice($order, (float) $decision->evaluated_price);
            $executedQty = (float) ($order->filled_qty ?: $order->qty ?: $order->quantity);
            $orderId = (string) ($order->exchange_order_id ?: $order->id);

            if ($executedPrice <= 0 || $executedQty <= 0) {
                $this->stateService->errorReviewRequired(
                    $config,
                    $decision,
                    'invalid_fill_payload',
                    'Exchange order was filled but fill price or quantity is missing.',
                    ['order_id' => $order->id, 'status' => $order->status],
                );
                return;
            }

            if ($side === RatioBotDecision::DECISION_BUY) {
                $this->stateService->onBuyFilled($config, $decision, $executedPrice, $executedQty, $orderId, $clientOrderId);
            } else {
                $this->stateService->onSellFilled($config, $decision, $executedPrice, $executedQty, $orderId, $clientOrderId);
            }

            Log::info('[RatioBotExec] Order filled', [
                'config_id' => $config->id,
                'decision_id' => $decision->id,
                'order_id' => $orderId,
                'side' => $side,
                'price' => $executedPrice,
                'qty' => $executedQty,
            ]);
            return;
        }

        $this->stateService->errorReviewRequired(
            $config,
            $decision,
            'execution_not_final',
            "Market order returned non-final status {$order->status}. Manual reconciliation is required before retry.",
            [
                'order_id' => $order->id,
                'exchange_order_id' => $order->exchange_order_id,
                'provider_status' => $order->provider_status,
                'client_order_id' => $clientOrderId,
            ],
        );
    }

    private function handleFuturesOrderResult(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        Order $order,
        string $side,
        float $qty,
        string $clientOrderId,
    ): void {
        $status = strtoupper((string) $order->status);
        $executedPrice = $this->avgFillPrice($order, (float) $decision->evaluated_price);
        $executedQty = (float) ($order->filled_qty ?: $order->qty ?: $qty);
        $orderId = (string) ($order->exchange_order_id ?: $order->id);

        $decision->update([
            'client_order_id' => $clientOrderId,
            'order_id' => $orderId,
            'execution_status' => in_array($status, ['FILLED', 'PARTIAL'], true)
                ? ($status === 'FILLED' ? RatioBotDecision::EXEC_FILLED : RatioBotDecision::EXEC_PARTIAL)
                : RatioBotDecision::EXEC_SUBMITTED,
            'executed_price' => $executedPrice > 0 ? $executedPrice : null,
            'executed_qty' => $executedQty > 0 ? $executedQty : null,
            'executed_at' => in_array($status, ['FILLED', 'PARTIAL'], true) ? now() : null,
        ]);

        $config->state?->update([
            'last_order_status' => $order->status,
            'last_fill_price' => $executedPrice > 0 ? $executedPrice : $config->state?->last_fill_price,
        ]);

        $this->stateService->rememberSignal($config, $decision->decision, (string) $decision->reason, (string) data_get($decision->metadata, 'signal_hash', ''), [
            'order_id' => $orderId,
            'last_order_status' => $order->status,
        ]);
        $this->stateService->markMarketSnapshot(
            $config,
            (float) ($decision->evaluated_price ?? 0),
            $decision->price_source,
            $decision->price_timestamp,
            ['last_order_status' => $order->status]
        );

        if (in_array($decision->decision, [RatioBotDecision::DECISION_LONG, RatioBotDecision::DECISION_SHORT], true)
            && in_array($status, ['FILLED', 'PARTIAL'], true)
        ) {
            $this->stateService->syncPositionSnapshot(
                $config,
                $decision->decision === RatioBotDecision::DECISION_SHORT ? 'short' : 'long',
                $executedQty,
                $executedPrice > 0 ? $executedPrice : (float) $decision->evaluated_price,
                0
            );
        }

        if (in_array($decision->decision, [RatioBotDecision::DECISION_EXIT_LONG, RatioBotDecision::DECISION_EXIT_SHORT], true)
            && in_array($status, ['FILLED', 'PARTIAL'], true)
        ) {
            $this->stateService->syncPositionSnapshot($config, 'flat', 0, null, 0);
        }
    }

    private function handleOrderException(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        Throwable $e,
        string $clientOrderId,
    ): void {
        $message = $e->getMessage();

        if ($this->isDuplicateOrderError($message)) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_DUPLICATE_BLOCKED,
                'reason' => 'duplicate_order_blocked: ' . $message,
                'client_order_id' => $clientOrderId,
            ]);

            $state = $this->stateService->getOrCreate($config);
            $state->update([
                'position_state' => $state->resumableTradingState(),
                'awaiting_since' => null,
                'block_reason_code' => 'duplicate_order_blocked',
                'block_reason_detail' => $message,
            ]);

            Log::warning('[RatioBotExec] Duplicate order blocked safely', [
                'config_id' => $config->id,
                'client_order_id' => $clientOrderId,
            ]);
            return;
        }

        Log::error('[RatioBotExec] Order submission failed', [
            'config_id' => $config->id,
            'decision_id' => $decision->id,
            'error' => $message,
        ]);
        $this->stateService->onOrderFailed($config, $decision, $message);
    }

    private function simulateSandboxExecution(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        string $side,
        float $price,
        float $qty,
        string $clientOrderId,
    ): void {
        $fakeOrderId = 'SANDBOX-' . strtoupper(Str::random(12));

        if ($side === RatioBotDecision::DECISION_BUY) {
            $this->stateService->onBuyFilled($config, $decision, $price, $qty, $fakeOrderId, $clientOrderId);
            return;
        }

        if ($side === RatioBotDecision::DECISION_SELL) {
            $this->stateService->onSellFilled($config, $decision, $price, $qty, $fakeOrderId, $clientOrderId);
            return;
        }

        $this->handleFuturesSandboxExecution($config, $decision, $side, $price, $qty, $fakeOrderId, $clientOrderId);
    }

    private function resolveQuantity(RatioBotConfig $config, RatioBotDecision $decision, string $side): float
    {
        if ($config->marketType() === 'futures') {
            return $this->resolveFuturesQuantity($config, $decision, $side);
        }

        if ($side === RatioBotDecision::DECISION_SELL) {
            $state = $this->stateService->getOrCreate($config);
            $qty = (float) ($state->last_buy_quantity ?? 0);
            if ($config->allow_partial_sell && (float) ($config->partial_sell_pct ?? 0) > 0) {
                $qty *= min(1.0, max(0.0, (float) $config->partial_sell_pct));
            }
            if ($qty <= 0) {
                throw new RuntimeException('Cannot sell: last_buy_quantity is zero');
            }

            return round($qty, 8);
        }

        $price = (float) ($decision->evaluated_price ?? 0);
        if ($price <= 0) {
            throw new RuntimeException("Cannot buy: invalid evaluated price {$price}");
        }

        $amount = (float) $config->base_quote_amount;
        if ($amount <= 0) {
            throw new RuntimeException('Cannot buy: base_quote_amount is zero');
        }

        $qty = match ((string) $config->order_size_mode) {
            'fixed_base' => $amount,
            'percent_of_balance' => $amount / $price,
            default => $amount / $price,
        };

        if ($config->allow_partial_buy && (float) ($config->partial_buy_pct ?? 0) > 0) {
            $qty *= min(1.0, max(0.0, (float) $config->partial_buy_pct));
        }

        return round($qty, 8);
    }

    private function resolveFuturesQuantity(RatioBotConfig $config, RatioBotDecision $decision, string $side): float
    {
        $state = $this->stateService->getOrCreate($config);
        if (in_array($side, [RatioBotDecision::DECISION_EXIT_LONG, RatioBotDecision::DECISION_EXIT_SHORT], true)) {
            $qty = (float) ($state->last_position_qty ?? 0);
            if ($qty <= 0) {
                throw new RuntimeException('Cannot close futures position: last_position_qty is zero');
            }

            return round($qty, 8);
        }

        $price = (float) ($decision->evaluated_price ?? 0);
        if ($price <= 0) {
            throw new RuntimeException('Cannot open futures position: invalid evaluated price');
        }

        $quoteBudget = (float) ($config->quote_asset_budget ?? $config->base_quote_amount ?? 0);
        if ($quoteBudget <= 0) {
            throw new RuntimeException('Cannot open futures position: quote_asset_budget is zero');
        }

        $leverage = max(1.0, (float) ($config->leverage ?? 1));
        $qty = ($quoteBudget * $leverage) / $price;
        $cap = (float) ($config->max_open_position_per_symbol ?? 0);
        if ($cap > 0) {
            $qty = min($qty, $cap);
        }

        return round($qty, 8);
    }

    private function avgFillPrice(Order $order, float $fallback): float
    {
        $price = (float) ($order->avg_fill_price ?: $order->price ?: $fallback);
        if ($price > 0) {
            return $price;
        }

        $rawFills = (array) data_get($order->raw_json, 'raw.fills', data_get($order->raw_json, 'fills', []));
        $totalQty = 0.0;
        $totalQuote = 0.0;
        foreach ($rawFills as $fill) {
            $qty = (float) ($fill['qty'] ?? 0);
            $fillPrice = (float) ($fill['price'] ?? 0);
            $totalQty += $qty;
            $totalQuote += $qty * $fillPrice;
        }

        return $totalQty > 0 ? $totalQuote / $totalQty : $fallback;
    }

    private function hasSubmittedTrade(RatioBotConfig $config, RatioBotDecision $currentDecision): bool
    {
        return RatioBotDecision::query()
            ->where('ratio_bot_config_id', $config->id)
            ->whereKeyNot($currentDecision->id)
            ->whereIn('decision', [
                RatioBotDecision::DECISION_BUY,
                RatioBotDecision::DECISION_SELL,
                RatioBotDecision::DECISION_LONG,
                RatioBotDecision::DECISION_SHORT,
                RatioBotDecision::DECISION_EXIT_LONG,
                RatioBotDecision::DECISION_EXIT_SHORT,
            ])
            ->whereIn('execution_status', [RatioBotDecision::EXEC_PENDING, RatioBotDecision::EXEC_SUBMITTED])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();
    }

    private function hasSameSideOpenLiveOrder(RatioBotConfig $config, string $side, string $clientOrderId): bool
    {
        return Order::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('asset_code', $config->normalizedSymbol())
            ->where('side', strtolower($this->orderSideForDecision($side)))
            ->where('client_order_id', '!=', $clientOrderId)
            ->where('meta->market_type', $config->marketType())
            ->whereIn('status', [
                Order::STATUS_PENDING_PROVIDER,
                Order::STATUS_NEW,
                Order::STATUS_PARTIAL,
                Order::STATUS_UNCERTAIN,
            ])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();
    }

    private function isDuplicateOrderError(string $message): bool
    {
        return str_contains($message, '-2010')
            || str_contains(strtolower($message), 'duplicate order')
            || str_contains(strtolower($message), 'duplicate client order id');
    }

    private function orderMeta(RatioBotConfig $config, RatioBotDecision $decision): array
    {
        return [
            'source' => 'ratio_bot',
            'ratio_bot_config_id' => (string) $config->id,
            'ratio_bot_decision_id' => (string) $decision->id,
            'ratio_bot_signal' => $decision->decision,
            'market_type' => $config->marketType(),
            'evaluated_price' => (float) $decision->evaluated_price,
            'price_source' => $decision->price_source,
            'price_timestamp' => optional($decision->price_timestamp)->toIso8601String(),
            'anchor_price' => $decision->anchor_price ? (float) $decision->anchor_price : null,
            'computed_ratio' => $decision->computed_ratio ? (float) $decision->computed_ratio : null,
            'threshold_used' => $decision->threshold_used ? (float) $decision->threshold_used : null,
            'decision_metadata' => $decision->metadata,
        ];
    }

    private function orderSideForDecision(string $decision): string
    {
        return in_array($decision, [
            RatioBotDecision::DECISION_BUY,
            RatioBotDecision::DECISION_LONG,
            RatioBotDecision::DECISION_EXIT_SHORT,
        ], true) ? 'buy' : 'sell';
    }

    private function futuresOrderSide(string $decision): string
    {
        return $this->orderSideForDecision($decision);
    }

    private function futuresPositionSide(string $decision, string $positionMode): string
    {
        if ($positionMode !== 'hedge') {
            return 'BOTH';
        }

        return in_array($decision, [RatioBotDecision::DECISION_LONG, RatioBotDecision::DECISION_EXIT_LONG], true)
            ? 'LONG'
            : 'SHORT';
    }

    private function resolveFuturesIntegration(RatioBotConfig $config): Integration
    {
        $query = Integration::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('provider_key', 'binance')
            ->where('status', 'active');

        if ($config->integration_id) {
            $query->whereKey($config->integration_id);
        }

        $integration = $query->latest('updated_at')->first();
        if (! $integration) {
            throw new RuntimeException('Active Binance integration not found for futures ratio bot.');
        }

        return $integration;
    }

    private function handleFuturesSandboxExecution(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        string $side,
        float $price,
        float $qty,
        string $orderId,
        string $clientOrderId,
    ): void {
        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_FILLED,
            'executed_price' => $price,
            'executed_qty' => $qty,
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'executed_at' => now(),
        ]);

        if (in_array($side, [RatioBotDecision::DECISION_LONG, RatioBotDecision::DECISION_SHORT], true)) {
            $this->stateService->syncPositionSnapshot(
                $config,
                $side === RatioBotDecision::DECISION_SHORT ? 'short' : 'long',
                $qty,
                $price,
                0
            );
            return;
        }

        $this->stateService->syncPositionSnapshot($config, 'flat', 0, null, 0);
    }
}
