<?php

namespace App\Models;

class WalletSnapshot extends BaseModel
{
    protected $casts = [
        'captured_at' => 'datetime',
    ];
}
