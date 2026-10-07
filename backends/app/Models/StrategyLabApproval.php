<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/**
 * Immutable owner decision on a completed run. APPROVE means permission to continue to the next research
 * stage (DEEP_TEST or EXPERIMENT) — never scientific proof and never production promotion.
 */
class StrategyLabApproval extends BaseModel
{
    public const DEEP_TEST = 'DEEP_TEST';

    public const EXPERIMENT = 'EXPERIMENT';

    protected $table = 'strategy_lab_approvals';

    protected $casts = ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class];

    public function freshTimestamp()
    {
        return \Illuminate\Support\Facades\Date::now('UTC');
    }
}
