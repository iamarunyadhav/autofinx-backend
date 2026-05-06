<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fast2XConfig extends BaseModel
{
    protected $table = 'fast2x_configs';

    protected $casts = [
        'paper_trading' => 'boolean',
        'enable_live_trading' => 'boolean',
        'ai_brain_enabled' => 'boolean',
        'settings' => 'array',
    ];

    public function state(): HasOne
    {
        return $this->hasOne(Fast2XState::class, 'fast2x_config_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Fast2XTrade::class, 'fast2x_config_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(Fast2XDecisionLog::class, 'fast2x_config_id');
    }

    public function normalizedSymbol(): string
    {
        return strtoupper(trim((string) ($this->symbol ?: 'BTCUSDT')));
    }

    public function mergedSettings(): array
    {
        return array_merge(config('fast2x.defaults', []), (array) $this->settings);
    }

    public function executionMode(): string
    {
        if ($this->paper_trading || ! $this->enable_live_trading) {
            return 'paper';
        }

        $mode = strtolower((string) ($this->mergedSettings()['envMode'] ?? 'paper'));

        return $mode === 'live' ? 'live' : 'testnet';
    }

    public function usesLiveVenue(): bool
    {
        return $this->executionMode() === 'live';
    }

    public function usesTestnetVenue(): bool
    {
        return $this->executionMode() === 'testnet';
    }
}
