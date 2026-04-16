<?php

namespace App\Models;

class EquitySnapshot extends BaseModel
{
    protected $table = 'equity_snapshots';

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
