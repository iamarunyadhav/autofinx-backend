<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends BaseModel
{
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(StrategyInstance::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function riskProfiles(): HasMany
    {
        return $this->hasMany(RiskProfile::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function portfolioIntelligenceConfig(): HasOne
    {
        return $this->hasOne(PortfolioIntelligenceConfig::class);
    }

    public function portfolioIntelligenceRuns(): HasMany
    {
        return $this->hasMany(PortfolioIntelligenceRun::class);
    }

    public function operationalReadinessSnapshots(): HasMany
    {
        return $this->hasMany(OperationalReadinessSnapshot::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
