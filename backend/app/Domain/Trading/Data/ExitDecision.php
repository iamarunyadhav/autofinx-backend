<?php

namespace App\Domain\Trading\Data;

class ExitDecision
{
    public const ACTION_NO_ACTION = 'no_action';
    public const ACTION_PARTIAL_EXIT = 'partial_exit';
    public const ACTION_FULL_EXIT = 'full_exit';
    public const ACTION_OPERATOR_REVIEW = 'operator_review';
    public const ACTION_BLOCKED = 'blocked';

    public function __construct(
        public readonly string $action,
        public readonly string $reasonCode,
        public readonly ?string $quantityMode = null,
        public readonly ?float $quantity = null,
        public readonly string|int|null $targetLevel = null,
        public readonly bool $requiresReview = false,
        public readonly bool $requiresCancelReplace = false,
        public readonly array $policySnapshot = [],
        public readonly array $marketSnapshot = [],
        public readonly string $correlationId = '',
        public readonly array $stateUpdates = [],
        public readonly array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason_code' => $this->reasonCode,
            'quantity_mode' => $this->quantityMode,
            'quantity' => $this->quantity,
            'target_level' => $this->targetLevel,
            'requires_review' => $this->requiresReview,
            'requires_cancel_replace' => $this->requiresCancelReplace,
            'policy_snapshot' => $this->policySnapshot,
            'market_snapshot' => $this->marketSnapshot,
            'correlation_id' => $this->correlationId,
            'state_updates' => $this->stateUpdates,
            'meta' => $this->meta,
        ];
    }
}
