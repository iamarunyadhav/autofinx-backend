<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/**
 * Market Context table `mctx_snapshots` (global public data, not workspace-scoped).
 * Instants are UTC wall time; bind UTC Carbons (->utc()) in where() clauses.
 */
class MarketContextSnapshot extends BaseModel
{
    protected $table = 'mctx_snapshots';

    protected $casts = [
        'as_of' => UtcDateTime::class,
        'payload' => 'array',
        'created_at' => UtcDateTime::class,
        'updated_at' => UtcDateTime::class,
    ];
}
