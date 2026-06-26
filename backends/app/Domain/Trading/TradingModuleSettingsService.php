<?php

namespace App\Domain\Trading;

use App\Domain\Ops\TradingEnvironmentGuard;
use App\Domain\Trading\FuturesOrderSizingService;
use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Models\Setting;
use Illuminate\Support\Arr;

class TradingModuleSettingsService
{
    public function __construct(
        private readonly TimeframeRegistry $timeframes,
        private readonly TradingEnvironmentGuard $modeGuard,
        private readonly LiveTradeProviderRegistry $providers,
        private readonly TradingIntegrationResolver $integrations,
    ) {
    }

    public function quickTradeConfig(?string $workspaceId = null): array
    {
        return $this->mergedGroup('quick_trade', $workspaceId, [
            'default_duration' => 60,
            'default_timeframe' => '1m',
            'min_stake' => 10,
            'max_stake' => 500,
            'max_active_trades' => 5,
            'payout_rate' => 0.91,
            'paper_only' => true,
            'live_supported' => false,
            'default_provider' => 'binance',
            'allowed_indicators' => ['RSI', 'MACD', 'BOLLINGER', 'CCI', 'STOCHASTIC'],
            'chart_types' => ['area', 'candles', 'bars', 'heikin_ashi'],
            'durations' => [5, 15, 30, 60, 300, 900],
            'allowed_symbols' => [
                ['symbol' => 'GOLD', 'display' => 'Gold', 'asset_class' => 'METALS', 'payout_rate' => 0.88],
                ['symbol' => 'GOLD_OTC', 'display' => 'Gold OTC', 'asset_class' => 'METALS', 'payout_rate' => 0.91],
                ['symbol' => 'EURUSD_OTC', 'display' => 'EUR/USD OTC', 'asset_class' => 'FX', 'payout_rate' => 0.89],
                ['symbol' => 'BTCUSDT', 'display' => 'BTC/USDT', 'asset_class' => 'CRYPTO', 'payout_rate' => 0.86],
            ],
        ]);
    }

    public function robotConfig(?string $workspaceId = null): array
    {
        return $this->mergedGroup('robot_trade', $workspaceId, [
            'paper_only' => false,
            'live_supported' => true,
            'default_strategy_profile' => 'balanced',
            'default_indicators' => ['RSI', 'MACD'],
            'max_active_trades' => 2,
            'cooldown_seconds' => 15,
            'confidence_threshold' => 60,
            'strategy_profiles' => [
                ['key' => 'conservative', 'label' => 'Conservative', 'max_active_trades' => 1, 'confidence_threshold' => 70],
                ['key' => 'balanced', 'label' => 'Balanced', 'max_active_trades' => 2, 'confidence_threshold' => 60],
                ['key' => 'aggressive', 'label' => 'Aggressive', 'max_active_trades' => 3, 'confidence_threshold' => 52],
            ],
        ]);
    }

    public function walletConfig(?string $workspaceId = null): array
    {
        return $this->mergedGroup('paper_wallet', $workspaceId, [
            'initial_balance' => 10000,
            'currency' => 'USD',
        ]);
    }

    public function metadata(?string $workspaceId = null): array
    {
        $quick = $this->quickTradeConfig($workspaceId);
        $state = $this->tradingState($workspaceId);
        $mode = $state['mode'] ?? 'paper';
        $provider = $state['preferred_provider'] ?? ($quick['default_provider'] ?? 'binance');
        $providerProfile = $state['provider_catalog'][$provider] ?? null;
        $symbols = $mode === 'live'
            ? (array) ($providerProfile['symbols'] ?? [])
            : (array) $quick['allowed_symbols'];
        $timeframes = $mode === 'live'
            ? $this->timeframes->optionsFor((array) ($providerProfile['timeframes'] ?? ['1m', '5m', '15m']))
            : $this->timeframes->options();
        $durations = $mode === 'live'
            ? (array) ($providerProfile['durations'] ?? [60, 300, 900])
            : (array) $quick['durations'];

        return [
            'symbols' => $symbols,
            'indicators' => $this->indicatorCatalog(),
            'chart_types' => collect($quick['chart_types'])
                ->map(fn (string $type) => ['key' => $type, 'label' => $this->chartTypeLabel($type)])
                ->values()
                ->all(),
            'timeframes' => $timeframes,
            'durations' => collect($durations)
                ->map(fn (int $seconds) => ['seconds' => $seconds, 'label' => $this->durationLabel($seconds)])
                ->values()
                ->all(),
            'quick_trade' => array_merge($quick, [
                'active_mode' => $mode,
                'selected_provider' => $provider,
                'min_stake' => $mode === 'live' && $provider === 'binance'
                    ? max((float) ($quick['min_stake'] ?? 10), FuturesOrderSizingService::BINANCE_MIN_OPEN_NOTIONAL)
                    : (float) ($quick['min_stake'] ?? 10),
                'paper_only' => true,
            ]),
            'robot_trade' => $this->robotConfig($workspaceId),
            'trading_state' => $state,
        ];
    }

