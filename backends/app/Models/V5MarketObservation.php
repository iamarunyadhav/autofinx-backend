<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class V5MarketObservation extends Model
{
    use HasUuids;

    protected $table = 'v5_market_observations';
    protected $guarded = ['id'];
    protected $casts = ['observed_at' => 'datetime'];
}
