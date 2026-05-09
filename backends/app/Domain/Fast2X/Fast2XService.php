<?php

namespace App\Domain\Fast2X;

use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Models\Fast2XConfig;
use App\Models\Fast2XState;
use Throwable;

class Fast2XService
{
    public function __construct(
        private readonly BotStateService $states,
        private readonly BinanceSpotProvider $provider,
        private readonly DynamicThresholdService $thresholds,
        private readonly IndicatorService $indicators,
        private readonly PnLTrackingService $pnl,
        private readonly RollbackService $rollback,
        private readonly StopLossService $stopLoss,
        private readonly TrailingProfitService $trailing,
        private readonly RiskGuardService $risk,
        private readonly OrderExecutionService $orders,
        private readonly DecisionLogService $logs,
        private readonly LivePortfolioGuardService $liveGuard,
        private readonly ExternalSignalFusionService $signalFusion,
    ) {}

    public function getOrCreateConfig(string $workspaceId): Fast2XConfig
    {
        $config = Fast2XConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'bot_name' => 'Fast2X',
                'symbol' => config('fast2x.default_symbol', 'BTCUSDT'),
                'trade_mode' => 'SPOT',
                'bot_mode' => 'FAST2X',
                'paper_trading' => true,
                'enable_live_trading' => false,
                'settings' => config('fast2x.defaults', []),
                'algorithm_markdown' => "## Fast2X Brain\n\nPaper mode first. Prefer net profit after fees, avoid choppy markets, respect loss guards.",
            ]
        );

        $this->states->getOrCreate($config);
        return $config->fresh('state');
    }

    public function updateConfig(Fast2XConfig $config, array $payload): Fast2XConfig
    {
        $settings = array_merge($config->mergedSettings(), (array) ($payload['settings'] ?? []));
        $requestedMode = strtolower((string) ($settings['envMode'] ?? 'paper'));
        $requestedVenueTrading = (bool) ($payload['enable_live_trading'] ?? false) && in_array($requestedMode, ['testnet', 'live'], true);
        $paperTrading = ! $requestedVenueTrading;
        $liveAllowed = $requestedVenueTrading;
        $downgradeReason = null;

        if ($requestedMode === 'live' && ! (bool) config('fast2x.enable_live_trading')) {
            $paperTrading = true;
            $liveAllowed = false;
            $downgradeReason = 'Live spot execution is globally disabled for Fast2X.';
        }

        if ($requestedVenueTrading && ! $this->environmentTradingReady($requestedMode, $settings)) {
            $paperTrading = true;
            $liveAllowed = false;
            $downgradeReason = $requestedMode === 'testnet'
                ? 'Fast2X Testnet mode requires Testnet gate enabled with valid testnet spot credentials.'
                : 'Fast2X Live mode requires Live gate enabled with valid live spot credentials.';
        }

        if ($requestedMode === 'live' && ! $paperTrading && $liveAllowed) {
            $guard = $this->liveGuard->canActivate($config->workspace_id, 'fast2x');
            if (! $guard['allowed']) {
                $paperTrading = true;
                $liveAllowed = false;
                $downgradeReason = $guard['reason'];
            }
        }

        $config->update([
            'symbol' => strtoupper((string) ($settings['symbol'] ?? $config->symbol)),
            'paper_trading' => $paperTrading,
            'enable_live_trading' => $liveAllowed,
            'ai_brain_enabled' => (bool) ($payload['ai_brain_enabled'] ?? $config->ai_brain_enabled),
            'algorithm_markdown' => $payload['algorithm_markdown'] ?? $config->algorithm_markdown,
            'settings' => array_merge($settings, ['paperTrading' => $paperTrading]),
        ]);

        if ($requestedVenueTrading && $paperTrading && ! $liveAllowed && $downgradeReason) {
            $event = $requestedMode === 'live' ? 'LIVE_GUARD_BLOCKED' : 'TESTNET_GUARD_BLOCKED';
            $this->logs->log($config->fresh('state'), $event, ['reason' => $downgradeReason]);
        }

        $this->logs->log($config->fresh('state'), 'CONFIG_UPDATED', ['reason' => 'Operator updated Fast2X configuration']);
        return $config->fresh('state');
    }

    public function refreshLivePrice(Fast2XConfig $config): array
    {
        try {
            $ticker = $this->provider->configuredTicker($config);
            $this->processTick($config->fresh('state'), $ticker['tick'], $ticker['latencyMs'], false);
            return [
                'currentPrice' => $ticker['tick']['price'],
                'change24h' => $ticker['change24h'],
                'spread' => $ticker['tick']['spread'],
                'volume' => $ticker['tick']['volume'],
                'lastTickTime' => now()->toIso8601String(),
                'latencyMs' => $ticker['latencyMs'],
            ];
        } catch (Throwable $e) {
            $this->states->pause($config, 'live_price_error: ' . $e->getMessage());
            return [
                'currentPrice' => (float) ($config->state?->current_price ?? 0),
                'change24h' => 0,
                'spread' => null,
                'volume' => null,
                'lastTickTime' => optional($config->state?->last_price_update_at)->toIso8601String(),
                'latencyMs' => (int) ($config->state?->latency_ms ?? 0),
                'error' => $e->getMessage(),
            ];
        }
    }

    public function processTick(Fast2XConfig $config, array $tick, int $latencyMs = 0, bool $allowTrading = true): array
    {
        $config = $config->fresh('state');
        $state = $this->states->getOrCreate($config);
        $settings = $this->brainAdjustedSettings($config);
        $price = (float) $tick['price'];
        $tickAgeMs = max(0, (int) floor(microtime(true) * 1000) - (int) ($tick['eventTime'] ?? 0));
        $history = (array) ($state->runtime_meta['ticks'] ?? []);
        $history[] = $tick;
        $history = array_slice($history, -120);
        $dynamic = $this->thresholds->resolve($history, $settings);
        $confirmation = $this->indicators->confirm($history, $settings);
        $externalSignalGate = $this->signalFusion->evaluate($config, $confirmation, $price);
        $spreadPercent = $price > 0 ? ((float) ($tick['spread'] ?? 0) / $price) * 100 : 0;

        $state->update([
            'current_price' => $price,
            'latency_ms' => $latencyMs,
            'last_price_update_at' => now(),
            'websocket_status' => 'Connected',
            'runtime_meta' => array_merge((array) $state->runtime_meta, [
                'ticks' => $history,
                'dynamic_thresholds' => $dynamic,
                'indicator_confirmation' => $confirmation,
                'external_signal_gate' => $externalSignalGate,
                'latest_tick' => $tick,
            ]),
        ]);

        if (! $allowTrading || $state->status !== 'Running') {
            return ['decision' => 'WAITING_FOR_SIGNAL', 'dynamic' => $dynamic, 'indicator' => $confirmation, 'externalSignalGate' => $externalSignalGate];
        }

        if ((float) $state->quantity > 0 && (float) $state->entry_price > 0) {
            return $this->manageOpenPosition($config->fresh('state'), $price, $dynamic, $tickAgeMs, $spreadPercent, $latencyMs);
        }

        return $this->evaluateEntry($config->fresh('state'), $price, $dynamic, $confirmation, $externalSignalGate, $tickAgeMs, $spreadPercent, $latencyMs);
    }

    private function evaluateEntry(Fast2XConfig $config, float $price, array $dynamic, array $confirmation, array $externalSignalGate, int $tickAgeMs, float $spreadPercent, int $latencyMs): array
    {
        $state = $config->state;
        $settings = $this->brainAdjustedSettings($config);
        $bottom = min((float) ($state?->current_bottom ?: $price), $price);
        $rebound = $bottom > 0 ? (($price - $bottom) / $bottom) * 100 : 0;
        $state?->update(['current_bottom' => $bottom, 'decision' => 'WAITING_FOR_SIGNAL']);

        if (($dynamic['avoidEntry'] ?? false) || ($dynamic['marketMode'] ?? '') === 'CHOPPY') {
            $mode = $dynamic['marketMode'] ?? 'UNKNOWN';
            $vol  = $dynamic['volatilityLevel'] ?? 'UNKNOWN';
            $this->logs->log($config, 'BUY_SKIPPED_DUE_TO_RISK', ['reason' => "Entry avoided: mode={$mode} volatility={$vol}"]);
            return ['decision' => 'BUY_SKIPPED_DUE_TO_RISK'];
        }

        if ($rebound < (float) ($dynamic['buyThresholdPercent'] ?? $settings['manualBuyThresholdPercent'])) {
            return ['decision' => 'WAITING_FOR_SIGNAL'];
        }

        if (! ($confirmation['allowTrade'] ?? false)) {
            $this->logs->log($config, 'BUY_SKIPPED_DUE_TO_RISK', ['reason' => implode(', ', (array) $confirmation['reasons'])]);
            return ['decision' => 'BUY_SKIPPED_DUE_TO_RISK'];
        }

        if (! ($externalSignalGate['allowed'] ?? true)) {
            $this->logs->log($config, 'BUY_SKIPPED_DUE_TO_SIGNAL', ['reason' => implode(', ', (array) ($externalSignalGate['reasons'] ?? ['External signal confirmation failed']))]);
            return ['decision' => 'BUY_SKIPPED_DUE_TO_SIGNAL', 'reason' => implode(', ', (array) ($externalSignalGate['reasons'] ?? []))];
        }

        $signalKey = 'BUY:' . $config->normalizedSymbol() . ':' . now()->format('YmdHi');
        $risk = $this->risk->validateBeforeTrade($config, [
            'side' => 'BUY',
            'signalKey' => $signalKey,
            'tickAgeMs' => $tickAgeMs,
            'spreadPercent' => $spreadPercent,
            'slippagePercent' => (float) ($settings['estimatedSlippagePercent'] ?? 0.02),
            'latencyMs' => $latencyMs,
            'exchangeHealthy' => true,
        ]);

        if (! $risk['allowed']) {
            $this->logs->log($config, 'BUY_SKIPPED_DUE_TO_RISK', ['reason' => $risk['reason']]);
            return ['decision' => 'BUY_SKIPPED_DUE_TO_RISK', 'reason' => $risk['reason']];
        }

        $state?->update(['state' => Fast2XState::ENTERING_TRADE, 'order_in_progress' => true, 'decision' => 'BUY_TRIGGER_DETECTED']);
        $this->logs->log($config->fresh('state'), 'BUY_TRIGGER_DETECTED', ['currentPrice' => $price]);
        return $this->orders->executeBuy($config->fresh('state'), ['price' => $price, 'signalKey' => $signalKey, 'reason' => 'MICRO_ENTRY_SIGNAL']);
    }

    private function manageOpenPosition(Fast2XConfig $config, float $price, array $dynamic, int $tickAgeMs, float $spreadPercent, int $latencyMs): array
    {
        $state = $config->state;
        $settings = array_merge($this->brainAdjustedSettings($config), $dynamic);
        $pnl = $this->pnl->calculate((float) $state->entry_price, $price, (float) $state->quantity, $settings);
        $trail = $this->trailing->resolve((float) $state->entry_price, $price, $state->current_peak ? (float) $state->current_peak : null, $settings);
        $state->update([
            'state' => Fast2XState::IN_POSITION,
            'decision' => 'IN_POSITION',
            'current_price' => $price,
            'current_peak' => $trail['peak'],
            'unrealized_pnl' => $pnl['unrealizedPnL'],
            'unrealized_pnl_percent' => $pnl['netPnLPercent'],
            'net_pnl_percent' => $pnl['netPnLPercent'],
        ]);

        $reason = null;
        if ($pnl['netPnLPercent'] >= (float) ($settings['minProfitTargetPercent'] ?? 0.2)) {
            $reason = 'PROFIT_TARGET_HIT';
        } elseif ($trail['shouldSell']) {
            $reason = 'TRAILING_TAKE_PROFIT_HIT';
        } elseif ($this->rollback->shouldRollback($pnl['netPnLPercent'], $settings)) {
            $reason = 'LOSS_ROLLBACK_TRIGGERED';
        } elseif ($this->stopLoss->shouldStop($pnl['netPnLPercent'], $settings)) {
            $reason = 'STOP_LOSS_TRIGGERED';
        }

        if (! $reason) {
            return ['decision' => 'IN_POSITION', 'pnl' => $pnl, 'trailing' => $trail];
        }

        $signalKey = 'SELL:' . $config->normalizedSymbol() . ':' . $reason . ':' . now()->format('YmdHis');
        $risk = $this->risk->validateBeforeTrade($config, [
            'side' => 'SELL',
            'signalKey' => $signalKey,
            'tickAgeMs' => $tickAgeMs,
            'spreadPercent' => $spreadPercent,
            'slippagePercent' => (float) ($settings['estimatedSlippagePercent'] ?? 0.02),
            'expectedNetProfitPercent' => $pnl['netPnLPercent'],
            'latencyMs' => $latencyMs,
            'exchangeHealthy' => true,
        ]);

        if (! $risk['allowed']) {
            $this->logs->log($config, 'TRADE_SKIPPED', ['reason' => $risk['reason'], 'netPnlPercent' => $pnl['netPnLPercent']]);
            return ['decision' => 'TRADE_SKIPPED', 'reason' => $risk['reason']];
        }

        $state->update([
            'state' => $reason === 'LOSS_ROLLBACK_TRIGGERED' ? Fast2XState::LOSS_ROLLBACK : Fast2XState::PROFIT_LOCK,
            'order_in_progress' => true,
            'decision' => $reason,
        ]);
        $this->logs->log($config->fresh('state'), $reason, ['currentPrice' => $price, 'netPnlPercent' => $pnl['netPnLPercent']]);
        return $this->orders->executeSell($config->fresh('state'), ['price' => $price, 'signalKey' => $signalKey, 'reason' => $reason]);
    }

    public function statusPayload(Fast2XConfig $config): array
    {
        $state = $this->states->getOrCreate($config);
        $settings = $this->brainAdjustedSettings($config);

        return [
            'botName' => 'Fast2X',
            'state' => $state->state,
            'status' => $state->status,
            'mode' => $this->modeLabel($config),
            'exchange' => 'Binance Spot',
            'symbol' => $config->normalizedSymbol(),
            'webSocketStatus' => $state->websocket_status,
            'latencyMs' => $state->latency_ms,
            'lastPriceUpdateTime' => optional($state->last_price_update_at)->toIso8601String(),
            'paperTrading' => $config->paper_trading,
            'liveTradingEnabled' => $config->enable_live_trading,
            'aiBrainEnabled' => $config->ai_brain_enabled,
            'decision' => $state->decision,
            'dynamicThresholds' => $state->runtime_meta['dynamic_thresholds'] ?? null,
            'indicatorConfirmation' => $state->runtime_meta['indicator_confirmation'] ?? null,
            'externalSignalGate' => $state->runtime_meta['external_signal_gate'] ?? null,
            'warning' => $config->executionMode() === 'live' ? 'Live trading is risky and requires explicit environment enablement.' : null,
            'liveGuard' => $config->usesLiveVenue() ? $this->liveGuard->canActivate($config->workspace_id, 'fast2x') : ['allowed' => true, 'reason' => null, 'activeBot' => null],
            'settings' => $settings,
        ];
    }

    public function pnlPayload(Fast2XConfig $config): array
    {
        $state = $this->states->getOrCreate($config);
        $wins = (int) $state->wins;
        $losses = (int) $state->losses;
        $total = max(1, $wins + $losses);

        return [
            'entryPrice' => (float) ($state->entry_price ?? 0),
            'currentPrice' => (float) ($state->current_price ?? 0),
            'unrealizedPnL' => (float) $state->unrealized_pnl,
            'realizedPnL' => (float) $state->realized_pnl,
            'netPnLAfterFees' => (float) $state->net_pnl,
            'netPnLPercent' => (float) $state->net_pnl_percent,
            'dailyProfit' => (float) $state->daily_profit,
            'dailyLoss' => (float) $state->daily_loss,
            'winRate' => round(($wins / $total) * 100, 2),
            'tradeCountToday' => (int) $state->trade_count_today,
            'currentPeak' => (float) ($state->current_peak ?? 0),
            'currentBottom' => (float) ($state->current_bottom ?? 0),
        ];
    }

    public function chartPayload(Fast2XConfig $config): array
    {
        $state = $this->states->getOrCreate($config);
        $settings = $this->brainAdjustedSettings($config);
        $ticks = array_values((array) ($state->runtime_meta['ticks'] ?? []));
        $entry = (float) ($state->entry_price ?? 0);
        $peak = (float) ($state->current_peak ?? 0);
        $bottom = (float) ($state->current_bottom ?? 0);
        $stop = $entry > 0 ? $entry * (1 - ((float) ($settings['stopLossPercent'] ?? 0.4) / 100)) : null;
        $target = $entry > 0 ? $entry * (1 + ((float) ($settings['minProfitTargetPercent'] ?? 0.2) / 100)) : null;
        $trailing = $peak > 0 ? $peak * (1 - ((float) ($settings['trailingTakeProfitPercent'] ?? 0.25) / 100)) : null;

        $points = array_map(function (array $tick, int $index) use ($entry, $stop, $target, $trailing, $peak, $bottom): array {
            $eventTime = (int) ($tick['eventTime'] ?? 0);
            return [
                'index' => $index,
                'time' => $eventTime > 0 ? \Carbon\Carbon::createFromTimestampMs($eventTime)->format('H:i:s') : now()->format('H:i:s'),
                'price' => (float) ($tick['price'] ?? 0),
                'bid' => isset($tick['bid']) ? (float) $tick['bid'] : null,
                'ask' => isset($tick['ask']) ? (float) $tick['ask'] : null,
                'entry' => $entry ?: null,
                'stopLoss' => $stop,
                'profitTarget' => $target,
                'trailingTakeProfit' => $trailing,
                'peak' => $peak ?: null,
                'bottom' => $bottom ?: null,
            ];
        }, $ticks, array_keys($ticks));

        if ($points === [] && (float) ($state->current_price ?? 0) > 0) {
            $points[] = [
                'index' => 0,
                'time' => now()->format('H:i:s'),
                'price' => (float) $state->current_price,
                'bid' => null,
                'ask' => null,
                'entry' => $entry ?: null,
                'stopLoss' => $stop,
                'profitTarget' => $target,
                'trailingTakeProfit' => $trailing,
                'peak' => $peak ?: null,
                'bottom' => $bottom ?: null,
            ];
        }

        $lastTrades = $config->trades()->orderByDesc('created_at')->limit(30)->get()->reverse()->values()->map(fn ($trade) => [
            'time' => optional($trade->created_at)->format('H:i:s'),
            'side' => $trade->side,
            'price' => (float) ($trade->exit_price ?: $trade->entry_price ?: 0),
            'netPnl' => (float) ($trade->net_pnl ?? 0),
            'reason' => $trade->reason,
        ]);

        return [
            'points' => $points,
            'markers' => $lastTrades,
            'levels' => [
                'entry' => $entry ?: null,
                'stopLoss' => $stop,
                'profitTarget' => $target,
                'trailingTakeProfit' => $trailing,
                'peak' => $peak ?: null,
                'bottom' => $bottom ?: null,
            ],
            'autoManagement' => [
                'state' => $state->state,
                'decision' => $state->decision,
                'mode' => strtoupper($config->executionMode()),
                'aiBrainEnabled' => (bool) $config->ai_brain_enabled,
                'dynamicThresholds' => $state->runtime_meta['dynamic_thresholds'] ?? null,
                'indicatorConfirmation' => $state->runtime_meta['indicator_confirmation'] ?? null,
                'externalSignalGate' => $state->runtime_meta['external_signal_gate'] ?? null,
                'riskControls' => [
                    'rollbackLossPercent' => (float) ($settings['rollbackLossPercent'] ?? 0.15),
                    'stopLossPercent' => (float) ($settings['stopLossPercent'] ?? 0.4),
                    'trailingTakeProfitPercent' => (float) ($settings['trailingTakeProfitPercent'] ?? 0.25),
                    'maxTradesPerMinute' => (int) ($settings['maxTradesPerMinute'] ?? 5),
                    'maxTradesPerDay' => (int) ($settings['maxTradesPerDay'] ?? 50),
                    'maxDailyLossPercent' => (float) ($settings['maxDailyLossPercent'] ?? 3),
                ],
            ],
        ];
    }

    private function brainAdjustedSettings(Fast2XConfig $config): array
    {
        $settings = $config->mergedSettings();
        if (! $config->ai_brain_enabled) {
            return $settings;
        }

        $brain = strtolower((string) $config->algorithm_markdown);
        if (str_contains($brain, 'conservative') || str_contains($brain, 'loss') || str_contains($brain, 'protect')) {
            $settings['minProfitTargetPercent'] = max((float) $settings['minProfitTargetPercent'], 0.25);
            $settings['rollbackLossPercent'] = min((float) $settings['rollbackLossPercent'], 0.12);
            $settings['maxTradesPerMinute'] = min((int) $settings['maxTradesPerMinute'], 3);
        }

        if (str_contains($brain, 'aggressive') || str_contains($brain, 'fast entry')) {
            $settings['manualBuyThresholdPercent'] = max(0.1, (float) $settings['manualBuyThresholdPercent'] * 0.8);
            $settings['cooldownSeconds'] = max(2, (int) $settings['cooldownSeconds'] - 1);
        }

        if (str_contains($brain, 'avoid choppy') || str_contains($brain, 'choppy')) {
            $settings['avoidChoppyMarket'] = true;
        }

        return $settings;
    }

    private function environmentTradingReady(string $mode, array $settings): bool
    {
        if (! in_array($mode, ['testnet', 'live'], true)) {
            return false;
        }

        $enabled = $mode === 'testnet'
            ? (bool) ($settings['envTestnetEnabled'] ?? false)
            : (bool) ($settings['envLiveEnabled'] ?? false);
        $key = $mode === 'testnet'
            ? (string) ($settings['testnetApiKey'] ?? '')
            : (string) ($settings['liveApiKey'] ?? '');
        $secret = $mode === 'testnet'
            ? (string) ($settings['testnetApiSecret'] ?? '')
            : (string) ($settings['liveApiSecret'] ?? '');

        return $enabled && $key !== '' && $secret !== '';
    }

    private function modeLabel(Fast2XConfig $config): string
    {
        return match ($config->executionMode()) {
            'live' => 'Live Spot',
            'testnet' => 'Testnet Spot',
            default => 'Paper Spot',
        };
    }
}