    public function tradingState(?string $workspaceId = null): array
    {
        $catalog = $this->providers->catalog();
        $resolved = $this->modeGuard->resolveState($workspaceId);
        $trading = $this->mergedGroup('trading', $workspaceId, [
            'preferred_provider' => $this->quickTradeConfig($workspaceId)['default_provider'] ?? 'binance',
            'preferred_integration_id' => null,
            'auto_trading_enabled' => true,
            'bot_default_interval_seconds' => 3600,
        ]);

        $preferredProvider = strtolower((string) ($trading['preferred_provider'] ?? 'binance'));
        $integrations = $workspaceId ? $this->integrations->activeIntegrations($workspaceId) : [];
        $preferredIntegrationId = is_string($trading['preferred_integration_id'] ?? null)
            ? $trading['preferred_integration_id']
            : null;

        // Honour an explicit preferred integration id first (so a workspace with
        // both testnet and live Binance integrations resolves the exact one the
        // user selected); only then fall back to the first integration for the
        // preferred provider.
        $activeIntegration = ($preferredIntegrationId
            ? collect($integrations)->firstWhere('id', $preferredIntegrationId)
            : null)
            ?: collect($integrations)->first(
                fn (array $integration) => strtolower((string) ($integration['provider_key'] ?? '')) === $preferredProvider
            );

        $providerProfile = $catalog[$preferredProvider] ?? [
            'key' => $preferredProvider,
            'label' => ucfirst($preferredProvider),
            'live_supported' => false,
            'symbols' => [],
            'timeframes' => ['1m', '5m', '15m'],
            'durations' => [60, 300, 900],
        ];

        return [
            'mode' => $resolved['mode'],
            'live_enabled' => $resolved['live_enabled'],
            'auto_trading_enabled' => $this->toBool($trading['auto_trading_enabled'] ?? true),
            'bot_default_interval_seconds' => max(60, (int) ($trading['bot_default_interval_seconds'] ?? 3600)),
            'preferred_provider' => $preferredProvider,
            'preferred_integration_id' => $activeIntegration['id'] ?? $preferredIntegrationId,
            'preferred_integration_label' => $activeIntegration['display_name'] ?? null,
            'active_integrations' => $integrations,
            'provider_catalog' => $catalog,
            'provider_profile' => $providerProfile,
            'venue_environment' => $activeIntegration['environment']
                ?? Arr::get($activeIntegration, 'config_json.environment', $resolved['settings']['venue_environment'] ?? null),
            'exchange_environment' => $activeIntegration['environment'] ?? null,
            'live_ready' => (bool) ($resolved['live_enabled']
                && $resolved['mode'] === 'live'
                && ($providerProfile['live_supported'] ?? false)
                && $activeIntegration),
            'live_ready_reason' => $this->liveReadyReason($resolved, $providerProfile, $activeIntegration),
        ];
    }

    public function automationEnabled(?string $workspaceId = null): bool
    {
        return $this->toBool($this->mergedGroup('trading', $workspaceId, [
            'auto_trading_enabled' => true,
        ])['auto_trading_enabled'] ?? true);
    }

