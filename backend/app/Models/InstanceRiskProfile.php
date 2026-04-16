<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceRiskProfile extends BaseModel
{
    public function instance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class, 'instance_id');
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class, 'risk_profile_id');
    }
}
