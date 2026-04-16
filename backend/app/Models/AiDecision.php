<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDecision extends BaseModel
{
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXECUTION_FAILED = 'execution_failed';

    protected $casts = [
        'confidence' => 'decimal:4',
        'context_summary_json' => 'array',
        'blockers_json' => 'array',
        'raw_proposal_json' => 'array',
        'validated_proposal_json' => 'array',
        'validation_result_json' => 'array',
        'policy_result_json' => 'array',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'executed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(AiDecisionEvent::class, 'ai_decision_id')->orderBy('created_at');
    }
}
