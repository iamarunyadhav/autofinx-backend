<?php

namespace App\Models;

class AuditSession extends BaseModel
{
    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_STOPPED   = 'stopped';

    public const MODE_STANDARD        = 'standard';
    public const MODE_DETAILED        = 'detailed';
    public const MODE_DEEP_DIAGNOSTIC = 'deep_diagnostic';

    public function events()
    {
        return $this->hasMany(AuditEvent::class, 'audit_session_id');
    }
}
