<?php

namespace App\Models;

class AuditEvent extends BaseModel
{
    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'market_price' => 'decimal:8',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public const CATEGORY_MARKET    = 'market';
    public const CATEGORY_ANALYSIS  = 'analysis';
    public const CATEGORY_PREDICTION = 'prediction';
    public const CATEGORY_DECISION  = 'decision';
    public const CATEGORY_RISK      = 'risk';
    public const CATEGORY_EXECUTION = 'execution';
    public const CATEGORY_HEALTH    = 'health';
    public const CATEGORY_INCIDENT  = 'incident';
    public const CATEGORY_SYSTEM    = 'system';

    public const SEVERITY_INFO     = 'info';
    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_MEDIUM   = 'medium';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public function session()
    {
        return $this->belongsTo(AuditSession::class, 'audit_session_id');
    }

    public function snapshot()
    {
        return $this->hasOne(AuditDecisionSnapshot::class, 'audit_event_id');
    }
}
