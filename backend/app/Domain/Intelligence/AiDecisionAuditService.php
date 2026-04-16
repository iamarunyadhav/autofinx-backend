<?php

namespace App\Domain\Intelligence;

use App\Models\AiDecision;
use App\Models\AiDecisionEvent;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Order;
use Illuminate\Support\Str;

class AiDecisionAuditService
{
    public function record(array $attributes, ?string $actorId = null): AiDecision
    {
        $decision = AiDecision::create(array_merge([
            'id' => (string) Str::uuid(),
        ], $attributes));

        $this->log($decision, 'ai_decision.recorded', $actorId, [
            'status' => $decision->final_status,
            'action' => $decision->action,
        ]);
        $this->event($decision, $this->recordEventType($decision), $actorId, [
            'status' => $decision->final_status,
            'action' => $decision->action,
        ]);
        $this->notifyRecorded($decision);

        return $decision;
    }

    public function markApproved(AiDecision $decision, string $actorId): AiDecision
    {
        $decision->forceFill([
            'final_status' => AiDecision::STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();

        $this->log($decision, 'ai_decision.approved', $actorId);
        $this->event($decision, 'approved', $actorId);

        return $decision->fresh();
    }

    public function markRejected(AiDecision $decision, string $actorId, string $reason): AiDecision
    {
        $decision->forceFill([
            'final_status' => AiDecision::STATUS_REJECTED,
            'rejected_by' => $actorId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->log($decision, 'ai_decision.rejected', $actorId, ['reason' => $reason]);
        $this->event($decision, 'rejected', $actorId, ['reason' => $reason]);
        $this->notify($decision, 'AI decision rejected', $reason, 'warning');

        return $decision->fresh();
    }

    public function markExecuted(AiDecision $decision, Order $order, ?string $actorId = null): AiDecision
    {
        $decision->forceFill([
            'final_status' => AiDecision::STATUS_EXECUTED,
            'executed_order_id' => $order->id,
            'executed_at' => now(),
        ])->save();

        $this->log($decision, 'ai_decision.executed', $actorId, [
            'order_id' => $order->id,
        ]);
        $this->event($decision, 'executed', $actorId, [
            'order_id' => $order->id,
        ]);
        $this->notify($decision, 'AI decision executed', 'Proposal executed through the guarded live spot path.', 'success');

        return $decision->fresh();
    }

    public function markExecutionFailed(AiDecision $decision, string $reason, ?string $actorId = null): AiDecision
    {
        $decision->forceFill([
            'final_status' => AiDecision::STATUS_EXECUTION_FAILED,
            'rejection_reason' => $reason,
        ])->save();

        $this->log($decision, 'ai_decision.execution_failed', $actorId, ['reason' => $reason]);
        $this->event($decision, 'execution_failed', $actorId, ['reason' => $reason]);
        $this->notify($decision, 'AI decision execution failed', $reason, 'destructive');

        return $decision->fresh();
    }

    public function expirePending(?string $workspaceId = null): int
    {
        $query = AiDecision::query()
            ->where('final_status', AiDecision::STATUS_PENDING_APPROVAL)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $stale = $query->get();

        foreach ($stale as $decision) {
            $decision->forceFill([
                'final_status' => AiDecision::STATUS_EXPIRED,
                'rejection_reason' => 'approval_expired',
            ])->save();
            $this->log($decision, 'ai_decision.expired', null, ['reason' => 'approval_expired']);
            $this->event($decision, 'expired', null, ['reason' => 'approval_expired']);
            $this->notify($decision, 'AI decision expired', 'Pending approval expired before operator review.', 'warning');
        }

        return $stale->count();
    }

    private function log(AiDecision $decision, string $action, ?string $actorId = null, array $metadata = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $decision->workspace_id,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => 'user',
            'target_id' => $decision->id,
            'target_type' => AiDecision::class,
            'metadata' => array_merge([
                'decision_status' => $decision->final_status,
                'symbol' => $decision->symbol,
                'action' => $decision->action,
            ], $metadata),
        ]);
    }

    private function event(AiDecision $decision, string $eventType, ?string $actorId = null, array $payload = []): void
    {
        AiDecisionEvent::create([
            'id' => (string) Str::uuid(),
            'ai_decision_id' => $decision->id,
            'workspace_id' => $decision->workspace_id,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'event_type' => $eventType,
            'payload_json' => array_merge([
                'decision_status' => $decision->final_status,
                'symbol' => $decision->symbol,
                'action' => $decision->action,
            ], $payload),
        ]);
    }

    private function recordEventType(AiDecision $decision): string
    {
        return match ($decision->final_status) {
            AiDecision::STATUS_PENDING_APPROVAL => 'pending_approval',
            AiDecision::STATUS_BLOCKED => 'blocked',
            default => 'recorded',
        };
    }

    private function notifyRecorded(AiDecision $decision): void
    {
        if ($decision->final_status === AiDecision::STATUS_PENDING_APPROVAL) {
            $this->notify($decision, 'AI decision pending approval', 'A supervised AI proposal is waiting for operator approval.', 'warning');
            return;
        }

        if ($decision->final_status === AiDecision::STATUS_BLOCKED) {
            $this->notify($decision, 'AI decision blocked', (string) ($decision->rejection_reason ?: 'Validation or policy blocked this proposal.'), 'destructive');
        }
    }

    private function notify(AiDecision $decision, string $title, string $body, string $severity): void
    {
        Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $decision->workspace_id,
            'type' => 'ai_decision',
            'content' => [
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
                'decision_id' => $decision->id,
                'symbol' => $decision->symbol,
                'action' => $decision->action,
                'status' => $decision->final_status,
            ],
        ]);
    }
}
