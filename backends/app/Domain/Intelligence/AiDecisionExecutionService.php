<?php

namespace App\Domain\Intelligence;

use App\Domain\Trading\LiveExecutionService;
use App\Domain\Trading\UncertainLiveOrderException;
use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\Order;
use RuntimeException;

class AiDecisionExecutionService
{
    public function __construct(
        private readonly LiveExecutionService $execution,
        private readonly AiDecisionAuditService $audit,
        private readonly AiTradingStateService $state,
    ) {
    }

    public function execute(AiDecision $decision, array $context, array $policyResult, array $config = [], ?string $actorId = null): array
    {
        $proposal = (array) ($decision->validated_proposal_json ?? []);
        $action = strtoupper((string) ($proposal['action'] ?? ''));

        // Only BUY and SELL go through automated order placement.
        // REDUCE requires operator confirmation through the position management UI.
        // All other non-trade actions are advisory and never auto-execute.
        if (! in_array($action, ['BUY', 'SELL'], true)) {
            return [
                'ok' => true,
                'decision' => $decision,
                'order' => null,
                'message' => 'AI decision does not require automated order placement (action: ' . $action . ').',
            ];
        }

        $stateSnapshot = $this->state->snapshot((string) $decision->workspace_id, $decision->symbol, $config, $context);
        if (! ($stateSnapshot['allowed'] ?? false)) {
            $reason = implode(',', (array) ($stateSnapshot['reason_codes'] ?? [])) ?: 'ai_trading_state_blocked';
            $decision = $this->audit->markExecutionFailed($decision, $reason, $actorId);
            $this->updateCycle($decision, AiDecisionCycle::STATUS_PAUSED, [
                'state_snapshot' => $stateSnapshot,
                'message' => $reason,
            ]);

            return [
                'ok' => false,
                'decision' => $decision,
                'order' => null,
                'message' => $reason,
            ];
        }

        $latestPrice = (float) data_get($context, 'price.latest', 0);
        $qty = (float) ($policyResult['estimated_qty'] ?? 0);
        if ($latestPrice <= 0 || $qty <= 0) {
            $decision = $this->audit->markExecutionFailed($decision, 'invalid_execution_basis', $actorId);
            $this->state->recordOrderFailure((string) $decision->workspace_id, $decision->symbol, $config, 'invalid_execution_basis', $actorId);
            $this->updateCycle($decision, AiDecisionCycle::STATUS_FAILED, [
                'message' => 'invalid_execution_basis',
            ]);

            return [
                'ok' => false,
                'decision' => $decision,
                'order' => null,
                'message' => 'invalid_execution_basis',
            ];
        }

        try {
            $order = $this->execution->placeOrder([
                'workspace_id' => (string) $decision->workspace_id,
                'integration_id' => (string) ($decision->integration_id ?: data_get($context, 'integration.id')),
                'symbol' => $decision->symbol,
                'side' => strtolower((string) $decision->action),
                'type' => 'market',
                'qty' => $qty,
                'price' => $latestPrice,
                'idempotency_key' => 'ai-decision-' . $decision->id,
                'meta' => [
                    'source' => 'ai_trading_middleware',
                    'ai_decision_id' => $decision->id,
                    'ai_provider' => $decision->provider_name,
                    'ai_model' => $decision->provider_model,
                ],
            ]);
        } catch (UncertainLiveOrderException $e) {
            $decision = $this->audit->markExecutionFailed($decision, $e->getMessage(), $actorId);
            $this->state->recordOrderFailure((string) $decision->workspace_id, $decision->symbol, $config, $e->getMessage(), $actorId);
            $this->updateCycle($decision, AiDecisionCycle::STATUS_FAILED, [
                'message' => $e->getMessage(),
                'order_id' => $e->order->id,
                'uncertain' => true,
            ]);

            return [
                'ok' => false,
                'decision' => $decision,
                'order' => $e->order,
                'message' => $e->getMessage(),
            ];
        } catch (RuntimeException $e) {
            $decision = $this->audit->markExecutionFailed($decision, $e->getMessage(), $actorId);
            $this->state->recordOrderFailure((string) $decision->workspace_id, $decision->symbol, $config, $e->getMessage(), $actorId);
            $this->updateCycle($decision, AiDecisionCycle::STATUS_FAILED, [
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'decision' => $decision,
                'order' => null,
                'message' => $e->getMessage(),
            ];
        }

        $decision = $this->audit->markExecuted($decision, $order, $actorId);
        $this->state->recordTradeExecuted((string) $decision->workspace_id, $decision->symbol, $config, [
            'last_order_id' => $order->id,
        ]);
        $this->recordLossIfNeeded($decision, $order, $config);
        $this->updateCycle($decision, AiDecisionCycle::STATUS_EXECUTED, [
            'order_id' => $order->id,
        ]);

        return [
            'ok' => true,
            'decision' => $decision,
            'order' => $order,
            'message' => 'AI decision executed through the canonical live spot order path.',
        ];
    }

    private function updateCycle(AiDecision $decision, string $status, array $executionResult = []): void
    {
        $cycle = AiDecisionCycle::query()
            ->where('ai_decision_id', $decision->id)
            ->latest('started_at')
            ->first();

        if (! $cycle) {
            return;
        }

        $cycle->fill([
            'status' => $status,
            'execution_result_json' => $executionResult,
            'finished_at' => $cycle->finished_at ?: now(),
            'duration_ms' => $cycle->started_at ? $cycle->started_at->diffInMilliseconds(now()) : null,
            'message' => (string) ($executionResult['message'] ?? $cycle->message),
        ])->save();
    }

    private function recordLossIfNeeded(AiDecision $decision, Order $order, array $config): void
    {
        $meta = (array) ($order->meta ?? []);
        $estimatedPnl = (float) ($meta['realized_pnl'] ?? 0);
        if ($estimatedPnl < 0) {
            $this->state->recordLoss((string) $decision->workspace_id, $decision->symbol, $config, 'negative_realized_pnl');
        }
    }
}
