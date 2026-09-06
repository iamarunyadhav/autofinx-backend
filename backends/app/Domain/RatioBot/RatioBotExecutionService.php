<?php

namespace App\Domain\RatioBot;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Trading\LiveOrderIntentService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesOrderIntentService;
use App\Domain\Trading\UncertainLiveOrderException;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use Illuminate\Support\Facades\Cache;
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
        private readonly RatioBotRiskService $risk,
        private readonly StrategyManagerService $strategyManager,
        private readonly ExchangeClientFactory $exchangeClients,
        private readonly MarketSnapshotService $snapshots,
        private readonly RatioBotExchangeStopOrderService $stopOrders,
        private readonly RatioBotFundingCostService $fundingCost,
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

        // Latency: this is a free, local DB check — run it before
        // resolveQuantity() so a duplicate-blocked trade never pays for a
        // live balance fetch (capBuyQuoteToLiveBalance) it won't use.
        if (! $config->isSandbox() && $this->hasSameSideOpenLiveOrder($config, $side, $clientOrderId)) {
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

        if ($this->staleSnapshotBlock($config, $decision)) {
            return;
        }

        if ($this->shockBlock($config, $decision)) {
            return;
        }

        if ($config->marketType() !== 'futures' && $this->entryPriceDriftBlock($config, $decision, $symbol)) {
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

                $route = $config->spotExecutionRoute();
                $order = $this->orders->place([
                    'workspace_id' => (string) $config->workspace_id,
                    'connection_id' => (string) $config->connection_id,
                    'symbol' => $symbol,
                    'side' => strtolower($side),
                    'type' => $route === 'convert' ? 'convert' : 'market',
                    'qty' => $qty,
                    'price' => (float) ($decision->evaluated_price ?? 0),
                    'max_quote_deviation_bps' => $route === 'convert' ? $config->maxAllowedSlippageBps() : null,
                    'idempotency_key' => $clientOrderId,
                    'meta' => array_merge($this->orderMeta($config, $decision), [
                        'spot_execution_route' => $route,
                    ]),
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

    /**
     * Shock Detector (spec §63): immediately before submitting, freeze the
     * order if the market is currently classified HIGH_VOLATILITY (an
     * abnormal one-second move / spread explosion — IndicatorService's
     * volatility_level=EXTREME) or LOW_LIQUIDITY (liquidity/volume just
     * disappeared) — reusing StrategyManagerService's existing regime
     * classifier rather than building a second detector. Only meaningful
     * for v2_enabled bots (regime classification is a V2 concept and is
     * inert otherwise, matching StrategyManagerService::analyze()'s own
     * gate) and fails open on any error — a shock freeze that can itself
     * throw and silently block every trade forever would be worse than no
     * shock detector at all. Transient: never pauses the whole bot, the
     * next evaluation cycle simply reassesses.
     */
    private function shockBlock(RatioBotConfig $config, RatioBotDecision $decision): bool
    {
        if (! $config->v2_enabled) {
            return false;
        }

        try {
            $state = $this->stateService->getOrCreate($config);
            $analysis = $this->strategyManager->analyze($config, $state, (float) $decision->evaluated_price);
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Shock detector check failed, continuing without it', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $regime = (string) ($analysis['regime'] ?? '');
        if (! in_array($regime, [StrategyManagerService::REGIME_HIGH_VOLATILITY, StrategyManagerService::REGIME_LOW_LIQUIDITY], true)) {
            return false;
        }

        $this->applyEntryBlock($config, $decision, [
            'code' => 'market_shock_detected',
            'detail' => "Market shock detected (regime={$regime}: " . (string) ($analysis['regime_reason'] ?? 'n/a') . '). Order frozen — will re-evaluate next cycle.',
            'pause' => false,
            'meta' => ['regime' => $regime, 'analysis' => $analysis],
        ]);

        return true;
    }

    private function staleSnapshotBlock(RatioBotConfig $config, RatioBotDecision $decision): bool
    {
        if (! $config->usesRealtimeFeed()) {
            return false;
        }

        $snapshot = $this->snapshots->forRatioBot($config);
        if ((bool) ($snapshot['is_fresh'] ?? false) && (float) ($snapshot['price'] ?? 0) > 0) {
            return false;
        }

        $this->applyEntryBlock($config, $decision, [
            'code' => 'stale_feed',
            'detail' => sprintf(
                'Selected-symbol market snapshot is not fresh for %s: %s.',
                $config->normalizedSymbol(),
                (string) ($snapshot['stale_reason'] ?? 'unknown')
            ),
            'pause' => false,
            'meta' => ['snapshot' => $snapshot],
        ]);

        return true;
    }

    /**
     * Pre-submit revalidation: re-fetch a fresh reference price for the
     * exact venue/symbol the order is about to use, and compare it against
     * the price the decision was evaluated at. Catches drift accumulated in
     * the confirmation/guard window between decision time and submission.
     * Mirrors RobotSessionService::entryPreflightBlock()'s drift gate.
     */
    private function entryPriceDriftBlock(RatioBotConfig $config, RatioBotDecision $decision, string $symbol): bool
    {
        if (! $config->connection_id) {
            return false; // let the existing "live_execution_requires_connection_id" path handle it
        }

        if ($config->maxEntryPriceDriftBps() <= 0 && (string) $decision->decision !== RatioBotDecision::DECISION_SELL) {
            return false; // drift checking disabled and there's no SELL net-profit refresh to run either — skip the fetch entirely
        }

        $decisionPrice = (float) ($decision->evaluated_price ?? 0);

        try {
            $freshPrice = $this->orders->fetchReferencePrice([
                'workspace_id' => (string) $config->workspace_id,
                'connection_id' => (string) $config->connection_id,
                'symbol' => $symbol,
            ]);
        } catch (Throwable $e) {
            // Fail closed: if freshness cannot be verified immediately before
            // submission, do not submit blind.
            $this->applyEntryBlock($config, $decision, [
                'code' => 'price_drift_check_failed',
                'detail' => 'Could not verify a fresh reference price before submission: ' . $e->getMessage(),
                'pause' => false,
                'meta' => [],
            ]);
            return true;
        }

        $block = $this->risk->priceDriftBlock($config, $decisionPrice, $freshPrice)
            ?? $this->risk->sellNetProfitBlock($config, $decision, $freshPrice);
        if ($block === null) {
            return false;
        }

        $this->applyEntryBlock($config, $decision, $block);
        return true;
    }

    private function applyEntryBlock(RatioBotConfig $config, RatioBotDecision $decision, array $block): void
    {
        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_SKIPPED,
            'reason' => $block['detail'],
            'metadata' => array_merge((array) ($decision->metadata ?? []), ['risk_block' => $block]),
        ]);

        if ((bool) ($block['pause'] ?? false)) {
            $this->stateService->block($config, (string) $block['code'], (string) $block['detail']);
        } else {
            $state = $this->stateService->getOrCreate($config);
            $state->update([
                'block_reason_code' => $block['code'],
                'block_reason_detail' => $block['detail'],
            ]);
        }

        Log::warning('[RatioBotExec] Entry blocked before submission', [
            'config_id' => $config->id,
            'decision_id' => $decision->id,
            'code' => $block['code'],
        ]);
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
            $executedQty = $this->resolveExecutedQty($order, (float) $order->quantity);
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
                // Read the cost basis before onSellFilled() overwrites/clears it,
                // then feed the realized PnL into the daily-loss/consecutive-loss
                // risk gates — otherwise max_daily_loss / max_consecutive_losses
                // never actually see this trade (they were only ever fed from
                // RatioBotOrderUpdateService's async webhook path, which this
                // synchronous fill path bypasses entirely).
                $entryPrice = (float) ($config->state?->last_buy_price ?? 0);
                $this->stateService->onSellFilled($config, $decision, $executedPrice, $executedQty, $orderId, $clientOrderId);
                if ($entryPrice > 0 && $executedQty > 0) {
                    $this->stateService->recordTradeOutcome($config, ($executedPrice - $entryPrice) * $executedQty);
                }
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
        $executedQty = $this->resolveExecutedQty($order, $qty);
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
            // A fill/partial-fill is successful order progress, not a failure —
            // reset the streak the same way onBuyFilled()/onSellFilled() do for
            // spot, otherwise an unrelated earlier failure can still trip
            // pause_on_order_failure_streak after this trade actually succeeded.
            ...(in_array($status, ['FILLED', 'PARTIAL'], true) ? ['consecutive_order_failures' => 0] : []),
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
            $fillPrice = $executedPrice > 0 ? $executedPrice : (float) $decision->evaluated_price;
            $side = $decision->decision === RatioBotDecision::DECISION_SHORT ? 'short' : 'long';
            $isRecoveryTopup = (string) data_get($decision->metadata, 'trigger', '') === 'futures_recovery_topup';

            if ($isRecoveryTopup) {
                // Part B: blend into the EXISTING position's average entry
                // rather than overwrite it -- this is a top-up of the same
                // stuck position, not a fresh entry.
                $priorState = $config->state()->first();
                $oldQty = abs((float) ($priorState?->last_position_qty ?? 0));
                $oldEntry = (float) ($priorState?->entry_price ?? 0);
                $newQty = $oldQty + $executedQty;
                $newEntry = $newQty > 0 ? (($oldQty * $oldEntry) + ($executedQty * $fillPrice)) / $newQty : $fillPrice;

                $this->stateService->syncPositionSnapshot($config, $side, $newQty, $newEntry, 0);
                $config->state()->first()?->update([
                    'futures_recovery_used' => true,
                    'futures_recovery_topup_notional' => (float) data_get($decision->metadata, 'futures_recovery_topup_notional', 0),
                ]);

                RatioBotEvent::create([
                    'ratio_bot_config_id' => $config->id,
                    'event_type' => RatioBotEvent::EVENT_FUTURES_RECOVERY_TOPUP_EXECUTED,
                    'context' => [
                        'entry_price_before' => $oldEntry,
                        'entry_price_after' => $newEntry,
                        'qty_before' => $oldQty,
                        'qty_after' => $newQty,
                        'topup_notional' => (float) data_get($decision->metadata, 'futures_recovery_topup_notional', 0),
                        'fill_price' => $fillPrice,
                    ],
                    'occurred_at' => now(),
                ]);
            } else {
                $this->stateService->syncPositionSnapshot($config, $side, $executedQty, $fillPrice, 0);
            }

            // Required, not optional (Part B spec Â§5.3): a stale stop after a
            // top-up is exactly the kind of gap Part C exists to prevent.
            // syncFuturesStopOrderSafely() always recomputes from the
            // freshest state, so this single call correctly covers both a
            // fresh entry and a top-up's blended entry price.
            $this->syncFuturesStopOrderSafely($config->fresh('state'));
        }

        if (in_array($decision->decision, [RatioBotDecision::DECISION_EXIT_LONG, RatioBotDecision::DECISION_EXIT_SHORT], true)
            && in_array($status, ['FILLED', 'PARTIAL'], true)
        ) {
            $this->cancelFuturesStopOrderSafely($config, 'position_closed');
            $this->stateService->syncPositionSnapshot($config, 'flat', 0, null, 0);
            $this->fundingCost->resetForClosedPosition($config);
        }
    }

    /**
     * Best-effort: the exchange-side stop is a secondary safety net, never
     * the primary trade. A failure placing/replacing it must never surface
     * as if the actual entry/exit failed -- log and move on, the next fill
     * or reconcile tick gets another chance.
     */
    private function syncFuturesStopOrderSafely(RatioBotConfig $config): void
    {
        try {
            $integration = $this->resolveFuturesIntegration($config);
            $workspaceConfig = $this->futuresConfigs->effective((string) $config->workspace_id);
            $this->stopOrders->syncStopOrder($config, $integration, $workspaceConfig);
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Exchange-side stop order sync failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Public: also called directly by RatioBotRealtimeEngine::forceExitDecision()
     * so the Liquidation Guard's own force-close cancels the now-stale
     * exchange-side stop as part of the same protective action, before it
     * submits the closing order itself.
     */
    public function cancelFuturesStopOrderSafely(RatioBotConfig $config, string $reason): void
    {
        try {
            $integration = $this->resolveFuturesIntegration($config);
            $workspaceConfig = $this->futuresConfigs->effective((string) $config->workspace_id);
            $this->stopOrders->cancelStopOrder($config, $integration, $workspaceConfig, $reason);
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Exchange-side stop order cancel failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
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
            $entryPrice = (float) ($config->state?->last_buy_price ?? 0);
            $this->stateService->onSellFilled($config, $decision, $price, $qty, $fakeOrderId, $clientOrderId);
            if ($entryPrice > 0 && $qty > 0) {
                $this->stateService->recordTradeOutcome($config, ($price - $entryPrice) * $qty);
            }
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
            $metadataQty = (float) data_get($decision->metadata, 'sell_quantity', 0);
            if ($metadataQty > 0) {
                $qty = $metadataQty;
            } else {
                $state = $this->stateService->getOrCreate($config);
                $totalQty = (float) ($state->last_buy_quantity ?? 0);
                if ($totalQty <= 0) {
                    throw new RuntimeException('Cannot sell: last_buy_quantity is zero');
                }

                if ($config->sellTranchesEnabled()) {
                    $qty = $this->resolveStagedSellQuantity($config, $state, $totalQty);
                } else {
                    $qty = $totalQty;
                    if ($config->allow_partial_sell && (float) ($config->partial_sell_pct ?? 0) > 0) {
                        $qty *= min(1.0, max(0.0, (float) $config->partial_sell_pct));
                    }
                }
            }

            return round($this->capSellQtyToLiveBalance($config, $qty), 8);
        }

        $price = (float) ($decision->evaluated_price ?? 0);
        if ($price <= 0) {
            throw new RuntimeException("Cannot buy: invalid evaluated price {$price}");
        }

        $stageQuoteAmount = (float) data_get($decision->metadata, 'stage_quote_amount', 0);
        if ($stageQuoteAmount > 0) {
            return round($this->capBuyQuoteToLiveBalance($config, $stageQuoteAmount) / $price, 8);
        }

        $state = $this->stateService->getOrCreate($config);

        // Reserve tranches (Phase 2): a buy fired while already partial-
        // holding spends an equal split of whatever reserve remains across
        // whatever tranches remain — self-correcting for rounding, the
        // final tranche always spends 100% of what's left.
        if ($state->isPartialHolding() && (int) ($state->reserve_tranches_remaining ?? 0) > 0) {
            $tranchesRemaining = (int) $state->reserve_tranches_remaining;
            $quoteRemaining = (float) ($state->reserve_quote_remaining ?? 0);
            $quoteForTranche = $quoteRemaining / $tranchesRemaining;
            if ($quoteForTranche <= 0) {
                throw new RuntimeException('Cannot buy reserve tranche: reserve_quote_remaining is zero');
            }

            return round($this->capBuyQuoteToLiveBalance($config, $quoteForTranche) / $price, 8);
        }

        $amount = (float) $config->base_quote_amount;
        if ($amount <= 0) {
            throw new RuntimeException('Cannot buy: base_quote_amount is zero');
        }

        if ($config->reserveEnabled()) {
            $amount *= $this->effectiveInitialEntryPct($config, $state, $price);
        }

        // fixed_base means $amount is already a BASE-asset quantity, not
        // quote currency — capping it against the quote balance would be
        // meaningless (wrong unit), so it's the one mode this skips.
        if ((string) $config->order_size_mode !== 'fixed_base') {
            $amount = $this->capBuyQuoteToLiveBalance($config, $amount);
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

    /**
     * Multi-bot / shared-wallet safety: never assume more quote-currency
     * capital is available than what's ACTUALLY free on the exchange right
     * now. Two bots on different symbols (e.g. BTCUSDT and ETHUSDT) with
     * independent base_quote_amount budgets but sharing the same real
     * account would otherwise both size against their full configured
     * budget even after the other has already spent from the same USDT
     * pool. Capping every BUY at min(configured amount, live free balance)
     * makes each bot naturally spend only what's genuinely left — the
     * exchange would reject an actual overspend anyway, just less
     * gracefully (a failed order instead of a smaller, successful one).
     * Fails open (returns the configured amount unchanged) on sandbox,
     * futures, missing connection, or any live-check error — this is a
     * safety refinement on top of the exchange's own hard balance
     * enforcement, not a replacement for it.
     *
     * Latency/rate-limit: the actual exchange fetch is cached for a few
     * seconds, keyed by connection+asset (not per-bot). Free balance only
     * ever moves when an order actually fills — it never drifts with market
     * price the way a reference price does — so a several-second-old read
     * cannot cause an overspend the exchange's own hard balance check
     * wouldn't already catch (see docblock above). This is what actually
     * matters for the multi-bot case: N bots sharing one exchange account
     * evaluating around the same tick now share ONE live balance fetch
     * instead of firing N, without weakening the cap itself.
     */
    private function capBuyQuoteToLiveBalance(RatioBotConfig $config, float $desiredQuote): float
    {
        if ($desiredQuote <= 0 || $config->isSandbox() || $config->marketType() === 'futures' || ! $config->connection_id) {
            return $desiredQuote;
        }

        $quoteAsset = $config->quoteAsset();
        if (! $quoteAsset) {
            return $desiredQuote;
        }

        try {
            $free = $this->liveFreeBalance($config, $quoteAsset);
            if ($free === null) {
                return $desiredQuote;
            }

            if ($free < $desiredQuote) {
                Log::info('[RatioBotExec] Live balance cap reduced buy size', [
                    'config_id' => $config->id,
                    'quote_asset' => $quoteAsset,
                    'desired_quote' => $desiredQuote,
                    'live_free_balance' => $free,
                ]);
            }

            return max(0.0, min($desiredQuote, $free));
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Live balance cap check failed, using configured amount unchanged', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return $desiredQuote;
        }
    }

    /**
     * SELL-side counterpart to capBuyQuoteToLiveBalance: this bot's own lot
     * / state bookkeeping is what sizes a sell, but if the real account
     * currently holds less BASE asset than that bookkeeping expects —
     * another bot on the same shared wallet already spent it, a manual
     * withdrawal, a partial-fill mismatch — submitting the bookkeeping's qty
     * would either be exchange-rejected or, worse, attempt to sell into
     * another bot's inventory. Same fail-open contract as the BUY-side cap:
     * sandbox, futures, missing connection, or any live-check error returns
     * the resolved quantity unchanged.
     */
    private function capSellQtyToLiveBalance(RatioBotConfig $config, float $desiredQty): float
    {
        if ($desiredQty <= 0 || $config->isSandbox() || $config->marketType() === 'futures' || ! $config->connection_id) {
            return $desiredQty;
        }

        $baseAsset = $config->baseAsset();
        if (! $baseAsset) {
            return $desiredQty;
        }

        try {
            $free = $this->liveFreeBalance($config, $baseAsset);
            if ($free === null) {
                return $desiredQty;
            }

            if ($free < $desiredQty) {
                Log::info('[RatioBotExec] Live balance cap reduced sell size', [
                    'config_id' => $config->id,
                    'base_asset' => $baseAsset,
                    'desired_qty' => $desiredQty,
                    'live_free_balance' => $free,
                ]);
            }

            return max(0.0, min($desiredQty, $free));
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Live sell balance cap check failed, using resolved quantity unchanged', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return $desiredQty;
        }
    }

    /**
     * Free balance for one asset on one exchange connection, cached for a
     * short TTL so concurrent bots on the same account don't each pay for
     * their own round trip. Returns null (caller fails open) when the
     * connection is missing/inactive or the asset isn't found.
     */
    private function liveFreeBalance(RatioBotConfig $config, string $asset): ?float
    {
        $cacheKey = "ratio_bot:live_free_balance:{$config->connection_id}:{$asset}";

        return Cache::remember($cacheKey, 4, function () use ($config, $asset) {
            $connection = ExchangeConnection::query()
                ->where('workspace_id', $config->workspace_id)
                ->whereKey($config->connection_id)
                ->first();
            if (! $connection || ! $connection->is_active) {
                return null;
            }

            $balances = $this->exchangeClients->makeFromConnection($connection)->fetchBalances();
            foreach ($balances as $balance) {
                if (strtoupper((string) ($balance['asset'] ?? '')) === $asset) {
                    return (float) ($balance['free'] ?? 0);
                }
            }

            return 0.0;
        });
    }

    /**
     * Staged SELL (V2) — mirrors the existing staged-BUY tranche split:
     * self-correcting even split across whatever sell stages remain, so the
     * final stage always sells 100% of whatever quantity is left.
     */
    private function resolveStagedSellQuantity(RatioBotConfig $config, RatioBotState $state, float $totalQty): float
    {
        if ($state->isPartialSold() && (int) ($state->sell_tranches_remaining ?? 0) > 0) {
            return round($totalQty / (int) $state->sell_tranches_remaining, 8);
        }

        $pct = min(1.0, max(0.01, (float) ($config->initial_exit_pct ?? 1.0)));

        return round($totalQty * $pct, 8);
    }

    /**
     * Resolve the effective fraction of base_quote_amount to spend on a
     * fresh entry. Static (config.initial_entry_pct) unless V2 dynamic
     * reserve mode is enabled, in which case the Strategy Manager may adjust
     * it within the bot's own configured reserve_min_pct/reserve_max_pct
     * bounds. Fails open to the static value on any error.
     */
    private function effectiveInitialEntryPct(RatioBotConfig $config, RatioBotState $state, float $price): float
    {
        $static = max(0.01, min(1.0, (float) ($config->initial_entry_pct ?? 1.0)));

        if (! $config->v2_enabled || (string) $config->reserve_mode !== 'dynamic') {
            return $static;
        }

        try {
            $analysis = app(StrategyManagerService::class)->analyze($config, $state, $price);
            $reservePct = $analysis['reserve']['effective_pct'] ?? null;
            if ($reservePct === null) {
                return $static;
            }

            return max(0.01, min(1.0, 1 - (float) $reservePct));
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Dynamic reserve resolution failed, using static initial_entry_pct', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return $static;
        }
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

        // Part B: a futures recovery top-up is sized off the ORIGINAL
        // position's notional (see RatioBotConfig::futuresRecoveryTopupNotional()),
        // never the bot's normal per-entry budget -- it's a bounded, one-time
        // addition, not a fresh full-size entry.
        $topupNotional = (float) data_get($decision->metadata, 'futures_recovery_topup_notional', 0);
        if ($topupNotional > 0) {
            return round($topupNotional / $price, 8);
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

    /**
     * `$order->filled_qty ?: $order->qty ?: ...` looks like a safe fallback
     * chain but is a real bug: Eloquent's decimal cast returns filled_qty as
     * a STRING (e.g. "0.00000000"), and PHP's `?:` only treats the exact
     * strings "" and "0" as falsy -- "0.00000000" is truthy, so an unfilled
     * quantity silently wins over the real requested qty instead of falling
     * through, and callers proceed with executedQty=0 instead of catching
     * it as invalid. Compare the actual numeric value instead.
     */
    private function resolveExecutedQty(Order $order, float $fallback): float
    {
        $filledQty = (float) ($order->filled_qty ?? 0);
        if ($filledQty > 0) {
            return $filledQty;
        }

        $qty = (float) ($order->qty ?? 0);

        return $qty > 0 ? $qty : $fallback;
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
