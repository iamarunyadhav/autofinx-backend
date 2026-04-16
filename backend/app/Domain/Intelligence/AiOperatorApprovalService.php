<?php

namespace App\Domain\Intelligence;

use App\Models\AiDecision;
use App\Models\AiTradingConfig;
use RuntimeException;

/**
 * Operator Approval Service for pending AI decisions.
 *
 * Responsibilities:
 *  - Validate that a decision is in an approvable state
 *  - Approve → mark approved → optionally auto-execute if the policy permits
 *  - Reject → mark rejected with a mandatory reason
 *  - All state transitions are audited via AiDecisionAuditService
 *
 * This service is intentionally conservative:
 *  - It will NOT execute a REDUCE or REVIEW_REQUIRED decision automatically.
 *  - It will NOT approve expired decisions.
 *  - It will NOT approve decisions that belong to a different workspace.
 *  - Execution after approval respects the same LiveExecutionGuard / risk path.
 */
class AiOperatorApprovalService
{
    public function __construct(
        private readonly AiDecisionAuditService $audit,
        private readonly AiDecisionExecutionService $execution,
        private readonly AiContextBuilderService $contextBuilder,
        private readonly AiTradingStateService $state,
        private readonly AiNotificationSummaryService $notifications,
    ) {
    }

    /**
     * Approve a pending AI decision.
     *
     * If the decision is an executable trade (BUY/SELL) and the workspace config
     * permits restricted_auto execution, the trade will be placed after approval.
     *
     * REDUCE and REVIEW_REQUIRED decisions are recorded-as-approved for operator
     * audit purposes but are never auto-executed here — the operator must take
     * manual action through the trade/position management UI.
     *
     * @param  string  $workspaceId  The workspace the decision belongs to.
     * @param  string  $decisionId  The decision to approve.
     * @param  string  $actorId  The operator approving.
     * @param  bool  $executeNow  Whether to attempt execution after approval (only honoured for BUY/SELL).
     * @return array{ok: bool, decision: AiDecision, order: mixed, message: string}
     */
    public function approve(string $workspaceId, string $decisionId, string $actorId, bool $executeNow = true): array
    {
        $decision = $this->resolveApprovableDecision($workspaceId, $decisionId);

        $this->audit->markApproved($decision, $actorId);
        $decision = $decision->fresh();

        $action = strtoupper((string) ($decision->action ?? 'NO_TRADE'));

        // REDUCE and REVIEW_REQUIRED approvals are operator acknowledgements only —
        // they do not trigger automated order placement.
        if (in_array($action, ['REDUCE', 'REVIEW_REQUIRED', 'HOLD', 'NO_TRADE'], true)) {
            $this->notifications->forDecision(
                $decision,
                'AI decision approved (no auto-execution)',
                "Operator approved [{$action}] decision. Manual follow-through required.",
                'info',
                ['actor_id' => $actorId],
            );

            return [
                'ok' => true,
                'decision' => $decision,
                'order' => null,
                'executed' => false,
                'message' => "Decision [{$action}] approved. No automated order placed — operator action required.",
            ];
        }

        // For BUY/SELL, attempt execution if operator elected to do so.
        if (! $executeNow) {
            return [
                'ok' => true,
                'decision' => $decision,
                'order' => null,
                'executed' => false,
                'message' => 'Decision approved. Execution deferred by operator.',
            ];
        }

        $config = $this->configForWorkspace($workspaceId);
        $context = $this->contextBuilder->build($workspaceId, (string) $decision->symbol, [
            'exchange' => 'binance',
        ]);

        $capitalBase = (float) ($context['balances']['capital_base'] ?? $context['balances']['available_quote'] ?? 0);
        $latestPrice = (float) ($context['price']['latest'] ?? 0);
        $sizePct = (float) data_get($decision->validated_proposal_json, 'size_pct', 0);

        if ($capitalBase <= 0 || $latestPrice <= 0 || $sizePct <= 0) {
            return [
                'ok' => false,
                'decision' => $decision,
                'order' => null,
                'executed' => false,
                'message' => 'Approved but execution aborted: missing price or capital basis at approval time.',
            ];
        }

        $estimatedNotional = round(($capitalBase * $sizePct) / 100, 8);
        $estimatedQty = round($estimatedNotional / $latestPrice, 8);

        $policyResult = [
            'allowed' => true,
            'can_execute_now' => true,
            'requires_human_approval' => false,
            'reason_codes' => ['operator_approved'],
            'estimated_notional' => $estimatedNotional,
            'estimated_qty' => $estimatedQty,
        ];

        $result = $this->execution->execute($decision, $context, $policyResult, $config->toArray(), $actorId);

        if (! ($result['ok'] ?? false)) {
            $this->notifications->forDecision(
                $decision,
                'AI approved trade failed on execution',
                (string) ($result['message'] ?? 'execution_failed'),
                'destructive',
                ['actor_id' => $actorId],
            );
        }

        return array_merge($result, ['executed' => ($result['ok'] ?? false)]);
    }

    /**
     * Reject a pending AI decision with a mandatory operator-supplied reason.
     *
     * @param  string  $workspaceId
     * @param  string  $decisionId
     * @param  string  $actorId
     * @param  string  $reason  Must not be empty.
     * @return array{ok: bool, decision: AiDecision, message: string}
     */
    public function reject(string $workspaceId, string $decisionId, string $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('A rejection reason is required to reject an AI decision.');
        }

        $decision = $this->resolveApprovableDecision($workspaceId, $decisionId);

        $this->audit->markRejected($decision, $actorId, $reason);

        return [
            'ok' => true,
            'decision' => $decision->fresh(),
            'message' => 'AI decision rejected by operator.',
        ];
    }

    /**
     * List pending decisions for a workspace, optionally filtered by symbol.
     */
    public function pendingForWorkspace(string $workspaceId, ?string $symbol = null, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $query = AiDecision::query()
            ->where('workspace_id', $workspaceId)
            ->where('final_status', AiDecision::STATUS_PENDING_APPROVAL)
            ->orderByDesc('created_at')
            ->limit(max(1, min(200, $limit)));

        if ($symbol) {
            $query->where('symbol', strtoupper($symbol));
        }

        return $query->get();
    }

    /**
     * Expire all pending decisions for a workspace that have passed their expiry time.
     */
    public function expireStale(?string $workspaceId = null): int
    {
        return $this->audit->expirePending($workspaceId);
    }

    // -------------------------------------------------------------------------

    private function resolveApprovableDecision(string $workspaceId, string $decisionId): AiDecision
    {
        $decision = AiDecision::find($decisionId);

        if (! $decision) {
            throw new RuntimeException("AI decision [{$decisionId}] not found.");
        }

        if ((string) $decision->workspace_id !== $workspaceId) {
            throw new RuntimeException('AI decision does not belong to this workspace.');
        }

        if ($decision->final_status !== AiDecision::STATUS_PENDING_APPROVAL) {
            throw new RuntimeException(
                "AI decision [{$decisionId}] is not pending approval (current status: {$decision->final_status})."
            );
        }

        if ($decision->expires_at && $decision->expires_at->isPast()) {
            // Expire it now so it is clean for future audits.
            $this->audit->expirePending($workspaceId);
            throw new RuntimeException(
                "AI decision [{$decisionId}] has expired and can no longer be approved."
            );
        }

        return $decision;
    }

    private function configForWorkspace(string $workspaceId): AiTradingConfig
    {
        $config = AiTradingConfig::query()
            ->where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->first();

        if (! $config) {
            throw new RuntimeException("No enabled AI trading config for workspace [{$workspaceId}].");
        }

        return $config;
    }
}
