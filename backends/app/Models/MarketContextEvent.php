<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/**
 * Market Context table `mctx_events` (global public data, not workspace-scoped).
 * Instants are UTC wall time; bind UTC Carbons (->utc()) in where() clauses.
 */
class MarketContextEvent extends BaseModel
{
    protected $table = 'mctx_events';

    protected $casts = [
        'published_at' => UtcDateTime::class,
        'first_seen_at' => UtcDateTime::class,
        'created_at' => UtcDateTime::class,
        'updated_at' => UtcDateTime::class,
    ];
}
