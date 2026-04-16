<?php

namespace App\Domain\Risk;

use App\Models\RiskEvent;
use Illuminate\Support\Str;

class AdvancedRiskGuard
{
    public function record(string $workspaceId, string $type, string $severity, array $reason): RiskEvent
    {
        return RiskEvent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => $type,
            'severity' => $severity,
            'reason_json' => $reason,
            'triggered_at' => now(),
        ]);
    }
}
