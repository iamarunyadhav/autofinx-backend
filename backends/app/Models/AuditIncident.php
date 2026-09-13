<?php

namespace App\Models;

class AuditIncident extends BaseModel
{
    protected $casts = [
        'first_detected_at' => 'datetime',
        'last_detected_at'  => 'datetime',
        'recovered_at'      => 'datetime',
        'self_recovered'    => 'boolean',
        'evidence'          => 'array',
    ];

    public const STATUS_DETECTED  = 'detected';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_RECOVERING = 'recovering';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_RECURRING = 'recurring';
    public const STATUS_PERSISTENT = 'persistent';
    public const STATUS_RESOLVED  = 'resolved';

    public const OPEN_STATUSES = [
        self::STATUS_DETECTED,
        self::STATUS_ACTIVE,
        self::STATUS_RECOVERING,
        self::STATUS_RECURRING,
        self::STATUS_PERSISTENT,
    ];

    public function incidentEvents()
    {
        return $this->hasMany(AuditIncidentEvent::class, 'audit_incident_id');
    }
}
