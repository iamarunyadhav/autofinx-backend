<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class V5ResearchSelection extends Model { use HasUuids; protected $guarded=['id']; protected $casts=['selected_at'=>'datetime','expires_at'=>'datetime','selection_price'=>'float','research_metrics'=>'array']; }
