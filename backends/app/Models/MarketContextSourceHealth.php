<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/**
 * Market Context table `mctx_source_health` (global public data, not workspace-scoped).
 * Instants are UTC wall time; bind UTC Carbons (->utc()) in where() clauses.
 */
class MarketContextSourceHealth extends BaseModel
{
    protected $table = 'mctx_source_health';

    protected $casts = [
        'last_attempt_at' => UtcDateTime::class,
        'last_success_at' => UtcDateTime::class,
        'next_due_at' => UtcDateTime::class,
        'disabled_until' => UtcDateTime::class,
        'consecutive_failures' => 'integer',
        'created_at' => UtcDateTime::class,
        'updated_at' => UtcDateTime::class,
    ];
}
