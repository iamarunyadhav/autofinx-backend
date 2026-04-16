<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Intelligence\AiTradingStateService;
use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Risk\WorkspaceLiveRiskService;
use App\Models\AiTradingState;
use App\Models\Ledger;
use App\Models\Order;
use App\Models\Position;
use App\Models\PositionExitState;
use App\Models\Trade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ExitOrchestratorService
{
    public function __construct(
        private readonly ExitPolicyEngine $engine,
        private readonly PositionExitStateService $states,
        private readonly PositionExitEventService $events,
        private readonly LiveExecutionService $execution,
        private readonly \App\Domain\Market\MarketPriceCacheService $prices,
        private readonly OperationalBreakerService $breakers,
        private readonly BinanceSpotHealthService $health,
        private readonly AiTradingStateService $aiState,
        private readonly KillSwitchService $killSwitch,
        private readonly WorkspaceLiveRiskService $workspaceRisk,
    ) {
    }

    public function evaluate(PositionExitState $state, bool $allowExecution = true, ?string $actorId = null, bool $manualTrigger = false): array
    {
        $state = $state->fresh(['position.symbol', 'activeExitOrder']);
        $position = $state->position;
        if (! $position instanceof Position) {
            throw new RuntimeException('Managed position was not found for exit evaluation.');
        }

        $correlationId = $this->correlationId($state, $manualTrigger);
        $marketSnapshot = $this->marketSnapshot($state);
        $context = $this->evaluationContext($state, $marketSnapshot, $correlationId);
        $decision = $this->engine->evaluate($position, $state, $marketSnapshot, $context);

        Log::info('exit_engine.evaluated', [
            'workspace_id' => $state->workspace_id,
            'position_id' => $state->position_id,
            'symbol' => $state->symbol,
            'correlation_id' => $correlationId,
            'action' => $decision->action,
            'reason_code' => $decision->reasonCode,
            'allow_execution' => $allowExecution,
        ]);

        $state = $this->applyStateUpdates($state, $decision);

        if ($decision->action === \App\Domain\Trading\Data\ExitDecision::ACTION_BLOCKED) {
            $state = $this->states->markBlocked($state, $decision->reasonCode, [
                'market' => $marketSnapshot,
                'context' => $context,
            ], $correlationId);

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'blocked' => true,
                'correlation_id' => $correlationId,
            ];
        }

        if ($decision->action === \App\Domain\Trading\Data\ExitDecision::ACTION_OPERATOR_REVIEW) {
            $state = $this->states->reviewRequired($position, $decision->reasonCode);

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'requires_review' => true,
                'correlation_id' => $correlationId,
            ];
        }

        if ($state->managed_mode === PositionExitState::MODE_BLOCKED) {
            $state = $this->states->clearBlock($state, $correlationId);
        } else {
            $state->last_evaluated_at = now();
            $state->save();
        }

        if ($decision->action === \App\Domain\Trading\Data\ExitDecision::ACTION_NO_ACTION || ! $allowExecution) {
            if (! $allowExecution && $decision->action !== \App\Domain\Trading\Data\ExitDecision::ACTION_NO_ACTION) {
                $this->events->record($state, 'exit_manual_evaluation', [
                    'message' => 'Exit evaluation completed without dispatch.',
                    'action' => $decision->action,
                    'reason_code' => $decision->reasonCode,
                ], 'info', 'operator', $correlationId);
            }

            return [
                'state' => $state->fresh(),
                'decision' => $decision,
                'executed' => false,
                'correlation_id' => $correlationId,
            ];
        }

        return $this->dispatchExitDecision($state, $position, $decision, $marketSnapshot, $correlationId, $actorId);
    }

    public function flatten(PositionExitState $state, ?string $actorId = null): array
    {
        $state = $state->fresh(['position.symbol']);
        $position = $state->position;
        if (! $position instanceof Position) {
            throw new RuntimeException('Managed position was not found for flatten.');
        }

        $marketSnapshot = $this->marketSnapshot($state);
        $correlationId = 'manual-flatten:' . $state->position_id . ':' . (string) Str::uuid();
        $quantity = abs((float) ($position->qty ?? $position->quantity ?? 0));

        $decision = new \App\Domain\Trading\Data\ExitDecision(
            action: \App\Domain\Trading\Data\ExitDecision::ACTION_FULL_EXIT,
            reasonCode: 'manual_flatten',
            quantityMode: 'full',
            quantity: $quantity,
            targetLevel: null,
            requiresReview: false,
            requiresCancelReplace: false,
            policySnapshot: (array) ($state->exit_policy_snapshot ?? []),
            marketSnapshot: $marketSnapshot,
            correlationId: $correlationId,
            stateUpdates: [],
            meta: ['force_exit' => true, 'manual_flatten' => true],
        );

        return $this->dispatchExitDecision($state, $position, $decision, $marketSnapshot, $correlationId, $actorId);
    }

    private function dispatchExitDecision(
        PositionExitState $state,
        Position $position,
        \App\Domain\Trading\Data\ExitDecision $decision,
        array $marketSnapshot,
        string $correlationId,
        ?string $actorId
    ): array {
        $executionContext = $this->states->evaluateContextForState($state);
        if (! $executionContext || (! ($executionContext['integration_id'] ?? null) && ! ($executionContext['bot_id'] ?? null) && ! ($executionContext['connection_id'] ?? null))) {
            $state = $this->states->reviewRequired($position, 'missing_execution_context');

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'requires_review' => true,
                'correlation_id' => $correlationId,
            ];
        }

        $decisionHash = $this->decisionHash($state, $decision);
        $this->events->record($state, $this->triggerEventType($decision), [
            'message' => $this->triggerMessage($decision),
            'reason_code' => $decision->reasonCode,
            'quantity' => $decision->quantity,
            'target_tp_level' => $decision->targetLevel,
        ], 'warning', 'exit_engine', $correlationId);

        $referencePrice = max(
            0.0,
            (float) ($marketSnapshot['price'] ?? 0),
            (float) ($position->last_mark_price ?? 0),
            (float) data_get($state->exit_policy_snapshot, 'entry_price', 0)
        );
        if ($referencePrice <= 0) {
            $state = $this->states->markBlocked($state, 'missing_reference_price', [], $correlationId);

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'blocked' => true,
                'correlation_id' => $correlationId,
            ];
        }

        $qty = $this->normalizedExitQty($position, $decision);
        if ($qty <= 0) {
            $state = $this->states->markBlocked($state, 'invalid_exit_qty', [], $correlationId);

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'blocked' => true,
                'correlation_id' => $correlationId,
            ];
        }

        try {
            $order = $this->execution->placeOrder([
                'workspace_id' => (string) $state->workspace_id,
                'integration_id' => $executionContext['integration_id'] ?? null,
                'bot_id' => $executionContext['bot_id'] ?? null,
                'connection_id' => $executionContext['connection_id'] ?? null,
                'symbol' => $state->symbol,
                'side' => 'sell',
                'type' => 'market',
                'qty' => $qty,
                'price' => $referencePrice,
                'idempotency_key' => $this->idempotencyKey($state, $decision),
                'meta' => [
                    'origin' => 'exit_engine',
                    'position_id' => $state->position_id,
                    'exit_reason' => $decision->reasonCode,
                    'exit_decision_hash' => $decisionHash,
                    'target_tp_level' => $decision->targetLevel,
                    'force_exit' => (bool) ($decision->meta['force_exit'] ?? false),
                    'manual_actor_id' => $actorId,
                    'exit_policy_snapshot' => $state->exit_policy_snapshot,
                    'source' => 'exit_engine',
                ],
            ]);
        } catch (UncertainLiveOrderException $e) {
            $state = $this->states->markUncertain($state, $e->getMessage(), $e->order, $correlationId);

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'uncertain' => true,
                'order' => $e->order,
                'correlation_id' => $correlationId,
            ];
        } catch (\Throwable $e) {
            $state = $this->states->markBlocked($state, $e->getMessage(), [
                'reason_code' => $decision->reasonCode,
            ], $correlationId);

            return [
                'state' => $state,
                'decision' => $decision,
                'executed' => false,
                'blocked' => true,
                'message' => $e->getMessage(),
                'correlation_id' => $correlationId,
            ];
        }

        $freshState = $state->fresh();
        if ($freshState && $freshState->status !== PositionExitState::STATUS_CLOSED && $freshState->active_exit_order_id !== $order->id) {
            $freshState = $this->states->markExitDispatched($freshState, $decisionHash, $order, [
                'reason_code' => $decision->reasonCode,
                'target_tp_level' => $decision->targetLevel,
                'quantity' => $qty,
            ]);
        }

        return [
            'state' => $freshState?->fresh() ?? $state->fresh(),
            'decision' => $decision,
            'executed' => true,
            'order' => $order,
            'correlation_id' => $correlationId,
        ];
    }

    private function applyStateUpdates(PositionExitState $state, \App\Domain\Trading\Data\ExitDecision $decision): PositionExitState
    {
        $highWater = $decision->stateUpdates['trailing_high_water_mark'] ?? null;
        if ($highWater !== null) {
            return $this->states->applyTrailingUpdate(
                $state,
                (float) $highWater,
                isset($decision->stateUpdates['trailing_stop_price']) ? (float) $decision->stateUpdates['trailing_stop_price'] : null,
                $decision->correlationId
            );
        }

        return $state;
    }

    private function marketSnapshot(PositionExitState $state): array
    {
        $snapshot = $this->prices->getLatestPrice('binance', $state->symbol);
        $timestampMs = (int) ($snapshot['timestamp_ms'] ?? 0);
        $staleThresholdSec = max(5, (int) env('LIVE_EXIT_PRICE_STALE_THRESHOLD_SEC', env('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC', 60)));
        $fresh = $timestampMs > 0 && (now()->valueOf() - $timestampMs) <= ($staleThresholdSec * 1000);

        return [
            'symbol' => $state->symbol,
            'price' => (float) ($snapshot['price'] ?? 0),
            'timestamp_ms' => $timestampMs ?: null,
            'source' => $snapshot['source'] ?? null,
            'fresh' => $fresh,
        ];
    }

    private function evaluationContext(PositionExitState $state, array $marketSnapshot, string $correlationId): array
    {
        $workspaceId = (string) $state->workspace_id;
        $symbol = (string) $state->symbol;
        $providerBreaker = $this->breakers->providerState($workspaceId, 'binance');
        $staleBreaker = $this->breakers->staleFeedState($workspaceId);
        $health = $this->health->snapshot($workspaceId);
        $aiState = $this->aiState->snapshot($workspaceId, $symbol);
        $riskRules = $this->workspaceRisk->resolveRules($workspaceId);

        $equity = max(0.0, (float) Ledger::query()->where('workspace_id', $workspaceId)->value('balance'));
        $realizedToday = (float) Trade::query()
            ->where('workspace_id', $workspaceId)
            ->whereDate('executed_at', now()->toDateString())
            ->sum('realized_pnl');
        $dailyLossLimitPct = max(0.0, (float) ($riskRules['daily_loss_pct'] ?? 0));
        $dailyLossBreached = $equity > 0
            && $dailyLossLimitPct > 0
            && $realizedToday < 0
            && ((abs($realizedToday) / $equity) * 100) >= $dailyLossLimitPct;

        $pauseActive = in_array((string) ($aiState['status'] ?? ''), [
            AiTradingState::STATUS_PAUSED,
            AiTradingState::STATUS_STOPPED,
        ], true);

        return [
            'correlation_id' => $correlationId,
            'market' => $marketSnapshot,
            'ops' => [
                'kill_switch_active' => $this->killSwitch->isActive($workspaceId),
                'provider_breaker' => $providerBreaker,
                'stale_feed_breaker' => $staleBreaker,
                'exchange_healthy' => ! ($providerBreaker['active'] ?? false) && ! ($staleBreaker['active'] ?? false) && ! $this->killSwitch->isActive($workspaceId),
                'breaker_severe' => (bool) (($providerBreaker['active'] ?? false) || ($staleBreaker['active'] ?? false)),
                'rest_health' => $health,
            ],
            'state' => [
                'pause_active' => $pauseActive,
                'ai_snapshot' => $aiState,
            ],
            'loss' => [
                'equity' => $equity,
                'realized_today' => $realizedToday,
                'daily_loss_limit_pct' => $dailyLossLimitPct,
                'daily_loss_breached' => $dailyLossBreached,
            ],
        ];
    }

    private function normalizedExitQty(Position $position, \App\Domain\Trading\Data\ExitDecision $decision): float
    {
        $availableQty = abs((float) ($position->qty ?? $position->quantity ?? 0));
        if ($decision->quantityMode === 'full' || $decision->quantity === null) {
            return $availableQty;
        }

        return min($availableQty, max(0.0, (float) $decision->quantity));
    }

    private function decisionHash(PositionExitState $state, \App\Domain\Trading\Data\ExitDecision $decision): string
    {
        return sha1(json_encode([
            'position_id' => $state->position_id,
            'state_version' => (int) $state->state_version,
            'action' => $decision->action,
            'reason_code' => $decision->reasonCode,
            'quantity_mode' => $decision->quantityMode,
            'quantity' => $decision->quantity,
            'target_level' => $decision->targetLevel,
        ], JSON_THROW_ON_ERROR));
    }

    private function idempotencyKey(PositionExitState $state, \App\Domain\Trading\Data\ExitDecision $decision): string
    {
        return sprintf(
            'exit:%s:%s:%s:%d',
            $state->position_id,
            $decision->reasonCode,
            $decision->targetLevel ?: 'full',
            (int) ($state->state_version ?? 0)
        );
    }

    private function correlationId(PositionExitState $state, bool $manualTrigger): string
    {
        $prefix = $manualTrigger ? 'manual-exit' : 'scheduled-exit';

        return $prefix . ':' . $state->position_id . ':' . (string) Str::uuid();
    }

    private function triggerEventType(\App\Domain\Trading\Data\ExitDecision $decision): string
    {
        return match ($decision->reasonCode) {
            'stop_loss_triggered' => 'stop_loss_triggered',
            'trailing_stop_triggered' => 'trailing_stop_triggered',
            'partial_take_profit_triggered' => 'take_profit_level_reached',
            'take_profit_triggered' => 'take_profit_triggered',
            'time_stop_reached', 'time_stop_reached_without_fresh_price' => 'time_stop_reached',
            default => 'force_exit_triggered',
        };
    }

    private function triggerMessage(\App\Domain\Trading\Data\ExitDecision $decision): string
    {
        return match ($decision->reasonCode) {
            'stop_loss_triggered' => 'Managed stop loss triggered for the live position.',
            'trailing_stop_triggered' => 'Managed trailing stop triggered after a favorable move reversed.',
            'partial_take_profit_triggered' => 'A partial take-profit level was reached and queued for execution.',
            'take_profit_triggered' => 'Managed take-profit exit triggered.',
            'time_stop_reached', 'time_stop_reached_without_fresh_price' => 'Managed time-stop exit triggered.',
            'manual_flatten' => 'Operator requested a manual protective flatten.',
            default => 'A force-protective exit was triggered by the exit engine.',
        };
    }
}
