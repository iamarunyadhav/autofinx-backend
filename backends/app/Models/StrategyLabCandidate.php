<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Strategy Lab research candidate (SPEC §105). Research only: no relation to any order path.
 * Lifecycle (owner approval between major stages; LIVE / AUTOPILOT do not exist here):
 * DRAFT -> AUDITED -> QUICK_TESTED -> OWNER_APPROVED_DEEP_TEST -> DEEP_TESTED -> OWNER_APPROVED_EXPERIMENT,
 * plus REJECTED / ARCHIVED. SHADOW_ELIGIBLE / PAPER_ELIGIBLE are reserved and unreachable through this API.
 */
class StrategyLabCandidate extends BaseModel
{
    public const DRAFT = 'DRAFT';

    public const AUDITED = 'AUDITED';

    public const QUICK_TESTED = 'QUICK_TESTED';

    public const OWNER_APPROVED_DEEP_TEST = 'OWNER_APPROVED_DEEP_TEST';

    public const DEEP_TESTED = 'DEEP_TESTED';

    public const OWNER_APPROVED_EXPERIMENT = 'OWNER_APPROVED_EXPERIMENT';

    public const REJECTED = 'REJECTED';

    public const ARCHIVED = 'ARCHIVED';

    /** Progress order; a run result never moves a candidate backwards. */
    public const ORDER = [self::DRAFT, self::AUDITED, self::QUICK_TESTED, self::OWNER_APPROVED_DEEP_TEST, self::DEEP_TESTED, self::OWNER_APPROVED_EXPERIMENT];

    public const SOURCE_TYPES = ['PINE', 'RULE_JSON', 'MANUAL_COMPONENT'];

    public const PURPOSES = ['FULL_STRATEGY', 'DIRECTION', 'TRADEABILITY_FILTER', 'NOISE_FILTER', 'ENTRY_CONFIRMATION', 'EXIT', 'RISK', 'FEATURE_IDEA', 'UNKNOWN'];

    protected $table = 'strategy_lab_candidates';

    protected $casts = ['created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class];

    public function freshTimestamp()
    {
        return \Illuminate\Support\Facades\Date::now('UTC');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StrategyLabVersion::class, 'candidate_id')->orderBy('version');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::REJECTED, self::ARCHIVED], true);
    }

    public function advanceTo(string $status): void
    {
        if ($this->isClosed()) {
            return;
        }
        if (array_search($status, self::ORDER, true) > array_search($this->status, self::ORDER, true)) {
            $this->status = $status;
            $this->save();
        }
    }
}
