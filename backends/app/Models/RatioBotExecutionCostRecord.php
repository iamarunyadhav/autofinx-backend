<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatioBotExecutionCostRecord extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array'];
}
