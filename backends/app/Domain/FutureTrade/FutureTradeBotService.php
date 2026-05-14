<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Market\FutureTradeLiveFeedManager;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\OperationalHealthService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Domain\Trading\FuturesOrderSizingService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use App\Models\MarketOhlc;
use App\Models\SchedulerRun;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
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
        private readonly ?FutureTradeLiveFeedManager $liveFeedManager = null,
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
        $this->autoEnsureLiveFeedStarted($config, $settings);
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

    private function autoEnsureLiveFeedStarted(FutureTradeBotConfig $config, array $settings): void
    {
        if (! ($settings['useLiveDataFeed'] ?? true)) {
            return;
        }

        if ($this->liveFeedManager === null) {
            return;
        }

        try {
            $status = $this->liveFeedManager->status((string) $config->workspace_id);
            if (! (bool) ($status['running'] ?? false)) {
                $started = $this->liveFeedManager->start();
                $this->log(
                    $config->fresh('state'),
                    'LIVE_FEED_DAEMON_STARTED',
                    null,
                    null,
                    0,
                    (bool) ($started['running'] ?? false)
                        ? 'Shared futures websocket feed daemon started automatically.'
                        : 'Attempted to start shared futures websocket feed daemon; REST fallback remains active.'
                );
            } elseif ((bool) ($status['restartRecommended'] ?? false)) {
                $this->log(
                    $config->fresh('state'),
                    'LIVE_FEED_RESTART_RECOMMENDED',
                    null,
                    null,
                    0,
                    'Shared futures websocket feed is running, but symbol list changed. Restart feed from UI for best coverage.'
                );
            }
        } catch (Throwable $e) {
            $this->log(
                $config->fresh('state'),
                'LIVE_FEED_AUTOSTART_FAILED',
                null,
                null,
                0,
                'Websocket daemon auto-start failed: ' . $e->getMessage() . '. REST fallback remains active.'
            );
        }
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
            'liveStatus' => $this->liveStatusPayload($config, $state, $bestSignal, $signals, $settings),
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
            'signalDebug' => $bestSignal['debug'] ?? null,
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
        $strictEntryGuards = (bool) ($settings['strictEntryGuards'] ?? false);
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), $signal, $settings);
        $settings = $dynamic['settings'];
        $analysis = (array) ($dynamic['public']['analysis'] ?? []);
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
        if (
            $strictEntryGuards
            && (
                (bool) ($signal['fastTimeframeWeakSetup'] ?? false)
                || (bool) ($signal['conflicted'] ?? false)
                || ! (bool) ($signal['strongSequence'] ?? true)
            )
        ) {
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Weak 5m setup rejected before execution.',
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED']),
                45
            );
            return;
        }
        if ($strictEntryGuards && (float) ($analysis['trendStrength'] ?? 0) < 0.45) {
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Trend-strength gate blocked entry: runtime trend strength is below 45%.',
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED']),
                45
            );
            return;
        }
        if (
            $strictEntryGuards
            && (int) ($analysis['totalTrades'] ?? 0) >= 6
            && (float) ($analysis['winRate'] ?? 100) < 35
        ) {
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('Poor regime guard blocked entry: %.2f%% win rate across %d closed trades.', (float) ($analysis['winRate'] ?? 0), (int) ($analysis['totalTrades'] ?? 0)),
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED']),
                60
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
        $price = (float) ($ticker['lastPrice'] ?? $ticker['price'] ?? 0);
        $change = (float) ($ticker['priceChangePercent'] ?? 0);
        $quoteVolume = (float) ($ticker['quoteVolume'] ?? 0);
        $high = (float) ($ticker['highPrice'] ?? $price);
        $low = (float) ($ticker['lowPrice'] ?? $price);
        $vwap = (float) ($ticker['weightedAvgPrice'] ?? 0);
        $openPrice = (float) ($ticker['openPrice'] ?? $price);
        $sequence = (array) ($ticker['sequence'] ?? []);
        $structure = (array) ($ticker['structure'] ?? []);
        $htf = (array) ($ticker['htf'] ?? []);

        $rangePct = $price > 0 ? (($high - $low) / $price) * 100 : 0.0;
        $intradayChangePct = $openPrice > 0 ? (($price - $openPrice) / $openPrice) * 100 : $change;
        $rangePosition = ($high > $low) ? (($price - $low) / ($high - $low)) : 0.5;
        $tf = strtolower((string) ($settings['timeframe'] ?? '5m'));
        $isFastTimeframe = in_array($tf, ['1m', '3m', '5m'], true);
        $strictEntryGuards = (bool) ($settings['strictEntryGuards'] ?? false);

        $sequenceNetChange = (float) ($sequence['netChangePercent'] ?? 0.0);
        $sequenceConsistency = (float) ($sequence['consistency'] ?? 0.0);
        $sequenceVolatility = (float) ($sequence['volatilityPercent'] ?? 0.0);
        $sequenceImpulse = (string) ($sequence['impulse'] ?? 'NEUTRAL');
        $rsi14 = (float) ($sequence['rsi14'] ?? 50.0);
        $emaBullish = isset($sequence['emaBullish']) ? (bool) $sequence['emaBullish'] : null;
        $emaStackBullish = (bool) ($sequence['emaStackBullish'] ?? false);
        $emaStackBearish = (bool) ($sequence['emaStackBearish'] ?? false);
        $macdBullish = isset($sequence['macdBullish']) ? (bool) $sequence['macdBullish'] : null;
        $macdHistGrowing = (bool) ($sequence['macdHistogramGrowing'] ?? false);
        $atrPercent = (float) ($sequence['atrPercent'] ?? 0.0);
        $adx14 = (float) ($sequence['adx14'] ?? 0.0);
        $sessionVwap = (float) ($sequence['sessionVwap'] ?? $vwap);
        $volumeOk = (bool) ($sequence['volumeOk'] ?? false);
        $extendedFromEma = (bool) ($sequence['extendedFromEma'] ?? false);
        $extendedFromVwap = (bool) ($sequence['extendedFromVwap'] ?? false);
        $utSignal = (string) ($sequence['utSignal'] ?? 'WAIT');
        $utBias = (string) ($sequence['utBias'] ?? 'NEUTRAL');

        $structureSignal = (string) ($structure['signal'] ?? 'WAIT');
        $bullishMsb = (bool) ($structure['bullishMsb'] ?? false);
        $bearishMsb = (bool) ($structure['bearishMsb'] ?? false);
        $bullishObValid = (bool) ($structure['bullishObValid'] ?? false);
        $bearishObValid = (bool) ($structure['bearishObValid'] ?? false);
        $structureContextAvailable = $bullishMsb
            || $bearishMsb
            || $bullishObValid
            || $bearishObValid
            || in_array($structureSignal, ['BUY', 'SELL'], true);

        $htfBullish = isset($htf['emaBullish']) ? (bool) $htf['emaBullish'] : null;
        $htf4hBullish = isset($htf['h4EmaBullish']) ? (bool) $htf['h4EmaBullish'] : null;
        $htfEma200Bullish = isset($htf['ema200Bullish']) ? (bool) $htf['ema200Bullish'] : null;
        $higherTimeframeContextAvailable = $htfBullish !== null || $htf4hBullish !== null || $htfEma200Bullish !== null;
        $symbolPerfAdjust = (float) ($ticker['symbolPerfAdjust'] ?? 0.0);
        $sequenceLastCandleTime = $sequence['lastCandleTime'] ?? null;
        $htfLastCandleTime = $htf['lastCandleTime_1h'] ?? null;

        $effectiveVwap = $sessionVwap > 0 ? $sessionVwap : $vwap;
        $vwapBullish = $effectiveVwap > 0 && $price > $effectiveVwap;
        $adxReady = $adx14 >= ($isFastTimeframe ? 17.0 : 16.0);
        $choppy = ($settings['avoidChoppyMarket'] ?? true)
            && ($sequenceVolatility > 0.32 || $adx14 < 15.0 || (abs($sequenceNetChange) < 0.08 && $sequenceConsistency < 0.55));
        $strongDirectionalSequence = $sequenceConsistency >= ($isFastTimeframe ? 0.58 : 0.54)
            && abs($sequenceNetChange) >= ($isFastTimeframe ? 0.12 : 0.09)
            && $adx14 >= ($isFastTimeframe ? 17.0 : 16.0)
            && $volumeOk;

        $buyReasons = [];
        $sellReasons = [];
        $avoidReasons = [];

        $buyChecks = [
            'ut' => $utBias === 'BUY' && in_array($utSignal, ['BUY', 'WAIT'], true),
            'msb' => $bullishMsb,
            'ob' => $bullishObValid,
            'structure' => $structureSignal === 'BUY',
            'vwap' => $vwapBullish,
            'ema' => $emaStackBullish,
            'htf' => $htfBullish === true && $htfEma200Bullish === true,
            'macro' => $htf4hBullish !== false,
            'rsi' => $rsi14 >= 50.0 && $rsi14 <= 70.0,
            'macd' => $macdBullish === true,
            'volume' => $volumeOk,
            'extension' => ! $extendedFromEma && ! $extendedFromVwap,
            'adx' => $adxReady,
            'sequence' => $strongDirectionalSequence && in_array($sequenceImpulse, ['BUY', 'NEUTRAL'], true),
        ];

        $sellChecks = [
            'ut' => $utBias === 'SELL' && in_array($utSignal, ['SELL', 'WAIT'], true),
            'msb' => $bearishMsb,
            'ob' => $bearishObValid,
            'structure' => $structureSignal === 'SELL',
            'vwap' => ! $vwapBullish,
            'ema' => $emaStackBearish,
            'htf' => $htfBullish === false && $htfEma200Bullish === false,
            'macro' => $htf4hBullish !== true,
            'rsi' => $rsi14 >= 30.0 && $rsi14 <= 50.0,
            'macd' => $macdBullish === false,
            'volume' => $volumeOk,
            'extension' => ! $extendedFromEma && ! $extendedFromVwap,
            'adx' => $adxReady,
            'sequence' => $strongDirectionalSequence && in_array($sequenceImpulse, ['SELL', 'NEUTRAL'], true),
        ];

        $buyScore = count(array_filter($buyChecks));
        $sellScore = count(array_filter($sellChecks));
        $dominantSignal = $buyScore > $sellScore ? 'BUY' : ($sellScore > $buyScore ? 'SELL' : 'WAIT');
        $dominantScore = max($buyScore, $sellScore);
        $voteGap = abs($buyScore - $sellScore);
        $conflicted = $buyScore >= 5 && $sellScore >= 5;
        $minimumDirectionalScore = $strictEntryGuards
            ? count($buyChecks)
            : ($isFastTimeframe ? 8 : 7);
        $minimumVoteGap = $strictEntryGuards
            ? count($buyChecks)
            : ($isFastTimeframe ? 2 : 1);
        if (! $strictEntryGuards) {
            if (! $structureContextAvailable) {
                $minimumDirectionalScore -= 2;
            }
            if (! $higherTimeframeContextAvailable) {
                $minimumDirectionalScore -= 1;
            }
            $minimumDirectionalScore = max($isFastTimeframe ? 5 : 4, $minimumDirectionalScore);
            if (! $structureContextAvailable && ! $higherTimeframeContextAvailable) {
                $minimumVoteGap = 1;
            }
        }

        $signal = 'WAIT';
        if ($dominantSignal !== 'WAIT' && $dominantScore >= $minimumDirectionalScore && $voteGap >= $minimumVoteGap) {
            $signal = $dominantSignal;
        }

        $fiveMinuteWeakSetup = $isFastTimeframe && (
            ! $strongDirectionalSequence
            || $conflicted
            || $choppy
            || $voteGap < 4
            || ($dominantSignal === 'BUY' && ($utBias !== 'BUY' || ! $bullishMsb || ! $bullishObValid))
            || ($dominantSignal === 'SELL' && ($utBias !== 'SELL' || ! $bearishMsb || ! $bearishObValid))
        );

        if ($strictEntryGuards && ($fiveMinuteWeakSetup || (($settings['avoidChoppyMarket'] ?? true) && $choppy))) {
            $signal = 'WAIT';
        }

        if ($signal === 'BUY') {
            $buyReasons = $this->directionalReasons('BUY', $buyChecks, [
                'rsi14' => $rsi14,
                'adx14' => $adx14,
                'timeframe' => $tf,
            ]);
        } elseif ($signal === 'SELL') {
            $sellReasons = $this->directionalReasons('SELL', $sellChecks, [
                'rsi14' => $rsi14,
                'adx14' => $adx14,
                'timeframe' => $tf,
            ]);
        } else {
            if (! $buyChecks['ut'] && ! $sellChecks['ut']) {
                $avoidReasons[] = 'UT Bot main trigger not aligned';
            }
            if (! $bullishMsb && ! $bearishMsb) {
                $avoidReasons[] = '15m market structure breakout not confirmed';
            }
            if (! $bullishObValid && ! $bearishObValid) {
                $avoidReasons[] = 'Order block confirmation missing';
            }
            if ($conflicted) {
                $avoidReasons[] = sprintf('Direction conflict between ticker and %s candle sequence', $tf);
            }
            if (! $strongDirectionalSequence) {
                $avoidReasons[] = sprintf('%s sequence not strong enough for breakout entry', $tf);
            }
            if ($choppy) {
                $avoidReasons[] = 'Low-clarity or choppy market avoided';
            }
            if (($dominantSignal === 'BUY' && ($rsi14 > 70.0 || $extendedFromEma || $extendedFromVwap))
                || ($dominantSignal === 'SELL' && ($rsi14 < 30.0 || $extendedFromEma || $extendedFromVwap))) {
                $avoidReasons[] = 'Entry too extended from EMA or VWAP';
            }
            if (! $volumeOk) {
                $avoidReasons[] = 'Volume below required breakout participation';
            }
            if (! $adxReady) {
                $avoidReasons[] = 'ADX strength too low';
            }
            if (($dominantSignal === 'BUY' && ($htfBullish !== true || $htfEma200Bullish !== true))
                || ($dominantSignal === 'SELL' && ($htfBullish !== false || $htfEma200Bullish !== false))) {
                $avoidReasons[] = '1h trend filter disagrees with breakout direction';
            }
        }

        $directionForBreakdown = $signal !== 'WAIT'
            ? $signal
            : ($dominantSignal !== 'WAIT' ? $dominantSignal : ($change >= 0 ? 'BUY' : 'SELL'));
        $activeChecks = $directionForBreakdown === 'SELL' ? $sellChecks : $buyChecks;
        $componentDebug = $this->signalComponentDebug(
            $directionForBreakdown,
            $activeChecks,
            [
                'strictEntryGuards' => $strictEntryGuards,
                'signal' => $signal,
                'dominantSignal' => $dominantSignal,
                'dominantScore' => $dominantScore,
                'voteGap' => $voteGap,
                'minimumDirectionalScore' => $minimumDirectionalScore,
                'minimumVoteGap' => $minimumVoteGap,
                'strongDirectionalSequence' => $strongDirectionalSequence,
                'volumeOk' => $volumeOk,
                'macdHistGrowing' => $macdHistGrowing,
                'lastVolume' => (float) ($sequence['lastVolume'] ?? 0.0),
                'volumeMa20' => (float) ($sequence['volumeMa20'] ?? 0.0),
                'sequenceConsistency' => $sequenceConsistency,
                'sequenceNetChange' => $sequenceNetChange,
                'sequenceImpulse' => $sequenceImpulse,
                'isFastTimeframe' => $isFastTimeframe,
                'structureSignal' => $structureSignal,
                'bullishMsb' => $bullishMsb,
                'bearishMsb' => $bearishMsb,
                'bullishObValid' => $bullishObValid,
                'bearishObValid' => $bearishObValid,
                'choppy' => $choppy,
                'conflicted' => $conflicted,
                'fiveMinuteWeakSetup' => $fiveMinuteWeakSetup,
                'adx14' => $adx14,
                'rsi14' => $rsi14,
                'vwapBullish' => $vwapBullish,
                'emaStackBullish' => $emaStackBullish,
                'emaStackBearish' => $emaStackBearish,
                'htfBullish' => $htfBullish,
                'htfEma200Bullish' => $htfEma200Bullish,
                'htf4hBullish' => $htf4hBullish,
                'extendedFromEma' => $extendedFromEma,
                'extendedFromVwap' => $extendedFromVwap,
                'symbolPerfAdjust' => $symbolPerfAdjust,
                'sequenceLastCandleTime' => $sequenceLastCandleTime,
                'htfLastCandleTime' => $htfLastCandleTime,
            ]
        );
        $confidence = $this->clamp((float) ($componentDebug['finalConfidence'] ?? 0.01), 0.01, 0.99);
        if ($signal === 'WAIT') {
            $confidence = min($confidence, 0.78);
            $componentDebug['finalConfidence'] = round($confidence, 4);
        }

        $reasonParts = $signal === 'BUY'
            ? $buyReasons
            : ($signal === 'SELL' ? $sellReasons : $avoidReasons);

        $this->safeDebugLog('[CONFIDENCE DEBUG]', [
            'symbol' => (string) ($ticker['symbol'] ?? 'BTCUSDT'),
            'timeframe' => $settings['timeframe'] ?? '5m',
            'price' => $price,
            'candleTime' => $sequenceLastCandleTime,
            'finalConfidence' => round($confidence, 4),
            'signal' => $signal,
            'dominantSignal' => $dominantSignal,
            'componentScores' => $componentDebug['componentScores'],
            'positiveFactors' => $componentDebug['positiveFactors'],
            'negativeFactors' => $componentDebug['negativeFactors'],
        ]);

        return [
            'symbol' => (string) ($ticker['symbol'] ?? 'BTCUSDT'),
            'signal' => $signal,
            'price' => $price,
            'changePercent' => round($change, 4),
            'intradayChange' => round($intradayChangePct, 4),
            'volume' => $quoteVolume,
            'rangePercent' => round($rangePct, 4),
            'rangePosition' => round($rangePosition, 4),
            'vwapBullish' => $vwapBullish,
            'sequence' => $sequence,
            'structure' => $structure,
            'confidence' => round($confidence, 4),
            'timeframe' => $settings['timeframe'] ?? '5m',
            'rsi14' => round($rsi14, 2),
            'emaBullish' => $emaBullish,
            'macdBullish' => $macdBullish,
            'macdHistGrowing' => $macdHistGrowing,
            'htfBullish' => $htfBullish,
            'htf4hBullish' => $htf4hBullish,
            'symbolPerfAdj' => round($symbolPerfAdjust, 4),
            'conflicted' => $conflicted,
            'strongSequence' => $strongDirectionalSequence,
            'fastTimeframeWeakSetup' => $fiveMinuteWeakSetup,
            'positiveFactors' => $componentDebug['positiveFactors'],
            'negativeFactors' => $componentDebug['negativeFactors'],
            'componentScores' => $componentDebug['componentScores'],
            'debug' => $componentDebug,
            'reason' => implode(' | ', $reasonParts),
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
            $structureSnapshots = $this->structureSnapshots($symbols, $dataBase);
            $htfSnapshots = $this->higherTfSnapshots($symbols, $settings, $dataBase);
            $configId = (string) $config->id;
            $result = collect($rows)
                ->filter(fn ($row) => in_array((string) ($row['symbol'] ?? ''), $symbols, true))
                ->map(function (array $row) use ($sequenceSnapshots, $structureSnapshots, $htfSnapshots, $configId) {
                    $symbol = (string) ($row['symbol'] ?? '');
                    $feedPrice = $this->cachedLatestPrice($symbol);
                    if ($feedPrice !== null && (float) ($feedPrice['price'] ?? 0) > 0) {
                        $row['lastPrice'] = (string) $feedPrice['price'];
                        $row['price'] = (string) $feedPrice['price'];
                    }
                    $row['sequence'] = $sequenceSnapshots[$symbol] ?? $this->emptySequenceSnapshot();
                    $row['structure'] = $structureSnapshots[$symbol] ?? $this->emptyStructureSnapshot();
                    $row['htf'] = $htfSnapshots[$symbol] ?? ['emaBullish' => null, 'h4EmaBullish' => null, 'ema200Bullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0, 'ema200_1h' => 0.0, 'lastCandleTime_1h' => null, 'lastCandleTime_4h' => null];
                    $row['symbolPerfAdjust'] = $this->symbolPerformanceAdjustment($symbol, $configId);

                    return $row;
                })
                ->values()
                ->all();
            if (! empty($result)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, max(3, min(10, (int) ($settings['scanIntervalSeconds'] ?? 3) * 2)));
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
        $cacheTtlSeconds = max(3, min(12, (int) ($settings['scanIntervalSeconds'] ?? 3) * 2));
        $snapshots = [];
        $missing = [];
        $timeframe = (string) ($settings['timeframe'] ?? '5m');

        foreach ($symbols as $symbol) {
            $cachedCandles = $this->cachedOhlcKlines($symbol, $timeframe, $limit);
            if (count($cachedCandles) >= 20) {
                $snapshots[$symbol] = $this->buildSequenceSnapshot($cachedCandles);
                continue;
            }
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
        $highs = [];
        $lows = [];
        $volumes = [];
        $closeTimes = [];

        foreach ($candles as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            $closeTime = (int) ($row[6] ?? 0);
            $open = (float) ($row[1] ?? 0);
            $high = (float) ($row[2] ?? 0);
            $low = (float) ($row[3] ?? 0);
            $close = (float) ($row[4] ?? 0);
            $volume = (float) ($row[5] ?? 0);

            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                continue;
            }

            $opens[] = $open;
            $highs[] = $high;
            $lows[] = $low;
            $closes[] = $close;
            $volumes[] = max(0.0, $volume);
            $closeTimes[] = $closeTime;
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

        // EMA stack and crossover
        $emaBullish = null;
        $ema9 = 0.0;
        $ema21 = 0.0;
        $ema50 = 0.0;
        $emaStackBullish = false;
        $emaStackBearish = false;
        if (count($closes) >= 21) {
            $ema9 = $this->calcEma($closes, 9);
            $ema21 = $this->calcEma($closes, 21);
            $emaBullish = $ema9 > $ema21;
            if (count($closes) >= 50) {
                $ema50 = $this->calcEma($closes, 50);
                $emaStackBullish = $ema9 > $ema21 && $ema21 > $ema50;
                $emaStackBearish = $ema9 < $ema21 && $ema21 < $ema50;
            }
        }

        // MACD(12,26,9) — requires 35+ candles
        $macd = count($closes) >= 35 ? $this->calcMacd($closes) : ['bullish' => null, 'histogram' => 0.0, 'histogramGrowing' => false];
        $atr14 = count($closes) >= 15 ? $this->calcAtr($highs, $lows, $closes, 14) : 0.0;
        $atrPercent = $last > 0 ? ($atr14 / $last) * 100 : 0.0;
        $sessionVwap = $this->calcSessionVwap($highs, $lows, $closes, $volumes);
        $volMa20 = count($volumes) >= 20 ? array_sum(array_slice($volumes, -20)) / 20 : (count($volumes) ? array_sum($volumes) / count($volumes) : 0.0);
        $lastVolume = (float) (end($volumes) ?: 0.0);
        $volumeOk = $volMa20 > 0 ? $lastVolume > ($volMa20 * 1.05) : false;
        $adx14 = count($closes) >= 20 ? $this->calcAdx($highs, $lows, $closes, 14) : 0.0;
        $extendedFromEma = $atr14 > 0 && $ema9 > 0 ? abs($last - $ema9) >= ($atr14 * 1.2) : false;
        $extendedFromVwap = $atr14 > 0 && $sessionVwap > 0 ? abs($last - $sessionVwap) >= ($atr14 * 1.4) : false;
        $utBot = count($closes) >= 12 ? $this->calcUtBot($closes, 10, 3.0) : ['signal' => 'WAIT', 'bias' => 'NEUTRAL', 'stop' => 0.0];

        return [
            'netChangePercent' => round($netChange, 4),
            'bullishRatio' => round($bullishRatio, 4),
            'bearishRatio' => round($bearishRatio, 4),
            'consistency' => round($consistency, 4),
            'volatilityPercent' => round($volatility, 4),
            'impulse' => $impulse,
            'rsi14' => round($rsi14, 2),
            'emaBullish' => $emaBullish,
            'ema9' => round($ema9, 6),
            'ema21' => round($ema21, 6),
            'ema50' => round($ema50, 6),
            'emaStackBullish' => $emaStackBullish,
            'emaStackBearish' => $emaStackBearish,
            'macdBullish' => $macd['bullish'],
            'macdHistogram' => round($macd['histogram'], 8),
            'macdHistogramGrowing' => $macd['histogramGrowing'],
            'atr14' => round($atr14, 8),
            'atrPercent' => round($atrPercent, 4),
            'adx14' => round($adx14, 4),
            'sessionVwap' => round($sessionVwap, 8),
            'volumeOk' => $volumeOk,
            'lastVolume' => round($lastVolume, 4),
            'volumeMa20' => round($volMa20, 4),
            'extendedFromEma' => $extendedFromEma,
            'extendedFromVwap' => $extendedFromVwap,
            'utSignal' => (string) ($utBot['signal'] ?? 'WAIT'),
            'utBias' => (string) ($utBot['bias'] ?? 'NEUTRAL'),
            'utStop' => round((float) ($utBot['stop'] ?? 0.0), 8),
            'lastCandleTime' => ! empty($closeTimes) && end($closeTimes) > 0
                ? Carbon::createFromTimestampMs((int) end($closeTimes))->toIso8601String()
                : null,
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
            'ema9' => 0.0,
            'ema21' => 0.0,
            'ema50' => 0.0,
            'emaStackBullish' => false,
            'emaStackBearish' => false,
            'macdBullish' => null,
            'macdHistogram' => 0.0,
            'macdHistogramGrowing' => false,
            'atr14' => 0.0,
            'atrPercent' => 0.0,
            'adx14' => 0.0,
            'sessionVwap' => 0.0,
            'volumeOk' => false,
            'lastVolume' => 0.0,
            'volumeMa20' => 0.0,
            'extendedFromEma' => false,
            'extendedFromVwap' => false,
            'utSignal' => 'WAIT',
            'utBias' => 'NEUTRAL',
            'utStop' => 0.0,
            'lastCandleTime' => null,
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

    private function calcAtr(array $highs, array $lows, array $closes, int $period = 14): float
    {
        if (count($closes) < $period + 1 || count($highs) !== count($closes) || count($lows) !== count($closes)) {
            return 0.0;
        }

        $trs = [];
        for ($i = 1; $i < count($closes); $i++) {
            $trs[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
        }

        if (count($trs) < $period) {
            return 0.0;
        }

        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }

        return $atr;
    }

    private function calcAdx(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $count = count($closes);
        if ($count < ($period * 2) || count($highs) !== $count || count($lows) !== $count) {
            return 0.0;
        }

        $trs = [];
        $plusDm = [];
        $minusDm = [];

        for ($i = 1; $i < $count; $i++) {
            $upMove = $highs[$i] - $highs[$i - 1];
            $downMove = $lows[$i - 1] - $lows[$i];
            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $trs[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
        }

        if (count($trs) < $period + 1) {
            return 0.0;
        }

        $tr14 = array_sum(array_slice($trs, 0, $period));
        $plus14 = array_sum(array_slice($plusDm, 0, $period));
        $minus14 = array_sum(array_slice($minusDm, 0, $period));
        $dxValues = [];

        for ($i = $period; $i < count($trs); $i++) {
            $tr14 = $tr14 - ($tr14 / $period) + $trs[$i];
            $plus14 = $plus14 - ($plus14 / $period) + $plusDm[$i];
            $minus14 = $minus14 - ($minus14 / $period) + $minusDm[$i];
            if ($tr14 <= 0) {
                continue;
            }

            $plusDi = 100 * ($plus14 / $tr14);
            $minusDi = 100 * ($minus14 / $tr14);
            $sum = $plusDi + $minusDi;
            if ($sum <= 0) {
                continue;
            }

            $dxValues[] = 100 * abs($plusDi - $minusDi) / $sum;
        }

        if (count($dxValues) < $period) {
            return count($dxValues) ? (float) end($dxValues) : 0.0;
        }

        $adx = array_sum(array_slice($dxValues, 0, $period)) / $period;
        for ($i = $period; $i < count($dxValues); $i++) {
            $adx = (($adx * ($period - 1)) + $dxValues[$i]) / $period;
        }

        return $adx;
    }

    private function calcSessionVwap(array $highs, array $lows, array $closes, array $volumes): float
    {
        $weighted = 0.0;
        $volumeTotal = 0.0;

        for ($i = 0; $i < count($closes); $i++) {
            $price = (($highs[$i] ?? 0.0) + ($lows[$i] ?? 0.0) + ($closes[$i] ?? 0.0)) / 3;
            $volume = (float) ($volumes[$i] ?? 0.0);
            $weighted += $price * $volume;
            $volumeTotal += $volume;
        }

        if ($volumeTotal <= 0) {
            return (float) (end($closes) ?: 0.0);
        }

        return $weighted / $volumeTotal;
    }

    private function calcUtBot(array $closes, int $atrPeriod = 10, float $keyValue = 3.0): array
    {
        if (count($closes) < max($atrPeriod + 2, 12)) {
            return ['signal' => 'WAIT', 'bias' => 'NEUTRAL', 'stop' => 0.0];
        }

        $trs = [];
        for ($i = 1; $i < count($closes); $i++) {
            $trs[] = abs($closes[$i] - $closes[$i - 1]);
        }

        $seed = array_sum(array_slice($trs, 0, min($atrPeriod, count($trs)))) / max(1, min($atrPeriod, count($trs)));
        $atr = $seed;
        foreach ($trs as $tr) {
            $atr = (($atr * ($atrPeriod - 1)) + $tr) / $atrPeriod;
        }

        $nLoss = $keyValue * $atr;
        $stop = (float) $closes[0];
        $prevStop = $stop;
        $prevClose = (float) $closes[0];
        $signal = 'WAIT';
        $bias = 'NEUTRAL';

        for ($i = 1; $i < count($closes); $i++) {
            $src = (float) $closes[$i];
            $prevStop = $stop;
            if ($src > $stop && $prevClose > $stop) {
                $stop = max($stop, $src - $nLoss);
            } elseif ($src < $stop && $prevClose < $stop) {
                $stop = min($stop, $src + $nLoss);
            } elseif ($src > $stop) {
                $stop = $src - $nLoss;
            } else {
                $stop = $src + $nLoss;
            }

            if ($prevClose < $prevStop && $src > $stop) {
                $signal = 'BUY';
                $bias = 'BUY';
            } elseif ($prevClose > $prevStop && $src < $stop) {
                $signal = 'SELL';
                $bias = 'SELL';
            } else {
                $bias = $src >= $stop ? 'BUY' : 'SELL';
                if ($i < count($closes) - 1) {
                    $signal = 'WAIT';
                }
            }

            $prevClose = $src;
        }

        return ['signal' => $signal, 'bias' => $bias, 'stop' => $stop];
    }

    private function structureSnapshots(array $symbols, string $base): array
    {
        $cacheKey = 'future_trade_structure_v1_' . md5(implode(',', $symbols));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $snapshots = [];
        try {
            foreach ($symbols as $symbol) {
                $cachedCandles = $this->cachedOhlcKlines($symbol, '15m', 80);
                if (count($cachedCandles) >= 20) {
                    $snapshots[$symbol] = $this->buildStructureSnapshot($cachedCandles);
                }
            }

            $missingSymbols = array_values(array_filter($symbols, fn (string $symbol) => ! isset($snapshots[$symbol])));
            if ($missingSymbols === []) {
                Cache::put($cacheKey, $snapshots, 45);
                return $snapshots;
            }

            $responses = Http::pool(function (Pool $pool) use ($missingSymbols, $base) {
                $requests = [];
                foreach ($missingSymbols as $symbol) {
                    $requests[$symbol . '_15m'] = $pool->as($symbol . '_15m')->timeout(6)
                        ->get($base . '/fapi/v1/klines', ['symbol' => $symbol, 'interval' => '15m', 'limit' => 80]);
                }
                return $requests;
            });

            foreach ($missingSymbols as $symbol) {
                try {
                    $payload = $responses[$symbol . '_15m']?->json();
                    $snapshots[$symbol] = $this->buildStructureSnapshot(is_array($payload) ? $payload : []);
                } catch (Throwable) {
                    $snapshots[$symbol] = $this->emptyStructureSnapshot();
                }
            }

            if (! empty($snapshots)) {
                Cache::put($cacheKey, $snapshots, 45);
            }
        } catch (Throwable) {
            foreach ($symbols as $symbol) {
                $snapshots[$symbol] = $this->emptyStructureSnapshot();
            }
        }

        return $snapshots;
    }

    private function buildStructureSnapshot(array $candles): array
    {
        if (count($candles) < 20) {
            return $this->emptyStructureSnapshot();
        }

        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];

        foreach ($candles as $row) {
            if (! is_array($row) || count($row) < 5) {
                continue;
            }

            $open = (float) ($row[1] ?? 0.0);
            $high = (float) ($row[2] ?? 0.0);
            $low = (float) ($row[3] ?? 0.0);
            $close = (float) ($row[4] ?? 0.0);
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
                continue;
            }

            $opens[] = $open;
            $highs[] = $high;
            $lows[] = $low;
            $closes[] = $close;
        }

        $count = count($closes);
        if ($count < 20) {
            return $this->emptyStructureSnapshot();
        }

        $lastClose = (float) $closes[$count - 1];
        $lastHigh = (float) $highs[$count - 1];
        $lastLow = (float) $lows[$count - 1];
        $prevClose = (float) $closes[$count - 2];
        $lookback = min(18, $count - 3);
        $historyHighs = array_slice($highs, -($lookback + 3), $lookback + 1);
        $historyLows = array_slice($lows, -($lookback + 3), $lookback + 1);
        $pivotHigh = count($historyHighs) ? max($historyHighs) : $lastHigh;
        $pivotLow = count($historyLows) ? min($historyLows) : $lastLow;

        $bullishBreakout = $lastHigh > $pivotHigh && $lastClose > $pivotHigh && $lastClose > $prevClose;
        $bearishBreakout = $lastLow < $pivotLow && $lastClose < $pivotLow && $lastClose < $prevClose;

        $bullishObTop = 0.0;
        $bullishObBottom = 0.0;
        $bullishObFound = false;
        if ($bullishBreakout) {
            for ($i = max(0, $count - 12); $i < $count - 1; $i++) {
                if ($opens[$i] > $closes[$i]) {
                    $bullishObTop = $highs[$i];
                    $bullishObBottom = $lows[$i];
                    $bullishObFound = true;
                }
            }
        }

        $bearishObTop = 0.0;
        $bearishObBottom = 0.0;
        $bearishObFound = false;
        if ($bearishBreakout) {
            for ($i = max(0, $count - 12); $i < $count - 1; $i++) {
                if ($opens[$i] < $closes[$i]) {
                    $bearishObTop = $highs[$i];
                    $bearishObBottom = $lows[$i];
                    $bearishObFound = true;
                }
            }
        }

        $bullishObValid = $bullishObFound && $lastClose >= $bullishObBottom;
        $bearishObValid = $bearishObFound && $lastClose <= $bearishObTop;
        $signal = $bullishBreakout && $bullishObValid ? 'BUY' : ($bearishBreakout && $bearishObValid ? 'SELL' : 'WAIT');

        return [
            'signal' => $signal,
            'bullishMsb' => $bullishBreakout,
            'bearishMsb' => $bearishBreakout,
            'bullishObValid' => $bullishObValid,
            'bearishObValid' => $bearishObValid,
            'pivotHigh' => round($pivotHigh, 8),
            'pivotLow' => round($pivotLow, 8),
            'bullishObTop' => round($bullishObTop, 8),
            'bullishObBottom' => round($bullishObBottom, 8),
            'bearishObTop' => round($bearishObTop, 8),
            'bearishObBottom' => round($bearishObBottom, 8),
        ];
    }

    private function emptyStructureSnapshot(): array
    {
        return [
            'signal' => 'WAIT',
            'bullishMsb' => false,
            'bearishMsb' => false,
            'bullishObValid' => false,
            'bearishObValid' => false,
            'pivotHigh' => 0.0,
            'pivotLow' => 0.0,
            'bullishObTop' => 0.0,
            'bullishObBottom' => 0.0,
            'bearishObTop' => 0.0,
            'bearishObBottom' => 0.0,
        ];
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
            foreach ($symbols as $symbol) {
                $closes1h = $this->extractClosesFromCachedOhlc($symbol, '1h', 220);
                $closes4h = $this->extractClosesFromCachedOhlc($symbol, '4h', 55);
                if (count($closes1h) >= 50 && count($closes4h) >= 20) {
                    $last1h = end($closes1h) ?: 0.0;
                    $last4h = end($closes4h) ?: 0.0;
                    $ema50_1h = count($closes1h) >= 50 ? $this->calcEma($closes1h, 50) : 0.0;
                    $ema200_1h = count($closes1h) >= 200 ? $this->calcEma($closes1h, 200) : 0.0;
                    $ema50_4h = count($closes4h) >= 50 ? $this->calcEma($closes4h, 50) : 0.0;
                    $snapshots[$symbol] = [
                        'emaBullish'   => $ema50_1h > 0 ? $last1h > $ema50_1h : null,
                        'h4EmaBullish' => $ema50_4h > 0 ? $last4h > $ema50_4h : null,
                        'ema200Bullish' => $ema200_1h > 0 ? $last1h > $ema200_1h : null,
                        'ema50_1h'     => round($ema50_1h, 4),
                        'ema50_4h'     => round($ema50_4h, 4),
                        'ema200_1h'    => round($ema200_1h, 4),
                        'lastCandleTime_1h' => $this->extractLastCandleTimeFromCachedOhlc($symbol, '1h'),
                        'lastCandleTime_4h' => $this->extractLastCandleTimeFromCachedOhlc($symbol, '4h'),
                    ];
                }
            }

            $missingSymbols = array_values(array_filter($symbols, fn (string $symbol) => ! isset($snapshots[$symbol])));
            if ($missingSymbols === []) {
                Cache::put($cacheKey, $snapshots, 60);
                return $snapshots;
            }

            // Fetch 1h and 4h candles in one parallel pool for efficiency
            $responses = Http::pool(function (Pool $pool) use ($missingSymbols, $base) {
                $requests = [];
                foreach ($missingSymbols as $symbol) {
                    $requests[$symbol . '_1h'] = $pool->as($symbol . '_1h')->timeout(6)
                        ->get($base . '/fapi/v1/klines', ['symbol' => $symbol, 'interval' => '1h', 'limit' => 220]);
                    $requests[$symbol . '_4h'] = $pool->as($symbol . '_4h')->timeout(6)
                        ->get($base . '/fapi/v1/klines', ['symbol' => $symbol, 'interval' => '4h', 'limit' => 55]);
                }
                return $requests;
            });
            foreach ($missingSymbols as $symbol) {
                try {
                    $closes1h = $this->extractCloses($responses[$symbol . '_1h'] ?? null);
                    $closes4h = $this->extractCloses($responses[$symbol . '_4h'] ?? null);
                    $last1h = end($closes1h) ?: 0.0;
                    $last4h = end($closes4h) ?: 0.0;
                    $ema50_1h = count($closes1h) >= 50 ? $this->calcEma($closes1h, 50) : 0.0;
                    $ema200_1h = count($closes1h) >= 200 ? $this->calcEma($closes1h, 200) : 0.0;
                    $ema50_4h = count($closes4h) >= 50 ? $this->calcEma($closes4h, 50) : 0.0;
                    $snapshots[$symbol] = [
                        'emaBullish'   => $ema50_1h > 0 ? $last1h > $ema50_1h : null,  // 1h EMA50
                        'h4EmaBullish' => $ema50_4h > 0 ? $last4h > $ema50_4h : null,  // 4h EMA50
                        'ema200Bullish' => $ema200_1h > 0 ? $last1h > $ema200_1h : null, // 1h EMA200
                        'ema50_1h'     => round($ema50_1h, 4),
                        'ema50_4h'     => round($ema50_4h, 4),
                        'ema200_1h'    => round($ema200_1h, 4),
                        'lastCandleTime_1h' => $this->extractLastCandleTime($responses[$symbol . '_1h'] ?? null),
                        'lastCandleTime_4h' => $this->extractLastCandleTime($responses[$symbol . '_4h'] ?? null),
                    ];
                } catch (Throwable) {
                    $snapshots[$symbol] = ['emaBullish' => null, 'h4EmaBullish' => null, 'ema200Bullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0, 'ema200_1h' => 0.0, 'lastCandleTime_1h' => null, 'lastCandleTime_4h' => null];
                }
            }
            if (! empty($snapshots)) {
                Cache::put($cacheKey, $snapshots, 60);
            }
        } catch (Throwable) {
            foreach ($symbols as $symbol) {
                $snapshots[$symbol] = ['emaBullish' => null, 'h4EmaBullish' => null, 'ema200Bullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0, 'ema200_1h' => 0.0, 'lastCandleTime_1h' => null, 'lastCandleTime_4h' => null];
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

    private function extractLastCandleTime($response): ?string
    {
        if (! $response || ! method_exists($response, 'successful') || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        $last = is_array($payload) ? end($payload) : null;
        $closeTime = is_array($last) ? (int) ($last[6] ?? 0) : 0;

        return $closeTime > 0 ? Carbon::createFromTimestampMs($closeTime)->toIso8601String() : null;
    }

    private function directionalReasons(string $side, array $checks, array $metrics): array
    {
        $reasons = [];
        $prefix = $side === 'BUY' ? 'bullish' : 'bearish';
        $tf = strtoupper((string) ($metrics['timeframe'] ?? '5m'));

        if (($checks['ut'] ?? false) === true) {
            $reasons[] = sprintf('UT Bot %s trigger aligned', $prefix);
        }
        if (($checks['msb'] ?? false) === true || ($checks['ob'] ?? false) === true || ($checks['structure'] ?? false) === true) {
            $reasons[] = sprintf('15m %s structure confirmation present', $prefix);
        }
        if (($checks['vwap'] ?? false) === true || ($checks['ema'] ?? false) === true) {
            $reasons[] = sprintf(
                '%s scanner filter: VWAP %s, EMA(9/21/50) stack %s',
                $tf,
                ($checks['vwap'] ?? false) ? 'aligned' : 'not aligned',
                ($checks['ema'] ?? false) ? 'aligned' : 'not aligned'
            );
        }
        if (($checks['htf'] ?? false) === true || ($checks['macro'] ?? false) === true) {
            $reasons[] = sprintf(
                'Higher timeframe filters: 1h EMA50/200 %s, 4h EMA50 %s',
                ($checks['htf'] ?? false) ? 'aligned' : 'mixed',
                ($checks['macro'] ?? false) ? 'supportive' : 'mixed'
            );
        }

        $reasons[] = sprintf(
            'RSI %.1f / ADX %.1f / MACD %s / volume %s',
            (float) ($metrics['rsi14'] ?? 0),
            (float) ($metrics['adx14'] ?? 0),
            (($checks['macd'] ?? false) ? $prefix : 'mixed'),
            (($checks['volume'] ?? false) ? 'confirmed' : 'weak')
        );

        return $reasons;
    }

    private function signalComponentDebug(string $side, array $checks, array $meta): array
    {
        $componentScores = [];
        $positiveFactors = [];
        $negativeFactors = [];
        $labelMap = [
            'ut' => 'UT Bot trigger',
            'msb' => '15m MSB',
            'ob' => '15m order block',
            'structure' => '15m structure signal',
            'vwap' => 'VWAP alignment',
            'ema' => '5m EMA(9/21/50) stack',
            'htf' => '1h EMA50/200 filter',
            'macro' => '4h EMA50 filter',
            'rsi' => 'RSI window',
            'macd' => 'MACD direction',
            'volume' => 'Volume confirmation',
            'extension' => 'Not overextended',
            'adx' => 'ADX readiness',
            'sequence' => '5m directional sequence',
        ];

        $structureHits = array_sum([
            ($checks['msb'] ?? false) ? 1 : 0,
            ($checks['ob'] ?? false) ? 1 : 0,
            ($checks['structure'] ?? false) ? 1 : 0,
        ]);
        $volumeRatio = ($meta['volumeMa20'] ?? 0) > 0
            ? ((float) ($meta['lastVolume'] ?? 0) / (float) $meta['volumeMa20'])
            : 0.0;
        $adx = (float) ($meta['adx14'] ?? 0.0);
        $isFastTimeframe = (bool) ($meta['isFastTimeframe'] ?? false);
        $sequenceConsistency = (float) ($meta['sequenceConsistency'] ?? 0.0);
        $sequenceNetChange = abs((float) ($meta['sequenceNetChange'] ?? 0.0));
        $sequenceImpulse = (string) ($meta['sequenceImpulse'] ?? 'NEUTRAL');
        $directionalImpulseAligned = in_array($sequenceImpulse, [$side, 'NEUTRAL'], true);

        $componentScores['ut'] = ($checks['ut'] ?? false) ? 0.14 : 0.0;
        $componentScores['structure'] = match (true) {
            $structureHits >= 3 => 0.08,
            $structureHits === 2 => 0.055,
            $structureHits === 1 => 0.025,
            default => 0.0,
        };
        $componentScores['vwap'] = ($checks['vwap'] ?? false) ? 0.06 : 0.0;
        $componentScores['ema'] = ($checks['ema'] ?? false) ? 0.06 : 0.0;
        $componentScores['htf'] = ($checks['htf'] ?? false) ? 0.07 : 0.0;
        $componentScores['macro'] = ($checks['macro'] ?? false) ? 0.03 : 0.0;
        $componentScores['rsi'] = ($checks['rsi'] ?? false) ? 0.05 : 0.0;
        $componentScores['macd'] = ($checks['macd'] ?? false) ? 0.06 : 0.0;
        $componentScores['extension'] = ($checks['extension'] ?? false) ? 0.05 : 0.0;
        $componentScores['adx'] = match (true) {
            $adx >= 22.0 => 0.06,
            $adx >= 18.0 => 0.05,
            $adx >= 15.0 => 0.03,
            default => 0.0,
        };
        $componentScores['volume'] = match (true) {
            $volumeRatio >= 1.10 => 0.07,
            $volumeRatio >= 0.95 => 0.04,
            $volumeRatio >= 0.85 => 0.015,
            default => 0.0,
        };
        $componentScores['sequence'] = match (true) {
            ($meta['strongDirectionalSequence'] ?? false) === true => 0.10,
            $directionalImpulseAligned && $sequenceConsistency >= ($isFastTimeframe ? 0.52 : 0.5) && $sequenceNetChange >= ($isFastTimeframe ? 0.08 : 0.06) => 0.04,
            default => 0.0,
        };
        $componentScores['macdHistogramBonus'] = ($meta['macdHistGrowing'] ?? false) ? 0.02 : 0.0;
        $componentScores['directionalSignalBonus'] = (($meta['signal'] ?? 'WAIT') !== 'WAIT' && ! ($meta['strictEntryGuards'] ?? false)) ? 0.14 : 0.0;
        $componentScores['trendContinuationBonus'] = (
            (($meta['signal'] ?? 'WAIT') !== 'WAIT')
            && ($checks['vwap'] ?? false)
            && ($checks['ema'] ?? false)
            && (($checks['htf'] ?? false) || ($checks['macro'] ?? false))
        ) ? 0.05 : 0.0;
        $componentScores['symbolPerfAdjust'] = round((float) ($meta['symbolPerfAdjust'] ?? 0.0), 4);

        foreach ($labelMap as $key => $label) {
            if ($key === 'msb' || $key === 'ob' || $key === 'structure') {
                continue;
            }
            if (($componentScores[$key] ?? 0) > 0) {
                $positiveFactors[] = $label;
            } else {
                $negativeFactors[] = $label;
            }
        }
        if ($componentScores['structure'] > 0) {
            $positiveFactors[] = '15m structure context';
        } else {
            $negativeFactors[] = '15m structure context';
        }

        if (($meta['signal'] ?? 'WAIT') === 'WAIT') {
            $negativeFactors[] = sprintf(
                'Directional gate not cleared (%d/%d votes, gap %d/%d)',
                (int) ($meta['dominantScore'] ?? 0),
                (int) ($meta['minimumDirectionalScore'] ?? 0),
                (int) ($meta['voteGap'] ?? 0),
                (int) ($meta['minimumVoteGap'] ?? 0)
            );
        }
        if (($meta['fiveMinuteWeakSetup'] ?? false) === true) {
            $negativeFactors[] = '5m weak-setup guard active';
        }
        if (($meta['choppy'] ?? false) === true) {
            $negativeFactors[] = 'Choppy market filter active';
        }
        if (($meta['conflicted'] ?? false) === true) {
            $negativeFactors[] = 'Buy/sell vote conflict';
        }
        if (($meta['extendedFromEma'] ?? false) === true || ($meta['extendedFromVwap'] ?? false) === true) {
            $negativeFactors[] = 'Entry extended from EMA/VWAP';
        }
        if ($adx < 15.0) {
            $negativeFactors[] = sprintf('ADX weak at %.2f', (float) ($meta['adx14'] ?? 0));
        } elseif ($adx < 18.0) {
            $negativeFactors[] = sprintf('ADX cautious at %.2f', (float) ($meta['adx14'] ?? 0));
        }
        if ($side === 'BUY' && (($meta['htfBullish'] ?? null) !== true || ($meta['htfEma200Bullish'] ?? null) !== true)) {
            $negativeFactors[] = '1h trend filter not fully bullish';
        }
        if ($side === 'SELL' && (($meta['htfBullish'] ?? null) !== false || ($meta['htfEma200Bullish'] ?? null) !== false)) {
            $negativeFactors[] = '1h trend filter not fully bearish';
        }

        $finalConfidence = round(array_sum($componentScores), 4);

        return [
            'decision' => ($meta['signal'] ?? 'WAIT') === 'WAIT' ? 'WAIT' : $meta['signal'],
            'decisionStatus' => ($meta['signal'] ?? 'WAIT') === 'WAIT' ? 'BLOCKED' : 'ELIGIBLE',
            'signalSide' => $side,
            'finalConfidence' => $finalConfidence,
            'positiveFactors' => array_values(array_unique($positiveFactors)),
            'negativeFactors' => array_values(array_unique($negativeFactors)),
            'componentScores' => $componentScores,
            'voteSummary' => [
                'dominantSignal' => $meta['dominantSignal'] ?? 'WAIT',
                'dominantScore' => (int) ($meta['dominantScore'] ?? 0),
                'minimumDirectionalScore' => (int) ($meta['minimumDirectionalScore'] ?? 0),
                'voteGap' => (int) ($meta['voteGap'] ?? 0),
                'minimumVoteGap' => (int) ($meta['minimumVoteGap'] ?? 0),
            ],
            'timing' => [
                'sequenceLastCandleTime' => $meta['sequenceLastCandleTime'] ?? null,
                'higherTimeframeLastCandleTime' => $meta['htfLastCandleTime'] ?? null,
            ],
        ];
    }

    private function liveStatusPayload(FutureTradeBotConfig $config, FutureTradeBotState $state, ?array $bestSignal, array $signals, array $settings): array
    {
        $lastChartPoint = Arr::last((array) ($state->chart_points ?? []));
        $ageSeconds = $state->last_scan_at ? now()->diffInSeconds($state->last_scan_at) : null;
        $feedPrice = $state->current_symbol ? $this->cachedLatestPrice((string) $state->current_symbol) : null;
        $hasSharedFeed = $feedPrice !== null;
        $daemonStatus = $this->liveFeedManager?->status((string) $config->workspace_id) ?? [
            'running' => false,
            'isFresh' => false,
            'pid' => null,
            'startedAt' => null,
            'lastTickAt' => null,
            'ageSeconds' => null,
            'restartRecommended' => false,
            'symbolSetInSync' => true,
        ];
        $trueWebsocketLive = (bool) ($daemonStatus['running'] ?? false) && (bool) ($daemonStatus['isFresh'] ?? false);

        $this->safeDebugLog('[LIVE DATA UPDATE]', [
            'symbol' => $state->current_symbol,
            'timeframe' => $settings['timeframe'] ?? '5m',
            'lastCandleTime' => data_get($bestSignal, 'debug.timing.sequenceLastCandleTime'),
            'lastPrice' => (float) ($state->current_price ?? 0),
            'updatedAt' => now()->toIso8601String(),
            'confidence' => (float) ($bestSignal['confidence'] ?? $state->confidence ?? 0),
        ]);

        return [
            'mode' => $trueWebsocketLive
                ? 'TRUE_WEBSOCKET_CACHE_LIVE'
                : ($hasSharedFeed ? 'SHARED_FEED_CACHE' : 'REST_POLLING'),
            'priceUpdateMode' => 'SCAN_LOOP',
            'indicatorMode' => 'FORMING_CANDLE',
            'lastUpdateTime' => optional($state->last_scan_at)->toIso8601String(),
            'lastChartPointTime' => $lastChartPoint['time'] ?? null,
            'lastCandleTime' => data_get($bestSignal, 'debug.timing.sequenceLastCandleTime'),
            'higherTimeframeLastCandleTime' => data_get($bestSignal, 'debug.timing.higherTimeframeLastCandleTime'),
            'ageSeconds' => $ageSeconds,
            'scanIntervalSeconds' => (int) ($settings['scanIntervalSeconds'] ?? 3),
            'isStale' => $ageSeconds !== null && $ageSeconds > max(6, (int) ($settings['scanIntervalSeconds'] ?? 3) * 3),
            'scannerSnapshotCount' => count($signals),
            'dataSource' => $trueWebsocketLive
                ? 'BINANCE_FUTURES_WEBSOCKET_CACHE'
                : ($hasSharedFeed
                    ? (string) ($feedPrice['source'] ?? 'shared-feed')
                    : (($settings['useLiveDataFeed'] ?? true) ? 'BINANCE_LIVE_REST' : 'BINANCE_EXECUTION_REST')),
            'daemon' => [
                'running' => (bool) ($daemonStatus['running'] ?? false),
                'pid' => $daemonStatus['pid'] ?? null,
                'startedAt' => $daemonStatus['startedAt'] ?? null,
                'lastTickAt' => $daemonStatus['lastTickAt'] ?? null,
                'feedAgeSeconds' => $daemonStatus['ageSeconds'] ?? null,
                'restartRecommended' => (bool) ($daemonStatus['restartRecommended'] ?? false),
                'symbolSetInSync' => (bool) ($daemonStatus['symbolSetInSync'] ?? true),
            ],
        ];
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
        $timeframe = strtolower((string) ($settings['timeframe'] ?? '5m'));
        $isFastTimeframe = in_array($timeframe, ['1m', '3m', '5m'], true);

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
        $baseRollback = (float) ($settings['rollbackLossPercent'] ?? 0.18);
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
                $effective += $isFastTimeframe
                    ? ($mode === 'fully_dynamic' ? 0.008 : 0.003)
                    : ($mode === 'fully_dynamic' ? 0.01 : 0.005);
                $adjustments[] = 'Mixed signal: keep entry slightly stricter.';
            }

            if ($analysis['confidenceGap'] < 0.025) {
                $effective += $mode === 'fully_dynamic' ? 0.018 : 0.01;
                $adjustments[] = 'Top signals are close together: avoid weak ranking edge.';
            }

            if (
                $isFastTimeframe
                && ($analysis['lastTradeReason'] ?? null) === null
                && (int) ($analysis['totalTrades'] ?? 0) === 0
                && (float) ($analysis['bestConfidence'] ?? 0) >= 0.58
                && (float) ($analysis['trendStrength'] ?? 0) >= 0.5
                && (float) ($analysis['directionAgreement'] ?? 0) >= 0.55
            ) {
                $effective -= $mode === 'fully_dynamic' ? 0.012 : 0.008;
                $adjustments[] = '5m scalp relief: aligned momentum allows a modest threshold reduction.';
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
                if ($totalTrades >= 6 && $winRate < 35) {
                    $effective = max($effective, 0.78);
                    $dynamicCooldown = max($dynamicCooldown, 180);
                    $next['tradeAmountUsdt'] = max(25, $baseTradeAmount * 0.8);
                    $adjustments[] = sprintf('Poor regime: %.2f%% win rate across %d trades forces stricter confidence and slower re-entry.', $winRate, $totalTrades);
                }
                if ((float) ($analysis['trendStrength'] ?? 0) < 0.45) {
                    $effective = max($effective, 0.78);
                    $dynamicCooldown = max($dynamicCooldown, 180);
                    $adjustments[] = 'Weak trend strength: require 78%+ confidence and slower re-entry.';
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
                $dynamicRollback = $this->clamp(max($baseRollback, $dynamicStop * ($analysis['choppy'] ? 0.72 : 0.66)), 0.22, max(0.24, $baseRollback * 1.75));

                $next['stopLossPercent'] = $dynamicStop;
                $next['takeProfitPercent'] = $dynamicTarget;
                $next['trailingTakeProfitPercent'] = $dynamicTrailing;
                $next['rollbackLossPercent'] = $dynamicRollback;
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
        $next['rollbackLossPercent'] = $this->clamp((float) ($next['rollbackLossPercent'] ?? $baseRollback), 0.18, max(0.22, $baseRollback * 1.75));
        $next['cooldownSeconds'] = (int) $this->clamp((float) ($next['cooldownSeconds'] ?? $baseCooldown), 10.0, 180.0);
        $next['tradeAmountUsdt'] = $this->clamp((float) ($next['tradeAmountUsdt'] ?? $baseTradeAmount), 25.0, max(25.0, $baseTradeAmount));

        $suggestion = $this->dynamicSuggestionText($mode, $analysis, $base, $next, $adjustments);

        $this->safeDebugLog('[ACTIVE MINIMUM DEBUG]', [
            'mode' => $mode,
            'baseMinimum' => round($base, 4),
            'floor' => round($floor, 4),
            'ceiling' => round($ceiling, 4),
            'adjustments' => $adjustments,
            'trendStrength' => $analysis['trendStrength'] ?? null,
            'directionAgreement' => $analysis['directionAgreement'] ?? null,
            'choppy' => $analysis['choppy'] ?? null,
            'winRate' => $analysis['winRate'] ?? null,
            'lastTradeReason' => $analysis['lastTradeReason'] ?? null,
            'finalActiveMinimum' => round($effective, 4),
        ]);

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

        $feedCandles = $this->cachedOhlcCandles($symbol, $interval, $safeLimit);
        if (count($feedCandles) >= min(20, $safeLimit)) {
            Cache::put($cacheKey, $feedCandles, now()->addSeconds(4));
            return $feedCandles;
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

    private function cachedLatestPrice(string $symbol): ?array
    {
        try {
            $cache = app(MarketPriceCacheService::class);
            $latest = $cache->getLatestPrice('binance_futures', $symbol);
            if (! is_array($latest)) {
                return null;
            }
            $timestampMs = (int) ($latest['timestamp_ms'] ?? 0);
            $ageMs = $timestampMs > 0 ? ((int) floor(microtime(true) * 1000) - $timestampMs) : PHP_INT_MAX;

            return $ageMs <= 15000 ? $latest : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cachedOhlcCandles(string $symbol, string $timeframe, int $limit): array
    {
        try {
            $interval = $this->normalizeKlineInterval($timeframe);
            $rows = MarketOhlc::query()
                ->where('exchange', 'binance_futures')
                ->where('symbol', strtoupper($symbol))
                ->where('timeframe', $interval)
                ->orderByDesc('ts')
                ->limit(max(1, $limit))
                ->get(['ts', 'open', 'high', 'low', 'close', 'volume'])
                ->reverse()
                ->values();

            if ($rows->isEmpty()) {
                return [];
            }

            $lastTs = optional($rows->last()->ts);
            $maxAgeSeconds = max(30, min(14400, $this->timeframeMinutes($interval) * 60 * 3));
            if (! $lastTs || $lastTs->diffInSeconds(now()) > $maxAgeSeconds) {
                return [];
            }

            return $rows->map(fn (MarketOhlc $row) => [
                'time' => $row->ts?->toIso8601String(),
                'open' => (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
                'volume' => (float) $row->volume,
            ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function cachedOhlcKlines(string $symbol, string $timeframe, int $limit): array
    {
        $candles = $this->cachedOhlcCandles($symbol, $timeframe, $limit);
        if ($candles === []) {
            return [];
        }

        $tfMinutes = $this->timeframeMinutes($timeframe);

        return array_map(function (array $candle) use ($tfMinutes) {
            $openMs = isset($candle['time']) ? Carbon::parse((string) $candle['time'])->getTimestampMs() : 0;
            $closeMs = $openMs > 0 ? $openMs + ($tfMinutes * 60 * 1000) - 1 : 0;

            return [
                $openMs,
                (string) ($candle['open'] ?? 0),
                (string) ($candle['high'] ?? 0),
                (string) ($candle['low'] ?? 0),
                (string) ($candle['close'] ?? 0),
                (string) ($candle['volume'] ?? 0),
                $closeMs,
            ];
        }, $candles);
    }

    private function extractClosesFromCachedOhlc(string $symbol, string $timeframe, int $limit): array
    {
        return array_map(
            static fn (array $row) => (float) ($row['close'] ?? 0),
            $this->cachedOhlcCandles($symbol, $timeframe, $limit)
        );
    }

    private function extractLastCandleTimeFromCachedOhlc(string $symbol, string $timeframe): ?string
    {
        $candles = $this->cachedOhlcCandles($symbol, $timeframe, 1);

        return $candles[0]['time'] ?? null;
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

    private function safeDebugLog(string $message, array $context = []): void
    {
        try {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->info($message, $context);
            }
        } catch (Throwable) {
            // Debug logging should never break scoring or tests.
        }
    }
}

