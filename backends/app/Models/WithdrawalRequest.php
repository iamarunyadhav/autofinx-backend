<?php

namespace App\Models;

class WithdrawalRequest extends BaseModel
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUBMITTED_TO_PROVIDER = 'submitted_to_provider';
    public const STATUS_PROVIDER_PENDING = 'provider_pending';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_REJECTED = 'rejected';

    protected $casts = [
        'reference' => 'array',
    ];
}
