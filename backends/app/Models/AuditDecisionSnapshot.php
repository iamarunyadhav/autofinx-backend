<?php

namespace App\Models;

class AuditDecisionSnapshot extends BaseModel
{
    protected $casts = [
        'passed_rules' => 'array',
        'failed_rules' => 'array',
    ];

    public function event()
    {
        return $this->belongsTo(AuditEvent::class, 'audit_event_id');
    }

    public function decision()
    {
        return $this->belongsTo(RatioBotDecision::class, 'ratio_bot_decision_id');
    }
}