    public function symbolMeta(?string $workspaceId, string $symbol, ?string $mode = null, ?string $provider = null): array
    {
        $normalized = strtoupper($symbol);
        $state = $this->tradingState($workspaceId);
        $mode = strtolower((string) ($mode ?? ($state['mode'] ?? 'paper')));
        $provider = strtolower((string) ($provider ?? ($state['preferred_provider'] ?? 'binance')));
        $catalog = $state['provider_catalog'][$provider] ?? [];
        $source = $mode === 'live'
            ? (array) ($catalog['symbols'] ?? [])
            : (array) $this->quickTradeConfig($workspaceId)['allowed_symbols'];

        $match = collect($source)
            ->first(fn (array $row) => strtoupper((string) ($row['symbol'] ?? '')) === $normalized);

        if ($match) {
            return $match;
        }

        return [
            'symbol' => $normalized,
            'display' => str_replace('_', ' ', $normalized),
            'asset_class' => $mode === 'live' ? 'CRYPTO' : (str_contains($normalized, 'USD') ? 'FX' : 'METALS'),
            'payout_rate' => (float) $this->quickTradeConfig($workspaceId)['payout_rate'],
        ];
    }

    public function durationsForMode(?string $workspaceId, string $mode, ?string $provider = null): array
    {
        if (strtolower($mode) !== 'live') {
            return (array) $this->quickTradeConfig($workspaceId)['durations'];
        }

        $state = $this->tradingState($workspaceId);
        $provider = strtolower((string) ($provider ?? ($state['preferred_provider'] ?? 'binance')));

        return (array) Arr::get($state, "provider_catalog.{$provider}.durations", [60, 300, 900]);
    }

    public function timeframesForMode(?string $workspaceId, string $mode, ?string $provider = null): array
    {
        if (strtolower($mode) !== 'live') {
            return $this->timeframes->options();
        }

        $state = $this->tradingState($workspaceId);
        $provider = strtolower((string) ($provider ?? ($state['preferred_provider'] ?? 'binance')));

        return $this->timeframes->optionsFor((array) Arr::get($state, "provider_catalog.{$provider}.timeframes", ['1m', '5m', '15m']));
    }

    public function indicatorCatalog(): array
    {
        return [
            ['key' => 'RSI', 'label' => 'RSI', 'description' => 'Relative strength mean-reversion signal.'],
            ['key' => 'MACD', 'label' => 'MACD', 'description' => 'Momentum and crossover trend confirmation.'],
            ['key' => 'BOLLINGER', 'label' => 'Bollinger Bands', 'description' => 'Range expansion and reversal envelope.'],
            ['key' => 'CCI', 'label' => 'CCI', 'description' => 'Commodity channel deviation score.'],
            ['key' => 'STOCHASTIC', 'label' => 'Stochastic', 'description' => 'Recent closing position within range.'],
            ['key' => 'HEIKIN_ASHI', 'label' => 'Heikin Ashi', 'description' => 'Smoothed candle transform for charting.'],
        ];
    }

    private function mergedGroup(string $group, ?string $workspaceId, array $defaults): array
    {
        $global = Setting::where('group', $group)
            ->whereNull('workspace_id')
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value_json])
            ->toArray();

        $workspace = [];
        if ($workspaceId) {
            $workspace = Setting::where('group', $group)
                ->where('workspace_id', $workspaceId)
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value_json])
                ->toArray();
        }

        return array_replace_recursive($defaults, $global, $workspace);
    }

    private function durationLabel(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds % 60 === 0 => ((int) ($seconds / 60)) . 'm',
            default => "{$seconds}s",
        };
    }

    private function chartTypeLabel(string $type): string
    {
        return match ($type) {
            'area' => 'Area',
            'candles' => 'Candles',
            'bars' => 'Bars',
            'heikin_ashi' => 'Heikin Ashi',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function liveReadyReason(array $resolved, array $providerProfile, ?array $activeIntegration): ?string
    {
        if (! $resolved['live_enabled']) {
            return 'Live switch is disabled for this workspace.';
        }

        if (($resolved['mode'] ?? 'paper') !== 'live') {
            return 'Workspace is currently in paper mode.';
        }

        if (! ($providerProfile['live_supported'] ?? false)) {
            return 'Selected provider does not support live quick trading yet.';
        }

        if (! $activeIntegration) {
            return 'Connect and activate a provider integration before switching live.';
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
