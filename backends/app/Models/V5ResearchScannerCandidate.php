<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
final class V5ResearchScannerCandidate extends Model { use HasUuids; protected $guarded=['id']; protected $casts=['evidence'=>'array','execution_authorized'=>'boolean']; }
