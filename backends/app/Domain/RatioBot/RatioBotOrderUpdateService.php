<?php

namespace App\Domain\RatioBot;

use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;

class RatioBotOrderUpdateService
{
    public function __construct(
        private readonly RatioBotStateService $stateService,
    ) {
    }

    public function syncFromOrder(Order $order): void
    {
        $configId = (string) data_get($order->meta, 'ratio_bot_config_id', '');
        if ($configId === '') {
            return;
        }

        $config = RatioBotConfig::query()->with('state')->find($configId);
        if (! $config) {
            return;
        }

        $decisionId = (string) data_get($order->meta, 'ratio_bot_decision_id', '');
        $decision = $decisionId !== '' ? RatioBotDecision::find($decisionId) : null;
        $signal = strtoupper((string) (data_get($order->meta, 'ratio_bot_signal') ?: $decision?->decision ?: ''));
        $status = strtoupper((string) $order->status);
        $executedQty = (float) ($order->filled_qty ?: $order->qty ?: $order->quantity ?: 0);
        $executedPrice = (float) ($order->avg_fill_price ?: $order->price ?: 0);

        $state = $this->stateService->getOrCreate($config);
        $state->update([
            'last_order_status' => $order->status,
            'last_fill_price' => $executedPrice > 0 ? $executedPrice : $state->last_fill_price,
            'last_runtime_error' => in_array($status, ['REJECTED', 'FAILED', 'CANCELLED'], true)
                ? (string) data_get($order->raw_json, 'error', data_get($order->raw_json, 'msg', $state->last_runtime_error))
                : $state->last_runtime_error,
        ]);

        if (! $decision) {
            return;
        }

        if (in_array($status, ['NEW', 'PARTIAL', 'PENDING_PROVIDER'], true)) {
            $decision->update([
                'execution_status' => $status === 'PARTIAL' ? RatioBotDecision::EXEC_PARTIAL : RatioBotDecision::EXEC_SUBMITTED,
                'order_id' => (string) ($order->exchange_order_id ?: $order->id),
                'client_order_id' => (string) ($order->client_order_id ?: $decision->client_order_id),
                'executed_qty' => $executedQty > 0 ? $executedQty : $decision->executed_qty,
                'executed_price' => $executedPrice > 0 ? $executedPrice : $decision->executed_price,
            ]);

            return;
        }

        if (in_array($status, ['REJECTED', 'FAILED', 'CANCELLED', 'UNCERTAIN'], true)) {
            $this->stateService->onOrderFailed($config, $decision, (string) data_get($order->raw_json, 'error', $status));

            return;
        }

        if (! in_array($status, ['FILLED', 'PARTIAL'], true)) {
            return;
        }

        if ($config->marketType() === 'spot') {
            if ($signal === RatioBotDecision::DECISION_BUY && $decision->execution_status !== RatioBotDecision::EXEC_FILLED) {
                $this->stateService->onBuyFilled(
                    $config,
                    $decision,
                    $executedPrice > 0 ? $executedPrice : (float) $decision->evaluated_price,
                    $executedQty,
                    (string) ($order->exchange_order_id ?: $order->id),
                    (string) ($order->client_order_id ?: $decision->client_order_id)
                );
            } elseif ($signal === RatioBotDecision::DECISION_SELL && $decision->execution_status !== RatioBotDecision::EXEC_FILLED) {
                $entryPrice = (float) ($state->last_buy_price ?? 0);
                $this->stateService->onSellFilled(
                    $config,
                    $decision,
                    $executedPrice > 0 ? $executedPrice : (float) $decision->evaluated_price,
                    $executedQty,
                    (string) ($order->exchange_order_id ?: $order->id),
                    (string) ($order->client_order_id ?: $decision->client_order_id)
                );
                if ($entryPrice > 0 && $executedQty > 0) {
                    $this->stateService->recordTradeOutcome($config, ($executedPrice - $entryPrice) * $executedQty);
                }
            }

            return;
        }

        $decision->update([
            'execution_status' => $status === 'FILLED' ? RatioBotDecision::EXEC_FILLED : RatioBotDecision::EXEC_PARTIAL,
            'order_id' => (string) ($order->exchange_order_id ?: $order->id),
            'client_order_id' => (string) ($order->client_order_id ?: $decision->client_order_id),
            'executed_qty' => $executedQty > 0 ? $executedQty : $decision->executed_qty,
            'executed_price' => $executedPrice > 0 ? $executedPrice : $decision->executed_price,
            'executed_at' => now(),
        ]);

        if (in_array($signal, [RatioBotDecision::DECISION_LONG, RatioBotDecision::DECISION_SHORT], true)) {
            $this->stateService->syncPositionSnapshot(
                $config,
                $signal === RatioBotDecision::DECISION_SHORT ? 'short' : 'long',
                $executedQty,
                $executedPrice > 0 ? $executedPrice : (float) $decision->evaluated_price,
                0
            );

            return;
        }

        $entryPrice = (float) ($state->entry_price ?? 0);
        $direction = strtolower((string) ($state->last_position_side ?? ''));
        $this->stateService->syncPositionSnapshot($config, 'flat', 0, null, 0);

        if ($entryPrice > 0 && $executedQty > 0) {
            $pnl = $direction === 'short'
                ? ($entryPrice - $executedPrice) * $executedQty
                : ($executedPrice - $entryPrice) * $executedQty;
            $this->stateService->recordTradeOutcome($config, $pnl);
        }
    }
}
