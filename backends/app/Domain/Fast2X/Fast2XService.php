<?php

namespace App\Domain\Fast2X;

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
        $paperTrading = array_key_exists('paperTrading', $settings) ? (bool) $settings['paperTrading'] : true;
        $liveAllowed = (bool) ($payload['enable_live_trading'] ?? false);

        if (! $paperTrading && (! $liveAllowed || ! (bool) config('fast2x.enable_live_trading'))) {
            $paperTrading = true;
            $liveAllowed = false;
        }

        $config->update([
            'symbol' => strtoupper((string) ($settings['symbol'] ?? $config->symbol)),
            'paper_trading' => $paperTrading,
            'enable_live_trading' => $liveAllowed,
            'ai_brain_enabled' => (bool) ($payload['ai_brain_enabled'] ?? $config->ai_brain_enabled),
            'algorithm_markdown' => $payload['algorithm_markdown'] ?? $config->algorithm_markdown,
            'settings' => array_merge($settings, ['paperTrading' => $paperTrading]),
        ]);

        $this->logs->log($config->fresh('state'), 'CONFIG_UPDATED', ['reason' => 'Operator updated Fast2X configuration']);
        return $config->fresh('state');
    }

    public function refreshLivePrice(Fast2XConfig $config): array
    {
        try {
            $ticker = $this->provider->latestTicker($config->normalizedSymbol());
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
                'latest_tick' => $tick,
            ]),
        ]);

        if (! $allowTrading || $state->status !== 'Running') {
            return ['decision' => 'WAITING_FOR_SIGNAL', 'dynamic' => $dynamic, 'indicator' => $confirmation];
        }

        if ((float) $state->quantity > 0 && (float) $state->entry_price > 0) {
            return $this->manageOpenPosition($config->fresh('state'), $price, $dynamic, $tickAgeMs, $spreadPercent, $latencyMs);
        }

        return $this->evaluateEntry($config->fresh('state'), $price, $dynamic, $confirmation, $tickAgeMs, $spreadPercent, $latencyMs);
    }

    private function evaluateEntry(Fast2XConfig $config, float $price, array $dynamic, array $confirmation, int $tickAgeMs, float $spreadPercent, int $latencyMs): array
    {
        $state = $config->state;
        $settings = $this->brainAdjustedSettings($config);
        $bottom = min((float) ($state?->current_bottom ?: $price), $price);
        $rebound = $bottom > 0 ? (($price - $bottom) / $bottom) * 100 : 0;
        $state?->update(['current_bottom' => $bottom, 'decision' => 'WAITING_FOR_SIGNAL']);

        if (($dynamic['marketMode'] ?? '') === 'CHOPPY') {
            $this->logs->log($config, 'BUY_SKIPPED_DUE_TO_RISK', ['reason' => 'Choppy market avoided']);
            return ['decision' => 'BUY_SKIPPED_DUE_TO_RISK'];
        }

        if ($rebound < (float) ($dynamic['buyThresholdPercent'] ?? $settings['manualBuyThresholdPercent'])) {
            return ['decision' => 'WAITING_FOR_SIGNAL'];
        }

        if (! ($confirmation['allowTrade'] ?? false)) {
            $this->logs->log($config, 'BUY_SKIPPED_DUE_TO_RISK', ['reason' => implode(', ', (array) $confirmation['reasons'])]);
            return ['decision' => 'BUY_SKIPPED_DUE_TO_RISK'];
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
            'mode' => $config->paper_trading ? 'Paper Trading' : 'Live Trading',
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
            'warning' => $config->paper_trading ? null : 'Live trading is risky and requires explicit environment enablement.',
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
}
