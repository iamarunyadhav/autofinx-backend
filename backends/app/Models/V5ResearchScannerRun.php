<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
final class V5ResearchScannerRun extends Model { use HasUuids; protected $guarded=['id']; protected $casts=['as_of'=>'datetime','source_status'=>'array']; public function candidates(): HasMany{return $this->hasMany(V5ResearchScannerCandidate::class,'scanner_run_id');} }
