<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FutureTradeBotService
{
    public function __construct(
        private readonly LivePortfolioGuardService $liveGuard,
    ) {}

    public function getOrCreateConfig(string $workspaceId): FutureTradeBotConfig
    {
        $config = FutureTradeBotConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'bot_name' => 'Future Trade Bot',
                'paper_trading' => true,
                'enable_live_trading' => false,
                'enabled' => false,
                'settings' => config('future_trade.defaults'),
                'algorithm_notes' => "Video-style futures scanner: scan symbols, issue BUY/SELL signals, paper execute only, protect with stop loss, rollback and trailing profit.",
            ]
        );

        $config->state()->firstOrCreate([], [
            'status' => 'Stopped',
            'state' => 'IDLE',
            'connection_status' => 'Disconnected',
            'trading_day' => now()->toDateString(),
        ]);

        return $config->fresh('state');
    }

    public function updateConfig(FutureTradeBotConfig $config, array $payload): FutureTradeBotConfig
    {
        $settings = array_merge($config->mergedSettings(), (array) ($payload['settings'] ?? []));
        $liveRequested = (bool) ($settings['liveTrading'] ?? false);
        $forceTestnet = array_key_exists('forceTestnet', $settings)
            ? (bool) $settings['forceTestnet']
            : (bool) config('future_trade.force_testnet');
        $liveAllowed = $liveRequested && $this->environmentTradingReady($forceTestnet);

        if ($liveAllowed) {
            $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
            if (! $guard['allowed']) {
                $liveAllowed = false;
            }
        }

        $settings['liveTrading'] = $liveAllowed;
        $settings['paperTrading'] = ! $liveAllowed;
        $settings['forceTestnet'] = $forceTestnet;

        $config->update([
            'paper_trading' => ! $liveAllowed,
            'enable_live_trading' => $liveAllowed,
            'settings' => $settings,
            'algorithm_notes' => $payload['algorithm_notes'] ?? $config->algorithm_notes,
        ]);

        if ($liveRequested && ! $liveAllowed) {
            $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', null, null, 0, 'Another live bot is already active for this workspace/account. Future Trade stays in paper mode.');
        }

        $this->log($config->fresh('state'), 'CONFIG_UPDATED', null, null, 0, $liveAllowed ? 'Future Trade Bot config saved. ' . ($forceTestnet ? 'Testnet futures enabled.' : 'Live futures enabled.') : 'Future Trade Bot config saved. Paper trading active.');
        return $config->fresh('state');
    }

    public function start(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->update(['enabled' => true, 'paper_trading' => true, 'enable_live_trading' => false]);
        $settings = $config->mergedSettings();
        if ((bool) ($settings['liveTrading'] ?? false) && $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true))) {
            $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
            if ($guard['allowed']) {
                $config->update(['paper_trading' => false, 'enable_live_trading' => true]);
            } else {
                $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', null, null, 0, $guard['reason']);
            }
        }
        $state = $this->state($config);
        $this->persistState($state, [
            'status' => 'Running',
            'state' => 'SCANNING',
            'connection_status' => 'Connected',
            'current_signal' => 'WAITING_FOR_SIGNAL',
            'daily_stop_until' => null,
            'trading_day' => now()->toDateString(),
        ]);
        $this->log($config->fresh('state'), 'BOT_STARTED', null, null, 0, 'Futures paper scanner started.');
        $this->scan($config->fresh('state'), true);
        return $state->exists ? $state->fresh() : $state;
    }

    public function stop(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->update(['enabled' => false]);
        $state = $this->state($config);
        $this->persistState($state, [
            'status' => 'Stopped',
            'state' => 'STOPPED',
            'connection_status' => 'Disconnected',
            'current_signal' => 'WAITING_FOR_SIGNAL',
        ]);
        $this->log($config->fresh('state'), 'BOT_STOPPED', $state->current_symbol, null, 0, 'Operator stopped futures bot.');
        return $state->exists ? $state->fresh() : $state;
    }

    public function emergencyStop(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), $signal, $settings);
        $settings = $dynamic['settings'];
        $settings['emergencyStop'] = true;
        if ((float) ($this->state($config)->quantity ?? 0) > 0 && $config->enable_live_trading) {
            try {
                $this->closeLivePosition($config, (float) ($this->state($config)->current_price ?? 0), 'EMERGENCY_STOP');
            } catch (Throwable $e) {
                $this->log($config->fresh('state'), 'LIVE_EMERGENCY_CLOSE_FAILED', $config->state?->current_symbol, null, 0, $e->getMessage());
            }
        }
        $config->update(['enabled' => false, 'settings' => $settings]);
        $this->persistState($state, [
            'status' => 'Stopped',
            'state' => 'EMERGENCY_STOP',
            'connection_status' => 'Disconnected',
            'current_signal' => 'EMERGENCY_STOP',
            'quantity' => 0,
            'position_side' => null,
        ]);
        $this->log($config->fresh('state'), 'EMERGENCY_STOP', $state->current_symbol, null, 0, 'All paper futures activity halted.');
        return $state->exists ? $state->fresh() : $state;
    }

    public function resetPaper(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->trades()->delete();
        $state = $this->state($config);
        $this->persistState($state, [
            'state' => 'IDLE',
            'status' => 'Stopped',
            'current_symbol' => null,
            'current_signal' => 'WAITING_FOR_SIGNAL',
            'entry_price' => null,
            'quantity' => 0,
            'position_side' => null,
            'peak_price' => null,
            'bottom_price' => null,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => 0,
            'daily_profit' => 0,
            'daily_loss' => 0,
            'trade_count_today' => 0,
            'wins' => 0,
            'losses' => 0,
            'daily_stop_until' => null,
            'trading_day' => now()->toDateString(),
            'scanner_snapshot' => [],
            'chart_points' => [],
        ]);
        $this->log($config->fresh('state'), 'PAPER_RESET', null, null, 0, 'Paper futures ledger reset.');
        return $state->fresh();
    }

    public function scan(FutureTradeBotConfig $config, bool $allowPaperExecution = true): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        $state = $this->refreshDailyCycleState($config, $state);
        $lockedForDay = $this->applyDailyLossLock($config, $state);
        $settings = $config->mergedSettings();
        $market = $this->marketSnapshot($settings);
        $signals = array_map(fn (array $ticker) => $this->scoreTicker($ticker, $settings), $market);
        usort($signals, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
        $best = $signals[0] ?? null;

        if (! $best) {
            return ['signals' => [], 'bestSignal' => null];
        }

        $dynamic = $this->dynamicDecision($config, $state, $signals, $best, $settings);
        $settings = $dynamic['settings'];
        $best['effectiveMinConfidence'] = $dynamic['effectiveMinConfidence'];
        $best['dynamicMode'] = $dynamic['mode'];

        $state->refresh();
        $inPosition = (float) $state->quantity > 0;

        if ($inPosition) {
            // When holding a position, track the price of THAT symbol, not the best signal's symbol
            $positionSymbol = (string) $state->current_symbol;
            $positionTicker = collect($market)->first(fn ($row) => (string) ($row['symbol'] ?? '') === $positionSymbol);
            $positionPrice = $positionTicker ? (float) ($positionTicker['lastPrice'] ?? $positionTicker['price'] ?? 0) : 0;

            if ($positionPrice > 0) {
                $chart = $this->appendChartPoint($state, $positionSymbol, $positionPrice, $dynamic['public'], (float) $state->confidence, (string) $state->current_signal);
                $this->persistState($state, [
                    'connection_status' => 'Connected',
                    'current_price' => $positionPrice,
                    'last_scan_at' => now(),
                    'scanner_snapshot' => array_slice($signals, 0, 12),
                    'chart_points' => $chart,
                ]);
                $this->manageOpenPosition($config->fresh('state'), $positionPrice);
            }
        } else {
            $chart = $this->appendChartPoint($state, $best['symbol'], (float) $best['price'], $dynamic['public'], (float) $best['confidence'], (string) $best['signal']);
            $this->persistState($state, [
                'connection_status' => 'Connected',
                'current_symbol' => $best['symbol'],
                'current_signal' => $best['signal'],
                'current_price' => $best['price'],
                'confidence' => $best['confidence'],
                'last_scan_at' => now(),
                'scanner_snapshot' => array_slice($signals, 0, 12),
                'chart_points' => $chart,
            ]);

            if (! $lockedForDay && $allowPaperExecution && $config->enabled && ($settings['autoExecutePaperSignals'] ?? true)) {
                $this->maybeEnterPaperPosition($config->fresh('state'), $best);
            }
        }

        return ['signals' => array_slice($signals, 0, 20), 'bestSignal' => $best];
    }

    public function statusPayload(FutureTradeBotConfig $config): array
    {
        $state = $this->state($config);
        $state = $this->refreshDailyCycleState($config, $state);
        $this->applyDailyLossLock($config, $state);
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $wins = (int) $state->wins;
        $losses = (int) $state->losses;
        $total = max(1, $wins + $losses);
        $accountSummary = $this->accountSummary($config);

        return [
            'botName' => 'Future Trade Bot',
            'status' => $state->status,
            'state' => $state->state,
            'mode' => $this->isLiveMode($config) ? (($settings['forceTestnet'] ?? true) ? 'Live Testnet Futures' : 'Live Futures') : 'Paper Futures',
            'exchange' => 'Binance Futures',
            'connectionStatus' => $state->connection_status,
            'currentSymbol' => $state->current_symbol,
            'currentSignal' => $state->current_signal,
            'currentPrice' => (float) ($state->current_price ?? 0),
            'confidence' => (float) $state->confidence,
            'positionSide' => $state->position_side,
            'entryPrice' => (float) ($state->entry_price ?? 0),
            'quantity' => (float) $state->quantity,
            'unrealizedPnl' => (float) $state->unrealized_pnl,
            'unrealizedPnlPercent' => (float) $state->unrealized_pnl_percent,
            'realizedPnl' => (float) $state->realized_pnl,
            'dailyProfit' => (float) $state->daily_profit,
            'dailyLoss' => (float) $state->daily_loss,
            'winRate' => round(($wins / $total) * 100, 2),
            'tradeCountToday' => (int) $state->trade_count_today,
            'lastScanAt' => optional($state->last_scan_at)->toIso8601String(),
            'settings' => $settings,
            'dynamic' => $dynamic['public'],
            'paperTrading' => ! $this->isLiveMode($config),
            'liveTrading' => $this->isLiveMode($config),
            'liveTradingEnvEnabled' => $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true)),
            'testnetTradingEnvEnabled' => (bool) config('future_trade.enable_testnet_trading'),
            'realTradingEnvEnabled' => (bool) config('future_trade.enable_live_trading'),
            'testnetCredentialsConfigured' => $this->hasFuturesCredentials(true),
            'realCredentialsConfigured' => $this->hasFuturesCredentials(false),
            'liveGuard' => $this->liveGuard->canActivate($config->workspace_id, 'future_trade'),
            'walletBalance' => $accountSummary['walletBalance'],
            'availableBalance' => $accountSummary['availableBalance'],
            'marginBalance' => $accountSummary['marginBalance'],
            'botProfit' => $accountSummary['botProfit'],
            'botLoss' => $accountSummary['botLoss'],
            'totalProfit' => $accountSummary['totalProfit'],
            'balanceSource' => $accountSummary['balanceSource'],
            'accountAsset' => $accountSummary['accountAsset'],
        ];
    }

    public function dashboardPayload(FutureTradeBotConfig $config): array
    {
        $state = $this->state($config);
        $state = $this->refreshDailyCycleState($config, $state);
        $this->applyDailyLossLock($config, $state);
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $bestSignal = $signals[0] ?? null;
        $dynamic = $this->dynamicDecision($config, $state, $signals, $bestSignal, $settings);
        $effectiveSettings = $dynamic['settings'];
        $entry = (float) ($state->entry_price ?? 0);
        $peak = (float) ($state->peak_price ?? 0);

        return [
            'status' => $this->statusPayload($config),
            'scanner' => $signals,
            'bestSignal' => $bestSignal,
            'chart' => $state->chart_points ?? [],
            'levels' => [
                'entry' => $entry ?: null,
	                'stopLoss' => $entry ? $this->stopLossPrice($entry, (string) $state->position_side, $effectiveSettings) : null,
	                'takeProfit' => $entry ? $this->takeProfitPrice($entry, (string) $state->position_side, $effectiveSettings) : null,
	                'trailing' => $entry && $peak ? $this->trailingPrice($peak, (string) $state->position_side, $effectiveSettings) : null,
	                'peak' => $peak ?: null,
	                'bottom' => (float) ($state->bottom_price ?? 0) ?: null,
	                'effectiveMinConfidence' => $dynamic['effectiveMinConfidence'],
	            ],
            'orderbook' => $this->syntheticOrderbook((float) ($state->current_price ?? 0)),
            'risk' => [
                'lossPrevention' => 'Stop loss, rollback, trailing profit, cooldown, max daily loss and max trades are active in paper mode.',
                'paperTrading' => ! $this->isLiveMode($config),
	                'liveTradingBlocked' => ! $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true)),
	            ],
	            'dynamic' => $dynamic['public'],
	        ];
	    }

    public function configPayload(FutureTradeBotConfig $config): array
    {
        return [
            'id' => $config->id,
            'settings' => $config->mergedSettings(),
            'algorithm_notes' => $config->algorithm_notes,
            'paper_trading' => $config->paper_trading,
            'enable_live_trading' => $config->enable_live_trading,
            'live_env_enabled' => $this->environmentTradingReady((bool) ($config->mergedSettings()['forceTestnet'] ?? true)),
            'testnet_env_enabled' => (bool) config('future_trade.enable_testnet_trading'),
            'real_env_enabled' => (bool) config('future_trade.enable_live_trading'),
            'testnet_credentials_configured' => $this->hasFuturesCredentials(true),
            'real_credentials_configured' => $this->hasFuturesCredentials(false),
            'available_symbols' => config('future_trade.available_symbols', []),
        ];
    }

    private function maybeEnterPaperPosition(FutureTradeBotConfig $config, array $signal): void
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), $signal, $settings);
        $settings = $dynamic['settings'];
        if ((string) $state->state === 'IN_POSITION' || (float) $state->quantity > 0) {
            return;
        }
        if (($settings['emergencyStop'] ?? false) || $signal['signal'] === 'WAIT') {
            return;
        }
        if ((float) $signal['confidence'] < (float) ($settings['minConfidence'] ?? 0.68)) {
            $this->log(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                'Confidence below dynamic threshold: needs ' . round((float) $settings['minConfidence'], 4),
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED'])
            );
            return;
        }
        if ($state->cooldown_until && $state->cooldown_until->isFuture()) {
            return;
        }
        if ((int) $state->trade_count_today >= (int) ($settings['maxTradesPerDay'] ?? 25)) {
            $this->log(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                'Max trades per day reached.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED'])
            );
            return;
        }
        if ((float) $state->daily_loss >= (float) ($settings['maxDailyLossPercent'] ?? 2)) {
            $this->log(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                'Daily loss guard active.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED'])
            );
            return;
        }

        if ($this->isLiveMode($config)) {
            $this->openLivePosition($config, $signal);
            return;
        }

        $side = $signal['signal'] === 'BUY' ? 'LONG' : 'SHORT';
        $price = (float) $signal['price'];
        $qty = ((float) ($settings['tradeAmountUsdt'] ?? 25) * (float) ($settings['leverage'] ?? 3)) / max($price, 0.00000001);
        $this->persistState($state, [
            'state' => 'IN_POSITION',
            'current_symbol' => $signal['symbol'],
            'position_side' => $side,
            'entry_price' => $price,
            'quantity' => $qty,
            'peak_price' => $price,
            'bottom_price' => $price,
            'current_signal' => $signal['signal'] . '_EXECUTED',
            'trade_count_today' => (int) $state->trade_count_today + 1,
            'status' => 'Running',
            'connection_status' => 'Connected',
        ]);

        $config->trades()->create([
            'symbol' => $signal['symbol'],
            'side' => $side,
            'entry_price' => $price,
            'quantity' => $qty,
            'leverage' => (float) ($settings['leverage'] ?? 3),
            'fees' => $price * $qty * 0.0004,
            'reason' => 'AUTO_' . $signal['signal'] . '_SIGNAL',
            'mode' => 'PAPER',
            'status' => 'OPEN',
            'meta' => ['signal' => $signal, 'dynamic' => $dynamic['public'], 'algorithm_mode' => $dynamic['mode'], 'idempotency_key' => (string) Str::uuid()],
        ]);
        $this->log(
            $config->fresh('state'),
            $signal['signal'] . '_EXECUTED',
            $signal['symbol'],
            $signal['signal'],
            $signal['confidence'],
            'Paper futures position opened.',
            $this->runtimeContext($dynamic, ['status' => 'OPEN', 'side' => $side, 'entryPrice' => $price, 'quantity' => $qty, 'mode' => 'PAPER'])
        );
    }

    private function manageOpenPosition(FutureTradeBotConfig $config, float $price): void
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $settings = $dynamic['settings'];
        $entry = (float) $state->entry_price;
        $qty = (float) $state->quantity;
        $side = (string) $state->position_side;
        if ($entry <= 0 || $qty <= 0 || ! in_array($side, ['LONG', 'SHORT'], true)) {
            return;
        }

        $direction = $side === 'LONG' ? 1 : -1;
        $gross = ($price - $entry) * $qty * $direction;
        $fees = $price * $qty * 0.0004;
        $net = $gross - $fees;
        $notional = $entry * $qty;
        $pct = $notional > 0 ? ($net / $notional) * 100 : 0;
        $peak = $side === 'LONG' ? max((float) ($state->peak_price ?? $entry), $price) : min((float) ($state->peak_price ?? $entry), $price);
        $bottom = $side === 'LONG' ? min((float) ($state->bottom_price ?? $entry), $price) : max((float) ($state->bottom_price ?? $entry), $price);

        $this->persistState($state, [
            'current_price' => $price,
            'peak_price' => $peak,
            'bottom_price' => $bottom,
            'unrealized_pnl' => $net,
            'unrealized_pnl_percent' => $pct,
        ]);

        $reason = null;
        if ($pct >= (float) ($settings['takeProfitPercent'] ?? 0.45)) {
            $reason = 'TAKE_PROFIT_HIT';
        } elseif ($pct <= -abs((float) ($settings['rollbackLossPercent'] ?? 0.18))) {
            $reason = 'LOSS_ROLLBACK_TRIGGERED';
        } elseif (($side === 'LONG' && $price <= $this->stopLossPrice($entry, $side, $settings)) || ($side === 'SHORT' && $price >= $this->stopLossPrice($entry, $side, $settings))) {
            $reason = 'STOP_LOSS_HIT';
        } elseif (($side === 'LONG' && $price <= $this->trailingPrice($peak, $side, $settings) && $peak > $entry) || ($side === 'SHORT' && $price >= $this->trailingPrice($peak, $side, $settings) && $peak < $entry)) {
            $reason = 'TRAILING_PROFIT_HIT';
        }

        if ($reason) {
            if ($this->isLiveMode($config)) {
                $this->closeLivePosition($config->fresh('state'), $price, $reason);
            } else {
                $this->closePaperPosition($config->fresh('state'), $price, $reason);
            }
        }
    }

    private function openLivePosition(FutureTradeBotConfig $config, array $signal): void
    {
        $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
        if (! $guard['allowed']) {
            $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', (string) $signal['symbol'], (string) $signal['signal'], (float) $signal['confidence'], $guard['reason']);
            return;
        }

        $settings = $config->mergedSettings();
        $state = $this->state($config);
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $settings = $dynamic['settings'];
        $client = $this->liveClient($settings);
        $symbol = strtoupper((string) $signal['symbol']);
        $side = $signal['signal'] === 'BUY' ? 'BUY' : 'SELL';
        $positionSide = $signal['signal'] === 'BUY' ? 'LONG' : 'SHORT';
        $price = (float) $signal['price'];
        $leverage = min((int) ($settings['leverage'] ?? 3), (int) ($settings['maxLeverage'] ?? 5));
        $filters = $this->symbolFilters($client->fetchExchangeInfo($symbol), $symbol);
        $qty = ((float) ($settings['tradeAmountUsdt'] ?? 25) * $leverage) / max($price, 0.00000001);
        $qty = $this->formatStep($qty, $filters['stepSize']);

        $client->setLeverage($symbol, $leverage);
        $client->setMarginType($symbol, (string) ($settings['marginMode'] ?? 'isolated'));
        $response = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $qty,
            'newClientOrderId' => substr('ft-entry-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);

        $entry = (float) ($response['avgPrice'] ?? 0) ?: $price;
        $stop = $this->stopLossPrice($entry, $positionSide, $settings);
        $take = $this->takeProfitPrice($entry, $positionSide, $settings);
        $exitSide = $side === 'BUY' ? 'SELL' : 'BUY';
        $stopResponse = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $exitSide,
            'type' => 'STOP_MARKET',
            'stopPrice' => $this->formatStep($stop, $filters['tickSize']),
            'quantity' => $qty,
            'reduceOnly' => 'true',
            'workingType' => 'MARK_PRICE',
            'newClientOrderId' => substr('ft-sl-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);
        $takeResponse = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $exitSide,
            'type' => 'TAKE_PROFIT_MARKET',
            'stopPrice' => $this->formatStep($take, $filters['tickSize']),
            'quantity' => $qty,
            'reduceOnly' => 'true',
            'workingType' => 'MARK_PRICE',
            'newClientOrderId' => substr('ft-tp-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);

        $state = $this->state($config);
        $this->persistState($state, [
            'state' => 'LIVE_IN_POSITION',
            'current_symbol' => $symbol,
            'position_side' => $positionSide,
            'entry_price' => $entry,
            'quantity' => (float) $qty,
            'peak_price' => $entry,
            'bottom_price' => $entry,
            'current_signal' => $signal['signal'] . '_LIVE_EXECUTED',
            'trade_count_today' => (int) $state->trade_count_today + 1,
            'status' => 'Running',
            'connection_status' => 'Connected',
        ]);

        $config->trades()->create([
            'symbol' => $symbol,
            'side' => $positionSide,
            'entry_price' => $entry,
            'quantity' => (float) $qty,
            'leverage' => $leverage,
            'fees' => $entry * (float) $qty * 0.0004,
            'reason' => 'LIVE_' . $signal['signal'] . '_SIGNAL',
            'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE',
            'status' => 'OPEN',
            'meta' => ['entry_order' => $response, 'stop_order' => $stopResponse, 'take_profit_order' => $takeResponse, 'signal' => $signal, 'dynamic' => $dynamic['public'], 'algorithm_mode' => $dynamic['mode']],
        ]);
        $this->log(
            $config->fresh('state'),
            'LIVE_' . $signal['signal'] . '_EXECUTED',
            $symbol,
            $signal['signal'],
            (float) $signal['confidence'],
            'Live futures entry submitted with protective reduce-only exits.',
            $this->runtimeContext($dynamic, ['status' => 'OPEN', 'side' => $positionSide, 'entryPrice' => $entry, 'quantity' => (float) $qty, 'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE'])
        );
    }

    private function closeLivePosition(FutureTradeBotConfig $config, float $price, string $reason): void
    {
        $state = $this->state($config);
        if ((float) $state->quantity <= 0 || ! $state->current_symbol || ! $state->position_side) {
            return;
        }

        $settings = $config->mergedSettings();
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $client = $this->liveClient($settings);
        $side = $state->position_side === 'LONG' ? 'SELL' : 'BUY';
        $filters = $this->symbolFilters($client->fetchExchangeInfo((string) $state->current_symbol), (string) $state->current_symbol);
        $qty = $this->formatStep((float) $state->quantity, $filters['stepSize']);
        $response = $client->placeOrder([
            'symbol' => (string) $state->current_symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $qty,
            'reduceOnly' => 'true',
            'newClientOrderId' => substr('ft-close-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);

        $direction = $state->position_side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $gross = ($price - $entry) * (float) $state->quantity * $direction;
        $fees = $price * (float) $state->quantity * 0.0004;
        $net = $gross - $fees;

        $config->trades()->create([
            'symbol' => (string) $state->current_symbol,
            'side' => 'LIVE_CLOSE_' . $state->position_side,
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => (float) $state->quantity,
            'leverage' => (float) ($settings['leverage'] ?? 3),
            'gross_pnl' => $gross,
            'fees' => $fees,
            'net_pnl' => $net,
            'reason' => $reason,
            'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE',
            'status' => 'SUBMITTED',
            'meta' => ['close_order' => $response, 'dynamic' => $dynamic['public'], 'algorithm_mode' => $dynamic['mode']],
        ]);

        $this->persistState($state, [
            'state' => 'COOLDOWN',
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) $state->realized_pnl + $net,
            'daily_profit' => $net > 0 ? (float) $state->daily_profit + $net : (float) $state->daily_profit,
            'daily_loss' => $net < 0 ? (float) $state->daily_loss + abs($net) : (float) $state->daily_loss,
            'wins' => $net > 0 ? (int) $state->wins + 1 : (int) $state->wins,
            'losses' => $net < 0 ? (int) $state->losses + 1 : (int) $state->losses,
            'cooldown_until' => now()->addSeconds((int) ($settings['cooldownSeconds'] ?? 10)),
            'current_signal' => $reason,
            'status' => 'Running',
            'connection_status' => 'Connected',
        ]);
        $this->log(
            $config->fresh('state'),
            'LIVE_' . $reason,
            $state->current_symbol,
            'CLOSE',
            1,
            'Live futures reduce-only close submitted.',
            $this->runtimeContext($dynamic, ['status' => $reason, 'side' => $state->position_side, 'entryPrice' => $entry, 'exitPrice' => $price, 'netPnl' => $net, 'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE'])
        );
        $freshConfig = $config->fresh('state');
        $this->applyDailyLossLock($freshConfig, $this->state($freshConfig));
    }

    private function closePaperPosition(FutureTradeBotConfig $config, float $price, string $reason): void
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $side = (string) $state->position_side;
        $direction = $side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $qty = (float) $state->quantity;
        $gross = ($price - $entry) * $qty * $direction;
        $fees = $price * $qty * 0.0004;
        $net = $gross - $fees;

        $config->trades()->create([
            'symbol' => (string) $state->current_symbol,
            'side' => 'CLOSE_' . $side,
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => $qty,
	            'leverage' => (float) ($dynamic['settings']['leverage'] ?? $settings['leverage'] ?? 3),
            'gross_pnl' => $gross,
            'fees' => $fees,
            'net_pnl' => $net,
            'reason' => $reason,
            'mode' => 'PAPER',
	            'status' => 'FILLED',
	            'meta' => ['dynamic' => $dynamic['public'], 'algorithm_mode' => $dynamic['mode']],
	        ]);

        $this->persistState($state, [
            'state' => 'COOLDOWN',
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) $state->realized_pnl + $net,
            'daily_profit' => $net > 0 ? (float) $state->daily_profit + $net : (float) $state->daily_profit,
            'daily_loss' => $net < 0 ? (float) $state->daily_loss + abs($net) : (float) $state->daily_loss,
            'wins' => $net > 0 ? (int) $state->wins + 1 : (int) $state->wins,
            'losses' => $net < 0 ? (int) $state->losses + 1 : (int) $state->losses,
            'cooldown_until' => now()->addSeconds((int) ($dynamic['settings']['cooldownSeconds'] ?? $settings['cooldownSeconds'] ?? 10)),
            'current_signal' => $reason,
            'status' => 'Running',
            'connection_status' => 'Connected',
        ]);
        $this->log(
            $config->fresh('state'),
            $reason,
            $state->current_symbol,
            'CLOSE_' . $side,
            1,
            'Paper futures position closed.',
            $this->runtimeContext($dynamic, ['status' => $reason, 'side' => $side, 'entryPrice' => $entry, 'exitPrice' => $price, 'netPnl' => $net, 'mode' => 'PAPER'])
        );
        $freshConfig = $config->fresh('state');
        $this->applyDailyLossLock($freshConfig, $this->state($freshConfig));
    }

    private function scoreTicker(array $ticker, array $settings): array
    {
        $price      = (float) ($ticker['lastPrice'] ?? $ticker['price'] ?? 0);
        $change     = (float) ($ticker['priceChangePercent'] ?? 0);
        $quoteVolume = (float) ($ticker['quoteVolume'] ?? 0);
        $high       = (float) ($ticker['highPrice'] ?? $price);
        $low        = (float) ($ticker['lowPrice'] ?? $price);
        $vwap       = (float) ($ticker['weightedAvgPrice'] ?? 0);
        $openPrice  = (float) ($ticker['openPrice'] ?? $price);
        $sequence   = (array) ($ticker['sequence'] ?? []);

        $rangePct   = $price > 0 ? (($high - $low) / $price) * 100 : 0;
        $sequenceNetChange = (float) ($sequence['netChangePercent'] ?? 0);
        $sequenceBullishRatio = (float) ($sequence['bullishRatio'] ?? 0);
        $sequenceBearishRatio = (float) ($sequence['bearishRatio'] ?? 0);
        $sequenceConsistency = (float) ($sequence['consistency'] ?? 0);
        $sequenceVolatility = (float) ($sequence['volatilityPercent'] ?? 0);
        $sequenceImpulse = (string) ($sequence['impulse'] ?? 'NEUTRAL');

        // --- Signal Direction (replaces raw 24h change direction) ---
        // Use VWAP cross: price above VWAP = recent buying pressure (bullish)
        $vwapBullish = $vwap > 0 && $price > $vwap;

        // Price position within 24h range: 0 = at low, 1 = at high
        $rangePosition = ($high > $low) ? (($price - $low) / ($high - $low)) : 0.5;

        // Short-term momentum: current price vs open price (intraday direction)
        $intradayChangePct = $openPrice > 0 ? (($price - $openPrice) / $openPrice) * 100 : $change;
        $bullishVotes = 0.0;
        $bearishVotes = 0.0;

        // Signal requires BOTH VWAP confirmation AND price in upper/lower zone
        if ($vwapBullish && $rangePosition >= 0.45 && $intradayChangePct >= 0) {
            $rawSignal = 'BUY';
        } elseif (! $vwapBullish && $rangePosition <= 0.55 && $intradayChangePct <= 0) {
            $rawSignal = 'SELL';
        } else {
            // Conflicting signals — lower confidence, use 24h change as tiebreak
            $rawSignal = $change >= 0 ? 'BUY' : 'SELL';
        }

        // --- Confidence Scoring (no hardcoded base) ---
        // Volume quality: how significant is the market activity
        $volumeScore = min(0.30, log10(max($quoteVolume, 1)) / 35);

        // Momentum strength: intraday change magnitude (not raw 24h)
        $momentumScore = min(0.35, abs($intradayChangePct) / 10);

        // Range quality: moderate range preferred — too tight = no opportunity, too wide = choppy
        $rangeScore = $rangePct >= 0.5 && $rangePct <= 6
            ? min(0.20, $rangePct / 15)
            : ($rangePct > 6 ? max(0.0, 0.20 - ($rangePct - 6) * 0.02) : 0.05);

        // VWAP alignment bonus
        $alignmentBonus = ($vwapBullish && $rawSignal === 'BUY') || (! $vwapBullish && $rawSignal === 'SELL') ? 0.12 : 0.0;

        // Direction consistency: intraday and 24h change pointing same way
        $consistencyBonus = (($rawSignal === 'BUY' && $change >= 0 && $intradayChangePct >= 0) ||
                             ($rawSignal === 'SELL' && $change <= 0 && $intradayChangePct <= 0)) ? 0.08 : 0.0;

        $confidence = round(min(0.99, $volumeScore + $momentumScore + $rangeScore + $alignmentBonus + $consistencyBonus), 4);

        // Choppy: large range, tiny net move — avoid regardless
        $choppy  = $rangePct > 9 && abs($change) < 1.5;
        $signal  = $choppy && ($settings['avoidChoppyMarket'] ?? true) ? 'WAIT' : $rawSignal;

        if ($vwapBullish) {
            $bullishVotes += 1.0;
        } else {
            $bearishVotes += 1.0;
        }

        if ($intradayChangePct >= 0.10) {
            $bullishVotes += 1.1;
        } elseif ($intradayChangePct <= -0.10) {
            $bearishVotes += 1.1;
        }

        if ($change >= 0.25) {
            $bullishVotes += 0.8;
        } elseif ($change <= -0.25) {
            $bearishVotes += 0.8;
        }

        if ($rangePosition >= 0.60) {
            $bullishVotes += 0.7;
        } elseif ($rangePosition <= 0.40) {
            $bearishVotes += 0.7;
        }

        if ($sequenceNetChange >= 0.12) {
            $bullishVotes += 1.6;
        } elseif ($sequenceNetChange <= -0.12) {
            $bearishVotes += 1.6;
        }

        if ($sequenceBullishRatio >= 0.58) {
            $bullishVotes += 1.0;
        }
        if ($sequenceBearishRatio >= 0.58) {
            $bearishVotes += 1.0;
        }

        if ($sequenceImpulse === 'BUY') {
            $bullishVotes += 0.9;
        } elseif ($sequenceImpulse === 'SELL') {
            $bearishVotes += 0.9;
        }

        $voteGap = abs($bullishVotes - $bearishVotes);
        $dominantSignal = $bullishVotes > $bearishVotes ? 'BUY' : ($bearishVotes > $bullishVotes ? 'SELL' : 'WAIT');
        $strongDirectionalSequence = $sequenceConsistency >= 0.55 && abs($sequenceNetChange) >= 0.12;
        $conflicted = $voteGap < 1.2
            || ($dominantSignal === 'BUY' && ($sequenceNetChange < -0.08 || $sequenceImpulse === 'SELL'))
            || ($dominantSignal === 'SELL' && ($sequenceNetChange > 0.08 || $sequenceImpulse === 'BUY'));
        $microChoppy = $sequenceVolatility > 0.22 && abs($sequenceNetChange) < 0.10;
        $choppy = $choppy || $microChoppy;

        $signal = $dominantSignal;
        if (($settings['avoidChoppyMarket'] ?? true) && ($choppy || $conflicted || ! $strongDirectionalSequence)) {
            $signal = 'WAIT';
        }

        $volumeScore = min(0.20, log10(max($quoteVolume, 1)) / 50);
        $intradayScore = min(0.18, abs($intradayChangePct) / 5);
        $sequenceScore = min(0.22, abs($sequenceNetChange) / 1.2);
        $consistencyScore = min(0.18, $sequenceConsistency * 0.18);
        $rangeScore = $rangePct >= 0.45 && $rangePct <= 5.5
            ? min(0.12, $rangePct / 25)
            : ($rangePct > 5.5 ? max(0.02, 0.12 - (($rangePct - 5.5) * 0.02)) : 0.03);
        $directionalBonus = $strongDirectionalSequence ? 0.12 : 0.04;
        $alignmentBonus = ($signal === 'BUY' && $vwapBullish && $sequenceNetChange > 0 && $intradayChangePct > 0)
            || ($signal === 'SELL' && ! $vwapBullish && $sequenceNetChange < 0 && $intradayChangePct < 0)
            ? 0.12
            : 0.0;
        $penalty = 0.0;
        if ($conflicted) {
            $penalty += 0.18;
        }
        if ($choppy) {
            $penalty += 0.16;
        }
        if (! $strongDirectionalSequence) {
            $penalty += 0.12;
        }

        $confidence = round($this->clamp($volumeScore + $intradayScore + $sequenceScore + $consistencyScore + $rangeScore + $directionalBonus + $alignmentBonus - $penalty, 0.01, 0.99), 4);

        $reasonParts = [];
        if ($signal === 'WAIT') {
            if ($choppy) {
                $reasonParts[] = 'Choppy futures market avoided';
            }
            if ($conflicted ?? false) {
                $reasonParts[] = 'Direction conflict between ticker and 10m candle sequence';
            }
            if (! ($strongDirectionalSequence ?? false)) {
                $reasonParts[] = '10m sequence not strong enough for entry';
            }
        } else {
            $reasonParts[] = $vwapBullish ? 'Price above VWAP (bullish)' : 'Price below VWAP (bearish)';
            $reasonParts[] = sprintf('10m sequence %.2f%%', $sequenceNetChange);
            $reasonParts[] = sprintf('Sequence consistency %.0f%%', $sequenceConsistency * 100);
            $reasonParts[] = sprintf('Range position %.0f%%', $rangePosition * 100);
            $reasonParts[] = sprintf('Intraday %.2f%%', $intradayChangePct);
        }

        return [
            'symbol'          => (string) ($ticker['symbol'] ?? 'BTCUSDT'),
            'signal'          => $signal,
            'price'           => $price,
            'changePercent'   => round($change, 4),
            'intradayChange'  => round($intradayChangePct, 4),
            'volume'          => $quoteVolume,
            'rangePercent'    => round($rangePct, 4),
            'rangePosition'   => round($rangePosition, 4),
            'vwapBullish'     => $vwapBullish,
            'sequence'        => $sequence,
            'confidence'      => $confidence,
            'timeframe'       => $settings['timeframe'] ?? '5m',
            'reason'          => implode(' | ', $reasonParts),
        ];
    }

    private function marketSnapshot(array $settings): array
    {
        $symbols = array_map('strtoupper', (array) ($settings['symbols'] ?? []));
        $cacheKey = 'future_trade_market_snapshot_' . md5(implode(',', $symbols));
        try {
            $base = rtrim((string) env('BINANCE_FUTURES_API_URL', 'https://fapi.binance.com'), '/');
            $rows = Http::timeout(7)->get($base . '/fapi/v1/ticker/24hr')->throw()->json();
            $sequenceSnapshots = $this->sequenceSnapshots($symbols, $settings, $base);
            $result = collect($rows)
                ->filter(fn ($row) => in_array((string) ($row['symbol'] ?? ''), $symbols, true))
                ->map(function (array $row) use ($sequenceSnapshots) {
                    $symbol = (string) ($row['symbol'] ?? '');
                    $row['sequence'] = $sequenceSnapshots[$symbol] ?? $this->emptySequenceSnapshot();

                    return $row;
                })
                ->values()
                ->all();
            if (! empty($result)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 60);
            }
            return $result;
        } catch (Throwable) {
            // Return last known good prices rather than fake mock data
            return \Illuminate\Support\Facades\Cache::get($cacheKey, []);
        }
    }

    private function sequenceSnapshots(array $symbols, array $settings, string $base): array
    {
        $lookbackMinutes = max(6, min(15, (int) ($settings['dynamicLookbackMinutes'] ?? 10)));
        $limit = max(10, $lookbackMinutes + 2);
        $cacheTtlSeconds = max(15, min(45, (int) ($settings['scanIntervalSeconds'] ?? 3) * 8));
        $snapshots = [];
        $missing = [];

        foreach ($symbols as $symbol) {
            $cacheKey = 'future_trade_sequence_' . md5($symbol . '|' . $limit);
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);

            if (is_array($cached)) {
                $snapshots[$symbol] = $cached;
            } else {
                $missing[$symbol] = $cacheKey;
            }
        }

        if ($missing === []) {
            return $snapshots;
        }

        $responses = Http::pool(function (Pool $pool) use ($missing, $base, $limit) {
            $requests = [];
            foreach (array_keys($missing) as $symbol) {
                $requests[$symbol] = $pool
                    ->as($symbol)
                    ->timeout(5)
                    ->get($base . '/fapi/v1/klines', [
                        'symbol' => $symbol,
                        'interval' => '1m',
                        'limit' => $limit,
                    ]);
            }

            return $requests;
        });

        foreach ($missing as $symbol => $cacheKey) {
            try {
                $payload = $responses[$symbol]?->successful() ? $responses[$symbol]->json() : [];
                $sequence = $this->buildSequenceSnapshot(is_array($payload) ? $payload : []);
                $snapshots[$symbol] = $sequence;
                \Illuminate\Support\Facades\Cache::put($cacheKey, $sequence, $cacheTtlSeconds);
            } catch (Throwable) {
                $snapshots[$symbol] = $this->emptySequenceSnapshot();
            }
        }

        return $snapshots;
    }

    private function buildSequenceSnapshot(array $candles): array
    {
        if (count($candles) < 3) {
            return $this->emptySequenceSnapshot();
        }

        $opens = [];
        $closes = [];

        foreach ($candles as $row) {
            if (! is_array($row) || count($row) < 5) {
                continue;
            }

            $open = (float) ($row[1] ?? 0);
            $close = (float) ($row[4] ?? 0);

            if ($open <= 0 || $close <= 0) {
                continue;
            }

            $opens[] = $open;
            $closes[] = $close;
        }

        if (count($closes) < 3) {
            return $this->emptySequenceSnapshot();
        }

        $first = $closes[0];
        $last = $closes[count($closes) - 1];
        $netChange = $first > 0 ? (($last - $first) / $first) * 100 : 0.0;
        $upCandles = 0;
        $downCandles = 0;
        $moves = [];

        for ($i = 0; $i < count($closes); $i++) {
            $open = $opens[$i] ?? $closes[$i];
            if ($closes[$i] > $open) {
                $upCandles++;
            } elseif ($closes[$i] < $open) {
                $downCandles++;
            }

            if ($i > 0 && $closes[$i - 1] > 0) {
                $moves[] = abs((($closes[$i] - $closes[$i - 1]) / $closes[$i - 1]) * 100);
            }
        }

        $directionalCandles = max(1, $upCandles + $downCandles);
        $bullishRatio = $upCandles / $directionalCandles;
        $bearishRatio = $downCandles / $directionalCandles;
        $consistency = max($bullishRatio, $bearishRatio);
        $volatility = count($moves) ? array_sum($moves) / count($moves) : 0.0;

        $recent = array_slice($closes, -3);
        $impulse = 'NEUTRAL';
        if (count($recent) === 3) {
            if ($recent[2] > $recent[1] && $recent[1] > $recent[0]) {
                $impulse = 'BUY';
            } elseif ($recent[2] < $recent[1] && $recent[1] < $recent[0]) {
                $impulse = 'SELL';
            }
        }

        return [
            'netChangePercent' => round($netChange, 4),
            'bullishRatio' => round($bullishRatio, 4),
            'bearishRatio' => round($bearishRatio, 4),
            'consistency' => round($consistency, 4),
            'volatilityPercent' => round($volatility, 4),
            'impulse' => $impulse,
        ];
    }

    private function emptySequenceSnapshot(): array
    {
        return [
            'netChangePercent' => 0.0,
            'bullishRatio' => 0.0,
            'bearishRatio' => 0.0,
            'consistency' => 0.0,
            'volatilityPercent' => 0.0,
            'impulse' => 'NEUTRAL',
        ];
    }

    private function dynamicDecision(FutureTradeBotConfig $config, FutureTradeBotState $state, array $signals, ?array $best, array $settings): array
    {
        $mode = strtolower((string) ($settings['confidenceMode'] ?? 'fixed'));
        if (! in_array($mode, ['fixed', 'semi_dynamic', 'fully_dynamic'], true)) {
            $mode = 'fixed';
        }

        $base = (float) ($settings['minConfidence'] ?? 0.72);
        $floor = (float) ($settings['dynamicMinConfidenceFloor'] ?? 0.68);
        $ceiling = (float) ($settings['dynamicMinConfidenceCeiling'] ?? 0.88);
        if ($floor > $ceiling) {
            [$floor, $ceiling] = [$ceiling, $floor];
        }

        $analysis = $this->trendAnalysis($state, $signals, $best, $settings);
        $effective = $base;
        $adjustments = [];
        $next = $settings;

        if ($mode !== 'fixed') {
            if ($analysis['choppy']) {
                $effective += $mode === 'fully_dynamic' ? 0.06 : 0.04;
                $next['takeProfitPercent'] = max(0.05, (float) ($settings['takeProfitPercent'] ?? 0.45) * ($mode === 'fully_dynamic' ? 0.82 : 0.90));
                $next['trailingTakeProfitPercent'] = max(0.03, (float) ($settings['trailingTakeProfitPercent'] ?? 0.18) * 0.85);
                $adjustments[] = 'Choppy market: require stronger confidence and take faster profit.';
            } elseif ($analysis['trendStrength'] >= 0.68 && $analysis['directionAgreement'] >= 0.58) {
                $effective -= $mode === 'fully_dynamic' ? 0.035 : 0.02;
                $next['takeProfitPercent'] = (float) ($settings['takeProfitPercent'] ?? 0.45) * ($mode === 'fully_dynamic' ? 1.20 : 1.10);
                $next['trailingTakeProfitPercent'] = (float) ($settings['trailingTakeProfitPercent'] ?? 0.18) * ($mode === 'fully_dynamic' ? 1.08 : 1.03);
                $adjustments[] = 'Strong aligned trend: allow earlier entry and let target breathe.';
            } else {
                $effective += $mode === 'fully_dynamic' ? 0.015 : 0.01;
                $adjustments[] = 'Mixed signal: keep entry slightly stricter.';
            }

            if ($analysis['confidenceGap'] < 0.025) {
                $effective += $mode === 'fully_dynamic' ? 0.025 : 0.015;
                $adjustments[] = 'Top signals are close together: avoid weak ranking edge.';
            }

            if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                $effective += $mode === 'fully_dynamic' ? 0.045 : 0.03;
                $next['cooldownSeconds'] = max((int) ($settings['cooldownSeconds'] ?? 60), $mode === 'fully_dynamic' ? 120 : 90);
                $adjustments[] = 'Recent loss: raise threshold and extend cooldown.';
            } elseif ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'TAKE_PROFIT')) {
                $effective += (float) ($settings['dynamicReentryBuffer'] ?? 0.02);
                $adjustments[] = 'Recent take profit: require a re-entry buffer to avoid chasing.';
            }

            if ($mode === 'fully_dynamic') {
                $winRate = $analysis['winRate'];
                if ($winRate > 0 && $winRate < 45) {
                    $effective += 0.03;
                    $next['tradeAmountUsdt'] = max(5, (float) ($settings['tradeAmountUsdt'] ?? 25) * 0.75);
                    $adjustments[] = 'Low current win rate: reduce size and demand cleaner entries.';
                } elseif ($winRate >= 65 && $analysis['trendStrength'] >= 0.55) {
                    $effective -= 0.015;
                    $adjustments[] = 'Good win rate with trend support: modestly loosen entry.';
                }
            }
        } else {
            $adjustments[] = 'Fixed mode: using the manual minConfidence without automatic adjustment.';
        }

        $effective = $this->clamp($effective, $floor, $ceiling);
        $next['minConfidence'] = $effective;
        $next['takeProfitPercent'] = $this->clamp((float) ($next['takeProfitPercent'] ?? $settings['takeProfitPercent'] ?? 0.45), 0.05, max(0.06, (float) ($settings['takeProfitPercent'] ?? 0.45) * 1.6));
        $next['stopLossPercent'] = $this->clamp((float) ($next['stopLossPercent'] ?? $settings['stopLossPercent'] ?? 0.25), 0.05, max(0.06, (float) ($settings['stopLossPercent'] ?? 0.25) * 1.35));
        $next['trailingTakeProfitPercent'] = $this->clamp((float) ($next['trailingTakeProfitPercent'] ?? $settings['trailingTakeProfitPercent'] ?? 0.18), 0.03, max(0.04, (float) ($settings['trailingTakeProfitPercent'] ?? 0.18) * 1.4));

        $suggestion = $this->dynamicSuggestionText($mode, $analysis, $base, $next, $adjustments);

        return [
            'mode' => $mode,
            'settings' => $next,
            'effectiveMinConfidence' => round($effective, 4),
            'public' => [
                'mode' => $mode,
                'baseMinConfidence' => round($base, 4),
                'effectiveMinConfidence' => round($effective, 4),
                'recommended' => [
                    'minConfidence' => round($effective, 4),
                    'takeProfitPercent' => round((float) ($next['takeProfitPercent'] ?? 0), 4),
                    'stopLossPercent' => round((float) ($next['stopLossPercent'] ?? 0), 4),
                    'trailingTakeProfitPercent' => round((float) ($next['trailingTakeProfitPercent'] ?? 0), 4),
                    'cooldownSeconds' => (int) ($next['cooldownSeconds'] ?? $settings['cooldownSeconds'] ?? 60),
                    'tradeAmountUsdt' => round((float) ($next['tradeAmountUsdt'] ?? $settings['tradeAmountUsdt'] ?? 0), 4),
                ],
                'analysis' => $analysis,
                'adjustments' => $adjustments,
                'suggestion' => $suggestion,
            ],
        ];
    }

    private function trendAnalysis(FutureTradeBotState $state, array $signals, ?array $best, array $settings): array
    {
        $scanInterval = max(1, (int) ($settings['scanIntervalSeconds'] ?? 3));
        $lookbackMinutes = max(1, min(10, (int) ($settings['dynamicLookbackMinutes'] ?? 10)));
        $limit = max(8, min(120, (int) ceil(($lookbackMinutes * 60) / $scanInterval)));
        $points = array_slice((array) ($state->chart_points ?? []), -$limit);
        $prices = array_values(array_filter(array_map(fn ($row) => (float) ($row['price'] ?? 0), $points), fn ($value) => $value > 0));
        $first = $prices[0] ?? 0;
        $last = $prices[count($prices) - 1] ?? 0;
        $priceChangePct = $first > 0 ? (($last - $first) / $first) * 100 : 0.0;
        $moves = [];
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i - 1] > 0) {
                $moves[] = abs((($prices[$i] - $prices[$i - 1]) / $prices[$i - 1]) * 100);
            }
        }
        $volatility = count($moves) ? array_sum($moves) / count($moves) : 0.0;
        $direction = $priceChangePct >= 0 ? 'BUY' : 'SELL';
        $usableSignals = array_values(array_filter($signals, fn ($signal) => isset($signal['signal']) && $signal['signal'] !== 'WAIT'));
        $agreement = count($usableSignals)
            ? count(array_filter($usableSignals, fn ($signal) => ($signal['signal'] ?? '') === ($best['signal'] ?? $direction))) / count($usableSignals)
            : 0.0;
        $bestConfidence = (float) ($best['confidence'] ?? ($signals[0]['confidence'] ?? 0));
        $secondConfidence = (float) ($signals[1]['confidence'] ?? 0);
        $gap = max(0.0, $bestConfidence - $secondConfidence);
        $range = (float) ($best['rangePercent'] ?? 0);
        $choppy = ($range > 7.5 && abs($priceChangePct) < 0.35) || ($volatility > 0.18 && abs($priceChangePct) < 0.18);
        $trendStrength = $this->clamp((abs($priceChangePct) / 1.2) + ($agreement * 0.35) + min(0.25, $gap * 2), 0, 1);
        $stateSignal = (string) ($state->current_signal ?? '');
        $stateExitReasons = ['TAKE_PROFIT_HIT', 'TRAILING_PROFIT_HIT', 'STOP_LOSS_HIT', 'LOSS_ROLLBACK_TRIGGERED'];
        $stateReason = in_array($stateSignal, $stateExitReasons, true) ? $stateSignal : null;
        $stateConfig = $state->relationLoaded('config') ? $state->getRelation('config') : null;
        $lastTrade = (! $stateReason && $stateConfig) ? $stateConfig->trades()->where('status', 'FILLED')->orderByDesc('created_at')->first() : null;
        $wins = (int) $state->wins;
        $losses = (int) $state->losses;
        $winRate = ($wins + $losses) > 0 ? round(($wins / ($wins + $losses)) * 100, 2) : 0.0;

        return [
            'lookbackMinutes' => $lookbackMinutes,
            'sampleCount' => count($prices),
            'priceChangePercent' => round($priceChangePct, 4),
            'volatilityPercent' => round($volatility, 4),
            'direction' => $direction,
            'directionAgreement' => round($agreement, 4),
            'confidenceGap' => round($gap, 4),
            'trendStrength' => round($trendStrength, 4),
            'choppy' => $choppy,
            'bestConfidence' => round($bestConfidence, 4),
            'lastTradeReason' => $stateReason ?: $lastTrade?->reason,
            'winRate' => $winRate,
        ];
    }

    private function dynamicSuggestionText(string $mode, array $analysis, float $base, array $settings, array $adjustments): string
    {
        $modeLabel = str_replace('_', ' ', $mode);
        $bias = $analysis['choppy'] ? 'market is choppy' : 'market bias is ' . $analysis['direction'];
        $lines = [
            sprintf('%s mode: %s over the last %d minute window.', ucfirst($modeLabel), $bias, (int) $analysis['lookbackMinutes']),
            sprintf('Base minConfidence %.2f, effective %.2f, trend strength %.0f%%, agreement %.0f%%.', $base, (float) ($settings['minConfidence'] ?? $base), $analysis['trendStrength'] * 100, $analysis['directionAgreement'] * 100),
            sprintf('Recommended values: TP %.2f%%, SL %.2f%%, trailing %.2f%%, cooldown %ds.', (float) ($settings['takeProfitPercent'] ?? 0), (float) ($settings['stopLossPercent'] ?? 0), (float) ($settings['trailingTakeProfitPercent'] ?? 0), (int) ($settings['cooldownSeconds'] ?? 0)),
        ];

        if ($adjustments) {
            $lines[] = implode(' ', array_slice($adjustments, 0, 3));
        }

        return implode(' ', $lines);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function syntheticOrderbook(float $price): array
    {
        if ($price <= 0) {
            return ['bids' => [], 'asks' => []];
        }

        $bids = [];
        $asks = [];
        for ($i = 1; $i <= 8; $i++) {
            $bids[] = ['price' => round($price * (1 - ($i * 0.00015)), 8), 'qty' => round(10 / $i, 4)];
            $asks[] = ['price' => round($price * (1 + ($i * 0.00015)), 8), 'qty' => round(9 / $i, 4)];
        }

        return ['bids' => $bids, 'asks' => $asks];
    }

    private function runtimeContext(?array $dynamic, array $extra = []): array
    {
        $public = (array) ($dynamic['public'] ?? $dynamic ?? []);
        $recommended = (array) ($public['recommended'] ?? []);
        $analysis = (array) ($public['analysis'] ?? []);

        return array_filter(array_merge([
            'algorithmMode' => $public['mode'] ?? null,
            'algorithmFlag' => isset($public['mode']) ? $this->algorithmFlag((string) $public['mode']) : null,
            'effectiveMinConfidence' => $public['effectiveMinConfidence'] ?? null,
            'activeTakeProfitPercent' => $recommended['takeProfitPercent'] ?? null,
            'activeStopLossPercent' => $recommended['stopLossPercent'] ?? null,
            'activeTrailingTakeProfitPercent' => $recommended['trailingTakeProfitPercent'] ?? null,
            'activeCooldownSeconds' => $recommended['cooldownSeconds'] ?? null,
            'activeTradeAmountUsdt' => $recommended['tradeAmountUsdt'] ?? null,
            'trendStrength' => $analysis['trendStrength'] ?? null,
            'directionAgreement' => $analysis['directionAgreement'] ?? null,
            'choppy' => $analysis['choppy'] ?? null,
        ], $extra), static fn ($value) => $value !== null);
    }

    private function appendChartPoint(FutureTradeBotState $state, string $symbol, float $price, ?array $dynamic = null, ?float $confidence = null, ?string $signal = null): array
    {
        $points = (array) ($state->chart_points ?? []);
        $recommended = (array) ($dynamic['recommended'] ?? []);
        $analysis = (array) ($dynamic['analysis'] ?? []);
        $points[] = [
            'time' => now()->format('H:i:s'),
            'symbol' => $symbol,
            'signal' => $signal,
            'price' => $price,
            'pnl' => (float) $state->unrealized_pnl_percent,
            'confidence' => $confidence,
            'algorithmMode' => $dynamic['mode'] ?? 'fixed',
            'algorithmFlag' => $this->algorithmFlag((string) ($dynamic['mode'] ?? 'fixed')),
            'effectiveMinConfidence' => $dynamic['effectiveMinConfidence'] ?? null,
            'activeTakeProfitPercent' => $recommended['takeProfitPercent'] ?? null,
            'activeStopLossPercent' => $recommended['stopLossPercent'] ?? null,
            'activeTrailingTakeProfitPercent' => $recommended['trailingTakeProfitPercent'] ?? null,
            'activeCooldownSeconds' => $recommended['cooldownSeconds'] ?? null,
            'activeTradeAmountUsdt' => $recommended['tradeAmountUsdt'] ?? null,
            'trendStrength' => $analysis['trendStrength'] ?? null,
            'directionAgreement' => $analysis['directionAgreement'] ?? null,
            'choppy' => $analysis['choppy'] ?? null,
            'decisionStatus' => $confidence !== null && isset($dynamic['effectiveMinConfidence'])
                ? ($confidence >= (float) $dynamic['effectiveMinConfidence'] ? 'ELIGIBLE' : 'BELOW_CONFIDENCE')
                : 'TRACKING',
        ];
        return array_slice($points, -120);
    }

    private function algorithmFlag(string $mode): string
    {
        return match ($mode) {
            'semi_dynamic' => 'SEMI',
            'fully_dynamic' => 'AUTO',
            default => 'NORMAL',
        };
    }

    private function refreshDailyCycleState(FutureTradeBotConfig $config, FutureTradeBotState $state): FutureTradeBotState
    {
        $today = now()->toDateString();
        $storedDay = $state->trading_day?->toDateString();

        if ($storedDay === $today) {
            return $state;
        }

        $wasLocked = $state->daily_stop_until !== null;
        $status = $config->enabled ? 'Running' : 'Stopped';
        $phase = $config->enabled ? 'SCANNING' : 'IDLE';

        $this->persistState($state, [
            'trading_day' => $today,
            'daily_stop_until' => null,
            'daily_profit' => 0,
            'daily_loss' => 0,
            'trade_count_today' => 0,
            'status' => $status,
            'state' => $phase,
            'connection_status' => $config->enabled ? 'Connected' : 'Disconnected',
        ]);

        if ($wasLocked && $config->enabled && $config->exists) {
            $this->log($config->fresh('state'), 'DAILY_LIMIT_RESET', $state->current_symbol, null, 0, 'New trading day started. Future Trade resumed automatically.');
        }

        return $state->exists ? $state->fresh() : $state;
    }

    private function applyDailyLossLock(FutureTradeBotConfig $config, FutureTradeBotState $state): bool
    {
        $settings = $config->mergedSettings();
        $limit = max(0.0, (float) ($settings['maxDailyLossPercent'] ?? 0));

        if ($limit <= 0 || (float) $state->quantity > 0) {
            return false;
        }

        if ((float) $state->daily_loss < $limit) {
            return false;
        }

        $alreadyLocked = $state->daily_stop_until && $state->daily_stop_until->isFuture();
        $lockUntil = now()->endOfDay();

        $this->persistState($state, [
            'status' => 'Stopped',
            'state' => 'DAILY_LOSS_LOCKED',
            'connection_status' => 'Daily loss limit reached',
            'daily_stop_until' => $lockUntil,
            'current_signal' => 'DAILY_LOSS_LIMIT_HIT',
        ]);

        if (! $alreadyLocked && $config->exists) {
            $this->log($config->fresh('state'), 'DAILY_LOSS_LIMIT_HIT', $state->current_symbol, null, 0, 'Daily loss limit reached. Bot locked until next trading day.');
        }

        return true;
    }

    private function persistState(FutureTradeBotState $state, array $attributes): void
    {
        if ($state->exists) {
            $state->update($attributes);
            return;
        }

        $state->forceFill($attributes);
    }

    private function stopLossPrice(float $entry, string $side, array $settings): float
    {
        $pct = (float) ($settings['stopLossPercent'] ?? 0.25) / 100;
        return $side === 'SHORT' ? $entry * (1 + $pct) : $entry * (1 - $pct);
    }

    private function takeProfitPrice(float $entry, string $side, array $settings): float
    {
        $pct = (float) ($settings['takeProfitPercent'] ?? 0.45) / 100;
        return $side === 'SHORT' ? $entry * (1 - $pct) : $entry * (1 + $pct);
    }

    private function trailingPrice(float $peak, string $side, array $settings): float
    {
        $pct = (float) ($settings['trailingTakeProfitPercent'] ?? 0.18) / 100;
        return $side === 'SHORT' ? $peak * (1 + $pct) : $peak * (1 - $pct);
    }

    private function state(FutureTradeBotConfig $config): FutureTradeBotState
    {
        return $config->state ?: $config->state()->create();
    }

    private function accountSummary(FutureTradeBotConfig $config): array
    {
        $trades = $config->trades()
            ->whereNotNull('net_pnl')
            ->get(['net_pnl']);

        $botProfit = (float) $trades
            ->filter(fn ($trade) => (float) $trade->net_pnl > 0)
            ->sum(fn ($trade) => (float) $trade->net_pnl);

        $botLoss = (float) $trades
            ->filter(fn ($trade) => (float) $trade->net_pnl < 0)
            ->sum(fn ($trade) => abs((float) $trade->net_pnl));

        $summary = [
            'walletBalance' => null,
            'availableBalance' => null,
            'marginBalance' => null,
            'botProfit' => $botProfit,
            'botLoss' => $botLoss,
            'totalProfit' => $botProfit - $botLoss,
            'balanceSource' => $this->isLiveMode($config) ? 'BINANCE_FUTURES' : 'PAPER_LEDGER',
            'accountAsset' => 'USDT',
        ];

        if (! $this->isLiveMode($config)) {
            return $summary;
        }

        try {
            $account = $this->liveClient($config->mergedSettings())->fetchAccountInfo();
            $summary['walletBalance'] = isset($account['totalWalletBalance']) ? (float) $account['totalWalletBalance'] : null;
            $summary['availableBalance'] = isset($account['availableBalance']) ? (float) $account['availableBalance'] : null;
            $summary['marginBalance'] = isset($account['totalMarginBalance']) ? (float) $account['totalMarginBalance'] : null;
        } catch (Throwable $e) {
            $summary['balanceSource'] = 'BINANCE_UNAVAILABLE';
            $this->log($config->fresh('state'), 'BALANCE_SYNC_FAILED', $config->state?->current_symbol, null, 0, $e->getMessage());
        }

        return $summary;
    }

    private function isLiveMode(FutureTradeBotConfig $config): bool
    {
        $settings = $config->mergedSettings();
        return (bool) $config->enable_live_trading
            && (bool) ($settings['liveTrading'] ?? false)
            && $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true));
    }

    private function liveClient(array $settings): BinanceFuturesClient
    {
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        if (! $this->environmentTradingReady($forceTestnet)) {
            throw new \RuntimeException($forceTestnet
                ? 'Future Trade testnet trading requires FUTURE_TRADE_ENABLE_TESTNET_TRADING=true and testnet futures API credentials.'
                : 'Future Trade live trading requires FUTURE_TRADE_ENABLE_LIVE_TRADING=true and live futures API credentials.'
            );
        }
        $baseUrl = $forceTestnet
            ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
            : config('services.binance.futures_api_url', 'https://fapi.binance.com');
        $apiKey = $forceTestnet
            ? config('services.binance.futures_testnet_api_key', '')
            : config('services.binance.futures_live_api_key', '');
        $apiSecret = $forceTestnet
            ? config('services.binance.futures_testnet_api_secret', '')
            : config('services.binance.futures_live_api_secret', '');

        return new BinanceFuturesClient(
            apiKey: (string) $apiKey,
            apiSecret: (string) $apiSecret,
            baseUrl: (string) $baseUrl,
        );
    }

    private function environmentTradingEnabled(bool $forceTestnet): bool
    {
        return $forceTestnet
            ? (bool) config('future_trade.enable_testnet_trading')
            : (bool) config('future_trade.enable_live_trading');
    }

    private function environmentTradingReady(bool $forceTestnet): bool
    {
        return $this->environmentTradingEnabled($forceTestnet) && $this->hasFuturesCredentials($forceTestnet);
    }

    private function hasFuturesCredentials(bool $forceTestnet): bool
    {
        $key = $forceTestnet
            ? (string) config('services.binance.futures_testnet_api_key', '')
            : (string) config('services.binance.futures_live_api_key', '');
        $secret = $forceTestnet
            ? (string) config('services.binance.futures_testnet_api_secret', '')
            : (string) config('services.binance.futures_live_api_secret', '');

        return $key !== '' && $secret !== '';
    }

    private function symbolFilters(array $exchangeInfo, string $symbol): array
    {
        $symbolInfo = collect((array) ($exchangeInfo['symbols'] ?? []))->first(fn ($row) => strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol));
        $filters = collect((array) ($symbolInfo['filters'] ?? []));
        $lot = (array) ($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'LOT_SIZE') ?? []);
        $price = (array) ($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'PRICE_FILTER') ?? []);

        return [
            'stepSize' => (float) ($lot['stepSize'] ?? 0.001),
            'tickSize' => (float) ($price['tickSize'] ?? 0.01),
        ];
    }

    private function formatStep(float $value, float $step): string
    {
        $step = $step > 0 ? $step : 0.000001;
        $rounded = floor(max($value, $step) / $step) * $step;
        $decimals = max(0, min(8, strlen(rtrim(explode('.', rtrim(sprintf('%.8F', $step), '0'))[1] ?? '', '0'))));
        $raw = number_format($rounded, $decimals, '.', '');
        return rtrim(rtrim($raw, '0'), '.') ?: number_format($step, $decimals, '.', '');
    }

    private function log(FutureTradeBotConfig $config, string $event, ?string $symbol, ?string $signal, float $confidence, string $reason, array $context = []): void
    {
        $config->logs()->create([
            'event' => $event,
            'symbol' => $symbol,
            'signal' => $signal,
            'price' => $config->state?->current_price,
            'confidence' => $confidence,
            'reason' => $reason,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
