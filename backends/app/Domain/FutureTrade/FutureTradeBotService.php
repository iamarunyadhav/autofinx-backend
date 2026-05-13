<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Ops\OperationalHealthService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Domain\Trading\FuturesOrderSizingService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use App\Models\SchedulerRun;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FutureTradeBotService
{
    public function __construct(
        private readonly LivePortfolioGuardService $liveGuard,
        private readonly OperationalHealthService $operationalHealth,
        private readonly FuturesOrderSizingService $orderSizing,
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
        $liveAllowed = $liveRequested && $this->environmentTradingReady($forceTestnet, $settings);

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
        if ((bool) ($settings['liveTrading'] ?? false) && $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true), $settings)) {
            $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
            if ($guard['allowed']) {
                $config->update(['paper_trading' => false, 'enable_live_trading' => true]);
            } else {
                $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', null, null, 0, $guard['reason']);
            }
        }
        $state = $this->state($config);
        $wasDailyLocked = $state->daily_stop_until && $state->daily_stop_until->isFuture();
        if ($wasDailyLocked) {
            $this->persistState($state, [
                'daily_profit' => 0,
                'daily_loss' => 0,
                'trade_count_today' => 0,
                'daily_stop_until' => null,
                'trading_day' => now()->toDateString(),
            ]);
            $this->log(
                $config->fresh('state'),
                'MANUAL_DAILY_LOCK_OVERRIDE',
                $state->current_symbol,
                null,
                0,
                'Operator manually restarted the bot after daily loss lock. Daily loss counters reset for a supervised retry.'
            );
            $state = $this->state($config);
        }
        $this->persistState($state, [
            'status' => 'Running',
            'state' => 'SCANNING',
            'connection_status' => 'Connected',
            'current_signal' => 'WAITING_FOR_SIGNAL',
            'daily_stop_until' => null,
            'trading_day' => now()->toDateString(),
        ]);
        $startedConfig = $config->fresh('state');
        $startedSettings = $startedConfig->mergedSettings();
        $this->log(
            $startedConfig,
            'BOT_STARTED',
            null,
            null,
            0,
            $this->isLiveMode($startedConfig)
                ? (($startedSettings['forceTestnet'] ?? true) ? 'Futures live testnet scanner started.' : 'Futures live scanner started.')
                : 'Futures paper scanner started.'
        );
        $this->scan($startedConfig, true);
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
        $config->logs()->delete();
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
        return $state->fresh();
    }

    public function clearHistory(FutureTradeBotConfig $config): array
    {
        $trades = $config->trades()->count();
        $logs = $config->logs()->count();
        $config->trades()->delete();
        $config->logs()->delete();
        $state = $this->state($config);
        $this->persistState($state, [
            'realized_pnl' => 0,
            'daily_profit' => 0,
            'daily_loss' => 0,
            'trade_count_today' => 0,
            'wins' => 0,
            'losses' => 0,
            'chart_points' => [],
            'scanner_snapshot' => [],
            'daily_stop_until' => null,
            'trading_day' => now()->toDateString(),
        ]);
        $this->log($config->fresh('state'), 'HISTORY_CLEARED', null, null, 0, "Operator cleared {$trades} trades and {$logs} logs for fresh analysis.");
        return ['cleared_trades' => $trades, 'cleared_logs' => $logs];
    }

    public function resetDailyLoss(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $state = $this->state($config);
        $this->persistState($state, [
            'daily_profit' => 0,
            'daily_loss' => 0,
            'trade_count_today' => 0,
            'daily_stop_until' => null,
            'trading_day' => now()->toDateString(),
            'current_signal' => 'WAITING_FOR_SIGNAL',
        ]);

        $this->log($config->fresh('state'), 'DAILY_LOSS_RESET', $state->current_symbol, null, 0, 'Operator reset the current trading day loss counters.');

        return $state->fresh();
    }

    public function recoverRuntime(FutureTradeBotConfig $config, string $action = 'auto'): array
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $symbol = strtoupper((string) ($state->current_symbol ?? ''));

        if (! $config->enable_live_trading) {
            return [
                'action' => $action,
                'resolved' => false,
                'message' => 'Live recovery actions are available only when Future Trade is using Binance Futures live/testnet mode.',
                'state' => $state->fresh(),
            ];
        }

        if ($symbol === '') {
            return [
                'action' => $action,
                'resolved' => false,
                'message' => 'No active Future Trade symbol is available to recover.',
                'state' => $state->fresh(),
            ];
        }

        $client = $this->liveClient($settings);
        $result = match ($action) {
            'cancel_reduce_only' => $this->cancelReduceOnlyOrders($config, $client, $symbol),
            'sync_state' => $this->synchronizeLivePositionState($config, $client, $symbol, 'OPERATOR_SYNC'),
            default => $this->resolveRuntimeState($config, $client, $symbol, 'OPERATOR_AUTO_RECOVERY'),
        };

        return $result + [
            'action' => $action,
            'state' => $this->state($config->fresh('state')),
        ];
    }

    public function testConnection(FutureTradeBotConfig $config): array
    {
        $settings = $config->mergedSettings();
        $mode = (string) ($settings['envMode'] ?? 'paper');
        if ($mode === 'paper') {
            return [
                'connected' => false,
                'mode' => 'paper',
                'message' => 'Paper mode selected. Switch to Testnet or Live in My Config to test a Binance futures connection.',
            ];
        }

        $forceTestnet = $mode !== 'live';
        $client = $this->liveClient(array_merge($settings, ['forceTestnet' => $forceTestnet]));
        $client->ping();
        $account = $client->fetchAccountInfo();
        $asset = collect((array) ($account['assets'] ?? []))
            ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === 'USDT');

        return [
            'connected' => true,
            'mode' => $mode,
            'message' => $forceTestnet ? 'Binance Futures testnet connection OK.' : 'Binance Futures live connection OK.',
            'walletBalance' => isset($account['totalWalletBalance']) ? (float) $account['totalWalletBalance'] : (float) ($asset['walletBalance'] ?? 0),
            'availableBalance' => isset($account['availableBalance']) ? (float) $account['availableBalance'] : (float) ($asset['availableBalance'] ?? 0),
            'marginBalance' => isset($account['totalMarginBalance']) ? (float) $account['totalMarginBalance'] : (float) ($asset['marginBalance'] ?? 0),
            'accountAsset' => strtoupper((string) ($asset['asset'] ?? 'USDT')) ?: 'USDT',
        ];
    }

    public function scan(FutureTradeBotConfig $config, bool $allowPaperExecution = true): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        try {
            $state = $this->refreshDailyCycleState($config, $state);
            $lockedForDay = $this->applyDailyLossLock($config, $state);
            $settings = $config->mergedSettings();
            $market = $this->marketSnapshot($config, $settings);
            $signals = array_map(fn (array $ticker) => $this->scoreTicker($ticker, $settings), $market);
            usort($signals, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
            $best = $signals[0] ?? null;

            if (! $best) {
                $this->persistState($state, [
                    'connection_status' => 'No market data',
                    'current_signal' => 'WAITING_FOR_SIGNAL',
                    'last_scan_at' => now(),
                ]);
                $this->logThrottled(
                    $config->fresh('state'),
                    'SCAN_EMPTY',
                    $state->current_symbol,
                    null,
                    0,
                    'Future Trade scan returned no ranked symbols. Market snapshot may be empty or stale.',
                    ['status' => 'EMPTY'],
                    60
                );
                return ['signals' => [], 'bestSignal' => null];
            }

            $dynamic = $this->dynamicDecision($config, $state, $signals, $best, $settings);
            $settings = $dynamic['settings'];
            $best['effectiveMinConfidence'] = $dynamic['effectiveMinConfidence'];
            $best['dynamicMode'] = $dynamic['mode'];

            $state->refresh();
            $inPosition = (float) $state->quantity > 0;

            if ($inPosition) {
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

                $autoExecutionEnabled = $this->isLiveMode($config) || (bool) ($settings['autoExecutePaperSignals'] ?? true);
                if (! $lockedForDay && $allowPaperExecution && $config->enabled && $autoExecutionEnabled) {
                    $this->maybeEnterPaperPosition($config->fresh('state'), $best);
                }
            }

            return ['signals' => array_slice($signals, 0, 20), 'bestSignal' => $best];
        } catch (Throwable $e) {
            $this->persistState($state, [
                'state' => 'SCAN_ERROR',
                'connection_status' => 'Error',
                'last_scan_at' => now(),
            ]);
            $this->logThrottled(
                $config->fresh('state'),
                'SCAN_FAILED',
                $state->current_symbol,
                $state->current_signal,
                (float) ($state->confidence ?? 0),
                $e->getMessage(),
                ['status' => 'ERROR'],
                30
            );

            return ['signals' => [], 'bestSignal' => null, 'error' => $e->getMessage()];
        }
    }

    public function statusPayload(FutureTradeBotConfig $config): array
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
        $effectiveMinConfidence = (float) ($dynamic['effectiveMinConfidence'] ?? ($settings['minConfidence'] ?? 0));
        $signalConfidence = (float) ($bestSignal['confidence'] ?? $state->confidence ?? 0);
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
            'entryReason' => $this->entryReasonSummary($state, $bestSignal, $effectiveMinConfidence),
            'currentPrice' => (float) ($state->current_price ?? 0),
            'confidence' => (float) $state->confidence,
            'activeMinConfidence' => round($effectiveMinConfidence, 4),
            'signalConfidenceGap' => round($signalConfidence - $effectiveMinConfidence, 4),
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
            'dailyLossLocked' => (bool) ($state->daily_stop_until && $state->daily_stop_until->isFuture()),
            'manualRestartAllowed' => true,
            'liveTradingEnvEnabled' => $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true), $settings),
            'testnetTradingEnvEnabled' => $this->environmentTradingEnabled(true, $settings),
            'realTradingEnvEnabled' => $this->environmentTradingEnabled(false, $settings),
            'testnetCredentialsConfigured' => $this->hasFuturesCredentials(true, $settings),
            'realCredentialsConfigured' => $this->hasFuturesCredentials(false, $settings),
            'liveGuard' => $this->liveGuard->canActivate($config->workspace_id, 'future_trade'),
            'walletBalance' => $accountSummary['walletBalance'],
            'availableBalance' => $accountSummary['availableBalance'],
            'marginBalance' => $accountSummary['marginBalance'],
            'botProfit' => $accountSummary['botProfit'],
            'botLoss' => $accountSummary['botLoss'],
            'totalProfit' => $accountSummary['totalProfit'],
            'balanceSource' => $accountSummary['balanceSource'],
            'accountAsset' => $accountSummary['accountAsset'],
            'balanceError' => $accountSummary['balanceError'],
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
        $marketChartSymbol = $this->resolveMarketChartSymbol($state, $bestSignal, $settings);
        $marketTimeframe = (string) ($settings['timeframe'] ?? '5m');
        $marketCandles = $marketChartSymbol ? $this->fetchMarketCandles($marketChartSymbol, $marketTimeframe, 180) : [];

        return [
            'status' => $this->statusPayload($config),
            'scanner' => $signals,
            'bestSignal' => $bestSignal,
            'chart' => $state->chart_points ?? [],
            'marketChart' => $this->marketChartPayload($marketChartSymbol, $marketTimeframe, 180, $marketCandles),
            'indicatorSuite' => $this->indicatorSuitePayload($marketCandles, $bestSignal, $effectiveSettings, $marketTimeframe),
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
	            'liveTradingBlocked' => ! $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true), $settings),
	            ],
	            'diagnostics' => $this->diagnosticsPayload($config),
	            'dynamic' => $dynamic['public'],
	        ];
	    }

    public function diagnosticsPayload(FutureTradeBotConfig $config, bool $persist = false): array
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
        $effectiveMinConfidence = (float) ($dynamic['effectiveMinConfidence'] ?? ($settings['minConfidence'] ?? 0));
        $account = $this->accountSummary($config);
        $health = $this->operationalHealth->summary($config->workspace_id);
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        $envReady = $this->environmentTradingReady($forceTestnet, $settings);
        $liveGuard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
        $requiredTradeAmountUsdt = (float) ($effectiveSettings['tradeAmountUsdt'] ?? $settings['tradeAmountUsdt'] ?? 0);

        $botBlockers = [];
        $botWarnings = [];
        $botChecks = [];

        $botChecks[] = [
            'label' => 'Mode',
            'state' => $this->isLiveMode($config) ? 'ok' : 'info',
            'message' => $this->isLiveMode($config)
                ? (($settings['forceTestnet'] ?? true) ? 'Live testnet mode is active.' : 'Live futures mode is active.')
                : 'Paper futures mode is active.',
        ];

        if ((bool) ($settings['emergencyStop'] ?? false)) {
            $botBlockers[] = 'emergency_stop_enabled';
            $botChecks[] = ['label' => 'Emergency stop', 'state' => 'blocked', 'message' => 'Algorithm config has emergency stop enabled.'];
        }

        if ($state->daily_stop_until && $state->daily_stop_until->isFuture()) {
            $botBlockers[] = 'daily_loss_lock_active';
            $botChecks[] = ['label' => 'Daily lock', 'state' => 'blocked', 'message' => 'Daily loss lock is active until the next trading day reset.'];
        }

        if ($state->cooldown_until && $state->cooldown_until->isFuture() && (string) $state->state !== 'IN_POSITION') {
            $botWarnings[] = 'cooldown_active';
            $botChecks[] = [
                'label' => 'Cooldown',
                'state' => 'warning',
                'message' => sprintf('Cooldown active until %s.', $state->cooldown_until->timezone(config('app.timezone'))->format('H:i:s')),
            ];
        }

        if ((int) $state->trade_count_today >= (int) ($settings['maxTradesPerDay'] ?? 0) && (int) ($settings['maxTradesPerDay'] ?? 0) > 0) {
            $botBlockers[] = 'max_trades_per_day_reached';
            $botChecks[] = ['label' => 'Trade limit', 'state' => 'blocked', 'message' => 'Today trade limit has been reached.'];
        }

        if ($bestSignal) {
            $signalConfidence = (float) ($bestSignal['confidence'] ?? 0);
            if (($bestSignal['signal'] ?? 'WAIT') === 'WAIT') {
                $botWarnings[] = 'best_signal_wait';
                $botChecks[] = ['label' => 'Scanner', 'state' => 'warning', 'message' => (string) ($bestSignal['reason'] ?? 'Scanner still returned WAIT.')];
            } elseif ($signalConfidence < $effectiveMinConfidence) {
                $botBlockers[] = 'confidence_below_threshold';
                $botChecks[] = [
                    'label' => 'Entry threshold',
                    'state' => 'blocked',
                    'message' => sprintf('Best signal %.1f%% is below active minimum %.1f%%.', $signalConfidence * 100, $effectiveMinConfidence * 100),
                ];
            } else {
                $botChecks[] = [
                    'label' => 'Entry threshold',
                    'state' => 'ok',
                    'message' => sprintf('Best signal %.1f%% is above active minimum %.1f%%.', $signalConfidence * 100, $effectiveMinConfidence * 100),
                ];
            }
        } else {
            $botWarnings[] = 'scanner_snapshot_empty';
            $botChecks[] = ['label' => 'Scanner', 'state' => 'warning', 'message' => 'No scanner snapshot is available yet.'];
        }

        if ((bool) ($settings['liveTrading'] ?? false)) {
            if (! $envReady) {
                $botBlockers[] = 'live_environment_not_ready';
                $botChecks[] = ['label' => 'Live environment', 'state' => 'blocked', 'message' => $forceTestnet
                    ? 'Testnet mode is selected but testnet gate or credentials are missing.'
                    : 'Live mode is selected but live gate or credentials are missing.'];
            } else {
                $botChecks[] = ['label' => 'Live environment', 'state' => 'ok', 'message' => $forceTestnet ? 'Testnet gate and credentials are ready.' : 'Live gate and credentials are ready.'];
            }

            if (! ($liveGuard['allowed'] ?? false)) {
                $botBlockers[] = 'live_guard_blocked';
                $botChecks[] = ['label' => 'Live guard', 'state' => 'blocked', 'message' => (string) ($liveGuard['reason'] ?? 'Another live bot is active.')];
            }
        }

        $walletState = 'info';
        $walletMessage = $this->isLiveMode($config)
            ? 'Live wallet balance is being monitored.'
            : 'Paper mode uses ledger-only balance and does not check Binance futures wallet.';

        if ($account['balanceSource'] === 'BINANCE_UNAVAILABLE') {
            $walletState = 'blocked';
            $walletMessage = (string) ($account['balanceError'] ?? 'Binance futures balance is unavailable.');
            $botBlockers[] = 'wallet_balance_unavailable';
        } elseif ($this->isLiveMode($config) && $account['availableBalance'] !== null && $requiredTradeAmountUsdt > 0 && (float) $account['availableBalance'] < $requiredTradeAmountUsdt) {
            $walletState = 'blocked';
            $walletMessage = sprintf(
                'Available balance %.4f %s is lower than required trade amount %.4f USDT.',
                (float) $account['availableBalance'],
                (string) ($account['accountAsset'] ?? 'USDT'),
                $requiredTradeAmountUsdt
            );
            $botBlockers[] = 'available_balance_too_low';
        }

        $latestAlert = $config->logs()
            ->whereIn('event', ['SCAN_FAILED', 'MARKET_SNAPSHOT_FAILED', 'LIVE_ENTRY_FAILED', 'BALANCE_SYNC_FAILED', 'TRADE_BLOCKED', 'SIGNAL_SKIPPED', 'LIVE_GUARD_BLOCKED', 'DAILY_LOSS_LIMIT_HIT'])
            ->orderByDesc('occurred_at')
            ->first();

        $recentRuns = SchedulerRun::query()
            ->orderByDesc('started_at')
            ->limit(5)
            ->get(['id', 'command', 'status', 'started_at', 'finished_at', 'duration_ms'])
            ->values()
            ->all();

        $stateName = ($health['state'] ?? 'healthy') === 'blocked' || $botBlockers !== []
            ? 'blocked'
            : (($health['state'] ?? 'healthy') !== 'healthy' || $botWarnings !== [] ? 'warning' : 'healthy');

        $summary = match ($stateName) {
            'blocked' => 'Trade automation is blocked by runtime or server checks. Review the blocker list below.',
            'warning' => 'Trade automation is running with warnings. Review runtime and server signals before trusting entries.',
            default => 'Runtime checks look healthy. Trade flow is eligible when the signal threshold passes.',
        };

        $payload = [
            'state' => $stateName,
            'summary' => $summary,
            'generatedAt' => now()->toIso8601String(),
            'botBlockers' => array_values(array_unique($botBlockers)),
            'botWarnings' => array_values(array_unique($botWarnings)),
            'botChecks' => $botChecks,
            'wallet' => [
                'state' => $walletState,
                'message' => $walletMessage,
                'walletBalance' => $account['walletBalance'],
                'availableBalance' => $account['availableBalance'],
                'marginBalance' => $account['marginBalance'],
                'accountAsset' => $account['accountAsset'],
                'requiredTradeAmountUsdt' => $requiredTradeAmountUsdt,
            ],
            'server' => [
                'state' => $health['state'] ?? 'unknown',
                'blockers' => array_values((array) ($health['blockers'] ?? [])),
                'warnings' => array_values((array) ($health['warnings'] ?? [])),
                'scheduler' => $health['checks']['scheduler'] ?? null,
                'queue' => $health['checks']['queue'] ?? null,
                'rest' => $health['checks']['rest_health'] ?? null,
                'streams' => $health['checks']['streams'] ?? null,
            ],
            'latestAlert' => $latestAlert ? [
                'event' => $latestAlert->event,
                'reason' => $latestAlert->reason,
                'occurredAt' => optional($latestAlert->occurred_at)->toIso8601String(),
            ] : null,
            'recentSchedulerRuns' => $recentRuns,
        ];

        if ($persist) {
            $this->logThrottled(
                $config->fresh('state'),
                'RUNTIME_DIAGNOSTIC',
                $state->current_symbol,
                $state->current_signal,
                (float) ($state->confidence ?? 0),
                $summary,
                [
                    'status' => strtoupper($stateName),
                    'botBlockers' => $payload['botBlockers'],
                    'botWarnings' => $payload['botWarnings'],
                    'serverState' => $payload['server']['state'],
                    'serverBlockers' => $payload['server']['blockers'],
                    'walletState' => $payload['wallet']['state'],
                ],
                240
            );
        }

        return $payload;
    }

    public function configPayload(FutureTradeBotConfig $config): array
    {
        return [
            'id' => $config->id,
            'settings' => $config->mergedSettings(),
            'algorithm_notes' => $config->algorithm_notes,
            'paper_trading' => $config->paper_trading,
            'enable_live_trading' => $config->enable_live_trading,
            'live_env_enabled' => $this->environmentTradingReady((bool) ($config->mergedSettings()['forceTestnet'] ?? true), $config->mergedSettings()),
            'testnet_env_enabled' => $this->environmentTradingEnabled(true, $config->mergedSettings()),
            'real_env_enabled' => $this->environmentTradingEnabled(false, $config->mergedSettings()),
            'testnet_credentials_configured' => $this->hasFuturesCredentials(true, $config->mergedSettings()),
            'real_credentials_configured' => $this->hasFuturesCredentials(false, $config->mergedSettings()),
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
        if (! $this->isWithinTradingHours($settings)) {
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Outside configured trading hours (UTC).',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED_HOURS']),
                300
            );
            return;
        }
        if (($settings['emergencyStop'] ?? false)) {
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Emergency stop is enabled in algorithm config.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED']),
                30
            );
            return;
        }
        if ($signal['signal'] === 'WAIT') {
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                (string) ($signal['reason'] ?? 'Strategy returned WAIT.'),
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED']),
                30
            );
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
        $rrTp = (float) ($settings['takeProfitPercent'] ?? 0.45);
        $rrRollback = (float) ($settings['rollbackLossPercent'] ?? 0.18);
        if ($rrTp < $rrRollback * 1.8) {
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('R:R gate: TP %.2f%% < rollback %.2f%% × 1.8 — insufficient reward for risk.', $rrTp, $rrRollback),
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED']),
                60
            );
            return;
        }
        if ($state->cooldown_until && $state->cooldown_until->isFuture()) {
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('Cooldown active until %s.', $state->cooldown_until->timezone(config('app.timezone'))->format('H:i:s')),
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED']),
                30
            );
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
            try {
                $this->openLivePosition($config, $signal);
            } catch (Throwable $e) {
                $this->persistState($state, [
                    'connection_status' => 'Live entry failed',
                    'state' => 'LIVE_ENTRY_ERROR',
                ]);
                $this->logThrottled(
                    $config->fresh('state'),
                    'LIVE_ENTRY_FAILED',
                    $signal['symbol'],
                    $signal['signal'],
                    (float) ($signal['confidence'] ?? 0),
                    $e->getMessage(),
                    $this->runtimeContext($dynamic, ['status' => 'ERROR']),
                    30
                );
            }
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
        $fees = ($entry + $price) * $qty * 0.0004;
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
        } elseif ($pct >= 0.10 && (($side === 'LONG' && $price <= $this->trailingPrice($peak, $side, $settings) && $peak > $entry) || ($side === 'SHORT' && $price >= $this->trailingPrice($peak, $side, $settings) && $peak < $entry))) {
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
        $filters = $this->orderSizing->extractFilters($client->fetchExchangeInfo($symbol), $symbol);
        $account = $client->fetchAccountInfo();
        $asset = collect((array) ($account['assets'] ?? []))
            ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === 'USDT');
        $availableBalance = isset($account['availableBalance'])
            ? (float) $account['availableBalance']
            : (float) ($asset['availableBalance'] ?? 0);
        $requiredTradeAmountUsdt = (float) ($settings['tradeAmountUsdt'] ?? 25);

        if ($availableBalance > 0 && $availableBalance < $requiredTradeAmountUsdt) {
            $this->log(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                $symbol,
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('Available balance %.4f USDT is lower than required trade amount %.4f USDT.', $availableBalance, $requiredTradeAmountUsdt),
                $this->runtimeContext($dynamic, [
                    'status' => 'BLOCKED',
                    'availableBalance' => $availableBalance,
                    'requiredTradeAmountUsdt' => $requiredTradeAmountUsdt,
                ])
            );

            return;
        }

        $sizing = $this->orderSizing->evaluate(
            ((float) ($settings['tradeAmountUsdt'] ?? 25) * $leverage) / max($price, 0.00000001),
            $price,
            $filters,
        );
        $autoSized = $this->minimumSafeTradeAmountUsdt((float) ($settings['tradeAmountUsdt'] ?? 25), $leverage, $price, $filters);
        if (
            (
                (float) ($sizing['quantity_float'] ?? 0) <= 0
                || ((float) ($sizing['minOpenNotional'] ?? 0) > 0 && (float) ($sizing['notional'] ?? 0) < (float) ($sizing['minOpenNotional'] ?? 0))
            )
            && $autoSized !== null
            && ($availableBalance <= 0 || $autoSized <= $availableBalance)
        ) {
            $settings['tradeAmountUsdt'] = $autoSized;
            $requiredTradeAmountUsdt = $autoSized;
            $sizing = $this->orderSizing->evaluate(
                ($autoSized * $leverage) / max($price, 0.00000001),
                $price,
                $filters,
            );
        }
        $qty = $sizing['quantity'];
        $qtyFloat = (float) $sizing['quantity_float'];
        $notional = (float) $sizing['notional'];
        $minNotional = (float) $sizing['minOpenNotional'];

        if ($qtyFloat <= 0) {
            $this->log(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                $symbol,
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Rounded quantity became zero after symbol step-size formatting.',
                $this->runtimeContext($dynamic, [
                    'status' => 'BLOCKED',
                    'price' => $price,
                    'quantity' => $qtyFloat,
                    'stepSize' => $sizing['stepSize'],
                    'recommendedTradeAmountUsdt' => $this->minimumSafeTradeAmountUsdt((float) ($settings['tradeAmountUsdt'] ?? 25), $leverage, $price, $filters),
                ])
            );

            return;
        }

        if ($minNotional > 0 && $notional < $minNotional) {
            $this->log(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                $symbol,
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf(
                    'Final order notional %.4f USDT is below Binance minimum %.4f USDT after quantity rounding. Increase trade size.',
                    $notional,
                    $minNotional
                ),
                $this->runtimeContext($dynamic, [
                    'status' => 'BLOCKED',
                    'price' => $price,
                    'quantity' => $qtyFloat,
                    'notional' => $notional,
                    'minNotional' => $minNotional,
                    'exchangeMinNotional' => $sizing['exchangeMinNotional'],
                    'tradeAmountUsdt' => $requiredTradeAmountUsdt,
                    'leverage' => $leverage,
                    'stepSize' => $sizing['stepSize'],
                    'recommendedTradeAmountUsdt' => $this->minimumSafeTradeAmountUsdt((float) ($settings['tradeAmountUsdt'] ?? 25), $leverage, $price, $filters),
                ])
            );

            return;
        }

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
            'meta' => [
                'entry_order' => $response,
                'exit_management' => 'BOT_MANAGED_MARKET_CLOSE',
                'signal' => $signal,
                'dynamic' => $dynamic['public'],
                'algorithm_mode' => $dynamic['mode'],
                'effective_trade_amount_usdt' => $requiredTradeAmountUsdt,
            ],
        ]);
        $this->log(
            $config->fresh('state'),
            'LIVE_' . $signal['signal'] . '_EXECUTED',
            $symbol,
            $signal['signal'],
            (float) $signal['confidence'],
            'Live futures entry submitted. TP/SL/trailing are managed by the bot loop.',
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
        $symbol = (string) $state->current_symbol;
        $filters = $this->symbolFilters($client->fetchExchangeInfo($symbol), $symbol);
        $exchange = $this->exchangePositionSnapshot($client, $symbol);

        if ((float) ($exchange['quantity'] ?? 0) <= 0) {
            $this->resolveRuntimeState($config, $client, $symbol, 'NO_EXCHANGE_POSITION_FOR_CLOSE');

            return;
        }

        $this->cancelReduceOnlyOrders($config, $client, $symbol, $exchange['reduce_only_orders']);

        $side = (($exchange['position_side'] ?? null) === 'LONG' ? 'SELL' : 'BUY');
        $qtyFloat = min(max((float) $state->quantity, 0.0), (float) $exchange['quantity']);
        $qty = $this->formatStep($qtyFloat, $filters['stepSize']);

        if ((float) $qty <= 0) {
            $this->resolveRuntimeState($config, $client, $symbol, 'ZERO_CLOSE_QUANTITY_AFTER_SYNC');

            return;
        }

        try {
            $response = $client->placeOrder([
                'symbol' => $symbol,
                'side' => $side,
                'type' => 'MARKET',
                'quantity' => $qty,
                'reduceOnly' => 'true',
                'newClientOrderId' => substr('ft-close-' . Str::uuid(), 0, 36),
                'positionSide' => 'BOTH',
            ]);
        } catch (Throwable $e) {
            if ($this->isReduceOnlyRejected($e)) {
                $resolution = $this->resolveRuntimeState($config, $client, $symbol, 'REDUCE_ONLY_REJECTED');
                $this->log(
                    $config->fresh('state'),
                    'LIVE_CLOSE_RECOVERY',
                    $symbol,
                    'CLOSE',
                    1,
                    'Reduce-only close was rejected. Exchange state was re-synced.',
                    $this->runtimeContext($dynamic, $resolution + ['status' => 'RECOVERED'])
                );

                return;
            }

            throw $e;
        }

        $direction = $state->position_side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $gross = ($price - $entry) * (float) $state->quantity * $direction;
        $fees = ($entry + $price) * (float) $state->quantity * 0.0004;
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
        $fees = ($entry + $price) * $qty * 0.0004;
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

        // Indicators from sequence analysis and higher timeframes
        $rsi14              = (float) ($sequence['rsi14'] ?? 50.0);
        $emaBullish         = isset($sequence['emaBullish'])    ? (bool) $sequence['emaBullish']           : null;
        $macdBullish        = isset($sequence['macdBullish'])   ? (bool) $sequence['macdBullish']          : null;
        $macdHistGrowing    = (bool) ($sequence['macdHistogramGrowing'] ?? false);
        $htfBullish         = isset($ticker['htf']['emaBullish'])   ? (bool) $ticker['htf']['emaBullish']   : null;  // 1h EMA50
        $htf4hBullish       = isset($ticker['htf']['h4EmaBullish'])  ? (bool) $ticker['htf']['h4EmaBullish'] : null;  // 4h EMA50
        $symbolPerfAdjust   = (float) ($ticker['symbolPerfAdjust'] ?? 0.0);
        $rsiBuyBlocked      = false;
        $rsiSellBlocked     = false;

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

        // RSI extreme filter: block overbought BUY and oversold SELL entries
        if ($rawSignal === 'BUY' && $rsi14 >= 72.0) {
            $rawSignal = 'WAIT';
            $rsiBuyBlocked = true;
        }
        if ($rawSignal === 'SELL' && $rsi14 <= 28.0) {
            $rawSignal = 'WAIT';
            $rsiSellBlocked = true;
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

        // EMA(9/21) crossover vote — fast EMA above slow = trending up
        if ($emaBullish === true) {
            $bullishVotes += 0.9;
        } elseif ($emaBullish === false) {
            $bearishVotes += 0.9;
        }

        // RSI momentum zone vote — 52-70 bullish momentum, 30-48 bearish momentum
        if ($rsi14 >= 52 && $rsi14 < 70) {
            $bullishVotes += 0.4;
        } elseif ($rsi14 <= 48 && $rsi14 > 30) {
            $bearishVotes += 0.4;
        }

        // 1h EMA50 trend
        if ($htfBullish === true) {
            $bullishVotes += 1.0;
        } elseif ($htfBullish === false) {
            $bearishVotes += 1.0;
        }

        // 4h EMA50 macro trend (strongest timeframe — higher weight)
        if ($htf4hBullish === true) {
            $bullishVotes += 1.4;
        } elseif ($htf4hBullish === false) {
            $bearishVotes += 1.4;
        }

        // MACD(12,26,9) signal — MACD above signal line + growing histogram = strong momentum
        if ($macdBullish === true) {
            $bullishVotes += $macdHistGrowing ? 1.2 : 0.7;
        } elseif ($macdBullish === false) {
            $bearishVotes += $macdHistGrowing ? 1.2 : 0.7;
        }

        $voteGap = abs($bullishVotes - $bearishVotes);
        $dominantSignal = $bullishVotes > $bearishVotes ? 'BUY' : ($bearishVotes > $bullishVotes ? 'SELL' : 'WAIT');
        $strongDirectionalSequence = $sequenceConsistency >= 0.44 && abs($sequenceNetChange) >= 0.06;
        $conflicted = $voteGap < 0.80
            || ($dominantSignal === 'BUY' && ($sequenceNetChange < -0.18 || ($sequenceImpulse === 'SELL' && $sequenceBearishRatio >= 0.60)))
            || ($dominantSignal === 'SELL' && ($sequenceNetChange > 0.18 || ($sequenceImpulse === 'BUY' && $sequenceBullishRatio >= 0.60)));
        $microChoppy = $sequenceVolatility > 0.30 && abs($sequenceNetChange) < 0.06;
        $choppy = $choppy || $microChoppy;

        $sequenceBiasAligned = ($dominantSignal === 'BUY' && ($sequenceNetChange >= 0 || $sequenceImpulse === 'BUY' || $sequenceBullishRatio >= 0.52))
            || ($dominantSignal === 'SELL' && ($sequenceNetChange <= 0 || $sequenceImpulse === 'SELL' || $sequenceBearishRatio >= 0.52));
        $tickerBiasAligned = ($dominantSignal === 'BUY' && ($vwapBullish || $intradayChangePct > 0 || $rangePosition >= 0.55))
            || ($dominantSignal === 'SELL' && (! $vwapBullish || $intradayChangePct < 0 || $rangePosition <= 0.45));
        $cleanOverride = ! $choppy
            && $dominantSignal !== 'WAIT'
            && $voteGap >= 1.55
            && $sequenceConsistency >= 0.46
            && $tickerBiasAligned
            && $sequenceBiasAligned;

        $signal = $dominantSignal;
        if (($settings['avoidChoppyMarket'] ?? true) && ($choppy || (($conflicted || ! $strongDirectionalSequence) && ! $cleanOverride))) {
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

        // EMA crossover alignment bonus
        $emaCrossBonus = (($dominantSignal === 'BUY' && $emaBullish === true) || ($dominantSignal === 'SELL' && $emaBullish === false)) ? 0.07 : 0.0;

        // 1h EMA50: bonus with-trend, penalty counter-trend
        $htfBonus = 0.0;
        $htfPenalty = 0.0;
        if ($htfBullish !== null) {
            if (($dominantSignal === 'BUY' && $htfBullish) || ($dominantSignal === 'SELL' && ! $htfBullish)) {
                $htfBonus = 0.06;
            } elseif (($dominantSignal === 'BUY' && ! $htfBullish) || ($dominantSignal === 'SELL' && $htfBullish)) {
                $htfPenalty = 0.12;
            }
        }

        // 4h EMA50: stronger macro trend filter — bigger bonus/penalty
        $htf4hBonus = 0.0;
        $htf4hPenalty = 0.0;
        if ($htf4hBullish !== null) {
            if (($dominantSignal === 'BUY' && $htf4hBullish) || ($dominantSignal === 'SELL' && ! $htf4hBullish)) {
                $htf4hBonus = 0.08;
            } elseif (($dominantSignal === 'BUY' && ! $htf4hBullish) || ($dominantSignal === 'SELL' && $htf4hBullish)) {
                $htf4hPenalty = 0.16; // macro counter-trend — strong penalty
            }
        }

        // MACD confirmation bonus
        $macdBonus = 0.0;
        $macdPenalty = 0.0;
        if ($macdBullish !== null) {
            $macdAligned = ($dominantSignal === 'BUY' && $macdBullish) || ($dominantSignal === 'SELL' && ! $macdBullish);
            if ($macdAligned) {
                $macdBonus = $macdHistGrowing ? 0.09 : 0.05; // stronger when momentum accelerating
            } else {
                $macdPenalty = $macdHistGrowing ? 0.10 : 0.05; // divergence — penalize
            }
        }

        // Symbol historical performance adjustment (from DB trade records)
        $confidence = round($this->clamp(
            $volumeScore + $intradayScore + $sequenceScore + $consistencyScore + $rangeScore
            + $directionalBonus + $alignmentBonus + $emaCrossBonus
            + $htfBonus + $htf4hBonus + $macdBonus
            - $penalty - $htfPenalty - $htf4hPenalty - $macdPenalty
            + $symbolPerfAdjust,
            0.01, 0.99
        ), 4);

        if ($signal === 'WAIT' && $cleanOverride && $confidence >= 0.58) {
            $signal = $dominantSignal;
        }

        // Hard RSI extreme block — applies after cleanOverride, cannot be bypassed
        if ($signal === 'BUY' && $rsi14 >= 72.0) {
            $signal = 'WAIT';
            $rsiBuyBlocked = true;
        } elseif ($signal === 'SELL' && $rsi14 <= 28.0) {
            $signal = 'WAIT';
            $rsiSellBlocked = true;
        }

        $reasonParts = [];
        $tf = $settings['timeframe'] ?? '5m';
        if ($signal === 'WAIT') {
            if ($rsiBuyBlocked) {
                $reasonParts[] = sprintf('RSI(14) %.1f — overbought, BUY entry blocked', $rsi14);
            }
            if ($rsiSellBlocked) {
                $reasonParts[] = sprintf('RSI(14) %.1f — oversold, SELL entry blocked', $rsi14);
            }
            if ($choppy) {
                $reasonParts[] = 'Choppy futures market avoided';
            }
            if ($conflicted ?? false) {
                $reasonParts[] = sprintf('Direction conflict between ticker and %s candle sequence', $tf);
            }
            if (! ($strongDirectionalSequence ?? false)) {
                $reasonParts[] = sprintf('%s sequence not strong enough for entry', $tf);
            }
            if ($htfBullish !== null && (($dominantSignal === 'BUY' && ! $htfBullish) || ($dominantSignal === 'SELL' && $htfBullish))) {
                $reasonParts[] = sprintf('1h EMA50 %s — counter-trend', $htfBullish ? 'bullish' : 'bearish');
            }
            if ($htf4hBullish !== null && (($dominantSignal === 'BUY' && ! $htf4hBullish) || ($dominantSignal === 'SELL' && $htf4hBullish))) {
                $reasonParts[] = sprintf('4h EMA50 %s — macro counter-trend', $htf4hBullish ? 'bullish' : 'bearish');
            }
            if ($macdBullish !== null && (($dominantSignal === 'BUY' && ! $macdBullish) || ($dominantSignal === 'SELL' && $macdBullish))) {
                $reasonParts[] = 'MACD divergence from signal direction';
            }
        } else {
            $reasonParts[] = $vwapBullish ? 'Price above VWAP (bullish)' : 'Price below VWAP (bearish)';
            $reasonParts[] = sprintf('%s sequence %.2f%%', $tf, $sequenceNetChange);
            $reasonParts[] = sprintf('RSI(14) %.1f', $rsi14);
            if ($macdBullish !== null) {
                $hist = $sequence['macdHistogram'] ?? 0.0;
                $reasonParts[] = sprintf('MACD %s%s', $macdBullish ? '▲' : '▼', $macdHistGrowing ? '+' : '');
            }
            if ($emaBullish !== null) {
                $reasonParts[] = $emaBullish ? 'EMA9>EMA21 ↑' : 'EMA9<EMA21 ↓';
            }
            if ($htfBullish !== null) {
                $reasonParts[] = $htfBullish ? '1h EMA50 ▲' : '1h EMA50 ▼';
            }
            if ($htf4hBullish !== null) {
                $reasonParts[] = $htf4hBullish ? '4h EMA50 ▲' : '4h EMA50 ▼';
            }
            if ($symbolPerfAdjust !== 0.0) {
                $reasonParts[] = sprintf('Symbol perf adj %+.0f%%', $symbolPerfAdjust * 100);
            }
            if ($cleanOverride && ($conflicted || ! $strongDirectionalSequence)) {
                $reasonParts[] = 'High-conviction override accepted';
            }
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
            'rsi14'           => round($rsi14, 2),
            'emaBullish'      => $emaBullish,
            'macdBullish'     => $macdBullish,
            'macdHistGrowing' => $macdHistGrowing,
            'htfBullish'      => $htfBullish,
            'htf4hBullish'    => $htf4hBullish,
            'symbolPerfAdj'   => round($symbolPerfAdjust, 4),
            'reason'          => implode(' | ', $reasonParts),
        ];
    }

    private function marketSnapshot(FutureTradeBotConfig $config, array $settings): array
    {
        $symbols = array_map('strtoupper', (array) ($settings['symbols'] ?? []));
        $cacheKey = 'future_trade_market_snapshot_' . md5(implode(',', $symbols));
        try {
            // Execution base: testnet or live depending on settings
            $execBase = rtrim((string) (($settings['forceTestnet'] ?? true)
                ? ($settings['testnetApiUrl'] ?? 'https://testnet.binancefuture.com')
                : ($settings['liveApiUrl'] ?? 'https://fapi.binance.com')), '/');

            // Data base: always live Binance for real market data (public endpoints, no auth needed)
            // This gives accurate prices, real volume, and real kline history regardless of execution mode
            $dataBase = ($settings['useLiveDataFeed'] ?? true)
                ? rtrim((string) ($settings['liveApiUrl'] ?? 'https://fapi.binance.com'), '/')
                : $execBase;

            $rows = Http::timeout(7)->get($dataBase . '/fapi/v1/ticker/24hr')->throw()->json();
            $sequenceSnapshots = $this->sequenceSnapshots($symbols, $settings, $dataBase);
            $htfSnapshots = $this->higherTfSnapshots($symbols, $settings, $dataBase);
            $configId = (string) $config->id;
            $result = collect($rows)
                ->filter(fn ($row) => in_array((string) ($row['symbol'] ?? ''), $symbols, true))
                ->map(function (array $row) use ($sequenceSnapshots, $htfSnapshots, $configId) {
                    $symbol = (string) ($row['symbol'] ?? '');
                    $row['sequence'] = $sequenceSnapshots[$symbol] ?? $this->emptySequenceSnapshot();
                    $row['htf'] = $htfSnapshots[$symbol] ?? ['emaBullish' => null, 'h4EmaBullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0];
                    $row['symbolPerfAdjust'] = $this->symbolPerformanceAdjustment($symbol, $configId);

                    return $row;
                })
                ->values()
                ->all();
            if (! empty($result)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 60);
            }
            return $result;
        } catch (Throwable $e) {
            $cached = Cache::get($cacheKey, []);
            $this->logThrottled(
                $config->fresh('state'),
                'MARKET_SNAPSHOT_FAILED',
                $config->state?->current_symbol,
                null,
                0,
                $e->getMessage(),
                [
                    'status' => 'ERROR',
                    'cachedSymbols' => is_array($cached) ? count($cached) : 0,
                    'requestedSymbols' => count($symbols),
                ],
                60
            );

            return $cached;
        }
    }

    private function sequenceSnapshots(array $symbols, array $settings, string $base): array
    {
        $lookbackMinutes = max(6, min(15, (int) ($settings['dynamicLookbackMinutes'] ?? 10)));
        $limit = max(60, $lookbackMinutes + 2); // 60+ for MACD(12,26,9) warmup
        $cacheTtlSeconds = max(15, min(45, (int) ($settings['scanIntervalSeconds'] ?? 3) * 8));
        $snapshots = [];
        $missing = [];
        $timeframe = (string) ($settings['timeframe'] ?? '5m');

        foreach ($symbols as $symbol) {
            $cacheKey = 'future_trade_sequence_' . md5($symbol . '|' . $limit . '|' . $timeframe);
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

        $responses = Http::pool(function (Pool $pool) use ($missing, $base, $limit, $timeframe) {
            $requests = [];
            foreach (array_keys($missing) as $symbol) {
                $requests[$symbol] = $pool
                    ->as($symbol)
                    ->timeout(5)
                    ->get($base . '/fapi/v1/klines', [
                        'symbol' => $symbol,
                        'interval' => $timeframe,
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

        // RSI(14) — requires 15+ candles
        $rsi14 = count($closes) >= 15 ? $this->calcRsi($closes, 14) : 50.0;

        // EMA(9)/EMA(21) crossover — requires 21+ candles
        $emaBullish = null;
        if (count($closes) >= 21) {
            $ema9  = $this->calcEma($closes, 9);
            $ema21 = $this->calcEma($closes, 21);
            $emaBullish = $ema9 > $ema21;
        }

        // MACD(12,26,9) — requires 35+ candles
        $macd = count($closes) >= 35 ? $this->calcMacd($closes) : ['bullish' => null, 'histogram' => 0.0, 'histogramGrowing' => false];

        return [
            'netChangePercent' => round($netChange, 4),
            'bullishRatio' => round($bullishRatio, 4),
            'bearishRatio' => round($bearishRatio, 4),
            'consistency' => round($consistency, 4),
            'volatilityPercent' => round($volatility, 4),
            'impulse' => $impulse,
            'rsi14' => round($rsi14, 2),
            'emaBullish' => $emaBullish,
            'macdBullish' => $macd['bullish'],
            'macdHistogram' => round($macd['histogram'], 8),
            'macdHistogramGrowing' => $macd['histogramGrowing'],
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
            'rsi14' => 50.0,
            'emaBullish' => null,
            'macdBullish' => null,
            'macdHistogram' => 0.0,
            'macdHistogramGrowing' => false,
        ];
    }

    private function calcMacd(array $closes, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
    {
        if (count($closes) < $slow + $signalPeriod) {
            return ['bullish' => null, 'histogram' => 0.0, 'histogramGrowing' => false, 'macd' => 0.0, 'signal' => 0.0];
        }
        $fastMult = 2.0 / ($fast + 1);
        $slowMult = 2.0 / ($slow + 1);
        // Seed fast EMA at first 'fast' candles, evolve up to 'slow'
        $fastEma = array_sum(array_slice($closes, 0, $fast)) / $fast;
        for ($i = $fast; $i < $slow; $i++) {
            $fastEma = ($closes[$i] - $fastEma) * $fastMult + $fastEma;
        }
        // Seed slow EMA at first 'slow' candles
        $slowEma = array_sum(array_slice($closes, 0, $slow)) / $slow;
        // Build MACD line from slow onwards
        $macdLine = [];
        for ($i = $slow; $i < count($closes); $i++) {
            $fastEma = ($closes[$i] - $fastEma) * $fastMult + $fastEma;
            $slowEma = ($closes[$i] - $slowEma) * $slowMult + $slowEma;
            $macdLine[] = $fastEma - $slowEma;
        }
        if (count($macdLine) < $signalPeriod) {
            $last = end($macdLine) ?: 0.0;
            return ['bullish' => null, 'histogram' => 0.0, 'histogramGrowing' => false, 'macd' => $last, 'signal' => 0.0];
        }
        // Signal line = EMA(signalPeriod) of MACD line
        $sigMult = 2.0 / ($signalPeriod + 1);
        $sigEma = array_sum(array_slice($macdLine, 0, $signalPeriod)) / $signalPeriod;
        for ($i = $signalPeriod; $i < count($macdLine); $i++) {
            $sigEma = ($macdLine[$i] - $sigEma) * $sigMult + $sigEma;
        }
        $macdVal = end($macdLine);
        $prevMacdVal = $macdLine[count($macdLine) - 2] ?? $macdVal;
        $histogram = $macdVal - $sigEma;
        $prevHistogram = $prevMacdVal - $sigEma;
        return [
            'bullish' => $macdVal > $sigEma,
            'histogram' => $histogram,
            'histogramGrowing' => $histogram > $prevHistogram,
            'macd' => $macdVal,
            'signal' => $sigEma,
        ];
    }

    private function calcEma(array $values, int $period): float
    {
        if (count($values) < $period) {
            return (float) (end($values) ?: 0.0);
        }
        $multiplier = 2.0 / ($period + 1);
        $sma = array_sum(array_slice($values, 0, $period)) / $period;
        $ema = $sma;
        for ($i = $period; $i < count($values); $i++) {
            $ema = ($values[$i] - $ema) * $multiplier + $ema;
        }
        return $ema;
    }

    private function calcRsi(array $closes, int $period = 14): float
    {
        if (count($closes) < $period + 1) {
            return 50.0;
        }
        $avgGain = 0.0;
        $avgLoss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff > 0) {
                $avgGain += $diff;
            } else {
                $avgLoss += abs($diff);
            }
        }
        $avgGain /= $period;
        $avgLoss /= $period;
        for ($i = $period + 1; $i < count($closes); $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $avgGain = ($avgGain * ($period - 1) + max(0.0, $diff)) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + max(0.0, -$diff)) / $period;
        }
        if ($avgLoss == 0.0) {
            return 100.0;
        }
        return round(100 - (100 / (1 + $avgGain / $avgLoss)), 2);
    }

    private function higherTfSnapshots(array $symbols, array $settings, string $base): array
    {
        $cacheKey = 'future_trade_htf_v2_' . md5(implode(',', $symbols));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $snapshots = [];
        try {
            // Fetch 1h and 4h candles in one parallel pool for efficiency
            $responses = Http::pool(function (Pool $pool) use ($symbols, $base) {
                $requests = [];
                foreach ($symbols as $symbol) {
                    $requests[$symbol . '_1h'] = $pool->as($symbol . '_1h')->timeout(6)
                        ->get($base . '/fapi/v1/klines', ['symbol' => $symbol, 'interval' => '1h', 'limit' => 55]);
                    $requests[$symbol . '_4h'] = $pool->as($symbol . '_4h')->timeout(6)
                        ->get($base . '/fapi/v1/klines', ['symbol' => $symbol, 'interval' => '4h', 'limit' => 55]);
                }
                return $requests;
            });
            foreach ($symbols as $symbol) {
                try {
                    $closes1h = $this->extractCloses($responses[$symbol . '_1h'] ?? null);
                    $closes4h = $this->extractCloses($responses[$symbol . '_4h'] ?? null);
                    $last1h = end($closes1h) ?: 0.0;
                    $last4h = end($closes4h) ?: 0.0;
                    $ema50_1h = count($closes1h) >= 50 ? $this->calcEma($closes1h, 50) : 0.0;
                    $ema50_4h = count($closes4h) >= 50 ? $this->calcEma($closes4h, 50) : 0.0;
                    $snapshots[$symbol] = [
                        'emaBullish'   => $ema50_1h > 0 ? $last1h > $ema50_1h : null,  // 1h EMA50
                        'h4EmaBullish' => $ema50_4h > 0 ? $last4h > $ema50_4h : null,  // 4h EMA50
                        'ema50_1h'     => round($ema50_1h, 4),
                        'ema50_4h'     => round($ema50_4h, 4),
                    ];
                } catch (Throwable) {
                    $snapshots[$symbol] = ['emaBullish' => null, 'h4EmaBullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0];
                }
            }
            if (! empty($snapshots)) {
                Cache::put($cacheKey, $snapshots, 300); // 5-min cache for HTF data
            }
        } catch (Throwable) {
            foreach ($symbols as $symbol) {
                $snapshots[$symbol] = ['emaBullish' => null, 'h4EmaBullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0];
            }
        }
        return $snapshots;
    }

    private function extractCloses($response): array
    {
        if (! $response || ! method_exists($response, 'successful') || ! $response->successful()) {
            return [];
        }
        $payload = $response->json();
        $closes = [];
        foreach ((array) $payload as $row) {
            if (is_array($row) && isset($row[4]) && (float) $row[4] > 0) {
                $closes[] = (float) $row[4];
            }
        }
        return $closes;
    }

    private function symbolPerformanceAdjustment(string $symbol, string $configId): float
    {
        $stats = Cache::remember('future_trade_sym_perf_' . md5($configId . $symbol), 300, function () use ($symbol, $configId) {
            return \Illuminate\Support\Facades\DB::table('future_trade_bot_trades')
                ->where('future_trade_bot_config_id', $configId)
                ->where('symbol', $symbol)
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN net_pnl > 0 THEN 1 ELSE 0 END) as wins')
                ->first();
        });
        if (! $stats || (int) $stats->total < 10) {
            return 0.0; // insufficient data — no adjustment
        }
        $winRate = (int) $stats->wins / (int) $stats->total;
        if ($winRate < 0.28) {
            return -0.14; // very poor symbol — strongly penalize
        }
        if ($winRate < 0.40) {
            return -0.07; // below average
        }
        if ($winRate >= 0.65) {
            return +0.05; // proven good performer — slight bonus
        }
        return 0.0;
    }

    private function isWithinTradingHours(array $settings): bool
    {
        if (! ($settings['tradingHoursEnabled'] ?? false)) {
            return true; // feature off — always trade
        }
        $startUtc = (string) ($settings['tradingHoursStartUtc'] ?? '00:00');
        $endUtc   = (string) ($settings['tradingHoursEndUtc']   ?? '23:59');
        [$sh, $sm] = array_map('intval', explode(':', $startUtc . ':00'));
        [$eh, $em] = array_map('intval', explode(':', $endUtc   . ':00'));
        $now = now()->utc();
        $nowMinutes   = $now->hour * 60 + $now->minute;
        $startMinutes = $sh * 60 + $sm;
        $endMinutes   = $eh * 60 + $em;
        // Support overnight windows (e.g. 22:00 – 06:00)
        if ($startMinutes <= $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes <= $endMinutes;
        }
        return $nowMinutes >= $startMinutes || $nowMinutes <= $endMinutes;
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
        $baseTakeProfit = (float) ($settings['takeProfitPercent'] ?? 0.45);
        $baseStopLoss = (float) ($settings['stopLossPercent'] ?? 0.25);
        $baseTrailing = (float) ($settings['trailingTakeProfitPercent'] ?? 0.18);
        $baseCooldown = max(10, (int) ($settings['cooldownSeconds'] ?? 60));
        $baseTradeAmount = max(25.0, (float) ($settings['tradeAmountUsdt'] ?? 25));

        if ($mode !== 'fixed') {
            if ($analysis['choppy']) {
                $effective += $mode === 'fully_dynamic' ? 0.06 : 0.04;
                $next['takeProfitPercent'] = max(0.55, $baseTakeProfit * ($mode === 'fully_dynamic' ? 0.82 : 0.90));
                $next['trailingTakeProfitPercent'] = max(0.03, $baseTrailing * 0.85);
                $adjustments[] = 'Choppy market: require stronger confidence and take faster profit.';
            } elseif ($analysis['trendStrength'] >= 0.68 && $analysis['directionAgreement'] >= 0.58) {
                $effective -= $mode === 'fully_dynamic' ? 0.045 : 0.028;
                $next['takeProfitPercent'] = $baseTakeProfit * ($mode === 'fully_dynamic' ? 1.20 : 1.10);
                $next['trailingTakeProfitPercent'] = $baseTrailing * ($mode === 'fully_dynamic' ? 1.08 : 1.03);
                $adjustments[] = 'Strong aligned trend: allow earlier entry and let target breathe.';
            } else {
                $effective += $mode === 'fully_dynamic' ? 0.01 : 0.005;
                $adjustments[] = 'Mixed signal: keep entry slightly stricter.';
            }

            if ($analysis['confidenceGap'] < 0.025) {
                $effective += $mode === 'fully_dynamic' ? 0.018 : 0.01;
                $adjustments[] = 'Top signals are close together: avoid weak ranking edge.';
            }

            if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                $effective += $mode === 'fully_dynamic' ? 0.045 : 0.03;
                $next['cooldownSeconds'] = max($baseCooldown, $mode === 'fully_dynamic' ? 120 : 90);
                $adjustments[] = 'Recent loss: raise threshold and extend cooldown.';
            } elseif ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'TAKE_PROFIT')) {
                $effective += (float) ($settings['dynamicReentryBuffer'] ?? 0.02) * 0.7;
                $adjustments[] = 'Recent take profit: require a re-entry buffer to avoid chasing.';
            }

            if ($mode === 'fully_dynamic') {
                $winRate = $analysis['winRate'];
                $totalTrades = max(0, (int) ($analysis['totalTrades'] ?? 0));
                $sampleWeight = $this->clamp((float) ($analysis['winRateSampleWeight'] ?? 0), 0.0, 1.0);
                if ($totalTrades < 5) {
                    $adjustments[] = 'Limited trade sample: keep win-rate influence muted until more closes are recorded.';
                }

                if ($winRate > 0 && $winRate < 45 && $sampleWeight >= 0.05) {
                    $effective += 0.012 + (0.028 * $sampleWeight);
                    $next['tradeAmountUsdt'] = max(25, $baseTradeAmount * (1 - (0.10 + (0.15 * $sampleWeight))));
                    $adjustments[] = sprintf('Weak win rate over %d closed trades: reduce size and demand cleaner entries.', $totalTrades);
                } elseif ($winRate >= 62 && $analysis['trendStrength'] >= 0.55 && $sampleWeight >= 0.35) {
                    $effective -= 0.006 + (0.014 * $sampleWeight);
                    $adjustments[] = sprintf('Healthy win rate over %d closed trades with trend support: modestly loosen entry.', $totalTrades);
                }

                $atrPercent = max(0.08, (float) ($analysis['atrPercent'] ?? 0));
                $volatilityPercent = max(0.04, (float) ($analysis['volatilityPercent'] ?? 0));
                $regime = (string) ($analysis['regime'] ?? 'balanced');
                $stopFloor = max(0.12, $baseStopLoss * 0.78);
                $stopCeiling = max($stopFloor + 0.05, min(0.95, $baseStopLoss * 1.55));
                $targetFloor = max(0.55, $baseTakeProfit * 0.75);
                $targetCeiling = max($targetFloor + 0.1, min(1.8, $baseTakeProfit * 1.55));
                $trailingFloor = max(0.03, $baseTrailing * 0.75);
                $trailingCeiling = max($trailingFloor + 0.02, min(0.75, $baseTrailing * 1.35));
                $atrStop = match ($regime) {
                    'trend' => $atrPercent * 0.72,
                    'choppy' => $atrPercent * 0.54,
                    'range' => $atrPercent * 0.60,
                    default => $atrPercent * 0.66,
                };
                $dynamicStop = $this->clamp(max($stopFloor, $atrStop), $stopFloor, $stopCeiling);
                $rewardMultiple = match ($regime) {
                    'trend' => 2.3,
                    'choppy' => 1.55,
                    'range' => 1.75,
                    default => 1.95,
                };
                $dynamicTarget = $this->clamp(max($targetFloor, $dynamicStop * $rewardMultiple), $targetFloor, $targetCeiling);
                $dynamicTrailing = $this->clamp(max($trailingFloor, $dynamicStop * ($regime === 'trend' ? 0.58 : 0.5)), $trailingFloor, $trailingCeiling);
                $cooldownBase = match ($regime) {
                    'trend' => max(45, (int) round($baseCooldown * 0.85)),
                    'choppy' => max($baseCooldown, 120),
                    'range' => max($baseCooldown, 90),
                    default => max($baseCooldown, 75),
                };
                $volatilityCooldown = (int) round($volatilityPercent * 160);
                $dynamicCooldown = (int) $this->clamp((float) max($cooldownBase, $volatilityCooldown), 45.0, 180.0);
                if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                    $dynamicCooldown = max($dynamicCooldown, 120);
                }
                $sizeMultiplier = 1.0;
                if ($analysis['choppy']) {
                    $sizeMultiplier *= 0.82;
                } elseif ($regime === 'range') {
                    $sizeMultiplier *= 0.9;
                }
                if ($volatilityPercent >= 0.4) {
                    $sizeMultiplier *= 0.85;
                }
                if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                    $sizeMultiplier *= 0.9;
                }
                $preAdjustedTradeAmount = max(25.0, (float) ($next['tradeAmountUsdt'] ?? $baseTradeAmount));
                $dynamicTradeAmount = $this->clamp($preAdjustedTradeAmount * $sizeMultiplier, 25.0, $baseTradeAmount);

                $next['stopLossPercent'] = $dynamicStop;
                $next['takeProfitPercent'] = $dynamicTarget;
                $next['trailingTakeProfitPercent'] = $dynamicTrailing;
                $next['cooldownSeconds'] = $dynamicCooldown;
                $next['tradeAmountUsdt'] = $dynamicTradeAmount;
                if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                    $effective = max($effective, $base + 0.01);
                }
                $adjustments[] = sprintf(
                    'Regime %s: ATR %.3f%% and volatility %.3f%% tuned TP/SL/cooldown/size inside conservative caps.',
                    $regime,
                    $atrPercent,
                    $volatilityPercent
                );
            }
        } else {
            $adjustments[] = 'Fixed mode: using the manual minConfidence without automatic adjustment.';
        }

        $effective = $this->clamp($effective, $floor, $ceiling);
        $next['minConfidence'] = $effective;
        $next['takeProfitPercent'] = $this->clamp((float) ($next['takeProfitPercent'] ?? $baseTakeProfit), 0.55, max(0.60, $baseTakeProfit * 1.6));
        $next['stopLossPercent'] = $this->clamp((float) ($next['stopLossPercent'] ?? $baseStopLoss), 0.05, max(0.06, $baseStopLoss * 1.6));
        $next['trailingTakeProfitPercent'] = $this->clamp((float) ($next['trailingTakeProfitPercent'] ?? $baseTrailing), 0.03, max(0.04, $baseTrailing * 1.4));
        $next['cooldownSeconds'] = (int) $this->clamp((float) ($next['cooldownSeconds'] ?? $baseCooldown), 10.0, 180.0);
        $next['tradeAmountUsdt'] = $this->clamp((float) ($next['tradeAmountUsdt'] ?? $baseTradeAmount), 25.0, max(25.0, $baseTradeAmount));

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

    private function entryReasonSummary(FutureTradeBotState $state, ?array $bestSignal, float $effectiveMinConfidence): ?string
    {
        if ($state->daily_stop_until && now()->lt($state->daily_stop_until)) {
            return 'Daily loss limit lock is active until the next trading day reset.';
        }

        if ($state->cooldown_until && now()->lt($state->cooldown_until) && ((string) $state->state !== 'IN_POSITION')) {
            return sprintf(
                'Cooldown active until %s.',
                $state->cooldown_until->timezone(config('app.timezone'))->format('H:i:s')
            );
        }

        if (! $bestSignal) {
            return 'Scanner has not produced a ranked signal yet.';
        }

        $parts = [];
        $reason = trim((string) ($bestSignal['reason'] ?? ''));
        if ($reason !== '') {
            $parts[] = $reason;
        }

        $signal = strtoupper((string) ($bestSignal['signal'] ?? 'WAIT'));
        $confidence = (float) ($bestSignal['confidence'] ?? 0);
        if ($confidence < $effectiveMinConfidence) {
            $parts[] = sprintf(
                'Confidence %.1f%% is below active minimum %.1f%%.',
                $confidence * 100,
                $effectiveMinConfidence * 100
            );
        } elseif ($signal === 'WAIT') {
            $parts[] = sprintf(
                'Confidence %.1f%% is ready, but the entry guard still wants a cleaner sequence.',
                $confidence * 100
            );
        }

        return implode(' ', array_values(array_filter($parts)));
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
        $usableSignals = array_values(array_filter($signals, fn ($signal) => isset($signal['signal']) && $signal['signal'] !== 'WAIT'));
        $bestSignalDirection = strtoupper((string) ($best['signal'] ?? ''));
        $priceDirection = $priceChangePct >= 0 ? 'BUY' : 'SELL';
        $agreement = count($usableSignals)
            ? count(array_filter($usableSignals, fn ($signal) => strtoupper((string) ($signal['signal'] ?? '')) === ($bestSignalDirection ?: $priceDirection))) / count($usableSignals)
            : 0.0;
        $direction = in_array($bestSignalDirection, ['BUY', 'SELL'], true) && $agreement >= 0.5
            ? $bestSignalDirection
            : $priceDirection;
        $directionSource = in_array($bestSignalDirection, ['BUY', 'SELL'], true) && $agreement >= 0.5
            ? 'scanner_consensus'
            : (count($usableSignals) > 0 ? 'price_action_fallback' : 'price_action_only');
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
        $totalTrades = $wins + $losses;
        $winRate = $totalTrades > 0 ? round(($wins / $totalTrades) * 100, 2) : 0.0;
        $winRateSampleWeight = $this->clamp(($totalTrades - 3) / 12, 0, 1);
        $atrPercent = $this->atrPercentProxy($points, $best);
        $regime = $choppy
            ? 'choppy'
            : ($trendStrength >= 0.68 && $agreement >= 0.58
                ? 'trend'
                : ($volatility >= 0.22 ? 'range' : 'balanced'));

        return [
            'lookbackMinutes' => $lookbackMinutes,
            'sampleCount' => count($prices),
            'usableSignalCount' => count($usableSignals),
            'priceChangePercent' => round($priceChangePct, 4),
            'volatilityPercent' => round($volatility, 4),
            'atrPercent' => round($atrPercent, 4),
            'rangePercent' => round($range, 4),
            'direction' => $direction,
            'priceDirection' => $priceDirection,
            'signalDirection' => $bestSignalDirection ?: 'WAIT',
            'directionSource' => $directionSource,
            'directionAgreement' => round($agreement, 4),
            'confidenceGap' => round($gap, 4),
            'trendStrength' => round($trendStrength, 4),
            'choppy' => $choppy,
            'regime' => $regime,
            'bestConfidence' => round($bestConfidence, 4),
            'lastTradeReason' => $stateReason ?: $lastTrade?->reason,
            'winRate' => $winRate,
            'totalTrades' => $totalTrades,
            'winRateSampleWeight' => round($winRateSampleWeight, 4),
        ];
    }

    private function dynamicSuggestionText(string $mode, array $analysis, float $base, array $settings, array $adjustments): string
    {
        $modeLabel = str_replace('_', ' ', $mode);
        $bias = $analysis['choppy'] ? 'market is choppy' : 'market bias is ' . $analysis['direction'];
        $lines = [
            sprintf('%s mode: %s over the last %d minute window.', ucfirst($modeLabel), $bias, (int) $analysis['lookbackMinutes']),
            sprintf('Base minConfidence %.2f, effective %.2f, trend strength %.0f%%, agreement %.0f%%.', $base, (float) ($settings['minConfidence'] ?? $base), $analysis['trendStrength'] * 100, $analysis['directionAgreement'] * 100),
            sprintf('Recommended values: TP %.2f%%, SL %.2f%%, trailing %.2f%%, cooldown %ds, size %.2f USDT.', (float) ($settings['takeProfitPercent'] ?? 0), (float) ($settings['stopLossPercent'] ?? 0), (float) ($settings['trailingTakeProfitPercent'] ?? 0), (int) ($settings['cooldownSeconds'] ?? 0), (float) ($settings['tradeAmountUsdt'] ?? 0)),
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

    private function atrPercentProxy(array $points, ?array $best): float
    {
        $prices = array_values(array_filter(array_map(fn ($row) => (float) ($row['price'] ?? 0), $points), fn ($value) => $value > 0));
        $moves = [];
        for ($i = 1; $i < count($prices); $i++) {
            if ($prices[$i - 1] > 0) {
                $moves[] = abs((($prices[$i] - $prices[$i - 1]) / $prices[$i - 1]) * 100);
            }
        }

        $chartAtr = count($moves) > 0 ? array_sum($moves) / count($moves) : 0.0;
        $signalRange = max(0.0, (float) ($best['rangePercent'] ?? 0));

        return max($chartAtr * 1.35, $signalRange * 0.22, $chartAtr > 0 ? 0.08 : 0.12);
    }

    private function minimumSafeTradeAmountUsdt(float $baseTradeAmountUsdt, int $leverage, float $price, array $filters): ?float
    {
        if ($price <= 0 || $leverage <= 0) {
            return null;
        }

        $stepSize = max(0.0, (float) ($filters['stepSize'] ?? 0));
        $exchangeMinNotional = max(\App\Domain\Trading\FuturesOrderSizingService::BINANCE_MIN_OPEN_NOTIONAL, (float) ($filters['minNotional'] ?? 0));
        if ($stepSize <= 0 && $exchangeMinNotional <= 0) {
            return null;
        }

        $requiredQty = max($stepSize, $exchangeMinNotional > 0 ? ($exchangeMinNotional / $price) : 0.0);
        if ($requiredQty <= 0) {
            return null;
        }

        $bufferedQty = max($stepSize, $requiredQty * 1.02);
        $requiredTradeAmount = ($bufferedQty * $price) / $leverage;
        $softCap = max($baseTradeAmountUsdt + 5.0, $baseTradeAmountUsdt * 1.35);
        if ($requiredTradeAmount > $softCap) {
            return null;
        }

        return round(max(25.0, $requiredTradeAmount), 4);
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

    private function resolveMarketChartSymbol(FutureTradeBotState $state, ?array $bestSignal, array $settings): ?string
    {
        $bestSymbol = strtoupper(trim((string) ($bestSignal['symbol'] ?? '')));
        if ($bestSymbol !== '') {
            return $bestSymbol;
        }

        $currentSymbol = strtoupper(trim((string) ($state->current_symbol ?? '')));
        if ($currentSymbol !== '') {
            return $currentSymbol;
        }

        $symbols = array_values(array_filter((array) ($settings['symbols'] ?? []), fn ($value) => is_string($value) && trim($value) !== ''));

        return isset($symbols[0]) ? strtoupper((string) $symbols[0]) : null;
    }

    private function marketChartPayload(?string $symbol, string $timeframe, int $limit = 180, ?array $candles = null): array
    {
        if (! $symbol) {
            return [
                'symbol' => null,
                'timeframe' => $timeframe,
                'candles' => [],
            ];
        }

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'candles' => $candles ?? $this->fetchMarketCandles($symbol, $timeframe, $limit),
        ];
    }

    private function fetchMarketCandles(string $symbol, string $timeframe, int $limit = 180): array
    {
        $interval = $this->normalizeKlineInterval($timeframe);
        $safeLimit = max(60, min(500, $limit));
        $cacheKey = 'future_trade_market_chart_' . md5($symbol . '|' . $interval . '|' . $safeLimit);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $base = rtrim((string) config('services.binance.futures.base_url', 'https://fapi.binance.com'), '/');
            $response = Http::timeout(5)->get($base . '/fapi/v1/klines', [
                'symbol' => $symbol,
                'interval' => $interval,
                'limit' => $safeLimit,
            ]);

            $rows = $response->successful() ? $response->json() : [];
            $candles = [];

            foreach ((array) $rows as $row) {
                if (! is_array($row) || count($row) < 6) {
                    continue;
                }

                $openTime = (int) ($row[0] ?? 0);
                $open = (float) ($row[1] ?? 0);
                $high = (float) ($row[2] ?? 0);
                $low = (float) ($row[3] ?? 0);
                $close = (float) ($row[4] ?? 0);
                $volume = (float) ($row[5] ?? 0);

                if ($openTime <= 0 || $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                    continue;
                }

                $candles[] = [
                    'time' => Carbon::createFromTimestampMs($openTime)->toIso8601String(),
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => $volume,
                ];
            }

            Cache::put($cacheKey, $candles, now()->addSeconds(8));

            return $candles;
        } catch (Throwable) {
            return [];
        }
    }

    private function normalizeKlineInterval(string $timeframe): string
    {
        $candidate = strtolower(trim($timeframe));

        return match ($candidate) {
            '1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d' => $candidate,
            default => '5m',
        };
    }

    private function indicatorSuitePayload(array $candles, ?array $bestSignal, array $settings, string $timeframe): array
    {
        if (count($candles) < 40) {
            return [
                'preset' => 'PRO Scalper',
                'state' => 'warming_up',
                'summary' => 'Not enough live candles for indicator confirmation yet.',
                'validations' => [],
                'backtest' => [
                    'trades' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'winRate' => 0.0,
                    'netPercent' => 0.0,
                    'bestTrade' => 0.0,
                    'worstTrade' => 0.0,
                ],
            ];
        }

        $enriched = $this->buildIndicatorCandles($candles, $timeframe);
        $latest = $enriched[count($enriched) - 1];
        $signal = strtoupper((string) ($bestSignal['signal'] ?? 'WAIT'));

        $validationMap = [
            'vwap' => $signal === 'BUY'
                ? $latest['close'] >= $latest['vwap']
                : ($signal === 'SELL' ? $latest['close'] <= $latest['vwap'] : null),
            'emaTrend' => $signal === 'BUY'
                ? $latest['emaFast'] > $latest['emaSlow']
                : ($signal === 'SELL' ? $latest['emaFast'] < $latest['emaSlow'] : null),
            'adxStrength' => $latest['adx'] >= 18,
            'openingRange' => $signal === 'BUY'
                ? ($latest['orHigh'] !== null && $latest['close'] >= $latest['orHigh'])
                : ($signal === 'SELL' ? ($latest['orLow'] !== null && $latest['close'] <= $latest['orLow']) : null),
            'notChoppy' => ! $latest['choppy'],
        ];

        $validations = [];
        $passed = 0;
        $total = 0;

        foreach ($validationMap as $key => $result) {
            if ($result === null) {
                continue;
            }
            $total++;
            $passed += $result ? 1 : 0;
            $validations[] = [
                'key' => $key,
                'label' => match ($key) {
                    'vwap' => 'Price aligned with VWAP',
                    'emaTrend' => 'EMA trend filter aligned',
                    'adxStrength' => 'ADX trend strength ready',
                    'openingRange' => 'Opening range breakout confirmed',
                    'notChoppy' => 'Market is not choppy',
                    default => $key,
                },
                'passed' => (bool) $result,
            ];
        }

        $score = $total > 0 ? round($passed / $total, 4) : 0.0;
        $executionReady = in_array($signal, ['BUY', 'SELL'], true) && $score >= 0.8;
        $backtest = $this->indicatorBacktestSummary($enriched, $settings);

        return [
            'preset' => 'PRO Scalper',
            'state' => $executionReady ? 'confirmed' : (in_array($signal, ['BUY', 'SELL'], true) ? 'mixed' : 'waiting'),
            'summary' => $executionReady
                ? 'PRO Scalper confirmation layer agrees with the active scanner direction.'
                : (in_array($signal, ['BUY', 'SELL'], true)
                    ? 'Scanner found a direction, but PRO Scalper confirmations are still mixed.'
                    : 'PRO Scalper is waiting for a cleaner execution setup.'),
            'signal' => $signal,
            'score' => $score,
            'executionReady' => $executionReady,
            'trend' => [
                'direction' => $latest['emaFast'] >= $latest['emaSlow'] ? 'BUY' : 'SELL',
                'adx' => round((float) $latest['adx'], 4),
                'vwap' => round((float) $latest['vwap'], 8),
                'openingRangeHigh' => $latest['orHigh'] !== null ? round((float) $latest['orHigh'], 8) : null,
                'openingRangeLow' => $latest['orLow'] !== null ? round((float) $latest['orLow'], 8) : null,
                'bubbleCount' => $this->indicatorBubbleCount($enriched),
                'zones' => $this->indicatorZoneSummary($enriched),
            ],
            'validations' => $validations,
            'backtest' => $backtest,
        ];
    }

    private function buildIndicatorCandles(array $candles, string $timeframe): array
    {
        $prepared = [];
        $closes = array_map(fn (array $row) => (float) ($row['close'] ?? 0), $candles);
        $emaFast = $this->emaSeries($closes, 34);
        $emaSlow = $this->emaSeries($closes, 89);
        $adx = $this->adxSeries($candles, 14);
        $atr = $this->atrSeries($candles, 14);
        $tfMinutes = $this->timeframeMinutes($timeframe);
        $orBars = max(1, (int) ceil((3 * $tfMinutes) / max($tfMinutes, 1)));
        $cumPv = 0.0;
        $cumV = 0.0;
        $orHigh = null;
        $orLow = null;

        foreach (array_values($candles) as $index => $row) {
            $high = (float) ($row['high'] ?? 0);
            $low = (float) ($row['low'] ?? 0);
            $close = (float) ($row['close'] ?? 0);
            $open = (float) ($row['open'] ?? $close);
            $volume = (float) ($row['volume'] ?? 0);
            $hlc3 = ($high + $low + $close) / 3;
            $cumPv += $hlc3 * max($volume, 0);
            $cumV += max($volume, 0);

            if ($index < $orBars) {
                $orHigh = $orHigh === null ? $high : max($orHigh, $high);
                $orLow = $orLow === null ? $low : min($orLow, $low);
            }

            $range = max($high - $low, 0.00000001);
            $priceMovePct = $open > 0 ? (($close - $open) / $open) * 100 : 0.0;

            $prepared[] = [
                'index' => $index,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'vwap' => $cumV > 0 ? $cumPv / $cumV : $close,
                'emaFast' => $emaFast[$index] ?? $close,
                'emaSlow' => $emaSlow[$index] ?? $close,
                'adx' => $adx[$index] ?? 0.0,
                'atr' => $atr[$index] ?? ($high - $low),
                'orHigh' => $index >= ($orBars - 1) ? $orHigh : null,
                'orLow' => $index >= ($orBars - 1) ? $orLow : null,
                'delta' => $range > 0 ? $volume * (($close - $open) / $range) : 0.0,
                'choppy' => (($high - $low) / max($close, 1)) * 100 > 7.5 && abs($priceMovePct) < 0.35,
            ];
        }

        return $prepared;
    }

    private function indicatorBacktestSummary(array $candles, array $settings): array
    {
        $position = null;
        $trades = [];
        $tp = max(0.0001, (float) ($settings['takeProfitPercent'] ?? 1.0) / 100);
        $sl = max(0.0001, (float) ($settings['stopLossPercent'] ?? 0.3) / 100);

        foreach ($candles as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $longSignal = $row['close'] > $row['vwap']
                && $row['emaFast'] > $row['emaSlow']
                && $row['adx'] >= 18
                && ! $row['choppy']
                && $row['orHigh'] !== null
                && $row['close'] >= $row['orHigh'];
            $shortSignal = $row['close'] < $row['vwap']
                && $row['emaFast'] < $row['emaSlow']
                && $row['adx'] >= 18
                && ! $row['choppy']
                && $row['orLow'] !== null
                && $row['close'] <= $row['orLow'];

            if ($position === null) {
                if ($longSignal) {
                    $position = ['side' => 'BUY', 'entry' => (float) $row['close']];
                } elseif ($shortSignal) {
                    $position = ['side' => 'SELL', 'entry' => (float) $row['close']];
                }
                continue;
            }

            $entry = (float) ($position['entry'] ?? 0);
            if ($entry <= 0) {
                $position = null;
                continue;
            }

            $pnl = ($position['side'] ?? 'BUY') === 'BUY'
                ? (((float) $row['close'] - $entry) / $entry)
                : (($entry - (float) $row['close']) / $entry);

            $reverse = (($position['side'] ?? 'BUY') === 'BUY' && $shortSignal)
                || (($position['side'] ?? 'BUY') === 'SELL' && $longSignal);

            if ($pnl >= $tp || $pnl <= -$sl || $reverse) {
                $trades[] = round($pnl * 100, 4);
                $position = null;
            }
        }

        $wins = count(array_filter($trades, fn (float $value) => $value > 0));
        $losses = count($trades) - $wins;

        return [
            'trades' => count($trades),
            'wins' => $wins,
            'losses' => $losses,
            'winRate' => count($trades) > 0 ? round(($wins / count($trades)) * 100, 2) : 0.0,
            'netPercent' => round(array_sum($trades), 4),
            'bestTrade' => count($trades) > 0 ? max($trades) : 0.0,
            'worstTrade' => count($trades) > 0 ? min($trades) : 0.0,
        ];
    }

    private function indicatorBubbleCount(array $candles): int
    {
        $absDelta = array_map(fn (array $row) => abs((float) ($row['delta'] ?? 0)), $candles);
        $threshold = $this->percentile($absDelta, 85);

        return count(array_filter($absDelta, fn (float $value) => $value >= max($threshold, 1.0)));
    }

    private function indicatorZoneSummary(array $candles): array
    {
        $highs = array_map(fn (array $row) => (float) ($row['high'] ?? 0), $candles);
        $lows = array_map(fn (array $row) => (float) ($row['low'] ?? 0), $candles);

        return [
            'supply' => count($highs) ? round(max($highs), 8) : null,
            'demand' => count($lows) ? round(min($lows), 8) : null,
        ];
    }

    private function emaSeries(array $values, int $length): array
    {
        if ($values === []) {
            return [];
        }

        $multiplier = 2 / ($length + 1);
        $series = [];
        $prev = (float) $values[0];
        $series[] = $prev;

        for ($i = 1; $i < count($values); $i++) {
            $prev = (((float) $values[$i] - $prev) * $multiplier) + $prev;
            $series[] = $prev;
        }

        return $series;
    }

    private function rmaSeries(array $values, int $length): array
    {
        if ($values === []) {
            return [];
        }

        $series = [];
        $prev = (float) $values[0];
        $series[] = $prev;

        for ($i = 1; $i < count($values); $i++) {
            $prev = (($prev * ($length - 1)) + (float) $values[$i]) / $length;
            $series[] = $prev;
        }

        return $series;
    }

    private function atrSeries(array $candles, int $length): array
    {
        if ($candles === []) {
            return [];
        }

        $values = [];
        foreach (array_values($candles) as $index => $row) {
            $high = (float) ($row['high'] ?? 0);
            $low = (float) ($row['low'] ?? 0);
            if ($index === 0) {
                $values[] = max(0.0, $high - $low);
                continue;
            }
            $prevClose = (float) ($candles[$index - 1]['close'] ?? $high);
            $values[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        return $this->rmaSeries($values, $length);
    }

    private function adxSeries(array $candles, int $length): array
    {
        if ($candles === []) {
            return [];
        }

        $plusDm = [0.0];
        $minusDm = [0.0];
        $tr = [max(0.0, ((float) ($candles[0]['high'] ?? 0)) - ((float) ($candles[0]['low'] ?? 0)))];

        for ($i = 1; $i < count($candles); $i++) {
            $current = $candles[$i];
            $prev = $candles[$i - 1];
            $upMove = (float) ($current['high'] ?? 0) - (float) ($prev['high'] ?? 0);
            $downMove = (float) ($prev['low'] ?? 0) - (float) ($current['low'] ?? 0);
            $plusDm[] = $upMove > $downMove && $upMove > 0 ? $upMove : 0.0;
            $minusDm[] = $downMove > $upMove && $downMove > 0 ? $downMove : 0.0;
            $high = (float) ($current['high'] ?? 0);
            $low = (float) ($current['low'] ?? 0);
            $prevClose = (float) ($prev['close'] ?? $high);
            $tr[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        $smTr = $this->rmaSeries($tr, $length);
        $smPlus = $this->rmaSeries($plusDm, $length);
        $smMinus = $this->rmaSeries($minusDm, $length);
        $dx = [];

        for ($i = 0; $i < count($smTr); $i++) {
            $plusDi = $smTr[$i] != 0 ? (100 * $smPlus[$i]) / $smTr[$i] : 0.0;
            $minusDi = $smTr[$i] != 0 ? (100 * $smMinus[$i]) / $smTr[$i] : 0.0;
            $dx[] = ($plusDi + $minusDi) != 0 ? (100 * abs($plusDi - $minusDi)) / ($plusDi + $minusDi) : 0.0;
        }

        return $this->rmaSeries($dx, $length);
    }

    private function percentile(array $values, float $percent): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $index = (int) floor($this->clamp(($percent / 100) * (count($values) - 1), 0, max(0, count($values) - 1)));

        return (float) ($values[$index] ?? 0.0);
    }

    private function timeframeMinutes(string $timeframe): int
    {
        $normalized = strtolower(trim($timeframe));
        if (! preg_match('/^(\d+)([mhd])$/', $normalized, $matches)) {
            return 5;
        }

        $amount = (int) ($matches[1] ?? 5);
        $unit = (string) ($matches[2] ?? 'm');

        return match ($unit) {
            'h' => $amount * 60,
            'd' => $amount * 1440,
            default => $amount,
        };
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
            'balanceError' => null,
        ];

        if (! $this->isLiveMode($config)) {
            return $summary;
        }

        try {
            $cacheKey = 'future_trade_account_summary:' . $config->workspace_id . ':' . ($config->executionMode());
            $account = Cache::remember($cacheKey, now()->addSeconds(10), function () use ($config) {
                return $this->liveClient($config->mergedSettings())->fetchAccountInfo();
            });
            $asset = collect((array) ($account['assets'] ?? []))
                ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === 'USDT');

            $summary['walletBalance'] = isset($account['totalWalletBalance'])
                ? (float) $account['totalWalletBalance']
                : (isset($asset['walletBalance']) ? (float) $asset['walletBalance'] : null);
            $summary['availableBalance'] = isset($account['availableBalance'])
                ? (float) $account['availableBalance']
                : (isset($asset['availableBalance']) ? (float) $asset['availableBalance'] : null);
            $summary['marginBalance'] = isset($account['totalMarginBalance'])
                ? (float) $account['totalMarginBalance']
                : (isset($asset['marginBalance']) ? (float) $asset['marginBalance'] : null);
            $summary['accountAsset'] = strtoupper((string) ($asset['asset'] ?? 'USDT')) ?: 'USDT';
        } catch (Throwable $e) {
            $summary['balanceSource'] = 'BINANCE_UNAVAILABLE';
            $summary['balanceError'] = $e->getMessage();
            $this->logThrottled(
                $config->fresh('state'),
                'BALANCE_SYNC_FAILED',
                $config->state?->current_symbol,
                null,
                0,
                $e->getMessage(),
                ['status' => 'ERROR'],
                60
            );
        }

        return $summary;
    }

    private function isLiveMode(FutureTradeBotConfig $config): bool
    {
        $settings = $config->mergedSettings();
        return (bool) $config->enable_live_trading
            && (bool) ($settings['liveTrading'] ?? false)
            && $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true), $settings);
    }

    private function liveClient(array $settings): BinanceFuturesClient
    {
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        if (! $this->environmentTradingReady($forceTestnet, $settings)) {
            throw new \RuntimeException($forceTestnet
                ? 'Future Trade testnet trading requires Testnet mode enabled in My Config and valid testnet futures API credentials.'
                : 'Future Trade live trading requires Live mode enabled in My Config and valid live futures API credentials.'
            );
        }
        $baseUrl = $forceTestnet
            ? ((string) ($settings['testnetApiUrl'] ?? 'https://testnet.binancefuture.com'))
            : ((string) ($settings['liveApiUrl'] ?? 'https://fapi.binance.com'));
        $apiKey = $forceTestnet
            ? (string) ($settings['testnetApiKey'] ?? '')
            : (string) ($settings['liveApiKey'] ?? '');
        $apiSecret = $forceTestnet
            ? (string) ($settings['testnetApiSecret'] ?? '')
            : (string) ($settings['liveApiSecret'] ?? '');

        return new BinanceFuturesClient(
            apiKey: (string) $apiKey,
            apiSecret: (string) $apiSecret,
            baseUrl: (string) $baseUrl,
        );
    }

    private function environmentTradingEnabled(bool $forceTestnet, array $settings): bool
    {
        return $forceTestnet
            ? (bool) ($settings['envTestnetEnabled'] ?? false)
            : (bool) ($settings['envLiveEnabled'] ?? false);
    }

    private function environmentTradingReady(bool $forceTestnet, array $settings): bool
    {
        return $this->environmentTradingEnabled($forceTestnet, $settings) && $this->hasFuturesCredentials($forceTestnet, $settings);
    }

    private function hasFuturesCredentials(bool $forceTestnet, array $settings): bool
    {
        $key = $forceTestnet
            ? (string) ($settings['testnetApiKey'] ?? '')
            : (string) ($settings['liveApiKey'] ?? '');
        $secret = $forceTestnet
            ? (string) ($settings['testnetApiSecret'] ?? '')
            : (string) ($settings['liveApiSecret'] ?? '');

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
            'minNotional' => (float) (($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'MIN_NOTIONAL')['notional'] ?? $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'MIN_NOTIONAL')['minNotional'] ?? $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'NOTIONAL')['minNotional'] ?? $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'NOTIONAL')['notional'] ?? 0)),
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

    private function logThrottled(FutureTradeBotConfig $config, string $event, ?string $symbol, ?string $signal, float $confidence, string $reason, array $context = [], int $ttlSeconds = 60): void
    {
        $fingerprint = sha1(json_encode([$config->id, $event, $symbol, $signal, trim($reason)]));
        $cacheKey = 'future_trade_log_throttle:' . $fingerprint;

        if (! Cache::add($cacheKey, true, now()->addSeconds(max(5, $ttlSeconds)))) {
            return;
        }

        $this->log($config, $event, $symbol, $signal, $confidence, $reason, $context);
    }

    private function resolveRuntimeState(FutureTradeBotConfig $config, BinanceFuturesClient $client, string $symbol, string $reason): array
    {
        $exchange = $this->exchangePositionSnapshot($client, $symbol);
        $cancelled = $this->cancelReduceOnlyOrders($config, $client, $symbol, $exchange['reduce_only_orders']);
        $synced = $this->synchronizeLivePositionState($config, $client, $symbol, $reason, $exchange);

        return [
            'resolved' => true,
            'message' => (string) ($synced['message'] ?? 'Runtime state re-synced from Binance.'),
            'exchange_quantity' => (float) ($exchange['quantity'] ?? 0),
            'exchange_side' => $exchange['position_side'] ?? null,
            'cancelled_reduce_only_orders' => (int) ($cancelled['cancelled_reduce_only_orders'] ?? 0),
            'local_state_cleared' => (bool) ($synced['local_state_cleared'] ?? false),
            'position_still_open' => (bool) ($synced['position_still_open'] ?? false),
        ];
    }

    private function synchronizeLivePositionState(
        FutureTradeBotConfig $config,
        BinanceFuturesClient $client,
        string $symbol,
        string $reason,
        ?array $exchangeSnapshot = null,
    ): array {
        $state = $this->state($config);
        $exchange = $exchangeSnapshot ?? $this->exchangePositionSnapshot($client, $symbol);
        $exchangeQty = (float) ($exchange['quantity'] ?? 0);

        if ($exchangeQty <= 0) {
            $this->persistState($state, [
                'state' => $config->enabled ? 'SCANNING' : 'STOPPED',
                'status' => $config->enabled ? 'Running' : 'Stopped',
                'current_signal' => 'WAITING_FOR_SIGNAL',
                'entry_price' => null,
                'quantity' => 0,
                'position_side' => null,
                'peak_price' => null,
                'bottom_price' => null,
                'unrealized_pnl' => 0,
                'unrealized_pnl_percent' => 0,
                'current_price' => (float) ($exchange['mark_price'] ?? $state->current_price ?? 0),
                'connection_status' => 'Connected',
            ]);

            $this->log(
                $config->fresh('state'),
                'LIVE_STATE_SYNCED',
                $symbol,
                null,
                0,
                'Binance shows no open futures position. Local bot position state was cleared.',
                ['reason' => $reason, 'exchangeQuantity' => 0]
            );

            return [
                'resolved' => true,
                'local_state_cleared' => true,
                'position_still_open' => false,
                'exchange_quantity' => 0.0,
                'exchange_side' => null,
                'message' => 'Binance shows no open position now. Bot state was cleared and scanner can continue.',
            ];
        }

        $side = (string) ($exchange['position_side'] ?? $state->position_side ?? '');
        $entryPrice = (float) ($exchange['entry_price'] ?? $state->entry_price ?? 0);
        $markPrice = (float) ($exchange['mark_price'] ?? $state->current_price ?? 0);
        $unrealized = (float) ($exchange['unrealized_pnl'] ?? 0);
        $unrealizedPercent = $entryPrice > 0
            ? (($markPrice - $entryPrice) / $entryPrice) * ($side === 'LONG' ? 100 : -100)
            : 0.0;

        $this->persistState($state, [
            'state' => 'LIVE_IN_POSITION',
            'status' => 'Running',
            'current_symbol' => $symbol,
            'position_side' => $side,
            'entry_price' => $entryPrice,
            'quantity' => $exchangeQty,
            'current_price' => $markPrice,
            'unrealized_pnl' => $unrealized,
            'unrealized_pnl_percent' => $unrealizedPercent,
            'connection_status' => 'Connected',
        ]);

        $this->log(
            $config->fresh('state'),
            'LIVE_STATE_SYNCED',
            $symbol,
            null,
            0,
            'Future Trade local state was updated from Binance open position data.',
            ['reason' => $reason, 'exchangeQuantity' => $exchangeQty, 'exchangeSide' => $side]
        );

        return [
            'resolved' => true,
            'local_state_cleared' => false,
            'position_still_open' => true,
            'exchange_quantity' => $exchangeQty,
            'exchange_side' => $side,
            'message' => sprintf('Binance still has an open %s position of %.8f. Bot state was synced to exchange.', strtolower($side), $exchangeQty),
        ];
    }

    private function cancelReduceOnlyOrders(
        FutureTradeBotConfig $config,
        BinanceFuturesClient $client,
        string $symbol,
        ?array $orders = null,
    ): array {
        $source = $orders ?? $this->exchangePositionSnapshot($client, $symbol)['reduce_only_orders'];
        $cancelled = 0;

        foreach ($source as $order) {
            try {
                $client->cancelOrder(
                    $symbol,
                    isset($order['orderId']) ? (string) $order['orderId'] : null,
                    isset($order['clientOrderId']) ? (string) $order['clientOrderId'] : null,
                );
                $cancelled++;
            } catch (Throwable $e) {
                $this->log(
                    $config->fresh('state'),
                    'LIVE_CANCEL_ORDER_FAILED',
                    $symbol,
                    null,
                    0,
                    $e->getMessage(),
                    ['orderId' => $order['orderId'] ?? null, 'clientOrderId' => $order['clientOrderId'] ?? null]
                );
            }
        }

        if ($cancelled > 0) {
            $this->log(
                $config->fresh('state'),
                'LIVE_REDUCE_ONLY_CANCELLED',
                $symbol,
                null,
                0,
                sprintf('Cancelled %d Binance reduce-only close order(s).', $cancelled),
                ['cancelled' => $cancelled]
            );
        }

        return [
            'resolved' => true,
            'cancelled_reduce_only_orders' => $cancelled,
            'message' => $cancelled > 0
                ? sprintf('Cancelled %d reduce-only Binance order(s).', $cancelled)
                : 'No reduce-only Binance orders were waiting.',
        ];
    }

    private function exchangePositionSnapshot(BinanceFuturesClient $client, string $symbol): array
    {
        $riskPayload = $client->fetchPositionRisk($symbol);
        $riskRows = collect(is_array($riskPayload) && array_is_list($riskPayload) ? $riskPayload : [$riskPayload])
            ->filter(fn ($row) => is_array($row) && strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol));
        $activeRisk = $riskRows->first(fn ($row) => abs((float) ($row['positionAmt'] ?? 0)) > 0);
        $openOrdersPayload = $client->fetchOpenOrders($symbol);
        $openOrders = collect(is_array($openOrdersPayload) && array_is_list($openOrdersPayload) ? $openOrdersPayload : [$openOrdersPayload])
            ->filter(fn ($row) => is_array($row) && strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol))
            ->values();
        $reduceOnlyOrders = $openOrders
            ->filter(fn ($row) => filter_var($row['reduceOnly'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->values()
            ->all();
        $positionAmt = (float) ($activeRisk['positionAmt'] ?? 0);

        return [
            'quantity' => abs($positionAmt),
            'signed_quantity' => $positionAmt,
            'position_side' => $positionAmt > 0 ? 'LONG' : ($positionAmt < 0 ? 'SHORT' : null),
            'entry_price' => (float) ($activeRisk['entryPrice'] ?? 0),
            'mark_price' => (float) ($activeRisk['markPrice'] ?? 0),
            'unrealized_pnl' => (float) ($activeRisk['unRealizedProfit'] ?? 0),
            'reduce_only_orders' => $reduceOnlyOrders,
            'open_orders' => $openOrders->all(),
        ];
    }

    private function isReduceOnlyRejected(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '-2022') || str_contains($message, 'ReduceOnly Order is rejected');
    }
}
