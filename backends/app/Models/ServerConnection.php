<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerConnection extends BaseModel
{
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const AUTH_METHOD_KEY = 'key';
    public const AUTH_METHOD_PASSWORD = 'password';

    protected $casts = [
        'last_checked_at' => 'datetime',
        'last_check_result' => 'array',
        'port' => 'integer',
    ];

    /**
     * Never serialize the encrypted credential -- same intent as
     * ExchangeConnection hiding its *_encrypted columns. Controllers also
     * build explicit response arrays that never reference this column, as a
     * second, independent layer of defense.
     */
    protected $hidden = [
        'credential_encrypted',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
