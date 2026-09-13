<?php

namespace App\Models;

class AuditIncidentEvent extends BaseModel
{
    public function incident()
    {
        return $this->belongsTo(AuditIncident::class, 'audit_incident_id');
    }

    public function event()
    {
        return $this->belongsTo(AuditEvent::class, 'audit_event_id');
    }
}
