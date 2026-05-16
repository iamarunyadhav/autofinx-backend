<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FutureTradeBotConfig extends BaseModel
{
    protected $table = 'future_trade_bot_configs';

    protected $casts = [
        'slot_index' => 'integer',
        'paper_trading' => 'boolean',
        'enable_live_trading' => 'boolean',
        'enabled' => 'boolean',
        'settings' => 'array',
    ];

    public function isPrimary(): bool
    {
        return (int) ($this->slot_index ?? 0) === 0;
    }

    public function state(): HasOne
    {
        return $this->hasOne(FutureTradeBotState::class, 'future_trade_bot_config_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(FutureTradeBotTrade::class, 'future_trade_bot_config_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FutureTradeBotLog::class, 'future_trade_bot_config_id');
    }

    public function missedSignalAudits(): HasMany
    {
        return $this->hasMany(FutureTradeMissedSignalAudit::class, 'future_trade_bot_config_id');
    }

    public function configVersions(): HasMany
    {
        return $this->hasMany(FutureTradeConfigVersion::class, 'future_trade_bot_config_id');
    }

    public function mergedSettings(): array
    {
        $settings = array_merge(config('future_trade.defaults', []), (array) $this->settings);
        $preset = strtolower(trim((string) ($settings['preset'] ?? '')));
        $presets = (array) config('future_trade.presets', []);

        if ($preset !== '' && array_key_exists($preset, $presets)) {
            $settings = array_merge($settings, (array) $presets[$preset], (array) $this->settings);
        }

        $settings['indicatorInfluence'] = array_merge(
            (array) config('future_trade.defaults.indicatorInfluence', []),
            (array) ($settings['indicatorInfluence'] ?? [])
        );

        return $settings;
    }

    public function executionMode(): string
    {
        if (! $this->enable_live_trading) {
            return 'paper';
        }

        $settings = $this->mergedSettings();
        $liveTrading = (bool) ($settings['liveTrading'] ?? false);
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);

        if (! $liveTrading) {
            return 'paper';
        }

        return $forceTestnet ? 'testnet' : 'live';
    }

    public function usesLiveVenue(): bool
    {
        return $this->executionMode() === 'live';
    }
}
