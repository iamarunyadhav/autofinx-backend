<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Instrument extends BaseModel
{
    protected $casts = [
        'enabled' => 'boolean',
    ];

    public const MARKET_METAL = 'metal';
    public const MARKET_ENERGY = 'energy';

    public function mappings(): HasMany
    {
        return $this->hasMany(InstrumentMapping::class, 'instrument_id');
    }

    public function mappingFor(string $provider): ?InstrumentMapping
    {
        return $this->mappings()->where('provider', strtolower(trim($provider)))->first();
    }

    public function normalizedSymbol(): string
    {
        return strtoupper(trim((string) $this->canonical_symbol));
    }
}
