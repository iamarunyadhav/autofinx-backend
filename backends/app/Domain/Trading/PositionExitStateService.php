<?php

namespace App\Domain\Trading;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Position;
use App\Models\PositionExitState;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class PositionExitStateService
{
    public function __construct(
        private readonly WorkspaceExitPolicyService $policies,
        private readonly PositionExitEventService $events,
    ) {
    }

    public function backfillWorkspace(string $workspaceId): int
    {
        $count = 0;

        $positions = Position::query()
            ->with(['symbol', 'exitState'])
            ->where('workspace_id', $workspaceId)
            ->whereRaw('ABS(COALESCE(qty, quantity, 0)) > 0')
            ->get();

        /** @var Position $position */
        foreach ($positions as $position) {
            if (! $this->isEligibleSpotPosition($position) || $position->exitState) {
                continue;
            }

            $this->reviewRequired($position, 'pre_existing_position');
            $count++;
        }

        return $count;
    }

    public function statesForWorkspace(string $workspaceId): Collection
    {
        $this->backfillWorkspace($workspaceId);

        return PositionExitState::query()
            ->with(['position.symbol', 'activeExitOrder'])
            ->where('workspace_id', $workspaceId)
            ->orderByRaw("CASE WHEN managed_mode = 'review_required' THEN 0 ELSE 1 END")
            ->orderBy('symbol')
            ->get();
    }

    public function findForPosition(Position $position): ?PositionExitState
    {
        return PositionExitState::query()
            ->where('position_id', $position->id)
            ->first();
    }

    public function reviewRequired(Position $position, string $reason, ?Order $order = null): PositionExitState
    {
        $state = $this->findForPosition($position);
        if (! $state) {
            $state = PositionExitState::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $position->workspace_id,
                'position_id' => $position->id,
                'symbol' => strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')),
                'provider' => strtolower((string) ($position->provider ?: 'binance')),
                'market_type' => 'spot',
                'status' => PositionExitState::STATUS_OPEN,
                'managed_mode' => PositionExitState::MODE_REVIEW_REQUIRED,
                'exit_policy_snapshot' => $this->withExecutionContext(
                    $this->policies->snapshotForPosition($position),
                    $position,
                    $order
                ),
                'stop_loss_price' => null,
                'take_profit_price' => null,
                'time_stop_at' => null,
                'partial_progress' => $this->emptyPartialProgress(),
                'blocked_reason' => $reason,
            ]);

            $this->events->record($state, 'operator_review_required', [
                'message' => 'Exit automation requires operator review before arming.',
                'reason' => $reason,
            ], 'warning', 'exit_state_sync', null, $order);

            return $state->fresh();
        }

        $state->fill([
            'status' => PositionExitState::STATUS_OPEN,
            'managed_mode' => PositionExitState::MODE_REVIEW_REQUIRED,
            'exit_policy_snapshot' => $this->withExecutionContext(
                $this->policies->snapshotForPosition($position),
                $position,
                $order
            ),
            'blocked_reason' => $reason,
            'last_evaluated_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        return $state->fresh();
    }

    public function armPosition(Position $position, ?Order $order = null, ?string $actorId = null): PositionExitState
    {
        if (! $this->isEligibleSpotPosition($position)) {
            throw new RuntimeException('Only Binance spot positions can be managed by the exit engine.');
        }

        $snapshot = $this->withExecutionContext(
            $this->policies->snapshotForPosition($position),
            $position,
            $order
        );

        if (! $this->policies->hasActionableExit($snapshot)) {
            throw new RuntimeException('Workspace exit policy does not define any actionable live exit controls.');
        }

        $state = $this->findForPosition($position) ?: new PositionExitState([
            'id' => (string) Str::uuid(),
            'workspace_id' => $position->workspace_id,
            'position_id' => $position->id,
            'symbol' => strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')),
            'provider' => strtolower((string) ($position->provider ?: 'binance')),
            'market_type' => 'spot',
        ]);

        $highWater = max(
            (float) ($position->last_mark_price ?? 0),
            (float) ($position->avg_entry ?? $position->avg_price ?? 0)
        );

        $state->fill([
            'status' => PositionExitState::STATUS_OPEN,
            'managed_mode' => PositionExitState::MODE_ARMED,
            'symbol' => strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')),
            'provider' => strtolower((string) ($position->provider ?: 'binance')),
            'exit_policy_snapshot' => $snapshot,
            'stop_loss_price' => $this->policies->stopLossPrice($snapshot),
            'take_profit_price' => $this->policies->takeProfitPrice($snapshot),
            'time_stop_at' => $this->policies->timeStopAt($snapshot),
            'trailing_high_water_mark' => $highWater > 0 ? $highWater : null,
            'trailing_stop_price' => $highWater > 0 ? $this->policies->trailingStopPrice($snapshot, $highWater) : null,
            'partial_progress' => $this->emptyPartialProgress(),
            'active_exit_order_id' => null,
            'blocked_reason' => null,
            'uncertainty_reason' => null,
            'last_evaluated_at' => now(),
            'last_action_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'position_armed', [
            'message' => 'Managed live exit policy armed for position.',
            'actor_id' => $actorId,
            'stop_loss_price' => $state->stop_loss_price,
            'take_profit_price' => $state->take_profit_price,
            'time_stop_at' => $state->time_stop_at?->toIso8601String(),
            'trailing_stop_price' => $state->trailing_stop_price,
        ], 'info', $actorId ? 'operator' : 'reconciliation', null, $order);

        return $state->fresh();
    }

    public function pause(PositionExitState $state, string $reason, ?string $actorId = null): PositionExitState
    {
        $state->fill([
            'managed_mode' => PositionExitState::MODE_PAUSED,
            'blocked_reason' => $reason,
            'last_action_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'exit_automation_paused', [
            'message' => 'Managed exit automation paused for position.',
            'reason' => $reason,
            'actor_id' => $actorId,
        ], 'warning', 'operator');

        return $state->fresh();
    }

    public function resume(PositionExitState $state, ?string $actorId = null): PositionExitState
    {
        if ($state->status === PositionExitState::STATUS_CLOSED) {
            throw new RuntimeException('Closed positions cannot be resumed.');
        }

        $state->fill([
            'managed_mode' => PositionExitState::MODE_ARMED,
            'blocked_reason' => null,
            'last_action_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'exit_automation_resumed', [
            'message' => 'Managed exit automation resumed for position.',
            'actor_id' => $actorId,
        ], 'info', 'operator');

        return $state->fresh();
    }

    public function markBlocked(PositionExitState $state, string $reason, array $payload = [], ?string $correlationId = null): PositionExitState
    {
        if ($state->managed_mode === PositionExitState::MODE_BLOCKED && $state->blocked_reason === $reason) {
            $state->last_evaluated_at = now();
            $state->save();

            return $state->fresh();
        }

        $state->fill([
            'managed_mode' => PositionExitState::MODE_BLOCKED,
            'blocked_reason' => $reason,
            'last_evaluated_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'exit_blocked', [
            'message' => 'Exit automation blocked by a safety condition.',
            'reason' => $reason,
        ] + $payload, 'warning', 'monitoring', $correlationId);

        return $state->fresh();
    }

    public function clearBlock(PositionExitState $state, ?string $correlationId = null): PositionExitState
    {
        if ($state->managed_mode !== PositionExitState::MODE_BLOCKED) {
            $state->last_evaluated_at = now();
            $state->save();

            return $state->fresh();
        }

        $state->fill([
            'managed_mode' => PositionExitState::MODE_ARMED,
            'blocked_reason' => null,
            'last_evaluated_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'exit_block_cleared', [
            'message' => 'Exit automation block cleared and monitoring resumed.',
        ], 'info', 'monitoring', $correlationId);

        return $state->fresh();
    }

    public function applyTrailingUpdate(PositionExitState $state, float $highWaterMark, ?float $trailingStopPrice, ?string $correlationId = null): PositionExitState
    {
        $currentHighWater = (float) ($state->trailing_high_water_mark ?? 0);
        if ($highWaterMark <= $currentHighWater + 1e-8) {
            return $state->fresh();
        }

        $state->fill([
            'trailing_high_water_mark' => $highWaterMark,
            'trailing_stop_price' => $trailingStopPrice,
            'last_evaluated_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'trailing_stop_updated', [
            'message' => 'Trailing stop reference tightened.',
            'trailing_high_water_mark' => $highWaterMark,
            'trailing_stop_price' => $trailingStopPrice,
        ], 'info', 'monitoring', $correlationId);

        return $state->fresh();
    }

    public function markExitDispatched(PositionExitState $state, string $decisionHash, Order $order, array $payload = []): PositionExitState
    {
        $state->fill([
            'status' => PositionExitState::STATUS_CLOSING,
            'managed_mode' => PositionExitState::MODE_CLOSING,
            'active_exit_order_id' => $order->id,
            'last_exit_decision_hash' => $decisionHash,
            'last_action_at' => now(),
            'last_evaluated_at' => now(),
            'blocked_reason' => null,
            'uncertainty_reason' => null,
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'exit_submitted', [
            'message' => 'Managed exit order submitted through the canonical live execution path.',
            'decision_hash' => $decisionHash,
        ] + $payload, 'warning', 'exit_engine', $order->trace_id, $order);

        return $state->fresh();
    }

    public function markUncertain(PositionExitState $state, string $reason, ?Order $order = null, ?string $correlationId = null): PositionExitState
    {
        $state->fill([
            'status' => PositionExitState::STATUS_UNCERTAIN,
            'managed_mode' => PositionExitState::MODE_BLOCKED,
            'uncertainty_reason' => $reason,
            'blocked_reason' => $reason,
            'last_action_at' => now(),
            'last_evaluated_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'exit_submission_uncertain', [
            'message' => 'Exit submission outcome is uncertain and requires operator review.',
            'reason' => $reason,
        ], 'destructive', 'exit_engine', $correlationId, $order);

        return $state->fresh();
    }

    public function syncAfterOrderUpdate(Position $position, Order $order): ?PositionExitState
    {
        $state = $this->findForPosition($position);

        if (! $this->isEligibleSpotPosition($position) || $this->positionQty($position) <= 0) {
            if ($state) {
                return $this->markClosed($state, $order);
            }

            return null;
        }

        if (! $state) {
            if ($this->isExitEngineOrder($order)) {
                return $this->reviewRequired($position, 'missing_state_for_exit_order', $order);
            }

            return $this->hasActionablePolicy($position, $order)
                ? $this->armPosition($position, $order)
                : $this->reviewRequired($position, 'no_actionable_exit_policy', $order);
        }

        if ($this->isExitEngineOrder($order)) {
            return $this->applyManagedExitFill($state, $position, $order);
        }

        if (strtolower((string) $order->side) === 'buy') {
            return $this->hasActionablePolicy($position, $order)
                ? $this->armPosition($position, $order)
                : $this->reviewRequired($position, 'no_actionable_exit_policy', $order);
        }

        if (strtolower((string) $order->side) === 'sell') {
            if ($this->positionQty($position) <= 0) {
                return $this->markClosed($state, $order);
            }

            return $this->reviewRequired($position, 'position_changed_outside_exit_engine', $order);
        }

        return $state->fresh();
    }

    public function evaluateContextForState(PositionExitState $state): array
    {
        $snapshot = (array) ($state->exit_policy_snapshot ?? []);

        return (array) ($snapshot['execution_context'] ?? []);
    }

    public function recordManualAction(PositionExitState $state, string $action, ?string $actorId, array $metadata = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $state->workspace_id,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'metadata' => ['position_id' => $state->position_id, 'state_id' => $state->id] + $metadata,
        ]);
    }

    private function applyManagedExitFill(PositionExitState $state, Position $position, Order $order): PositionExitState
    {
        $progress = $this->normalizePartialProgress($state->partial_progress);
        $filledQty = max(0.0, (float) ($order->filled_qty ?? 0));
        $processedQty = max(0.0, (float) ($progress['processed_exit_order_fills'][$order->id] ?? 0));
        $delta = max(0.0, $filledQty - $processedQty);
        $targetLevel = data_get($order->meta, 'target_tp_level');

        if ($delta > 0) {
            $progress['processed_exit_order_fills'][$order->id] = $filledQty;
            $progress['cumulative_exit_qty'] = round(max(0.0, (float) $progress['cumulative_exit_qty'] + $delta), 8);

            if ($targetLevel !== null && ! in_array((string) $targetLevel, array_map('strval', (array) $progress['consumed_levels']), true)) {
                $progress['consumed_levels'][] = $targetLevel;
            }

            if (! empty($state->exit_policy_snapshot['break_even_after_partial'])
                && empty($progress['break_even_applied'])
                && (float) $progress['cumulative_exit_qty'] > 0
            ) {
                $entryPrice = (float) ($state->exit_policy_snapshot['entry_price'] ?? 0);
                if ($entryPrice > 0) {
                    $state->stop_loss_price = max((float) ($state->stop_loss_price ?? 0), $entryPrice);
                    $progress['break_even_applied'] = true;
                    $this->events->record($state, 'break_even_applied', [
                        'message' => 'Stop loss moved to break-even after partial profit-taking.',
                        'stop_loss_price' => $state->stop_loss_price,
                    ], 'info', 'reconciliation', $order->trace_id, $order);
                }
            }
        }

        $state->partial_progress = $progress;
        $state->last_action_at = now();

        if ($order->status === Order::STATUS_UNCERTAIN || strtoupper((string) $order->status) === Order::STATUS_UNCERTAIN) {
            return $this->markUncertain($state, 'exit_order_uncertain', $order, $order->trace_id);
        }

        if ($this->isTerminalOrder($order)) {
            $state->active_exit_order_id = null;
            if ($this->positionQty($position) <= 0) {
                return $this->markClosed($state, $order);
            }

            $state->status = PositionExitState::STATUS_OPEN;
            $state->managed_mode = PositionExitState::MODE_ARMED;
            $state->uncertainty_reason = null;
            $state->blocked_reason = null;
            $this->bumpVersion($state);
            $state->save();

            $eventType = $targetLevel !== null ? 'partial_exit_filled' : 'managed_exit_completed';
            $this->events->record($state, $eventType, [
                'message' => $targetLevel !== null ? 'Partial take-profit exit filled.' : 'Managed exit order finished.',
                'target_tp_level' => $targetLevel,
                'filled_qty' => $filledQty,
            ], 'info', 'reconciliation', $order->trace_id, $order);

            return $state->fresh();
        }

        $state->save();

        return $state->fresh();
    }

    private function markClosed(PositionExitState $state, ?Order $order = null): PositionExitState
    {
        $state->fill([
            'status' => PositionExitState::STATUS_CLOSED,
            'managed_mode' => PositionExitState::MODE_CLOSED,
            'active_exit_order_id' => null,
            'blocked_reason' => null,
            'uncertainty_reason' => null,
            'last_action_at' => now(),
            'last_evaluated_at' => now(),
        ]);
        $this->bumpVersion($state);
        $state->save();

        $this->events->record($state, 'position_closed', [
            'message' => 'Position is fully closed and no longer requires managed exits.',
        ], 'success', 'reconciliation', $order?->trace_id, $order);

        return $state->fresh();
    }

    private function withExecutionContext(array $snapshot, Position $position, ?Order $order = null): array
    {
        $snapshot['execution_context'] = $this->executionContext($position, $order);

        return $snapshot;
    }

    private function executionContext(Position $position, ?Order $order = null): array
    {
        $candidate = $order ?: Order::query()
            ->where('workspace_id', $position->workspace_id)
            ->where('asset_code', strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')))
            ->when($position->bot_id, fn ($query, $botId) => $query->where('bot_id', $botId))
            ->where(function ($query): void {
                $query->where('meta->mode', 'live')
                    ->orWhereNotNull('integration_id')
                    ->orWhereNotNull('bot_id');
            })
            ->latest('created_at')
            ->first();

        return [
            'integration_id' => $candidate?->integration_id,
            'bot_id' => $candidate?->bot_id ?: $position->bot_id,
            'connection_id' => data_get($candidate?->meta, 'connection_id'),
            'source_order_id' => $candidate?->id,
        ];
    }

    private function emptyPartialProgress(): array
    {
        return [
            'consumed_levels' => [],
            'cumulative_exit_qty' => 0.0,
            'break_even_applied' => false,
            'processed_exit_order_fills' => [],
        ];
    }

    private function normalizePartialProgress(?array $progress): array
    {
        return array_merge($this->emptyPartialProgress(), $progress ?? []);
    }

    private function positionQty(Position $position): float
    {
        return abs((float) ($position->qty ?? $position->quantity ?? 0));
    }

    private function isEligibleSpotPosition(Position $position): bool
    {
        return strtoupper((string) ($position->asset_class ?: 'CRYPTO')) === 'CRYPTO'
            && strtolower((string) ($position->provider ?: 'binance')) === 'binance'
            && strtolower((string) ($position->market_type ?: 'spot')) === 'spot'
            && $this->positionQty($position) > 0;
    }

    private function isExitEngineOrder(Order $order): bool
    {
        return strtolower((string) data_get($order->meta, 'origin', '')) === 'exit_engine';
    }

    private function isTerminalOrder(Order $order): bool
    {
        return in_array(strtoupper((string) $order->status), [
            strtoupper(Order::STATUS_FILLED),
            strtoupper(Order::STATUS_CANCELLED),
            strtoupper(Order::STATUS_REJECTED),
            strtoupper(Order::STATUS_FAILED),
        ], true);
    }

    private function bumpVersion(PositionExitState $state): void
    {
        $state->state_version = max(0, (int) ($state->state_version ?? 0)) + 1;
    }

    private function hasActionablePolicy(Position $position, ?Order $order = null): bool
    {
        $snapshot = $this->withExecutionContext(
            $this->policies->snapshotForPosition($position),
            $position,
            $order
        );

        return $this->policies->hasActionableExit($snapshot);
    }
}
