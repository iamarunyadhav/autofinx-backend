<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Market\FutureTradeLiveFeedManager;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\OperationalHealthService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Domain\Signals\IndicatorIntelligenceService;
use App\Domain\Signals\StrategyResearchLabService;
use App\Domain\Trading\FuturesOrderSizingService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeConfigVersion;
use App\Models\FutureTradeLogicalTrade;
use App\Models\FutureTradeLogicalTradeEvent;
use App\Models\FutureTradeMissedSignalAudit;
use App\Models\FutureTradeBotState;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\SchedulerRun;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FutureTradeBotService
{
    public const PROFILE_PRIME_V617_CRYPTO = 'autofinx_prime_v6_1_7_crypto';
    private const REST_STATE_FRESHNESS_SECONDS = 60;

    public function __construct(
        private readonly LivePortfolioGuardService $liveGuard,
        private readonly OperationalHealthService $operationalHealth,
        private readonly FuturesOrderSizingService $orderSizing,
        private readonly IndicatorIntelligenceService $indicatorIntelligence,
        private readonly StrategyResearchLabService $researchLab,
        private readonly ?FutureTradeLiveFeedManager $liveFeedManager = null,
        private readonly ?FutureTradeLockService $lockService = null,
        private readonly ?FutureTradePnLService $pnlService = null,
        private readonly ?FutureTradeSignalStabilityService $signalStability = null,
        private readonly ?FutureTradeUserStreamService $userStreamService = null,
    ) {}

    private function lockSvc(): FutureTradeLockService
    {
        return $this->lockService ?? app(FutureTradeLockService::class);
    }

    private function pnlSvc(): FutureTradePnLService
    {
        return $this->pnlService ?? app(FutureTradePnLService::class);
    }

    private function stabilitySvc(): FutureTradeSignalStabilityService
    {
        return $this->signalStability ?? app(FutureTradeSignalStabilityService::class);
    }

    private function userStreamSvc(): FutureTradeUserStreamService
    {
        return $this->userStreamService ?? app(FutureTradeUserStreamService::class);
    }

    /**
     * Centralized pre-execution gate. Hard-blocks live execution when feed or
     * user-stream freshness fail safety thresholds. Paper trading is gated only
     * by feed freshness (no exchange credentials are required).
     *
     * Returns the array of failed blocker keys (empty when execution is safe).
     */
    private function preExecutionFreshnessBlockers(FutureTradeBotConfig $config, ?array $signal): array
    {
        return $this->preExecutionFreshnessSnapshot($config, $signal)['blockers'];
    }

    private function preExecutionFreshnessSnapshot(FutureTradeBotConfig $config, ?array $signal): array
    {
        $blockers = [];
        $warnings = [];
        $symbol = strtoupper((string) ($signal['symbol'] ?? $config->state?->current_symbol ?? ''));
        $isPineWebhook = (string) ($signal['source'] ?? '') === 'pine_webhook';
        $feed = $this->feedFreshnessSnapshot($symbol);
        $stream = $this->userStreamSvc()->snapshot($config);

        // For pine_webhook entries, force a fresh REST account pull so we
        // never block on a stale 10s cache when the user stream worker is
        // down. The webhook is the brain — REST is the safety check.
        if ($isPineWebhook && $this->isLiveMode($config)) {
            $this->forceRefreshAccountCache($config);
        }
        $account = $this->isLiveMode($config) ? $this->accountSummary($config) : null;
        $restState = $this->restStateSyncSnapshot($account);
        $freshnessGateSource = 'user_stream';
        $usedRestFallback = false;
        $userStreamWarning = false;

        if (! $isPineWebhook && ! ($feed['feedFresh'] ?? false)) {
            $blockers[] = 'stale_feed';
            $freshnessGateSource = 'blocked';
        }

        if ($this->isLiveMode($config)) {
            if (! $stream['configured']) {
                if ($restState['fresh'] && $restState['verified'] && $restState['keyedRequestHealthy']) {
                    $freshnessGateSource = 'rest_sync';
                    $usedRestFallback = true;
                    $userStreamWarning = true;
                } elseif ($isPineWebhook && $restState['verified'] && $restState['keyedRequestHealthy']) {
                    // Pine webhook with a verified REST snapshot but slightly
                    // stale (<= 120s): still allow but warn — never block a
                    // valid TradingView signal solely on user-stream absence.
                    $freshnessGateSource = 'rest_sync';
                    $usedRestFallback = true;
                    $userStreamWarning = true;
                    $warnings[] = 'user_stream_not_configured_rest_fallback_active';
                } else {
                    $blockers[] = 'user_stream_not_configured_and_state_sync_stale';
                    $blockers[] = 'binance_state_stale_cannot_verify_balance_or_position';
                    $freshnessGateSource = 'blocked';
                }
            } else {
                $heartbeatFresh = (bool) ($stream['heartbeatFresh'] ?? false);
                $eventFresh = (bool) ($stream['eventFresh'] ?? false);
                $streamFresh = $heartbeatFresh || $eventFresh;

                if ($streamFresh) {
                    $freshnessGateSource = 'user_stream';
                } elseif ($restState['fresh']) {
                    $freshnessGateSource = 'rest_sync';
                    $usedRestFallback = true;
                    $userStreamWarning = true;
                } elseif ($isPineWebhook && $restState['verified'] && $restState['keyedRequestHealthy']) {
                    // Webhook source with a verified REST snapshot — allow with warning.
                    $freshnessGateSource = 'rest_sync';
                    $usedRestFallback = true;
                    $userStreamWarning = true;
                    $warnings[] = 'user_stream_stale_rest_fallback_active';
                } else {
                    $blockers[] = 'user_stream_stale_and_state_sync_stale';
                    $blockers[] = 'binance_state_stale_cannot_verify_balance_or_position';
                    $freshnessGateSource = 'blocked';
                }
            }

            if (! $restState['keyedRequestHealthy']) {
                $blockers[] = 'binance_state_sync_failed';
                $freshnessGateSource = 'blocked';
            } elseif (! $restState['verified']) {
                $blockers[] = 'binance_state_unverified';
                $freshnessGateSource = 'blocked';
            }
            if (! $restState['fresh'] && ! ($isPineWebhook && $restState['verified'] && $restState['keyedRequestHealthy'])) {
                $blockers[] = 'binance_state_stale_cannot_verify_balance_or_position';
            }
        }

        return [
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'feed' => $feed,
            'userStream' => $stream,
            'restSync' => $restState,
            'freshnessGateSource' => $blockers === [] ? $freshnessGateSource : 'blocked',
            'usedRestFallback' => $usedRestFallback && $blockers === [],
            'userStreamWarning' => $userStreamWarning && $blockers === [],
        ];
    }

    /**
     * Bust the 10s account-summary cache so the next accountSummary() call
     * performs a fresh signed GET /fapi/v2/account. Used before evaluating
     * a Pine webhook signal to ensure the freshness gate never blocks the
     * webhook because of a stale REST snapshot.
     *
     * Only forces a refresh when the existing cache's synced_at is older
     * than 30 seconds — this avoids hammering Binance when a recent fresh
     * snapshot already exists and keeps test fixtures stable.
     */
    private function forceRefreshAccountCache(FutureTradeBotConfig $config): void
    {
        if (! $this->isLiveMode($config)) {
            return;
        }
        try {
            $cacheKey = 'future_trade_account_summary:' . $config->workspace_id . ':' . ($config->executionMode());
            $metaCacheKey = $cacheKey . ':meta';
            $meta = (array) ($this->cacheGet($metaCacheKey, []) ?: []);
            $syncedAt = isset($meta['synced_at']) && $meta['synced_at']
                ? Carbon::parse((string) $meta['synced_at'])
                : null;
            $ageSeconds = $syncedAt ? max(0, $syncedAt->diffInSeconds(now())) : null;
            if ($ageSeconds === null || $ageSeconds > 30) {
                $this->cacheForget($cacheKey);
            }
        } catch (Throwable $e) {
            // Cache bust is best-effort; if it fails the next call will use the cached entry.
        }
    }

    private function restStateSyncSnapshot(?array $account): array
    {
        if ($account === null) {
            return [
                'verified' => true,
                'fresh' => true,
                'keyedRequestHealthy' => true,
                'syncedAt' => null,
                'ageSeconds' => null,
                'source' => 'paper',
            ];
        }

        $syncedAt = isset($account['binanceStateSyncedAt']) && $account['binanceStateSyncedAt']
            ? Carbon::parse((string) $account['binanceStateSyncedAt'])
            : null;
        $ageSeconds = $syncedAt ? max(0, $syncedAt->diffInSeconds(now())) : null;
        $balanceSource = (string) ($account['balanceSource'] ?? '');
        $keyedRequestHealthy = ! in_array($balanceSource, ['BINANCE_UNAVAILABLE', 'BINANCE_TEMPORARILY_UNAVAILABLE'], true);
        $verified = $syncedAt !== null || in_array($balanceSource, ['BINANCE_FUTURES', 'BINANCE_CACHE_STALE'], true);

        return [
            'verified' => $verified,
            'fresh' => $ageSeconds !== null && $ageSeconds <= self::REST_STATE_FRESHNESS_SECONDS,
            'keyedRequestHealthy' => $keyedRequestHealthy,
            'syncedAt' => $syncedAt?->toIso8601String(),
            'ageSeconds' => $ageSeconds,
            'source' => $balanceSource !== '' ? strtolower($balanceSource) : 'unknown',
        ];
    }

    private function freshnessContext(array $freshness): array
    {
        $stream = (array) ($freshness['userStream'] ?? []);
        $rest = (array) ($freshness['restSync'] ?? []);

        return [
            'freshnessGateSource' => $freshness['freshnessGateSource'] ?? 'blocked',
            'userStreamConfigured' => (bool) ($stream['configured'] ?? false),
            'userStreamFresh' => (bool) ($stream['fresh'] ?? false),
            'userStreamEventFresh' => (bool) ($stream['eventFresh'] ?? false),
            'userStreamHeartbeatFresh' => (bool) ($stream['heartbeatFresh'] ?? false),
            'userStreamLastSeenAt' => $stream['lastEventAt'] ?? null,
            'userStreamHeartbeatAt' => $stream['lastHeartbeatAt'] ?? null,
            'userStreamHeartbeatAgeSec' => $stream['heartbeatAgeSeconds'] ?? null,
            'binanceStateSyncedAt' => $rest['syncedAt'] ?? null,
            'binanceStateAgeSec' => $rest['ageSeconds'] ?? null,
            'binanceStateVerified' => (bool) ($rest['verified'] ?? false),
            'binanceStateFresh' => (bool) ($rest['fresh'] ?? false),
            'binanceKeyedRequestHealthy' => (bool) ($rest['keyedRequestHealthy'] ?? false),
            'usedRestFallback' => (bool) ($freshness['usedRestFallback'] ?? false),
            'userStreamWarning' => (bool) ($freshness['userStreamWarning'] ?? false),
            'freshnessWarnings' => (array) ($freshness['warnings'] ?? []),
        ];
    }

    private function logFreshnessFallbackIfNeeded(FutureTradeBotConfig $config, array $signal, array $freshness): void
    {
        if (! (bool) ($freshness['usedRestFallback'] ?? false)) {
            return;
        }

        $this->logThrottled(
            $config->fresh('state'),
            'BINANCE_STATE_CACHE_USED',
            (string) ($signal['symbol'] ?? null),
            (string) ($signal['signal'] ?? null),
            (float) ($signal['confidence'] ?? 0),
            'User stream heartbeat was stale, but Binance REST state sync was fresh so execution remained allowed.',
            $this->freshnessContext($freshness),
            30
        );
    }

    /**
     * Generate a deterministic signal key for idempotency, used by both the
     * locking layer and the DB unique constraint on logical_trades.
     */
    private function buildSignalKey(FutureTradeBotConfig $config, array $signal, array $settings): string
    {
        $tf = strtolower((string) ($settings['timeframe'] ?? '5m'));
        $stability = $this->stabilitySvc();
        $candle = (string) ($signal['candle_open'] ?? $stability->previousClosedCandleOpenIso($tf));
        return $stability->generateSignalKey(
            (string) ($signal['symbol'] ?? ''),
            (string) ($signal['signal'] ?? ''),
            $tf,
            $candle,
            [
                'config' => (string) $config->id,
                'profile' => (string) ($settings['signalProfile'] ?? self::PROFILE_PRIME_V617_CRYPTO),
                'strategy_order_id' => (string) ($signal['strategy_order_id'] ?? data_get($signal, 'debug.strategyOrderId', '')),
                'source' => (string) ($signal['source'] ?? ''),
            ]
        );
    }

    public function getOrCreateConfig(string $workspaceId): FutureTradeBotConfig
    {
        $config = FutureTradeBotConfig::firstOrCreate(
            ['workspace_id' => $workspaceId, 'slot_index' => 0],
            [
                'bot_name' => 'Future Trade Bot',
                'paper_trading' => true,
                'enable_live_trading' => false,
                'enabled' => false,
                'settings' => config('future_trade.defaults'),
                'algorithm_notes' => 'Default crypto profile: AutoFinX Prime v6.1.7 Crypto Stable Default. Use only for validated crypto futures; Forex and XAUUSD/Gold require separate profiles.',
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

    /**
     * Ensure the number of active slot-configs for a workspace matches maxOpenPositions.
     * Slot 0 is always the primary (settings authority). Slots 1..N-1 are shadow positions
     * that mirror the primary's settings and enabled/live state.
     */
    public function syncSlots(FutureTradeBotConfig $primary): void
    {
        if (! $primary->isPrimary()) {
            return;
        }

        $settings = $primary->mergedSettings();
        $maxSlots = max(1, (int) ($settings['maxOpenPositions'] ?? 1));
        $workspaceId = $primary->workspace_id;

        // Create missing shadow slots and initialise their state
        for ($slot = 1; $slot < $maxSlots; $slot++) {
            $shadow = FutureTradeBotConfig::firstOrCreate(
                ['workspace_id' => $workspaceId, 'slot_index' => $slot],
                [
                    'bot_name' => ($primary->bot_name ?? 'Future Trade Bot') . ' (Slot ' . ($slot + 1) . ')',
                    'paper_trading' => $primary->paper_trading,
                    'enable_live_trading' => $primary->enable_live_trading,
                    'enabled' => $primary->enabled,
                    'settings' => $primary->settings,
                    'algorithm_notes' => $primary->algorithm_notes,
                ]
            );
            $shadow->state()->firstOrCreate([], [
                'status' => 'Stopped',
                'state' => 'IDLE',
                'connection_status' => 'Disconnected',
                'trading_day' => now()->toDateString(),
            ]);
        }

        // Keep existing shadow slots in sync with primary settings/mode
        if ($maxSlots > 1) {
            FutureTradeBotConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('slot_index', '>', 0)
                ->where('slot_index', '<', $maxSlots)
                ->update([
                    'paper_trading' => $primary->paper_trading,
                    'enable_live_trading' => $primary->enable_live_trading,
                    'enabled' => $primary->enabled,
                    'settings' => json_encode($primary->settings),
                ]);
        }

        // Disable any slots beyond the current limit
        FutureTradeBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->where('slot_index', '>=', $maxSlots)
            ->update(['enabled' => false]);
    }

    public function updateConfig(FutureTradeBotConfig $config, array $payload): FutureTradeBotConfig
    {
        $beforeSettings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $beforeNotes = (string) ($config->algorithm_notes ?? '');
        $settings = $this->normalizeFutureTradeSettings(array_merge($config->mergedSettings(), (array) ($payload['settings'] ?? [])));
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
        $newNotes = (string) ($payload['algorithm_notes'] ?? $config->algorithm_notes ?? '');
        $auditChanges = $this->settingsDiff($beforeSettings, $settings);
        if ($beforeNotes !== $newNotes) {
            $auditChanges['algorithm_notes'] = [
                'old' => $beforeNotes,
                'new' => $newNotes,
            ];
        }

        $config->update([
            'paper_trading' => ! $liveAllowed,
            'enable_live_trading' => $liveAllowed,
            'settings' => $settings,
            'algorithm_notes' => $newNotes,
        ]);

        // Propagate live/paper mode and settings changes to all shadow slots
        if ($config->isPrimary()) {
            $this->syncSlots($config->fresh());
        }

        if ($liveRequested && ! $liveAllowed) {
            $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', null, null, 0, 'Another live bot is already active for this workspace/account. Future Trade stays in paper mode.');
        }

        $auditUser = auth()->user();
        if ($auditChanges !== []) {
            $this->emitConfigAuditLogs($config, $auditChanges, $auditUser?->id);
            $this->log(
                $config->fresh('state'),
                'CONFIG_AUDIT',
                null,
                null,
                0,
                'Future Trade Bot settings updated.',
                [
                    'changedFields' => array_keys($auditChanges),
                    'changes' => $auditChanges,
                    'userId' => $auditUser?->id,
                    'userName' => $auditUser?->name,
                    'userEmail' => $auditUser?->email,
                    'updatedAt' => now()->toIso8601String(),
                ]
            );
        }

        $this->log($config->fresh('state'), 'CONFIG_UPDATED', null, null, 0, $liveAllowed ? 'Future Trade Bot config saved. ' . ($forceTestnet ? 'Testnet futures enabled.' : 'Live futures enabled.') : 'Future Trade Bot config saved. Paper trading active.');
        $this->storeConfigVersion($config->fresh('state'), $settings, $newNotes, 'manual_update', [
            'changedFields' => array_keys($auditChanges),
            'liveAllowed' => $liveAllowed,
        ], $auditUser?->id);
        return $config->fresh('state');
    }

    public function importResearchScript(FutureTradeBotConfig $config, array $payload): array
    {
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $lab = (array) ($settings['researchLab'] ?? []);
        $scripts = array_values((array) ($lab['scripts'] ?? []));
        $scripts[] = $this->researchLab->importScript(
            (string) ($payload['name'] ?? ''),
            (string) ($payload['script'] ?? ''),
            (string) ($payload['source'] ?? 'pine')
        );
        $lab['scripts'] = array_slice($scripts, -20);
        $settings['researchLab'] = $lab;
        $config->update(['settings' => $settings]);

        return $this->researchLabPayload($config->fresh(), $settings);
    }

    public function importResearchTrades(FutureTradeBotConfig $config, array $payload): array
    {
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $lab = (array) ($settings['researchLab'] ?? []);
        $datasets = array_values((array) ($lab['datasets'] ?? []));
        $datasets[] = $this->researchLab->importTradeDataset(
            (string) ($payload['name'] ?? ''),
            (string) ($payload['payload'] ?? ''),
            (string) ($payload['format'] ?? 'csv')
        );
        $lab['datasets'] = array_slice($datasets, -12);
        $settings['researchLab'] = $lab;
        $config->update(['settings' => $settings]);

        return $this->researchLabPayload($config->fresh(), $settings);
    }

    public function start(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->update(['enabled' => true, 'paper_trading' => true, 'enable_live_trading' => false]);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
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
            $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
            $market = $this->marketSnapshot($config, $settings);
            $signals = array_map(fn (array $ticker) => $this->scoreTicker($ticker, $settings), $market);
            usort($signals, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

            // When multiple slots share a workspace, exclude symbols already held by sibling slots
            // so each slot trades a different symbol (e.g. BTC + ETH, not BTC + BTC).
            if (! $config->isPrimary() || FutureTradeBotConfig::query()
                ->where('workspace_id', $config->workspace_id)
                ->where('id', '!=', $config->id)
                ->exists()
            ) {
                $siblingSymbols = FutureTradeBotConfig::query()
                    ->where('workspace_id', $config->workspace_id)
                    ->where('id', '!=', $config->id)
                    ->with('state')
                    ->get()
                    ->map(fn ($c) => $c->state?->current_symbol)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (! empty($siblingSymbols)) {
                    $signals = array_values(array_filter(
                        $signals,
                        fn ($s) => ! in_array($s['symbol'] ?? '', $siblingSymbols, true)
                    ));
                }
            }

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
                $signalSource = $this->normalizedSignalSource($settings);
                $mirrorExecutesEntries = match ($signalSource) {
                    'webhook' => false,
                    'webhook_fallback' => $this->webhookFallbackEnabled($settings) && ! $this->pineWebhookRecentlyReceived($config, $settings),
                    default => true,
                };

                $chart = $this->appendChartPoint($state, $best['symbol'], (float) $best['price'], $dynamic['public'], (float) $best['confidence'], (string) $best['signal']);
                $statePatch = [
                    'connection_status' => 'Connected',
                    'current_price' => $best['price'],
                    'last_scan_at' => now(),
                    'scanner_snapshot' => array_slice($signals, 0, 12),
                    'chart_points' => $chart,
                ];

                if ($mirrorExecutesEntries) {
                    $statePatch['current_symbol'] = $best['symbol'];
                    $statePatch['current_signal'] = $best['signal'];
                    $statePatch['confidence'] = $best['confidence'];
                } else {
                    $statePatch['current_symbol'] = $state->current_symbol ?: $best['symbol'];
                    $statePatch['current_signal'] = $state->current_signal ?: 'WAIT';
                    $statePatch['confidence'] = (float) ($state->confidence ?? 0);
                }

                $this->persistState($state, $statePatch);
                $this->logScanStatus($config->fresh('state'), $best, $dynamic);

                $autoExecutionEnabled = $this->isLiveMode($config) || (bool) ($settings['autoExecutePaperSignals'] ?? true);
                if (! $lockedForDay && $allowPaperExecution && $config->enabled && $autoExecutionEnabled && $mirrorExecutesEntries) {
                    $this->maybeEnterPaperPosition($config->fresh('state'), $best);
                } elseif (! $mirrorExecutesEntries) {
                    // Webhook-only mode: scanner refreshes the UI snapshot above
                    // but must NOT open positions itself — that's the webhook's
                    // job. Surface this on the runtime log throttled, so the
                    // operator sees why the scanner is "quiet" on entries.
                    $this->logThrottled(
                        $config,
                        'MIRROR_EXECUTION_OFF',
                        (string) ($best['symbol'] ?? null),
                        (string) ($best['signal'] ?? null),
                        (float) ($best['confidence'] ?? 0),
                        'Signal source is set to webhook — backend scanner will not auto-execute entries.',
                        ['status' => 'INFO', 'signalSource' => $signalSource],
                        300
                    );
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
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
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
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $bestSignal = $signals[0] ?? null;
        $dynamic = $this->dynamicDecision($config, $state, $signals, $bestSignal, $settings);
        $effectiveSettings = $dynamic['settings'];
        $entry = (float) ($state->entry_price ?? 0);
        $peak = (float) ($state->peak_price ?? 0);
        $marketChartSymbol = $this->resolveMarketChartSymbol($state, $bestSignal, $settings);
        $marketTimeframe = (string) ($settings['timeframe'] ?? '5m');
        $marketCandles = $marketChartSymbol ? $this->fetchMarketCandles($marketChartSymbol, $marketTimeframe, 180) : [];

        $signalStability = $this->buildSignalStabilitySnapshot($config, $bestSignal, $settings);
        $runtimeHealth = $this->buildRuntimeHealthSnapshot($config, $state, $bestSignal);

        return [
            'status' => $this->statusPayload($config),
            'scanner' => $signals,
            'bestSignal' => $bestSignal,
            'signalStability' => $signalStability,
            'runtimeHealth' => $runtimeHealth,
            'realized' => $this->pnlSvc()->realizedSummary($config),
            'chart' => $state->chart_points ?? [],
            'marketChart' => $this->marketChartPayload($marketChartSymbol, $marketTimeframe, 180, $marketCandles),
            'indicatorSuite' => $this->indicatorSuitePayload($marketCandles, $bestSignal, $effectiveSettings, $marketTimeframe),
            'signalDebug' => $bestSignal['debug'] ?? null,
            'indicatorIntelligence' => $this->indicatorIntelligencePayload($config, $effectiveSettings, $bestSignal),
            'researchLab' => $this->researchLabPayload($config, $effectiveSettings),
            'missedTradeAudit' => $this->missedTradeAuditPayload($config, $effectiveSettings),
            'performanceFeedback' => $this->performanceFeedbackPayload($config),
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

    public function liveStatusLightPayload(FutureTradeBotConfig $config): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $bestSignal = $signals[0] ?? null;
        $dynamic = $this->dynamicDecision($config, $state, $signals, $bestSignal, $settings);
        $feed = $this->feedFreshnessSnapshot((string) ($state->current_symbol ?: ($bestSignal['symbol'] ?? '')));
        $lastChartPoint = Arr::last((array) ($state->chart_points ?? []));
        $chartAt = isset($lastChartPoint['time']) ? Carbon::parse((string) $lastChartPoint['time']) : null;
        $latestBlock = $config->logs()
            ->whereIn('event', ['TRADE_BLOCKED', 'SIGNAL_SKIPPED'])
            ->orderByDesc('occurred_at')
            ->first();
        $account = $this->cachedAccountSummaryForStatus($config);

        return [
            'workspaceId' => (string) $config->workspace_id,
            'enabled' => (bool) $config->enabled,
            'paperTrading' => ! $this->isLiveMode($config),
            'liveTrading' => $this->isLiveMode($config),
            'mode' => $this->isLiveMode($config) ? (($settings['forceTestnet'] ?? true) ? 'LIVE_TESTNET' : 'LIVE') : 'PAPER',
            'strategyMode' => $this->strategyModeLabel($settings),
            'classicSimpleMode' => (bool) ($settings['classicSimpleMode'] ?? false),
            'confidenceMode' => (string) ($settings['confidenceMode'] ?? 'fixed'),
            'minConfidence' => (float) ($settings['minConfidence'] ?? 0),
            'activeMinimum' => round((float) ($dynamic['effectiveMinConfidence'] ?? ($settings['minConfidence'] ?? 0)), 4),
            'strictEntryGuards' => (bool) ($settings['strictEntryGuards'] ?? false),
            'corePassRule' => (int) ($settings['corePassRule'] ?? 3),
            'feedMode' => $feed['feedMode'],
            'feedFresh' => $feed['feedFresh'],
            'tickAgeSeconds' => (int) ($feed['feedAgeSeconds'] ?? 0),
            'lastTickAt' => $feed['lastTickAt'],
            'lastTickPrice' => $feed['lastTickPrice'],
            'lastScanAt' => $this->isoTimestamp($state->last_scan_at),
            'scanAgeSeconds' => (int) ($this->ageSecondsSince($state->last_scan_at) ?? 0),
            'chartUpdatedAt' => $this->isoTimestamp($chartAt),
            'chartAgeSeconds' => (int) ($this->ageSecondsSince($chartAt) ?? 0),
            'currentSymbol' => $state->current_symbol,
            'currentPrice' => (float) ($feed['lastTickPrice'] ?? $state->current_price ?? 0),
            'currentSignal' => $state->current_signal,
            'currentConfidence' => (float) ($state->confidence ?? 0),
            'lastSkipReason' => $latestBlock?->reason,
            'positionSide' => $state->position_side ?: 'FLAT',
            'walletBalance' => $account['walletBalance'] ?? null,
            'availableBalance' => $account['availableBalance'] ?? null,
            'accountAsset' => $account['accountAsset'] ?? 'USDT',
            'minQuantityPrecheck' => $this->minimumQuantityPrecheck($config, $settings, $state, $bestSignal),
            'updatedAt' => $this->isoTimestamp(now()),
        ];
    }

    public function scannerSnapshotPayload(FutureTradeBotConfig $config): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $dynamic = $this->dynamicDecision($config, $state, $signals, $signals[0] ?? null, $settings);
        $activeMinimum = (float) ($dynamic['effectiveMinConfidence'] ?? ($settings['minConfidence'] ?? 0));

        return [
            'updatedAt' => $this->isoTimestamp($state->last_scan_at),
            'scanAgeSeconds' => (int) ($this->ageSecondsSince($state->last_scan_at) ?? 0),
            'symbols' => array_map(function (array $signal) use ($activeMinimum): array {
                return [
                    'symbol' => (string) ($signal['symbol'] ?? ''),
                    'price' => (float) ($signal['price'] ?? 0),
                    'signal' => (string) ($signal['signal'] ?? 'WAIT'),
                    'confidence' => (float) ($signal['confidence'] ?? 0),
                    'activeMinimum' => (float) ($signal['effectiveMinConfidence'] ?? $activeMinimum),
                    'coreEntryPassed' => (bool) data_get($signal, 'debug.checks.coreEntryPassed', false),
                    'componentScores' => (array) data_get($signal, 'debug.componentScores', []),
                    'positiveFactors' => (array) data_get($signal, 'debug.positiveFactors', []),
                    'negativeFactors' => (array) data_get($signal, 'debug.negativeFactors', []),
                    'lastSkipReason' => (string) data_get($signal, 'debug.decisionStatus', ''),
                ];
            }, $signals),
        ];
    }

    public function ingestPineWebhook(FutureTradeBotConfig $config, array $payload): array
    {
        $receivedAt = now();
        // Webhook latency stopwatch — milestones surfaced to UI + log so we can
        // see exactly where time goes between webhook arrival and Binance order.
        $t0 = microtime(true);
        $latency = [
            't0_received_at' => $receivedAt->toIso8601String(),
            'validatedMs' => null,
            'stateSyncedMs' => null,
            'orderPlacedMs' => null,
            'totalMs' => null,
        ];
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $state = $this->state($config);

        if (! $config->enabled) {
            return $this->rejectPineWebhook($config, $payload, 'bot_disabled');
        }
        if (! $this->isPrimeCryptoProfile($settings)) {
            return $this->rejectPineWebhook($config, $payload, 'profile_not_prime_v617');
        }
        $payloadProfile = trim((string) ($payload['profile'] ?? $payload['profile_id'] ?? ''));
        if ($payloadProfile !== '' && $payloadProfile !== self::PROFILE_PRIME_V617_CRYPTO) {
            return $this->rejectPineWebhook($config, $payload, 'payload_profile_not_prime_v617', [
                'payloadProfile' => $payloadProfile,
                'expectedProfile' => self::PROFILE_PRIME_V617_CRYPTO,
            ]);
        }
        if ($this->isLiveMode($config) && ! $this->pineWebhookLiveEnabled()) {
            return $this->rejectPineWebhook($config, $payload, 'pine_webhook_live_disabled');
        }
        // Webhook is only accepted when this config has explicitly opted into
        // the webhook signal source. This is the "no conflicts" guarantee:
        // the operator must flip the UI toggle to switch from the backend
        // mirror scanner to TradingView-fed entries.
        $signalSource = $this->normalizedSignalSource($settings);
        if (! in_array($signalSource, ['webhook', 'webhook_fallback'], true)) {
            return $this->rejectPineWebhook($config, $payload, 'webhook_disabled_in_mirror_mode', [
                'signalSource' => $signalSource,
                'hint' => 'Set Future Trade > Settings > Signal source to "Webhook" or "Webhook + Binance fallback" before sending TradingView alerts.',
            ]);
        }
        $payloadSource = strtolower(trim((string) ($payload['source'] ?? '')));
        if ($payloadSource !== 'pine_webhook') {
            return $this->rejectPineWebhook($config, $payload, 'source_must_be_pine_webhook', [
                'payloadSource' => $payloadSource ?: null,
                'expectedSource' => 'pine_webhook',
            ]);
        }

        $symbol = $this->normalizePineSymbol((string) ($payload['symbol'] ?? $payload['ticker'] ?? ''));
        $allowedSymbols = array_values(array_map('strtoupper', (array) ($settings['symbols'] ?? [])));
        if ($symbol === '' || ! in_array($symbol, $allowedSymbols, true)) {
            return $this->rejectPineWebhook($config, $payload, 'symbol_not_allowed', ['symbol' => $symbol, 'allowedSymbols' => $allowedSymbols]);
        }

        $timeframe = strtolower((string) ($payload['timeframe'] ?? $payload['interval'] ?? $settings['timeframe'] ?? '15m'));
        if (! in_array($timeframe, ['15', '15m'], true)) {
            return $this->rejectPineWebhook($config, $payload, 'timeframe_must_be_15m', ['timeframe' => $timeframe]);
        }
        $timeframe = '15m';

        $confirmedOnly = filter_var($settings['confirmedCandleOnly'] ?? config('future_trade.confirmed_candle_only', true), FILTER_VALIDATE_BOOLEAN);
        $barConfirmed = array_key_exists('bar_confirmed', $payload)
            ? filter_var($payload['bar_confirmed'], FILTER_VALIDATE_BOOLEAN)
            : (array_key_exists('confirmed', $payload) ? filter_var($payload['confirmed'], FILTER_VALIDATE_BOOLEAN) : null);
        if ($confirmedOnly && $barConfirmed !== true) {
            return $this->rejectPineWebhook($config, $payload, 'bar_not_confirmed');
        }

        $rawDecision = strtoupper(trim((string) ($payload['signal'] ?? $payload['action'] ?? $payload['side'] ?? 'WAIT')));
        $positionSide = strtoupper((string) ($state->position_side ?? ''));
        $isCloseSignal = false;
        $closeTargetSide = null;
        $decision = match ($rawDecision) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            'WATCH', 'HOLD', 'NEUTRAL' => 'WAIT',
            'EXIT_LONG', 'FLAT_LONG' => 'CLOSE_LONG',
            'EXIT_SHORT', 'FLAT_SHORT' => 'CLOSE_SHORT',
            default => $rawDecision,
        };
        if (in_array($decision, ['CLOSE_LONG', 'CLOSE_SHORT'], true)) {
            $isCloseSignal = true;
            $closeTargetSide = $decision === 'CLOSE_LONG' ? 'LONG' : 'SHORT';
        } elseif (in_array($decision, ['CLOSE', 'EXIT', 'FLAT'], true)) {
            $isCloseSignal = true;
            $closeTargetSide = in_array($positionSide, ['LONG', 'SHORT'], true) ? $positionSide : null;
            $decision = $closeTargetSide ? 'CLOSE_' . $closeTargetSide : 'CLOSE';
        }
        if (! in_array($decision, ['BUY', 'SELL', 'WAIT', 'CLOSE', 'CLOSE_LONG', 'CLOSE_SHORT'], true)) {
            return $this->rejectPineWebhook($config, $payload, 'unsupported_signal', ['signal' => $decision]);
        }

        $candle = $this->pineWebhookCandleTimes($payload, $timeframe);
        if ($candle['close'] === null || $candle['open'] === null) {
            return $this->rejectPineWebhook($config, $payload, 'candle_time_required');
        }
        $futureCloseAllowedForExit = $isCloseSignal
            && $candle['open']->lessThanOrEqualTo($receivedAt->copy()->addSeconds(90))
            && $candle['close']->lessThanOrEqualTo($receivedAt->copy()->addMinutes($this->timeframeMinutes($timeframe))->addSeconds(90));
        if ($candle['close']->greaterThan($receivedAt->copy()->addSeconds(90)) && ! $futureCloseAllowedForExit) {
            return $this->rejectPineWebhook($config, $payload, 'candle_close_in_future', ['candleClose' => $candle['close']->toIso8601String()]);
        }
        if (((int) $candle['close']->minute % 15) !== 0) {
            return $this->rejectPineWebhook($config, $payload, 'candle_close_not_15m_aligned', ['candleClose' => $candle['close']->toIso8601String()]);
        }

        $price = (float) ($payload['price'] ?? $payload['close'] ?? 0);
        if ($price <= 0) {
            return $this->rejectPineWebhook($config, $payload, 'price_required');
        }

        // Milestone: payload fully validated and parsed. Everything after this
        // point can place an order, so this is the "validated" boundary.
        $latency['validatedMs'] = (int) round((microtime(true) - $t0) * 1000);

        $webhookTiming = $this->pineWebhookTiming($payload, $candle, $receivedAt);

        $longScore = (float) ($payload['longScore'] ?? $payload['long_score'] ?? ($decision === 'BUY' ? ($payload['score'] ?? 0) : 0));
        $shortScore = (float) ($payload['shortScore'] ?? $payload['short_score'] ?? ($decision === 'SELL' ? ($payload['score'] ?? 0) : 0));
        $activeScore = max($longScore, $shortScore, (float) ($payload['score'] ?? 0));
        $confidence = $activeScore > 1 ? $activeScore / 100 : (float) ($payload['confidence'] ?? 0);
        $confidence = $confidence > 0 ? $this->clamp($confidence, 0.01, 0.99) : ($decision === 'WAIT' ? 0.0 : (float) ($settings['minConfidence'] ?? 0.72));
        $riskOverrides = array_filter([
            'takeProfitPercent' => $this->positivePercentOrNull($payload['takeProfitPercent'] ?? $payload['take_profit_percent'] ?? $payload['tp'] ?? null),
            'stopLossPercent' => $this->positivePercentOrNull($payload['stopLossPercent'] ?? $payload['stop_loss_percent'] ?? $payload['sl'] ?? null),
            'trailingTakeProfitPercent' => $this->positivePercentOrNull($payload['trailingTakeProfitPercent'] ?? $payload['trailing_take_profit_percent'] ?? $payload['trailing'] ?? null),
            'rollbackLossPercent' => $this->positivePercentOrNull($payload['rollbackLossPercent'] ?? $payload['rollback_loss_percent'] ?? $payload['rollback'] ?? null),
        ], static fn ($value) => $value !== null);

        $signal = [
            'symbol' => $symbol,
            'signal' => $decision,
            'price' => $price,
            'changePercent' => (float) ($payload['changePercent'] ?? $payload['change_percent'] ?? 0),
            'intradayChange' => (float) ($payload['changePercent'] ?? $payload['change_percent'] ?? 0),
            'volume' => (float) ($payload['volume'] ?? 0),
            'rangePercent' => (float) ($payload['rangePercent'] ?? $payload['range_percent'] ?? 0),
            'rangePosition' => (float) ($payload['rangePosition'] ?? $payload['range_position'] ?? 0.5),
            'confidence' => round($confidence, 4),
            'effectiveMinConfidence' => (float) ($settings['minConfidence'] ?? 0.72),
            'timeframe' => $timeframe,
            'candle_open' => $candle['open']->toIso8601String(),
            'candle_close' => $candle['close']->toIso8601String(),
            'source' => 'pine_webhook',
            'strategy_order_id' => (string) ($payload['strategy_order_id'] ?? $payload['strategyOrderId'] ?? $payload['order_id'] ?? ''),
            'reason' => (string) ($payload['reason'] ?? 'TradingView Pine v6.1.7 webhook'),
            'debug' => [
                'profile' => self::PROFILE_PRIME_V617_CRYPTO,
                'profileName' => (string) ($settings['signalProfileName'] ?? 'AutoFinX Prime v6.1.7 Crypto Stable Default'),
                'source' => 'pine_webhook',
                'webhookTiming' => $webhookTiming,
                'strategyOrderId' => (string) ($payload['strategy_order_id'] ?? $payload['strategyOrderId'] ?? $payload['order_id'] ?? ''),
                'longScore' => $longScore,
                'shortScore' => $shortScore,
                'finalConfidence' => round($confidence, 4),
                'riskOverrides' => $riskOverrides,
                'riskOverridePolicy' => filter_var($settings['useWebhookRiskOverrides'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? 'webhook_risk_overrides_enabled'
                    : ($riskOverrides !== [] ? 'webhook_risk_values_ignored_backend_risk_active' : 'backend_risk_active'),
                'pinePayload' => Arr::except($payload, ['token', 'secret']),
                'checks' => [
                    'barConfirmed' => true,
                    'timeframe15m' => true,
                    'symbolAllowed' => true,
                    'secondaryAiWeightingDisabled' => true,
                ],
            ],
        ];

        $this->persistState($state, [
            'current_symbol' => $symbol,
            'current_signal' => $decision,
            'current_price' => $price,
            'confidence' => $confidence,
            'last_scan_at' => $receivedAt,
            'scanner_snapshot' => [$signal],
        ]);

        if ($riskOverrides !== [] && ! filter_var($settings['useWebhookRiskOverrides'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->logThrottled(
                $config->fresh('state'),
                'WEBHOOK_RISK_IGNORED',
                $symbol,
                $decision,
                $confidence,
                'webhook_risk_values_ignored_backend_risk_active',
                [
                    'source' => 'pine_webhook',
                    'profile' => self::PROFILE_PRIME_V617_CRYPTO,
                    'riskOverrides' => $riskOverrides,
                    'activeRiskAuthority' => 'backend_ui_settings',
                ],
                30
            );
        }

        if ($isCloseSignal) {
            $activeState = $this->state($config->fresh('state'));
            $activeSide = strtoupper((string) ($activeState->position_side ?? ''));
            $hasOpenPosition = (float) ($activeState->quantity ?? 0) > 0 && in_array($activeSide, ['LONG', 'SHORT'], true);

            $closeAllowed = $hasOpenPosition && ($closeTargetSide === null || $closeTargetSide === $activeSide);
            $executed = false;
            $reason = 'Pine webhook produced close signal.';
            $hardBlockPct = (float) ($settings['hardDailyLossBlockPercent'] ?? 0.8);
            $dailyLossPct = (float) ($activeState->daily_loss ?? 0);

            if ($closeAllowed) {
                $closeReason = 'PINE_WEBHOOK_' . $decision;
                if ($this->isLiveMode($config)) {
                    $this->closeLivePosition($config->fresh('state'), $price, $closeReason);
                } else {
                    $this->closePaperPosition($config->fresh('state'), $price, $closeReason);
                }
                $executed = true;
                $reason = 'Pine webhook close signal closed the active position.';
            } elseif ($hasOpenPosition) {
                $reason = 'Pine webhook close side did not match the active position side.';
            } else {
                $reason = 'Pine webhook close signal accepted; no active position was open.';
            }

            $this->log($config->fresh('state'), 'PINE_WEBHOOK_CLOSE', $symbol, $decision, $confidence, $reason, [
                'source' => 'pine_webhook',
                'profile' => self::PROFILE_PRIME_V617_CRYPTO,
                'signalSource' => $signalSource,
                'candleOpen' => $signal['candle_open'],
                'candleClose' => $signal['candle_close'],
                'webhookTiming' => $webhookTiming,
                'closeTargetSide' => $closeTargetSide,
                'activePositionSide' => $activeSide ?: null,
                'dailyLossTier' => $hardBlockPct > 0 && $dailyLossPct >= $hardBlockPct ? 'hard_block_exit_allowed' : null,
                'dailyLossPercent' => $dailyLossPct,
            ]);

            return $this->finalizeWebhookLatency($config, $signal, $t0, $latency, [
                'accepted' => true,
                'executed' => $executed,
                'eligible' => $executed,
                'decision' => $decision,
                'signal' => $decision,
                'source' => 'pine_webhook',
                'execution' => $executed ? 'executed' : ($hasOpenPosition ? 'blocked_close_signal_side_mismatch' : 'blocked_close_signal_no_open_position'),
                'reason' => $executed ? 'close_signal_executed' : ($hasOpenPosition ? 'close_signal_side_mismatch' : 'close_signal_no_open_position'),
            ]);
        }

        if ($decision === 'WAIT') {
            $this->log($config->fresh('state'), 'PINE_WEBHOOK_WAIT', $symbol, 'WAIT', $confidence, 'Pine webhook produced WAIT/WATCH/HOLD/NEUTRAL; no trade executed.', [
                'source' => 'pine_webhook',
                'profile' => self::PROFILE_PRIME_V617_CRYPTO,
                'signalSource' => $signalSource,
                'candleOpen' => $signal['candle_open'],
                'candleClose' => $signal['candle_close'],
                'webhookTiming' => $webhookTiming,
            ]);

            return $this->finalizeWebhookLatency($config, $signal, $t0, $latency, [
                'accepted' => true,
                'executed' => false,
                'eligible' => false,
                'decision' => 'WAIT',
                'signal' => 'WAIT',
                'source' => 'pine_webhook',
                'execution' => 'logged_only',
                'reason' => 'wait_signal',
            ]);
        }

        $this->log($config->fresh('state'), 'PINE_WEBHOOK_SIGNAL', $symbol, $decision, $confidence, 'Pine webhook produced executable signal.', [
            'source' => 'pine_webhook',
            'profile' => self::PROFILE_PRIME_V617_CRYPTO,
            'signalSource' => $signalSource,
            'candleOpen' => $signal['candle_open'],
            'candleClose' => $signal['candle_close'],
            'webhookTiming' => $webhookTiming,
        ]);

        $signalKey = $this->buildSignalKey($config, $signal, $settings);
        if ($this->lockSvc()->hasSignalKey((string) $config->id, $signalKey)) {
            $this->logThrottled(
                $config->fresh('state'),
                'DUPLICATE_WEBHOOK_IGNORED',
                $symbol,
                $decision,
                $confidence,
                'duplicate_webhook_ignored',
                [
                    'source' => 'pine_webhook',
                    'signalKey' => $signalKey,
                    'candleOpen' => $signal['candle_open'],
                    'strategyOrderId' => $signal['strategy_order_id'] ?? null,
                ],
                30
            );

            return $this->finalizeWebhookLatency($config, $signal, $t0, $latency, [
                'accepted' => true,
                'executed' => false,
                'eligible' => false,
                'decision' => $decision,
                'signal' => $decision,
                'source' => 'pine_webhook',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'execution' => 'duplicate_ignored',
                'reason' => 'duplicate_webhook_ignored',
            ]);
        }

        $activeState = $this->state($config->fresh('state'));
        $activeSide = strtoupper((string) ($activeState->position_side ?? ''));
        $entrySide = $decision === 'BUY' ? 'LONG' : 'SHORT';
        $hasOpenPosition = (float) ($activeState->quantity ?? 0) > 0 && in_array($activeSide, ['LONG', 'SHORT'], true);
        if ($hasOpenPosition) {
            if ($activeSide === $entrySide) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'DUPLICATE_WEBHOOK_IGNORED',
                    $symbol,
                    $decision,
                    $confidence,
                    'same_direction_position_exists',
                    [
                        'source' => 'pine_webhook',
                        'activePositionSide' => $activeSide,
                        'requestedPositionSide' => $entrySide,
                    ],
                    30
                );

                return $this->finalizeWebhookLatency($config, $signal, $t0, $latency, [
                    'accepted' => true,
                    'executed' => false,
                    'eligible' => false,
                    'decision' => $decision,
                    'signal' => $decision,
                    'source' => 'pine_webhook',
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'execution' => 'blocked_same_direction_position_exists',
                    'reason' => 'same_direction_position_exists',
                ]);
            }

            $reversalMode = strtolower((string) ($settings['reversalMode'] ?? 'close_only'));
            $closeReason = 'PINE_WEBHOOK_OPPOSITE_' . $decision . '_' . strtoupper($reversalMode === 'close_then_reverse' ? 'CLOSE_THEN_REVERSE' : 'CLOSE_ONLY');
            if ($this->isLiveMode($config)) {
                $this->closeLivePosition($config->fresh('state'), $price, $closeReason);
            } else {
                $this->closePaperPosition($config->fresh('state'), $price, $closeReason);
            }
            $this->log(
                $config->fresh('state'),
                'PINE_WEBHOOK_REVERSAL_CLOSE',
                $symbol,
                $decision,
                $confidence,
                $reversalMode === 'close_then_reverse'
                    ? 'Opposite Pine signal closed the active position. Reverse entry requires a fresh confirmed webhook after close confirmation.'
                    : 'Opposite Pine signal closed the active position. Reversal mode is close_only, so no new opposite entry was opened.',
                [
                    'source' => 'pine_webhook',
                    'activePositionSide' => $activeSide,
                    'requestedPositionSide' => $entrySide,
                    'reversalMode' => $reversalMode,
                ]
            );

            $latency['orderPlacedMs'] = (int) round((microtime(true) - $t0) * 1000);
            return $this->finalizeWebhookLatency($config, $signal, $t0, $latency, [
                'accepted' => true,
                'executed' => true,
                'eligible' => true,
                'decision' => $decision,
                'signal' => $decision,
                'source' => 'pine_webhook',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'execution' => 'close_only_executed',
                'reason' => $reversalMode === 'close_then_reverse' ? 'opposite_signal_closed_position_reverse_waiting_confirmation' : 'opposite_signal_closed_position_close_only',
            ]);
        }

        $beforeTradeCount = (int) $this->state($config)->trade_count_today;
        // Milestone: state-sync prep done; about to enter execution path.
        $latency['stateSyncedMs'] = (int) round((microtime(true) - $t0) * 1000);
        $this->maybeEnterPaperPosition($config->fresh('state'), array_merge($signal, ['signal_key' => $signalKey]));
        $afterState = $this->state($config->fresh('state'));
        $executed = (int) $afterState->trade_count_today > $beforeTradeCount
            || in_array((string) $afterState->state, ['IN_POSITION', 'LIVE_IN_POSITION'], true);
        // Milestone: execution path returned (order placed if executed=true).
        $latency['orderPlacedMs'] = (int) round((microtime(true) - $t0) * 1000);

        return $this->finalizeWebhookLatency($config, $signal, $t0, $latency, [
            'accepted' => true,
            'executed' => $executed,
            'eligible' => $executed,
            'decision' => $decision,
            'signal' => $decision,
            'source' => 'pine_webhook',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'candleOpen' => $signal['candle_open'],
            'candleClose' => $signal['candle_close'],
            'state' => $afterState->state,
            'currentSignal' => $afterState->current_signal,
            'execution' => $executed ? 'executed' : 'blocked_with_logged_reason',
        ]);
    }

    public function recordRawPineWebhookRequest(FutureTradeBotConfig $config, array $payload, array $context = []): void
    {
        $rawBody = (string) ($context['rawBody'] ?? '');
        $symbol = $this->normalizePineSymbol((string) ($payload['symbol'] ?? $payload['ticker'] ?? ''));
        $signal = strtoupper(trim((string) ($payload['signal'] ?? $payload['action'] ?? $payload['side'] ?? '')));
        $confidence = (float) ($payload['confidence'] ?? $payload['score'] ?? 0);
        if ($confidence > 1) {
            $confidence /= 100;
        }

        $safePayload = $this->sanitizeWebhookPayload($payload);
        $this->log($config->fresh('state'), 'WEBHOOK_RAW_RECEIVED', $symbol ?: null, $signal ?: null, $confidence, 'TradingView webhook request received at backend.', [
            'source' => 'pine_webhook',
            'workspace' => $config->workspace_id,
            'receivedAt' => now()->toIso8601String(),
            'remoteIp' => $context['remoteIp'] ?? null,
            'headersSummary' => (array) ($context['headersSummary'] ?? []),
            'rawBodyPreview' => Str::limit($this->maskWebhookRawBody($rawBody), 4000, '...'),
            'parsedJson' => $safePayload,
            'parseStatus' => (string) ($context['parseStatus'] ?? ($payload === [] ? 'empty' : 'json')),
            'tokenStatus' => (string) ($context['tokenStatus'] ?? 'unknown'),
            'profile' => (string) ($payload['profile'] ?? $payload['profile_id'] ?? ''),
            'symbol' => $symbol,
            'timeframe' => (string) ($payload['timeframe'] ?? $payload['interval'] ?? ''),
            'signal' => $signal,
            'confidence' => $confidence,
            'barConfirmed' => array_key_exists('bar_confirmed', $payload)
                ? filter_var($payload['bar_confirmed'], FILTER_VALIDATE_BOOLEAN)
                : (array_key_exists('confirmed', $payload) ? filter_var($payload['confirmed'], FILTER_VALIDATE_BOOLEAN) : null),
        ]);
    }

    public function lastWebhookPayload(FutureTradeBotConfig $config): array
    {
        $latest = $config->logs()
            ->where('event', 'WEBHOOK_RAW_RECEIVED')
            ->orderByDesc('occurred_at')
            ->first();

        if (! $latest) {
            return [
                'received' => false,
                'summary' => 'No TradingView webhook payload has reached this workspace yet.',
            ];
        }

        $context = $this->arrayContext($latest->context ?? []);
        $nextDecision = $config->logs()
            ->whereIn('event', ['PINE_WEBHOOK_SIGNAL', 'PINE_WEBHOOK_WAIT', 'PINE_WEBHOOK_CLOSE', 'PINE_WEBHOOK_REJECTED', 'DUPLICATE_WEBHOOK_IGNORED'])
            ->where('occurred_at', '>=', $latest->occurred_at)
            ->orderBy('occurred_at')
            ->first();
        $decisionEvent = $nextDecision?->event;
        $rejected = $decisionEvent === 'PINE_WEBHOOK_REJECTED';
        $duplicate = $decisionEvent === 'DUPLICATE_WEBHOOK_IGNORED';
        $executable = $decisionEvent === 'PINE_WEBHOOK_SIGNAL' && in_array(strtoupper((string) $nextDecision?->signal), ['BUY', 'SELL'], true);

        // Latency telemetry from the most recent webhook execution. Stored by
        // finalizeWebhookLatency() on each ingestPineWebhook() invocation.
        $latency = (array) ($this->cacheGet('future_trade_last_webhook_latency:' . $config->workspace_id, []) ?: []);

        // Daily-loss tier so the UI can render the right severity color and
        // tell the operator which threshold has been crossed.
        $state = $config->state;
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $dailyLossPct = (float) ($state?->daily_loss ?? 0);
        $softWarn = (float) ($settings['softDailyLossWarnPercent'] ?? 0.3);
        $blockEntries = (float) ($settings['maxDailyLossPercent'] ?? 0.5);
        $hardBlock = (float) ($settings['hardDailyLossBlockPercent'] ?? 0.8);
        $dailyLossTier = match (true) {
            $hardBlock > 0 && $dailyLossPct >= $hardBlock => 'hard_block',
            $blockEntries > 0 && $dailyLossPct >= $blockEntries => 'block_entries',
            $softWarn > 0 && $dailyLossPct >= $softWarn => 'warn',
            default => 'ok',
        };

        return [
            'received' => true,
            'receivedAt' => optional($latest->occurred_at)->toIso8601String(),
            'ageSeconds' => $this->ageSecondsSince($latest->occurred_at),
            'symbol' => $latest->symbol,
            'signal' => $latest->signal,
            'confidence' => (float) $latest->confidence,
            'profile' => $context['profile'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'rawBodyPreview' => $context['rawBodyPreview'] ?? null,
            'parsedJson' => $context['parsedJson'] ?? [],
            'parseStatus' => $context['parseStatus'] ?? null,
            'tokenStatus' => $context['tokenStatus'] ?? null,
            'barConfirmed' => $context['barConfirmed'] ?? null,
            'remoteIp' => $context['remoteIp'] ?? null,
            'headersSummary' => $context['headersSummary'] ?? [],
            'decisionEvent' => $decisionEvent,
            'executable' => $executable,
            'duplicate' => $duplicate,
            'tokenValid' => ($context['tokenStatus'] ?? null) === 'valid',
            'parsedJsonValid' => ($context['parseStatus'] ?? null) === 'json',
            'rejectedReason' => $rejected ? $nextDecision?->reason : null,
            'finalReason' => $nextDecision?->reason,
            'latency' => $latency !== [] ? $latency : null,
            'dailyLossTier' => $dailyLossTier,
            'dailyLossPercent' => $dailyLossPct,
            'dailyLossThresholds' => [
                'softWarnPercent' => $softWarn,
                'blockEntriesPercent' => $blockEntries,
                'hardBlockPercent' => $hardBlock,
            ],
        ];
    }

    public function binanceHealthPayload(FutureTradeBotConfig $config): array
    {
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $baseUrl = (string) ($settings['forceTestnet'] ?? true
            ? ($settings['testnetApiUrl'] ?? 'https://testnet.binancefuture.com')
            : ($settings['liveApiUrl'] ?? 'https://fapi.binance.com'));
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'fapi.binance.com');
        $dnsOk = $host !== '' && gethostbyname($host) !== $host;
        $account = $this->accountSummary($config);
        $rest = $this->restStateSyncSnapshot($account);
        $source = (string) ($account['balanceSource'] ?? 'unknown');
        $accountOk = in_array($source, ['BINANCE_FUTURES', 'PAPER_LEDGER', 'BINANCE_CACHE_STALE'], true);
        $restOk = (bool) ($rest['keyedRequestHealthy'] ?? false) && (bool) ($rest['verified'] ?? false);
        $failureReason = null;

        if (! $dnsOk) {
            $failureReason = 'dns_resolution_failed';
            $this->logThrottled($config->fresh('state'), 'BINANCE_DNS_FAILED', $config->state?->current_symbol, null, 0, 'Binance API host DNS resolution failed.', [
                'host' => $host,
                'baseUrl' => $baseUrl,
            ], 120);
        } elseif (! $restOk) {
            $failureReason = (string) ($account['balanceError'] ?? $account['balanceWarning'] ?? 'binance_rest_degraded');
            if (str_contains(strtolower($failureReason), 'binance_rest_circuit_open')) {
                $this->logThrottled($config->fresh('state'), 'BINANCE_REST_CIRCUIT_OPEN', $config->state?->current_symbol, null, 0, $failureReason, [
                    'balanceSource' => $source,
                    'rest' => $rest,
                ], 120);
            }
            $this->logThrottled($config->fresh('state'), 'BINANCE_ACCOUNT_SYNC_FAILED', $config->state?->current_symbol, null, 0, $failureReason, [
                'balanceSource' => $source,
                'rest' => $rest,
            ], 120);
        } else {
            $this->logThrottled($config->fresh('state'), 'BINANCE_HEALTH_OK', $config->state?->current_symbol, null, 1, 'Binance REST state is healthy or safely cached.', [
                'balanceSource' => $source,
                'rest' => $rest,
            ], 300);
        }

        return [
            'dns_ok' => $dnsOk,
            'rest_ok' => $restOk,
            'account_ok' => $accountOk,
            'server_time_ok' => $restOk,
            'last_success_at' => $rest['syncedAt'] ?? null,
            'last_failure_at' => $failureReason ? now()->toIso8601String() : null,
            'failure_reason' => $failureReason,
            'cached_balance_age_seconds' => $rest['ageSeconds'] ?? null,
            'cached_position_age_seconds' => $rest['ageSeconds'] ?? null,
            'balance_source' => strtolower($source),
            'fresh' => (bool) ($rest['fresh'] ?? false),
        ];
    }

    public function runtimeHealthPayload(FutureTradeBotConfig $config): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $runtime = $this->buildRuntimeHealthSnapshot($config, $state, $signals[0] ?? null);
        $health = $this->operationalHealth->summary($config->workspace_id);

        return [
            'scheduler' => (string) data_get($health, 'checks.scheduler.state', 'unknown'),
            'queue' => (string) data_get($health, 'checks.queue.state', 'unknown'),
            'last_schedule_tick' => data_get($health, 'checks.scheduler.last_run_at'),
            'last_queue_job' => data_get($health, 'checks.queue.last_job_at'),
            'duplicate_workers_detected' => (bool) data_get($health, 'checks.queue.duplicate_workers_detected', false),
            'failed_jobs' => (int) data_get($health, 'checks.queue.failed_jobs', 0),
            'binance_rest' => $this->binanceHealthPayload($config)['rest_ok'] ? 'ok' : 'degraded',
            'webhook_recent' => $this->pineWebhookRecentlyReceived($config, $settings),
            'runtime' => $runtime,
        ];
    }

    public function liveChartPayload(FutureTradeBotConfig $config, ?string $symbol = null, ?string $timeframe = null, int $limit = 180): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $bestSignal = $signals[0] ?? null;
        $chartSymbol = strtoupper(trim((string) ($symbol ?: $this->resolveMarketChartSymbol($state, $bestSignal, $settings))));
        $chartTimeframe = $this->normalizeKlineInterval((string) ($timeframe ?: ($settings['timeframe'] ?? '5m')));
        $candles = $chartSymbol !== '' ? $this->fetchMarketCandles($chartSymbol, $chartTimeframe, $limit) : [];
        $feed = $this->feedFreshnessSnapshot($chartSymbol);
        $isForming = false;
        $lastCandleClose = count($candles) > 0 ? (float) ($candles[count($candles) - 1]['close'] ?? 0) : null;

        if (($feed['feedFresh'] ?? false) && (float) ($feed['lastTickPrice'] ?? 0) > 0 && count($candles) > 0) {
            $index = count($candles) - 1;
            $price = (float) $feed['lastTickPrice'];
            $candles[$index]['close'] = $price;
            $candles[$index]['high'] = max((float) ($candles[$index]['high'] ?? $price), $price);
            $candles[$index]['low'] = min((float) ($candles[$index]['low'] ?? $price), $price);
            $candles[$index]['isFormingCandle'] = true;
            $lastCandleClose = $price;
            $isForming = true;
        }

        $chartUpdatedAt = $this->isoTimestamp(now());

        return [
            'symbol' => $chartSymbol ?: null,
            'timeframe' => $chartTimeframe,
            'candles' => $candles,
            'latestTickPrice' => $feed['lastTickPrice'],
            'latestTickAt' => $feed['lastTickAt'],
            'chartUpdatedAt' => $chartUpdatedAt,
            'chartAgeSeconds' => 0,
            'tickAgeSeconds' => (int) ($feed['feedAgeSeconds'] ?? 0),
            'lastCandleClose' => $lastCandleClose,
            'isFormingCandle' => $isForming,
            'dataSource' => $feed['source'],
            'debug' => [
                'latestTickPrice' => $feed['lastTickPrice'],
                'chartUpdatedAt' => $chartUpdatedAt,
                'lastCandleClose' => $lastCandleClose,
                'isFormingCandle' => $isForming,
            ],
        ];
    }

    public function diagnosticsPayload(FutureTradeBotConfig $config, bool $persist = false): array
    {
        $state = $this->state($config);
        $state = $this->refreshDailyCycleState($config, $state);
        $this->applyDailyLossLock($config, $state);
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $signals = array_slice((array) ($state->scanner_snapshot ?? []), 0, 20);
        $bestSignal = $signals[0] ?? null;
        $dynamic = $this->dynamicDecision($config, $state, $signals, $bestSignal, $settings);
        $effectiveSettings = $dynamic['settings'];
        $effectiveMinConfidence = (float) ($dynamic['effectiveMinConfidence'] ?? ($settings['minConfidence'] ?? 0));
        $account = $this->accountSummary($config);
        $health = $this->operationalHealth->summary($config->workspace_id);
        $webhookTelemetry = $this->latestWebhookTelemetry($config);
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        $envReady = $this->environmentTradingReady($forceTestnet, $settings);
        $liveGuard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
        $requiredTradeAmountUsdt = (float) ($effectiveSettings['tradeAmountUsdt'] ?? $settings['tradeAmountUsdt'] ?? 0);
        $runtimeHealth = $this->buildRuntimeHealthSnapshot($config, $state, $bestSignal);

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

        if (in_array($account['balanceSource'], ['BINANCE_TEMPORARILY_UNAVAILABLE', 'BINANCE_CACHE_STALE'], true)) {
            $walletState = 'warning';
            $walletMessage = (string) ($account['balanceWarning'] ?? $account['balanceError'] ?? 'Binance futures balance sync is temporarily degraded.');
            $botWarnings[] = 'wallet_balance_sync_degraded';
        } elseif ($account['balanceSource'] === 'BINANCE_UNAVAILABLE') {
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
            ->whereIn('event', ['SCAN_FAILED', 'MARKET_SNAPSHOT_FAILED', 'LIVE_ENTRY_FAILED', 'BALANCE_SYNC_FAILED', 'BALANCE_SYNC_WARNING', 'TRADE_BLOCKED', 'SIGNAL_SKIPPED', 'LIVE_GUARD_BLOCKED', 'DAILY_LOSS_LIMIT_HIT'])
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

        $tradeEligibility = $this->tradeEligibilitySnapshot(
            $config,
            $state,
            $settings,
            $bestSignal,
            $webhookTelemetry,
            $runtimeHealth,
            $health,
            $walletState,
            $botBlockers,
            $botWarnings,
            $latestAlert ? [
                'event' => $latestAlert->event,
                'reason' => $latestAlert->reason,
                'occurredAt' => optional($latestAlert->occurred_at)->toIso8601String(),
            ] : null
        );

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
            'webhook' => $webhookTelemetry,
            'tradeEligibility' => $tradeEligibility,
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
                    'webhook' => $webhookTelemetry,
                    'tradeEligibility' => $tradeEligibility,
                ],
                240
            );
        }

        return $payload;
    }

    /**
     * Human-facing explanation of why the bot is not opening a position now.
     * This does not change trading decisions; it mirrors the same guards used
     * elsewhere so support/debug screens can identify the first real blocker.
     */
    private function tradeEligibilitySnapshot(
        FutureTradeBotConfig $config,
        FutureTradeBotState $state,
        array $settings,
        ?array $bestSignal,
        array $webhookTelemetry,
        array $runtimeHealth,
        array $health,
        string $walletState,
        array $botBlockers,
        array $botWarnings,
        ?array $latestAlert
    ): array {
        $signalSource = $this->normalizedSignalSource($settings);
        $webhookAuthority = in_array($signalSource, ['webhook', 'webhook_fallback'], true);
        $latestWebhookEvent = (string) ($webhookTelemetry['latestEvent'] ?? '');
        $latestWebhookSignal = strtoupper((string) ($webhookTelemetry['signal'] ?? ''));
        $runtimeBlockers = array_values((array) ($runtimeHealth['activeBlockers'] ?? []));
        $serverBlockers = array_values((array) ($health['blockers'] ?? []));
        $serverWarnings = array_values((array) ($health['warnings'] ?? []));
        if ($webhookAuthority) {
            $diagnosticOnlyBlockers = [
                'stale_market_data',
                'stale_feed_active',
                'market_data_unavailable',
                'market_data_stale',
                'ai_trading_disabled',
                'external_context_disabled',
            ];
            $serverBlockers = array_values(array_filter(
                $serverBlockers,
                static fn ($blocker): bool => ! in_array((string) $blocker, $diagnosticOnlyBlockers, true)
            ));
            foreach (['ai_trading_disabled', 'external_context_disabled', 'market_data_unavailable', 'market_data_stale'] as $warning) {
                if (in_array($warning, (array) ($health['warnings'] ?? []), true) || in_array($warning, (array) ($health['blockers'] ?? []), true)) {
                    $serverWarnings[] = $warning;
                }
            }
        }

        $blockers = [];
        $warnings = [];
        $checks = [];

        $checks[] = [
            'label' => 'Signal authority',
            'state' => $webhookAuthority ? 'ok' : 'warning',
            'message' => $webhookAuthority
                ? 'TradingView webhook is the entry authority; backend scanner is diagnostic only.'
                : 'Backend scanner can control entries because webhook source is not selected.',
        ];

        if ($webhookAuthority) {
            if ($latestWebhookEvent === '') {
                $warnings[] = 'waiting_for_tradingview_webhook';
                $checks[] = ['label' => 'Latest webhook', 'state' => 'warning', 'message' => 'No TradingView webhook has reached this workspace yet.'];
            } elseif ($latestWebhookEvent === 'PINE_WEBHOOK_REJECTED') {
                $reason = (string) ($webhookTelemetry['reason'] ?? 'unknown_rejection');
                $blockers[] = 'latest_webhook_rejected';
                $checks[] = ['label' => 'Latest webhook', 'state' => 'blocked', 'message' => "Latest webhook was rejected: {$reason}."];
            } elseif (in_array($latestWebhookEvent, ['PINE_WEBHOOK_WAIT', 'PINE_WEBHOOK_CLOSE'], true)) {
                $warnings[] = strtolower($latestWebhookEvent);
                $checks[] = ['label' => 'Latest webhook', 'state' => 'warning', 'message' => 'Latest webhook was accepted but it was WAIT/CLOSE, so no entry should open.'];
            } elseif ($latestWebhookEvent === 'PINE_WEBHOOK_SIGNAL') {
                $checks[] = ['label' => 'Latest webhook', 'state' => 'ok', 'message' => "Latest webhook was accepted as {$latestWebhookSignal}."];
            }
        }

        foreach ($runtimeBlockers as $blocker) {
            $blockers[] = strtolower((string) $blocker);
            $checks[] = ['label' => 'Live safety', 'state' => 'blocked', 'message' => 'Runtime safety blocker active: '.(string) $blocker.'.'];
        }

        if ($walletState === 'blocked') {
            $blockers[] = 'wallet_blocked';
        } elseif ($walletState === 'warning') {
            $warnings[] = 'wallet_warning';
        }

        foreach ($serverBlockers as $blocker) {
            $blockers[] = (string) $blocker;
        }
        foreach ($serverWarnings as $warning) {
            $warnings[] = (string) $warning;
        }

        foreach ($botBlockers as $blocker) {
            $blockers[] = (string) $blocker;
        }
        foreach ($botWarnings as $warning) {
            $warnings[] = (string) $warning;
        }

        $bestSignalName = strtoupper((string) ($bestSignal['signal'] ?? $state->current_signal ?? 'WAIT'));
        if ($webhookAuthority && $bestSignalName === 'WAIT') {
            $warnings[] = 'scanner_wait_diagnostic_only';
            $checks[] = ['label' => 'Scanner mirror', 'state' => 'warning', 'message' => 'Scanner mirror is WAIT; in webhook mode this is diagnostic and does not reject a valid webhook entry.'];
        }

        if ($latestAlert && (string) ($latestAlert['event'] ?? '') === 'BALANCE_SYNC_FAILED') {
            $warnings[] = 'binance_balance_sync_failed';
            $checks[] = ['label' => 'Binance time/balance', 'state' => 'warning', 'message' => (string) ($latestAlert['reason'] ?? 'Balance sync failed.')];
        }

        $blockers = array_values(array_unique(array_filter($blockers)));
        $warnings = array_values(array_unique(array_filter($warnings)));
        $hasExecutableWebhook = $webhookAuthority && $latestWebhookEvent === 'PINE_WEBHOOK_SIGNAL' && in_array($latestWebhookSignal, ['BUY', 'SELL'], true);
        $canOpenNow = $blockers === [] && (! $webhookAuthority || $hasExecutableWebhook);

        $decision = $canOpenNow
            ? 'eligible'
            : ($blockers !== [] ? 'blocked' : 'waiting');

        $summary = match ($decision) {
            'eligible' => 'Trade flow is eligible for the latest executable signal.',
            'blocked' => 'Trade flow is blocked; fix the first blocker before forcing entries.',
            default => $webhookAuthority
                ? 'Waiting for an accepted BUY/SELL TradingView webhook.'
                : 'Waiting for backend scanner signal to pass.',
        };

        return [
            'decision' => $decision,
            'summary' => $summary,
            'canOpenNow' => $canOpenNow,
            'signalSource' => $signalSource,
            'webhookAuthority' => $webhookAuthority,
            'latestWebhookEvent' => $latestWebhookEvent ?: null,
            'latestWebhookSignal' => $latestWebhookSignal ?: null,
            'latestWebhookReason' => $webhookTelemetry['reason'] ?? null,
            'bestSignal' => $bestSignalName,
            'bestConfidence' => (float) ($bestSignal['confidence'] ?? $state->confidence ?? 0),
            'activeMinimum' => (float) data_get($bestSignal, 'effectiveMinConfidence', $settings['minConfidence'] ?? 0),
            'runtimeBlockers' => $runtimeBlockers,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    // -------------------------------------------------------------------------
    // Backtest public accessors — thin wrappers so BacktestEngine can call
    // private scoring/snapshot/SL-TP helpers without using Reflection.
    // -------------------------------------------------------------------------

    public function scoreTickerForBacktest(array $ticker, array $settings): array
    {
        return $this->scoreTicker($ticker, $settings);
    }

    public function buildSequenceSnapshotForBacktest(array $candles): array
    {
        return $this->buildSequenceSnapshot($candles);
    }

    public function buildStructureSnapshotForBacktest(array $candles): array
    {
        return $this->buildStructureSnapshot($candles);
    }

    public function normalizeSettingsForBacktest(array $settings): array
    {
        return $this->normalizeFutureTradeSettings($settings);
    }

    private function normalizePineSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        $symbol = preg_replace('/^[A-Z0-9_]+:/', '', $symbol) ?? $symbol;
        $symbol = str_replace(['.P', 'PERP'], '', $symbol);

        return preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '';
    }

    /**
     * @return array{open: ?Carbon, close: ?Carbon}
     */
    private function pineWebhookCandleTimes(array $payload, string $timeframe): array
    {
        $close = $this->parsePineWebhookTime($payload['candle_close_time'] ?? $payload['time_close'] ?? $payload['close_time'] ?? null);
        $open = $this->parsePineWebhookTime($payload['candle_open_time'] ?? $payload['candle_open'] ?? $payload['time'] ?? null);
        $minutes = $this->timeframeMinutes($timeframe);

        if ($close === null && $open !== null) {
            $close = $open->copy()->addMinutes($minutes);
        }
        if ($open === null && $close !== null) {
            $open = $close->copy()->subMinutes($minutes);
        }

        return ['open' => $open, 'close' => $close];
    }

    private function parsePineWebhookTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $number = (int) $value;
                return $number > 9999999999
                    ? Carbon::createFromTimestampMs($number)
                    : Carbon::createFromTimestamp($number);
            }

            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function pineWebhookTiming(array $payload, array $candle, Carbon $receivedAt): array
    {
        $sentAt = $this->parsePineWebhookTime(
            $payload['sent_at']
                ?? $payload['alert_time']
                ?? $payload['webhook_sent_at']
                ?? $payload['timestamp']
                ?? null
        );
        $closeAt = $candle['close'] ?? null;

        $timing = [
            'receivedAt' => $receivedAt->toIso8601String(),
            'payloadSentAt' => $sentAt?->toIso8601String(),
            'candleCloseAt' => $closeAt instanceof Carbon ? $closeAt->toIso8601String() : null,
            'transportLatencyMs' => $sentAt ? $this->positiveMillisecondsBetween($sentAt, $receivedAt) : null,
            'signalLatencyMs' => $closeAt instanceof Carbon ? $this->positiveMillisecondsBetween($closeAt, $receivedAt) : null,
        ];

        return array_filter($timing, static fn ($value) => $value !== null);
    }

    private function positiveMillisecondsBetween(Carbon $from, Carbon $to): int
    {
        $fromSeconds = (float) $from->format('U.u');
        $toSeconds = (float) $to->format('U.u');

        return max(0, (int) round(($toSeconds - $fromSeconds) * 1000));
    }

    private function rejectPineWebhook(FutureTradeBotConfig $config, array $payload, string $reason, array $context = []): array
    {
        $symbol = $this->normalizePineSymbol((string) ($payload['symbol'] ?? $payload['ticker'] ?? ''));
        $signal = strtoupper(trim((string) ($payload['signal'] ?? $payload['action'] ?? $payload['side'] ?? 'WAIT')));
        $this->log($config->fresh('state'), 'PINE_WEBHOOK_REJECTED', $symbol ?: null, $signal ?: null, 0.0, $reason, array_merge([
            'source' => 'pine_webhook',
            'profile' => self::PROFILE_PRIME_V617_CRYPTO,
            'payload' => Arr::except($payload, ['token', 'secret']),
        ], $context));

        return [
            'accepted' => false,
            'executed' => false,
            'eligible' => false,
            'source' => 'pine_webhook',
            'execution' => 'rejected',
            'reason' => $reason,
        ] + $context;
    }

    // -------------------------------------------------------------------------

    public function configPayload(FutureTradeBotConfig $config): array
    {
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());

        return [
            'id' => $config->id,
            'settings' => $settings,
            'algorithm_notes' => $config->algorithm_notes,
            'paper_trading' => $config->paper_trading,
            'enable_live_trading' => $config->enable_live_trading,
            'live_env_enabled' => $this->environmentTradingReady((bool) ($settings['forceTestnet'] ?? true), $settings),
            'testnet_env_enabled' => $this->environmentTradingEnabled(true, $settings),
            'real_env_enabled' => $this->environmentTradingEnabled(false, $settings),
            'testnet_credentials_configured' => $this->hasFuturesCredentials(true, $settings),
            'real_credentials_configured' => $this->hasFuturesCredentials(false, $settings),
            'available_symbols' => config('future_trade.available_symbols', []),
            'config_versions' => $this->configVersionsPayload($config),
        ];
    }

    public function generateTuningPlanPreview(FutureTradeBotConfig $config): array
    {
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $auditPayload = $this->missedTradeAuditPayload($config, $settings);
        $modeSummary = collect((array) ($auditPayload['modeComparison'] ?? []));
        $recommendedMode = $this->recommendedModeFromAuditSummary($modeSummary, $settings);
        $reviewedAudits = $config->missedSignalAudits()
            ->where('status', 'REVIEWED')
            ->latest('signal_time')
            ->limit(120)
            ->get();

        $missedWins = $reviewedAudits->where('would_have_result', 'MISSED_WIN');
        $avgGap = $missedWins->count() > 0 ? abs((float) $missedWins->avg('confidence_gap')) : 0.0;
        $currentMin = (float) ($settings['minConfidence'] ?? 0.52);
        $recommendedMin = $this->clamp(
            $currentMin - min(0.05, max(0.0, $avgGap * 0.65)),
            0.45,
            0.60
        );

        $sampleCount = (int) ($auditPayload['reviewedSignals'] ?? 0);
        $recommendedConfidenceMode = $sampleCount >= 50 && $recommendedMode !== 'CLASSIC_SIMPLE_LIVE_SIGNAL'
            ? 'semi_dynamic'
            : 'fixed';

        $topBlockers = collect((array) ($auditPayload['topBlockers'] ?? []))
            ->filter(fn (array $item) => (int) ($item['recommendedWeight'] ?? $item['currentWeight'] ?? 0) !== (int) ($item['currentWeight'] ?? 0))
            ->take(8)
            ->values()
            ->all();

        $recommendedSettings = $settings;
        $recommendedSettings['strictEntryGuards'] = false;
        $recommendedSettings['minConfidence'] = round($recommendedMin, 4);
        $recommendedSettings['confidenceMode'] = $recommendedConfidenceMode;
        $recommendedSettings['classicSimpleMode'] = $recommendedMode === 'CLASSIC_SIMPLE_LIVE_SIGNAL';
        $recommendedSettings['coreOnlyMode'] = $recommendedMode === 'CORE_ONLY';

        foreach ($topBlockers as $blocker) {
            $factor = (string) ($blocker['factor'] ?? '');
            if ($factor === '') {
                continue;
            }
            $recommendedSettings['indicatorInfluence'][$factor] = (int) ($blocker['recommendedWeight'] ?? 0);
            $recommendedSettings['indicatorControls'][$factor] = array_merge(
                (array) ($recommendedSettings['indicatorControls'][$factor] ?? []),
                [
                    'weight' => (int) ($blocker['recommendedWeight'] ?? 0),
                    'enabled' => (int) ($blocker['recommendedWeight'] ?? 0) > 0,
                ]
            );
        }

        $currentMode = $this->resolveStrategyMode($settings);
        $currentSummary = $modeSummary->firstWhere('mode', $currentMode) ?? [];
        $recommendedSummary = $modeSummary->firstWhere('mode', $recommendedMode) ?? [];

        return [
            'analysisOnly' => true,
            'autoApply' => false,
            'generatedAt' => now()->toIso8601String(),
            'current' => [
                'mode' => $currentMode,
                'minConfidence' => round($currentMin, 4),
                'confidenceMode' => (string) ($settings['confidenceMode'] ?? 'fixed'),
                'strictEntryGuards' => (bool) ($settings['strictEntryGuards'] ?? false),
            ],
            'recommended' => [
                'mode' => $recommendedMode,
                'minConfidence' => round($recommendedMin, 4),
                'confidenceMode' => $recommendedConfidenceMode,
                'strictEntryGuards' => false,
                'weightChanges' => array_map(fn (array $item) => [
                    'factor' => (string) ($item['factor'] ?? ''),
                    'currentWeight' => (int) ($item['currentWeight'] ?? 0),
                    'recommendedWeight' => (int) ($item['recommendedWeight'] ?? 0),
                    'reason' => (string) ($item['reason'] ?? ''),
                ], $topBlockers),
            ],
            'previewImpact' => [
                'currentWouldTrade' => (int) ($currentSummary['wouldTrade'] ?? 0),
                'recommendedWouldTrade' => (int) ($recommendedSummary['wouldTrade'] ?? 0),
                'capturedMissedWinsDelta' => (int) (($recommendedSummary['capturedMissedWins'] ?? 0) - ($currentSummary['capturedMissedWins'] ?? 0)),
                'allowedBadSignalsDelta' => (int) (($recommendedSummary['allowedBadSignals'] ?? 0) - ($currentSummary['allowedBadSignals'] ?? 0)),
                'netWouldHavePnlDelta' => round((float) (($recommendedSummary['netWouldHavePnlPercent'] ?? 0) - ($currentSummary['netWouldHavePnlPercent'] ?? 0)), 4),
            ],
            'forwardValidation' => [
                'reviewedSamples' => $sampleCount,
                'currentModeSummary' => $currentSummary,
                'recommendedModeSummary' => $recommendedSummary,
                'recommendation' => (string) ($recommendedSummary['recommendation'] ?? 'Collect more data'),
            ],
            'notes' => [
                'Preview only. No config was saved or applied.',
                'Hard blockers remain unchanged.',
                'Use config versions before applying any tuning manually.',
            ],
        ];
    }

    private function maybeEnterPaperPosition(FutureTradeBotConfig $config, array $signal): void
    {
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $primePineExecution = $this->isPrimeCryptoProfile($settings);
        $strictEntryGuards = (bool) ($settings['strictEntryGuards'] ?? false);
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), $signal, $settings);
        $settings = $dynamic['settings'];
        $analysis = (array) ($dynamic['public']['analysis'] ?? []);
        if ((string) $state->state === 'IN_POSITION' || (float) $state->quantity > 0) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['open_position_exists']);
            return;
        }
        if (! $this->isWithinTradingHours($settings)) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['outside_trading_hours']);
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Outside configured trading hours (UTC).',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED_HOURS'], $signal, ['outside_trading_hours']),
                300
            );
            return;
        }
        if (($settings['emergencyStop'] ?? false)) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['emergency_stop']);
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Emergency stop is enabled in algorithm config.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['emergency_stop']),
                30
            );
            return;
        }
        if ($signal['signal'] === 'WAIT') {
            $this->recordMissedTradeAudit($config, $signal, $dynamic, ['strategy_wait_signal']);
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'WAIT', ['strategy_wait_signal']);
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                (string) ($signal['reason'] ?? 'Strategy returned WAIT.'),
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED'], $signal, ['strategy_wait_signal']),
                30
            );
            return;
        }
        if ($this->primeMirrorExecutionBlocked($config, $settings, $signal)) {
            $sourceMode = $this->normalizedSignalSource($settings);
            $reason = $sourceMode === 'webhook'
                ? 'backend_scanner_read_only_webhook_mode'
                : ($sourceMode === 'webhook_fallback' && ! $this->webhookFallbackEnabled($settings)
                    ? 'webhook_fallback_disabled_backend_scanner_read_only'
                    : ($this->isLiveMode($config)
                        ? 'prime_mirror_live_disabled_pending_tv_csv_validation'
                        : 'prime_mirror_execution_disabled_use_tradingview_webhook'));
            $this->recordMissedTradeAudit($config, $signal, $dynamic, [$reason]);
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', [$reason]);
            $this->log(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Backend scanner is read-only in TradingView webhook mode. Only Pine webhook can execute.',
                $this->runtimeContext($dynamic, [
                    'status' => 'BLOCKED',
                    'source' => (string) ($signal['source'] ?? 'backend_mirror'),
                    'requiredSource' => 'pine_webhook',
                ], $signal, [$reason])
            );
            return;
        }
        if ((float) $signal['confidence'] < (float) ($settings['minConfidence'] ?? 0.68)) {
            $this->recordMissedTradeAudit($config, $signal, $dynamic, ['confidence_below_threshold']);
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['confidence_below_threshold']);
            $this->log(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                'Confidence below dynamic threshold: needs ' . round((float) $settings['minConfidence'], 4),
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED'], $signal, ['confidence_below_threshold'])
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
            $this->recordMissedTradeAudit($config, $signal, $dynamic, ['weak_setup_hard_block']);
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['weak_setup_hard_block']);
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Weak 5m setup rejected before execution.',
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED'], $signal, ['weak_setup_hard_block']),
                45
            );
            return;
        }
        if ($strictEntryGuards && (float) ($analysis['trendStrength'] ?? 0) < 0.45) {
            $this->recordMissedTradeAudit($config, $signal, $dynamic, ['trend_strength_too_low']);
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['trend_strength_too_low']);
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Trend-strength gate blocked entry: runtime trend strength is below 45%.',
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED'], $signal, ['trend_strength_too_low']),
                45
            );
            return;
        }
        if (
            $strictEntryGuards
            && (int) ($analysis['totalTrades'] ?? 0) >= 6
            && (float) ($analysis['winRate'] ?? 100) < 35
        ) {
            $this->recordMissedTradeAudit($config, $signal, $dynamic, ['poor_regime_guard']);
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['poor_regime_guard']);
            $this->logThrottled(
                $config,
                'SIGNAL_SKIPPED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('Poor regime guard blocked entry: %.2f%% win rate across %d closed trades.', (float) ($analysis['winRate'] ?? 0), (int) ($analysis['totalTrades'] ?? 0)),
                $this->runtimeContext($dynamic, ['status' => 'SKIPPED'], $signal, ['poor_regime_guard']),
                60
            );
            return;
        }
        $rrTp = (float) ($settings['takeProfitPercent'] ?? 0.45);
        $rrRollback = (float) ($settings['rollbackLossPercent'] ?? 0.18);
        if (! $primePineExecution && $rrTp < $rrRollback * 1.8) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['risk_reward_guard']);
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('R:R gate: TP %.2f%% < rollback %.2f%% × 1.8 — insufficient reward for risk.', $rrTp, $rrRollback),
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['risk_reward_guard']),
                60
            );
            return;
        }
        if ($state->cooldown_until && $state->cooldown_until->isFuture()) {
            // Opposite-direction Pine webhook signals bypass the cooldown.
            // TradingView issuing a fresh signal in the opposite direction is
            // a legitimate trend reversal — making the operator wait 180s on
            // it would cost the move. Same-side re-entry still respects the
            // cooldown to prevent rapid stop-out churn.
            $isPineWebhook = (string) ($signal['source'] ?? '') === 'pine_webhook';
            $oppositeOfLastClose = $this->signalIsOppositeOfLastClose($config, (string) ($signal['signal'] ?? ''));
            if ($isPineWebhook && $oppositeOfLastClose) {
                $this->logThrottled(
                    $config,
                    'COOLDOWN_BYPASSED_OPPOSITE_SIGNAL',
                    $signal['symbol'],
                    $signal['signal'],
                    (float) ($signal['confidence'] ?? 0),
                    'Pine webhook opposite-direction signal bypassed cooldown.',
                    ['source' => 'pine_webhook', 'lastClosedSide' => $this->readLastClosedSide($config), 'cooldownUntil' => $state->cooldown_until?->toIso8601String()],
                    30
                );
            } else {
                $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['cooldown_active']);
                $this->logThrottled(
                    $config,
                    'TRADE_BLOCKED',
                    $signal['symbol'],
                    $signal['signal'],
                    (float) ($signal['confidence'] ?? 0),
                    sprintf('Cooldown active until %s.', $state->cooldown_until->timezone(config('app.timezone'))->format('H:i:s')),
                    $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['cooldown_active']),
                    30
                );
                return;
            }
        }
        if ((int) $state->trade_count_today >= (int) ($settings['maxTradesPerDay'] ?? 25)) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['max_trades_per_day']);
            $this->log(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                'Max trades per day reached.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['max_trades_per_day'])
            );
            return;
        }
        $dailyLossPct = (float) $state->daily_loss;
        $softWarnPct = (float) ($settings['softDailyLossWarnPercent'] ?? 0.3);
        $blockEntriesPct = (float) ($settings['maxDailyLossPercent'] ?? 0.5);
        $hardBlockPct = (float) ($settings['hardDailyLossBlockPercent'] ?? 0.8);

        // Tier 3 (hard cap): block new BUY/SELL entries. Risk-reducing
        // CLOSE/reduce-only exits are handled before this guard and stay allowed.
        if ($hardBlockPct > 0 && $dailyLossPct >= $hardBlockPct) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['daily_loss_hard_block']);
            $this->log(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                sprintf('Daily loss hard cap reached: %.2f%% >= %.2f%%. Manual review required.', $dailyLossPct, $hardBlockPct),
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED', 'dailyLossTier' => 'hard_block', 'dailyLossPercent' => $dailyLossPct], $signal, ['daily_loss_hard_block'])
            );
            return;
        }

        // Tier 2: block new entries. Exits / close webhooks still proceed
        // (close-side flow does not pass through this guard).
        if ($blockEntriesPct > 0 && $dailyLossPct >= $blockEntriesPct) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['daily_loss_guard']);
            $this->log(
                $config,
                'TRADE_BLOCKED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                sprintf('Daily loss guard active: %.2f%% >= %.2f%%. New entries blocked; exits remain allowed.', $dailyLossPct, $blockEntriesPct),
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED', 'dailyLossTier' => 'block_entries', 'dailyLossPercent' => $dailyLossPct], $signal, ['daily_loss_guard'])
            );
            return;
        }

        // Tier 1 (soft): log warning at first crossing then allow trade. The
        // warning surfaces in the WebhookCommandCenter so the operator sees it
        // before the harder thresholds kick in.
        if ($softWarnPct > 0 && $dailyLossPct >= $softWarnPct) {
            $this->logThrottled(
                $config,
                'DAILY_LOSS_WARN',
                $signal['symbol'],
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                sprintf('Daily loss %.2f%% crossed soft threshold %.2f%%. Trades still allowed; hard block at %.2f%%.', $dailyLossPct, $softWarnPct, $hardBlockPct),
                ['dailyLossTier' => 'warn', 'dailyLossPercent' => $dailyLossPct],
                300
            );
        }

        // === Hard freshness gates (feed + Binance user stream) ===
        // These block BEFORE we evaluate idempotency, so a stale stream cannot
        // ever produce a real exchange order even if everything else is OK.
        $freshness = $this->preExecutionFreshnessSnapshot($config, $signal);
        $freshnessBlockers = $freshness['blockers'];
        if ($freshnessBlockers !== []) {
            if (in_array('binance_state_stale_cannot_verify_balance_or_position', $freshnessBlockers, true)) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'BINANCE_STATE_STALE_BLOCKED',
                    (string) ($signal['symbol'] ?? null),
                    (string) ($signal['signal'] ?? null),
                    (float) ($signal['confidence'] ?? 0),
                    'binance_state_stale_cannot_verify_balance_or_position',
                    $this->freshnessContext($freshness),
                    30
                );
            }
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', $freshnessBlockers);
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                (string) ($signal['symbol'] ?? null),
                (string) ($signal['signal'] ?? null),
                (float) ($signal['confidence'] ?? 0),
                'Execution blocked by freshness gate: ' . implode(', ', $freshnessBlockers),
                $this->runtimeContext($dynamic, array_merge(['status' => 'BLOCKED'], $this->freshnessContext($freshness)), $signal, $freshnessBlockers),
                30
            );
            return;
        }
        $this->logFreshnessFallbackIfNeeded($config, $signal, $freshness);

        // === Hard duplicate-prevention gates (apply before LIVE or PAPER) ===
        $symbolUpper = strtoupper((string) ($signal['symbol'] ?? ''));
        $timeframe = strtolower((string) ($settings['timeframe'] ?? '5m'));
        $signalKey = $this->buildSignalKey($config, $signal, $settings);
        $stability = $this->stabilitySvc();
        $candleOpen = (string) ($signal['candle_open'] ?? $stability->previousClosedCandleOpenIso($timeframe));

        // Same-candle re-entry block (covers the ETHUSDT-SHORT-twice-in-2-seconds case).
        if ($stability->sameCandleExecuted((string) $config->id, $symbolUpper, (string) $signal['signal'], $candleOpen)) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['same_candle_reentry_blocked']);
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $symbolUpper,
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Same-candle re-entry blocked: this symbol/side already opened during this candle.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED', 'signalKey' => $signalKey, 'candleOpen' => $candleOpen], $signal, ['same_candle_reentry_blocked']),
                30
            );
            return;
        }

        // DB-level idempotency: signal_key must be unique per config.
        if ($this->lockSvc()->hasSignalKey((string) $config->id, $signalKey)) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'BLOCKED', ['duplicate_signal_key']);
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $symbolUpper,
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Duplicate signal_key blocked: same signal already produced a logical trade.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED', 'signalKey' => $signalKey], $signal, ['duplicate_signal_key']),
                30
            );
            return;
        }

        if ($this->isLiveMode($config)) {
            $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'OPEN', [], ['live_entry_allowed']);
            try {
                $this->openLivePosition($config, array_merge($signal, [
                    'signal_key' => $signalKey,
                    'candle_open' => $candleOpen,
                    'timeframe' => $timeframe,
                ]));
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
                    $this->runtimeContext($dynamic, ['status' => 'ERROR'], $signal),
                    30
                );
            }
            return;
        }

        // === Paper entry path (locked + DB-level idempotent) ===
        $lockResult = $this->lockSvc()->withEntryLock($config, $symbolUpper, function () use (
            $config, $signal, $signalKey, $candleOpen, $timeframe, $settings, $dynamic, $state, $stability, $symbolUpper
        ) {
            // Re-check inside the lock.
            if ($this->lockSvc()->hasActiveLogicalTrade((string) $config->id, $symbolUpper)) {
                $this->logThrottled(
                    $config,
                    'TRADE_BLOCKED',
                    $symbolUpper,
                    $signal['signal'],
                    (float) ($signal['confidence'] ?? 0),
                    'Open logical trade already exists for this symbol (locked re-check).',
                    $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['open_position_exists']),
                    30
                );
                return false;
            }
            if ($this->lockSvc()->hasSignalKey((string) $config->id, $signalKey)) {
                $this->logThrottled(
                    $config,
                    'TRADE_BLOCKED',
                    $symbolUpper,
                    $signal['signal'],
                    (float) ($signal['confidence'] ?? 0),
                    'Duplicate signal_key blocked (locked re-check).',
                    $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['duplicate_signal_key']),
                    30
                );
                return false;
            }

            $side = $signal['signal'] === 'BUY' ? 'LONG' : 'SHORT';
            $price = (float) $signal['price'];
            $tradeAmountUsdt = (float) ($settings['tradeAmountUsdt'] ?? 25);
            if (($settings['weakSetupAction'] ?? 'reduce_size') === 'reduce_size' && (bool) ($signal['fastTimeframeWeakSetup'] ?? false)) {
                $tradeAmountUsdt *= 0.5;
            }
            $qty = ($tradeAmountUsdt * (float) ($settings['leverage'] ?? 3)) / max($price, 0.00000001);
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

            $legacyTrade = $config->trades()->create([
                'symbol' => $signal['symbol'],
                'side' => $side,
                'entry_price' => $price,
                'quantity' => $qty,
                'leverage' => (float) ($settings['leverage'] ?? 3),
                'fees' => $price * $qty * 0.0004,
                'reason' => 'AUTO_' . $signal['signal'] . '_SIGNAL',
                'mode' => 'PAPER',
                'status' => 'OPEN',
                'meta' => [
                    'signal' => $signal,
                    'dynamic' => $dynamic['public'],
                    'algorithm_mode' => $dynamic['mode'],
                    'signal_profile' => $settings['signalProfile'] ?? null,
                    'signal_profile_name' => $settings['signalProfileName'] ?? null,
                    'market_profile' => $settings['marketProfile'] ?? null,
                    'timeframe' => $settings['timeframe'] ?? null,
                    'idempotency_key' => (string) Str::uuid(),
                    'signal_key' => $signalKey,
                ],
            ]);

            $logical = FutureTradeLogicalTrade::create([
                'future_trade_bot_config_id' => $config->id,
                'workspace_id' => (string) $config->workspace_id,
                'symbol' => $symbolUpper,
                'side' => $side,
                'status' => FutureTradeLogicalTrade::STATUS_OPEN,
                'mode' => 'PAPER',
                'signal_key' => $signalKey,
                'candle_time' => $candleOpen,
                'timeframe' => $timeframe,
                'entry_price' => $price,
                'quantity' => $qty,
                'leverage' => (float) ($settings['leverage'] ?? 3),
                'fees' => $price * $qty * 0.0004,
                'opened_at' => now(),
                'meta' => ['legacy_trade_id' => $legacyTrade->id],
            ]);

            FutureTradeLogicalTradeEvent::create([
                'future_trade_logical_trade_id' => $logical->id,
                'future_trade_bot_config_id' => $config->id,
                'symbol' => $symbolUpper,
                'event_type' => FutureTradeLogicalTradeEvent::EVENT_ENTRY_FILLED,
                'price' => $price,
                'quantity' => $qty,
                'payload' => ['signal' => $signal, 'mode' => 'PAPER'],
            ]);

            // Same-candle re-entry hard block — survives even after the lock is
            // released (the cached flag lasts longer than the candle).
            $stability->rememberCandleExecution((string) $config->id, $symbolUpper, $signal['signal'], $candleOpen);

            $this->log(
                $config->fresh('state'),
                $signal['signal'] . '_EXECUTED',
                $signal['symbol'],
                $signal['signal'],
                $signal['confidence'],
                'Paper futures position opened.',
                $this->runtimeContext($dynamic, [
                    'status' => 'OPEN',
                    'side' => $side,
                    'entryPrice' => $price,
                    'quantity' => $qty,
                    'mode' => 'PAPER',
                    'signalKey' => $signalKey,
                    'logicalTradeId' => $logical->id,
                ], $signal)
            );
            return true;
        });

        if ($lockResult === null) {
            $this->logThrottled(
                $config,
                'TRADE_BLOCKED',
                $symbolUpper,
                $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Entry lock not acquired — another worker is processing this symbol.',
                $this->runtimeContext($dynamic, ['status' => 'BLOCKED'], $signal, ['entry_lock_active']),
                30
            );
            return;
        }
        if ($lockResult === false) {
            return;
        }

        $allowedBecause = ['paper_entry_allowed'];
        if (($settings['weakSetupAction'] ?? 'reduce_size') === 'reduce_size' && (bool) ($signal['fastTimeframeWeakSetup'] ?? false)) {
            $allowedBecause[] = 'weak_setup_allowed_with_reduced_size';
        }
        $this->emitTradeDecisionDebug($config, $signal, $dynamic, 'OPEN', [], $allowedBecause);
    }

    private function manageOpenPosition(FutureTradeBotConfig $config, float $price): void
    {
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $lastSignals = (array) ($state->scanner_snapshot ?? []);
        $dynamic = $this->dynamicDecision($config, $state, $lastSignals, $lastSignals[0] ?? null, $settings);
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
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        if ($this->primeMirrorExecutionBlocked($config, $settings, $signal)) {
            $sourceMode = $this->normalizedSignalSource($settings);
            $reason = $sourceMode === 'webhook'
                ? 'backend_scanner_read_only_webhook_mode'
                : ($sourceMode === 'webhook_fallback' && ! $this->webhookFallbackEnabled($settings)
                    ? 'webhook_fallback_disabled_backend_scanner_read_only'
                    : ($this->isLiveMode($config)
                        ? 'prime_mirror_live_disabled_pending_tv_csv_validation'
                        : 'prime_mirror_execution_disabled_use_tradingview_webhook'));
            $this->logThrottled(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                (string) ($signal['symbol'] ?? null),
                (string) ($signal['signal'] ?? null),
                (float) ($signal['confidence'] ?? 0),
                'Prime backend mirror execution is disabled. Use TradingView Pine webhook source.',
                ['status' => 'BLOCKED', 'hardBlockers' => [$reason], 'source' => (string) ($signal['source'] ?? 'backend_mirror')],
                15
            );
            return;
        }

        // Defense-in-depth: re-run the freshness gate here too. Any future
        // caller that bypasses maybeEnterPaperPosition still hits this check
        // before a real order is placed.
        $freshness = $this->preExecutionFreshnessSnapshot($config, $signal);
        $freshnessBlockers = $freshness['blockers'];
        if ($freshnessBlockers !== []) {
            if (in_array('binance_state_stale_cannot_verify_balance_or_position', $freshnessBlockers, true)) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'BINANCE_STATE_STALE_BLOCKED',
                    (string) ($signal['symbol'] ?? null),
                    (string) ($signal['signal'] ?? null),
                    (float) ($signal['confidence'] ?? 0),
                    'binance_state_stale_cannot_verify_balance_or_position',
                    $this->freshnessContext($freshness),
                    30
                );
            }
            $this->logThrottled(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                (string) ($signal['symbol'] ?? null),
                (string) ($signal['signal'] ?? null),
                (float) ($signal['confidence'] ?? 0),
                'Live entry blocked: ' . implode(', ', $freshnessBlockers),
                array_merge(['status' => 'BLOCKED', 'hardBlockers' => $freshnessBlockers], $this->freshnessContext($freshness)),
                15
            );
            return;
        }
        $this->logFreshnessFallbackIfNeeded($config, $signal, $freshness);

        // Per-symbol entry lock with mandatory re-check inside the lock. This
        // closes the 1–2 second duplicate-entry window that previously allowed
        // two ETHUSDT SHORTs to open back-to-back.
        $symbolUpper = strtoupper((string) ($signal['symbol'] ?? ''));
        $signalKey = (string) ($signal['signal_key'] ?? '');
        $result = $this->lockSvc()->withEntryLock($config, $symbolUpper, function () use ($config, $signal, $symbolUpper, $signalKey) {
            if ($symbolUpper !== '' && $this->lockSvc()->hasActiveLogicalTrade((string) $config->id, $symbolUpper)) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'TRADE_BLOCKED',
                    $symbolUpper,
                    (string) ($signal['signal'] ?? null),
                    (float) ($signal['confidence'] ?? 0),
                    'Open logical trade already exists for this symbol (live re-check inside lock).',
                    ['status' => 'BLOCKED', 'hardBlockers' => ['open_position_exists']],
                    30
                );
                return false;
            }
            if ($signalKey !== '' && $this->lockSvc()->hasSignalKey((string) $config->id, $signalKey)) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'TRADE_BLOCKED',
                    $symbolUpper,
                    (string) ($signal['signal'] ?? null),
                    (float) ($signal['confidence'] ?? 0),
                    'Duplicate signal_key blocked (live re-check).',
                    ['status' => 'BLOCKED', 'hardBlockers' => ['duplicate_signal_key']],
                    30
                );
                return false;
            }
            $this->openLivePositionLocked($config, $signal);
            return true;
        });

        if ($result === null) {
            $this->logThrottled(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                $symbolUpper ?: null,
                (string) ($signal['signal'] ?? null),
                (float) ($signal['confidence'] ?? 0),
                'Duplicate live entry blocked because another live entry is already being processed.',
                ['status' => 'BLOCKED', 'hardBlockers' => ['duplicate_live_position']],
                15
            );
            return;
        }
    }

    private function openLivePositionLocked(FutureTradeBotConfig $config, array $signal): void
    {
        $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
        if (! $guard['allowed']) {
            $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', (string) $signal['symbol'], (string) $signal['signal'], (float) $signal['confidence'], $guard['reason']);
            return;
        }

            $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $state = $this->state($config);
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $settings = $dynamic['settings'];
        $client = $this->liveClient($settings);
        $symbol = strtoupper((string) $signal['symbol']);
        $side = $signal['signal'] === 'BUY' ? 'BUY' : 'SELL';
        $positionSide = $signal['signal'] === 'BUY' ? 'LONG' : 'SHORT';
        $price = (float) $signal['price'];
        $leverage = min((int) ($settings['leverage'] ?? 3), (int) ($settings['maxLeverage'] ?? 5));
        $freshState = $this->state($config->fresh('state'));
        if ((float) ($freshState->quantity ?? 0) > 0 || in_array((string) ($freshState->state ?? ''), ['IN_POSITION', 'LIVE_IN_POSITION'], true)) {
            $this->logThrottled(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                $symbol,
                (string) $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Duplicate live position blocked: local bot state already has an open position.',
                $this->runtimeContext($dynamic, [
                    'status' => 'BLOCKED',
                    'state' => $freshState->state,
                    'quantity' => (float) ($freshState->quantity ?? 0),
                ], $signal, ['duplicate_live_position', 'open_position_guard']),
                15
            );

            return;
        }

        $exchangeBefore = $this->exchangePositionSnapshot($client, $symbol);
        if ((float) ($exchangeBefore['quantity'] ?? 0) > 0) {
            $this->logThrottled(
                $config->fresh('state'),
                'TRADE_BLOCKED',
                $symbol,
                (string) $signal['signal'],
                (float) ($signal['confidence'] ?? 0),
                'Duplicate live position blocked: Binance already reports an open futures position.',
                $this->runtimeContext($dynamic, [
                    'status' => 'BLOCKED',
                    'exchangeQuantity' => (float) ($exchangeBefore['quantity'] ?? 0),
                    'exchangeSide' => $exchangeBefore['position_side'] ?? null,
                ], $signal, ['duplicate_live_position', 'binance_position_confirmation']),
                15
            );

            return;
        }

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
                ], $signal, ['insufficient_wallet_balance'])
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
                ], $signal, ['rounded_quantity_zero'])
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
                ], $signal, ['min_notional_invalid'])
            );

            return;
        }

        $client->setLeverage($symbol, $leverage);
        $client->setMarginType($symbol, (string) ($settings['marginMode'] ?? 'isolated'));

        // Deterministic clientOrderId — if a retry fires on the same signal_key
        // Binance will reject the duplicate instead of opening a second order.
        $signalKey = (string) ($signal['signal_key'] ?? '');
        $clientOrderId = $signalKey !== ''
            ? $this->stabilitySvc()->deterministicClientOrderId($signalKey, 'entry')
            : substr('ft-entry-' . Str::uuid(), 0, 36);
        $response = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $qty,
            'newClientOrderId' => $clientOrderId,
            'positionSide' => 'BOTH',
        ]);
        $exchange = $this->exchangePositionSnapshot($client, $symbol);
        $confirmedOpen = (float) ($exchange['quantity'] ?? 0) > 0
            && (($exchange['position_side'] ?? null) === $positionSide);
        $entry = (float) ($exchange['entry_price'] ?? 0)
            ?: (float) ($response['avgPrice'] ?? 0)
            ?: $price;

        $state = $this->state($config);
        if ($confirmedOpen) {
            $this->persistState($state, [
                'state' => 'LIVE_IN_POSITION',
                'current_symbol' => $symbol,
                'position_side' => $positionSide,
                'entry_price' => $entry,
                'quantity' => (float) ($exchange['quantity'] ?? $qty),
                'peak_price' => $entry,
                'bottom_price' => $entry,
                'current_signal' => $signal['signal'] . '_LIVE_EXECUTED',
                'trade_count_today' => (int) $state->trade_count_today + 1,
                'status' => 'Running',
                'connection_status' => 'Connected',
            ]);
        } else {
            $this->persistState($state, [
                'state' => 'SCANNING',
                'current_symbol' => $symbol,
                'current_signal' => $signal['signal'] . '_LIVE_SUBMITTED',
                'status' => 'Running',
                'connection_status' => 'Pending exchange fill',
            ]);
        }

        $modeLabel = ($settings['forceTestnet'] ?? true) ? 'LIVE_TESTNET' : 'LIVE';
        $legacyTrade = $config->trades()->create([
            'symbol' => $symbol,
            'side' => $positionSide,
            'entry_price' => $entry,
            'quantity' => (float) $qty,
            'leverage' => $leverage,
            'fees' => $entry * (float) $qty * 0.0004,
            'reason' => 'LIVE_' . $signal['signal'] . '_SIGNAL',
            'mode' => $modeLabel,
            'status' => $confirmedOpen ? 'OPEN' : 'SUBMITTED',
            'meta' => [
                'entry_order' => $response,
                'exchange_snapshot' => $exchange,
                'exit_management' => 'BOT_MANAGED_MARKET_CLOSE',
                'signal' => $signal,
                'dynamic' => $dynamic['public'],
                'algorithm_mode' => $dynamic['mode'],
                'signal_profile' => $settings['signalProfile'] ?? null,
                'signal_profile_name' => $settings['signalProfileName'] ?? null,
                'market_profile' => $settings['marketProfile'] ?? null,
                'timeframe' => $settings['timeframe'] ?? null,
                'effective_trade_amount_usdt' => $requiredTradeAmountUsdt,
                'signal_key' => $signalKey,
                'client_order_id' => $clientOrderId,
            ],
        ]);

        // Logical trade — source of truth for realized PnL. Persisted even if
        // the exchange hasn't confirmed yet, so the signal_key uniqueness lock
        // engages immediately.
        $logical = FutureTradeLogicalTrade::create([
            'future_trade_bot_config_id' => $config->id,
            'workspace_id' => (string) $config->workspace_id,
            'symbol' => $symbol,
            'side' => $positionSide,
            'status' => $confirmedOpen ? FutureTradeLogicalTrade::STATUS_OPEN : FutureTradeLogicalTrade::STATUS_OPEN,
            'mode' => $modeLabel,
            'signal_key' => $signalKey ?: null,
            'candle_time' => (string) ($signal['candle_open'] ?? null),
            'timeframe' => strtolower((string) ($signal['timeframe'] ?? ($settings['timeframe'] ?? '5m'))),
            'entry_client_order_id' => $clientOrderId,
            'entry_exchange_order_id' => (string) ($response['orderId'] ?? ''),
            'entry_price' => $entry,
            'quantity' => (float) $qty,
            'leverage' => $leverage,
            'fees' => $entry * (float) $qty * 0.0004,
            'opened_at' => now(),
            'meta' => [
                'legacy_trade_id' => $legacyTrade->id,
                'entry_response' => $response,
                'exchange_snapshot_initial' => $exchange,
            ],
        ]);
        FutureTradeLogicalTradeEvent::create([
            'future_trade_logical_trade_id' => $logical->id,
            'future_trade_bot_config_id' => $config->id,
            'symbol' => $symbol,
            'event_type' => $confirmedOpen
                ? FutureTradeLogicalTradeEvent::EVENT_ENTRY_FILLED
                : FutureTradeLogicalTradeEvent::EVENT_ENTRY_SUBMITTED,
            'client_order_id' => $clientOrderId,
            'exchange_order_id' => (string) ($response['orderId'] ?? ''),
            'price' => $entry,
            'quantity' => (float) $qty,
            'payload' => ['entry_response' => $response, 'exchange_snapshot' => $exchange],
        ]);

        // Same-candle re-entry hard block.
        $candleOpenForLock = (string) ($signal['candle_open'] ?? '');
        if ($candleOpenForLock !== '') {
            $this->stabilitySvc()->rememberCandleExecution(
                (string) $config->id,
                $symbol,
                (string) $signal['signal'],
                $candleOpenForLock
            );
        }
        $this->log(
            $config->fresh('state'),
            $confirmedOpen ? 'LIVE_' . $signal['signal'] . '_EXECUTED' : 'LIVE_ENTRY_SUBMITTED',
            $symbol,
            $signal['signal'],
            (float) $signal['confidence'],
            $confirmedOpen
                ? 'Live futures entry confirmed from Binance position snapshot. TP/SL/trailing are managed by the bot loop.'
                : 'Live futures entry submitted, but Binance has not yet confirmed an open position snapshot.',
            $this->runtimeContext($dynamic, ['status' => $confirmedOpen ? 'OPEN' : 'SUBMITTED', 'side' => $positionSide, 'entryPrice' => $entry, 'quantity' => (float) ($exchange['quantity'] ?? $qty), 'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE'], $signal)
        );
    }

    private function closeLivePosition(FutureTradeBotConfig $config, float $price, string $reason): void
    {
        $state = $this->state($config);
        if ((float) $state->quantity <= 0 || ! $state->current_symbol || ! $state->position_side) {
            return;
        }

        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
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

        // Look up the active logical trade for this symbol/side so we can mint
        // a deterministic close clientOrderId — preventing a retried close
        // request from creating a second close order on Binance.
        $logicalForClose = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('symbol', $symbol)
            ->where('side', (string) $state->position_side)
            ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
            ->orderByDesc('opened_at')
            ->first();
        $closeKey = $logicalForClose?->signal_key ?: ('close-' . $symbol . '-' . substr((string) $config->id, 0, 8) . '-' . (string) now()->timestamp);
        $closeClientOrderId = $this->stabilitySvc()->deterministicClientOrderId($closeKey, 'exit');

        try {
            $response = $client->placeOrder([
                'symbol' => $symbol,
                'side' => $side,
                'type' => 'MARKET',
                'quantity' => $qty,
                'reduceOnly' => 'true',
                'newClientOrderId' => $closeClientOrderId,
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

        $postCloseExchange = $this->exchangePositionSnapshot($client, $symbol);
        $positionClosed = (float) ($postCloseExchange['quantity'] ?? 0) <= 0;

        $direction = $state->position_side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $gross = ($price - $entry) * (float) $state->quantity * $direction;
        $fees = ($entry + $price) * (float) $state->quantity * 0.0004;
        $net = $gross - $fees;
        $moveStats = $this->positionMoveStats($state);
        $sourceSignal = (array) data_get($state->scanner_snapshot, '0', []);

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
            'status' => $positionClosed ? 'FILLED' : 'SUBMITTED',
            'meta' => [
                'close_order' => $response,
                'exchange_snapshot' => $postCloseExchange,
                'dynamic' => $dynamic['public'],
                'algorithm_mode' => $dynamic['mode'],
                'signal_profile' => $settings['signalProfile'] ?? null,
                'signal_profile_name' => $settings['signalProfileName'] ?? null,
                'market_profile' => $settings['marketProfile'] ?? null,
                'timeframe' => $settings['timeframe'] ?? null,
                'move_stats' => $moveStats,
                'source_signal' => $sourceSignal,
                'webhook_timing' => data_get($sourceSignal, 'debug.webhookTiming'),
            ],
        ]);

        // Record an exit event on the logical trade (whether or not it
        // confirmed) so reconciliation/audit always has a paper trail.
        if ($logicalForClose) {
            $logicalUpdate = [
                'exit_client_order_id' => $closeClientOrderId,
                'exit_exchange_order_id' => (string) ($response['orderId'] ?? $logicalForClose->exit_exchange_order_id ?? ''),
                'status' => $positionClosed ? FutureTradeLogicalTrade::STATUS_CLOSED : FutureTradeLogicalTrade::STATUS_CLOSING,
            ];

            if ($positionClosed) {
                $logicalUpdate['exit_price'] = $price;
                $logicalUpdate['gross_pnl'] = $gross;
                $logicalUpdate['net_pnl'] = $net;
                $logicalUpdate['closed_at'] = now();
            }

            $logicalForClose->update($logicalUpdate);
            FutureTradeLogicalTradeEvent::create([
                'future_trade_logical_trade_id' => $logicalForClose->id,
                'future_trade_bot_config_id' => $config->id,
                'symbol' => $symbol,
                'event_type' => $positionClosed
                    ? FutureTradeLogicalTradeEvent::EVENT_EXIT_FILLED
                    : FutureTradeLogicalTradeEvent::EVENT_EXIT_SUBMITTED,
                'client_order_id' => $closeClientOrderId,
                'exchange_order_id' => (string) ($response['orderId'] ?? ''),
                'price' => $price,
                'quantity' => (float) $state->quantity,
                'payload' => ['close_order' => $response, 'exchange_snapshot' => $postCloseExchange, 'reason' => $reason],
            ]);
        }

        if ($positionClosed) {
            $closedSideBeforeWipe = (string) $state->position_side;
            $cooldownSeconds = (int) ($settings['cooldownSeconds'] ?? 10);
            $this->markOpenEntryTradesClosed($config, $symbol, $closedSideBeforeWipe, $price, $net);
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
                'cooldown_until' => now()->addSeconds($cooldownSeconds),
                'current_signal' => $reason,
                'status' => 'Running',
                'connection_status' => 'Connected',
            ]);
            // Cache the side that was just closed so an opposite-direction
            // Pine webhook can bypass the cooldown gate.
            $this->recordLastClosedSide($config, $closedSideBeforeWipe, $cooldownSeconds);
        } else {
            $this->persistState($state, [
                'state' => 'LIVE_IN_POSITION',
                'status' => 'Running',
                'current_signal' => 'LIVE_CLOSE_SUBMITTED',
                'connection_status' => 'Waiting for Binance close confirmation',
            ]);
        }
        $this->log(
            $config->fresh('state'),
            $positionClosed ? 'LIVE_' . $reason : 'LIVE_CLOSE_SUBMITTED',
            $state->current_symbol,
            'CLOSE',
            1,
            $positionClosed
                ? 'Live futures close confirmed from Binance position snapshot.'
                : 'Live futures reduce-only close submitted and is waiting for Binance confirmation.',
            $this->runtimeContext($dynamic, ['status' => $positionClosed ? $reason : 'SUBMITTED', 'side' => $state->position_side, 'entryPrice' => $entry, 'exitPrice' => $price, 'netPnl' => $net, 'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE'])
        );
        if ($positionClosed) {
            $freshConfig = $config->fresh('state');
            $this->applyDailyLossLock($freshConfig, $this->state($freshConfig));
        }
    }

    private function closePaperPosition(FutureTradeBotConfig $config, float $price, string $reason): void
    {
        $state = $this->state($config);
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $dynamic = $this->dynamicDecision($config, $state, (array) ($state->scanner_snapshot ?? []), null, $settings);
        $side = (string) $state->position_side;
        $direction = $side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $qty = (float) $state->quantity;
        $gross = ($price - $entry) * $qty * $direction;
        $fees = ($entry + $price) * $qty * 0.0004;
        $net = $gross - $fees;
        $moveStats = $this->positionMoveStats($state);
        $sourceSignal = (array) data_get($state->scanner_snapshot, '0', []);

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
            'meta' => [
                'dynamic' => $dynamic['public'],
                'algorithm_mode' => $dynamic['mode'],
                'signal_profile' => $settings['signalProfile'] ?? null,
                'signal_profile_name' => $settings['signalProfileName'] ?? null,
                'market_profile' => $settings['marketProfile'] ?? null,
                'timeframe' => $settings['timeframe'] ?? null,
                'move_stats' => $moveStats,
                'source_signal' => $sourceSignal,
                'webhook_timing' => data_get($sourceSignal, 'debug.webhookTiming'),
            ],
	        ]);

        $this->markOpenEntryTradesClosed($config, (string) $state->current_symbol, $side, $price, $net);

        $cooldownSeconds = (int) ($dynamic['settings']['cooldownSeconds'] ?? $settings['cooldownSeconds'] ?? 10);
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
            'cooldown_until' => now()->addSeconds($cooldownSeconds),
            'current_signal' => $reason,
            'status' => 'Running',
            'connection_status' => 'Connected',
        ]);
        $this->recordLastClosedSide($config, $side, $cooldownSeconds);
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

    /**
     * Flip historical OPEN entry rows to CLOSED. We do NOT copy net_pnl onto
     * entry rows anymore — that caused the realized-PnL double-count visible on
     * the dashboard, because the close row also persisted the same net_pnl.
     *
     * The realized PnL source of truth lives on the matching CLOSE row (legacy)
     * or on the linked logical_trade (new path). This method only records the
     * exit_price snapshot for human inspection of the entry row.
     */
    private function markOpenEntryTradesClosed(FutureTradeBotConfig $config, string $symbol, string $side, float $exitPrice, float $netPnl): void
    {
        $config->trades()
            ->where('symbol', strtoupper($symbol))
            ->whereIn('status', ['OPEN', 'SUBMITTED'])
            ->where('side', 'not like', '%CLOSE%')
            ->update([
                'exit_price' => $exitPrice,
                // Intentionally do NOT copy net_pnl here — the close row owns
                // the realized PnL; the entry row stays neutral so dashboard
                // SUM(net_pnl) does not double-count.
                'status' => 'CLOSED',
                'updated_at' => now(),
            ]);

        // Mirror the close into the linked logical_trade (the new source of
        // truth). If no logical_trade was ever created for this position
        // (legacy install), this is a no-op.
        FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('symbol', strtoupper($symbol))
            ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
            ->orderBy('created_at')
            ->get()
            ->each(function (FutureTradeLogicalTrade $trade) use ($exitPrice, $netPnl) {
                $entry = (float) $trade->entry_price;
                $qty = (float) $trade->quantity;
                $direction = $trade->side === 'LONG' ? 1 : -1;
                $gross = ($exitPrice - $entry) * $qty * $direction;
                $fees = (float) ($trade->fees ?? 0);
                // Trust whatever net_pnl the caller computed (it included fees),
                // but recompute gross from the recorded entry to keep numbers
                // internally consistent.
                $trade->update([
                    'exit_price' => $exitPrice,
                    'gross_pnl' => $gross,
                    'net_pnl' => $netPnl,
                    'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
                    'closed_at' => now(),
                ]);
                FutureTradeLogicalTradeEvent::create([
                    'future_trade_logical_trade_id' => $trade->id,
                    'future_trade_bot_config_id' => $trade->future_trade_bot_config_id,
                    'symbol' => $trade->symbol,
                    'event_type' => FutureTradeLogicalTradeEvent::EVENT_EXIT_FILLED,
                    'price' => $exitPrice,
                    'quantity' => $qty,
                    'payload' => ['net_pnl' => $netPnl, 'gross_pnl' => $gross],
                ]);
            });
    }

    private function scoreTicker(array $ticker, array $settings): array
    {
        $settings = $this->normalizeFutureTradeSettings($settings);
        if ($this->isPrimeCryptoProfile($settings)) {
            return $this->scoreTickerPrimeCrypto($ticker, $settings);
        }

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
        $classicSimpleMode = (bool) ($settings['classicSimpleMode'] ?? false);

        if ($classicSimpleMode) {
            return $this->scoreTickerClassic($ticker, $settings, [
                'price' => $price,
                'change' => $change,
                'quoteVolume' => $quoteVolume,
                'high' => $high,
                'low' => $low,
                'openPrice' => $openPrice,
                'rangePct' => $rangePct,
                'intradayChangePct' => $intradayChangePct,
                'rangePosition' => $rangePosition,
                'sequence' => $sequence,
            ]);
        }

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
        $symbolPerfAdjustCap = max(0.0, (float) ($settings['symbolPerfAdjustCap'] ?? 0.04));
        $symbolPerfAdjust = $this->clamp((float) ($ticker['symbolPerfAdjust'] ?? 0.0), -$symbolPerfAdjustCap, $symbolPerfAdjustCap);
        $sequenceLastCandleTime = $sequence['lastCandleTime'] ?? null;
        $htfLastCandleTime = $htf['lastCandleTime_1h'] ?? null;

        $effectiveVwap = $sessionVwap > 0 ? $sessionVwap : $vwap;
        $vwapBullish = $effectiveVwap > 0 && $price > $effectiveVwap;
        $adxReady = $adx14 >= ($isFastTimeframe ? 17.0 : 16.0);
        $choppy = ($settings['avoidChoppyMarket'] ?? true)
            && ($sequenceVolatility > 0.32 || $adx14 < 15.0 || (abs($sequenceNetChange) < 0.08 && $sequenceConsistency < 0.55));
        $strongDirectionalSequence = $sequenceConsistency >= ($isFastTimeframe ? 0.54 : 0.52)
            && abs($sequenceNetChange) >= ($isFastTimeframe ? 0.10 : 0.08)
            && $adx14 >= ($isFastTimeframe ? 16.0 : 15.5)
            && $volumeOk;

        $buyReasons = [];
        $sellReasons = [];
        $avoidReasons = [];

        $buyChecks = [
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

        $buyCoreChecks = [
            'vwap' => $buyChecks['vwap'],
            'ema' => $buyChecks['ema'],
            'adx' => $buyChecks['adx'],
            'macd' => $buyChecks['macd'],
        ];
        $sellCoreChecks = [
            'vwap' => $sellChecks['vwap'],
            'ema' => $sellChecks['ema'],
            'adx' => $sellChecks['adx'],
            'macd' => $sellChecks['macd'],
        ];
        $buyCoreScore = count(array_filter($buyCoreChecks));
        $sellCoreScore = count(array_filter($sellCoreChecks));
        $corePassRule = $this->resolveCorePassRule($settings['corePassRule'] ?? 3);
        $buyScore = ($buyCoreScore * 2) + count(array_filter($buyChecks));
        $sellScore = ($sellCoreScore * 2) + count(array_filter($sellChecks));
        $dominantSignal = $buyCoreScore > $sellCoreScore
            ? 'BUY'
            : ($sellCoreScore > $buyCoreScore
                ? 'SELL'
                : ($buyScore > $sellScore ? 'BUY' : ($sellScore > $buyScore ? 'SELL' : 'WAIT')));
        $dominantScore = max($buyCoreScore, $sellCoreScore);
        $voteGap = abs($buyCoreScore - $sellCoreScore);
        $conflicted = $buyCoreScore >= 3 && $sellCoreScore >= 3;
        $coreBuyAligned = $buyCoreScore >= $corePassRule;
        $coreSellAligned = $sellCoreScore >= $corePassRule;
        $configuredDirectionalScore = (int) ($settings['directionalMinScore5m'] ?? 5);
        $configuredVoteGap = (int) ($settings['directionalMinVoteGap5m'] ?? 1);
        $minimumDirectionalScore = $strictEntryGuards
            ? 4
            : ($isFastTimeframe ? max(3, min(4, $configuredDirectionalScore)) : 3);
        $minimumVoteGap = $strictEntryGuards
            ? 2
            : ($isFastTimeframe ? max(1, $configuredVoteGap) : 1);
        if (! $strictEntryGuards) {
            if (! $structureContextAvailable) {
                $minimumDirectionalScore -= 0;
            }
            if (! $higherTimeframeContextAvailable) {
                $minimumDirectionalScore -= 0;
            }
            $minimumDirectionalScore = max(3, $minimumDirectionalScore);
        }

        $signal = 'WAIT';
        if (
            $dominantSignal !== 'WAIT'
            && $dominantScore >= $minimumDirectionalScore
            && $dominantScore >= $corePassRule
            && $voteGap >= $minimumVoteGap
        ) {
            $signal = $dominantSignal;
        } elseif (
            ! $strictEntryGuards
            && $dominantSignal !== 'WAIT'
            && $voteGap >= 1
            && $dominantScore >= $corePassRule
            && (
                ($dominantSignal === 'BUY' && $coreBuyAligned)
                || ($dominantSignal === 'SELL' && $coreSellAligned)
            )
        ) {
            $signal = $dominantSignal;
        }

        $fiveMinuteWeakSetup = $isFastTimeframe && (
            (! $strongDirectionalSequence && $sequenceConsistency < 0.50)
            || $conflicted
            || (($settings['avoidChoppyMarket'] ?? true) && $choppy && ! $volumeOk)
            || ($dominantScore < 3)
            || ($voteGap < 1)
            || (($extendedFromEma || $extendedFromVwap) && ! $volumeOk)
        );

        if ($strictEntryGuards && ($fiveMinuteWeakSetup || (($settings['avoidChoppyMarket'] ?? true) && $choppy))) {
            $signal = 'WAIT';
        }

        if ($strictEntryGuards && $signal !== 'WAIT') {
            $directionChecks = $signal === 'SELL' ? $sellChecks : $buyChecks;
            $breakoutAligned = (bool) ($directionChecks['msb'] ?? false);

            if (! $breakoutAligned) {
                $signal = 'WAIT';
            }
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
            if (! $bullishMsb && ! $bearishMsb) {
                $avoidReasons[] = '15m market structure breakout not confirmed';
            }
            if (! $bullishObValid && ! $bearishObValid) {
                $avoidReasons[] = 'Order block confirmation missing; breakout quality softened';
            }
            if ($conflicted) {
                $avoidReasons[] = sprintf('Direction conflict between ticker and %s candle sequence', $tf);
            }
            if (! $strongDirectionalSequence) {
                $avoidReasons[] = sprintf('%s sequence momentum is weak; entry quality softened', $tf);
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
                $avoidReasons[] = '1h trend context is mixed; confidence softened';
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
                'coreBuyAligned' => $coreBuyAligned,
                'coreSellAligned' => $coreSellAligned,
                'corePassRule' => $corePassRule,
                'buyCoreChecks' => $buyCoreChecks,
                'sellCoreChecks' => $sellCoreChecks,
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
                'influenceWeights' => (array) ($settings['indicatorInfluence'] ?? []),
            ]
        );
        $componentDebug['componentBreakdown'] = $this->indicatorIntelligence->annotateBreakdown(
            IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
            (array) ($componentDebug['componentBreakdown'] ?? []),
            $settings
        );
        $componentDebug['indicatorControls'] = $this->indicatorIntelligence->controls(
            IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
            $settings
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

    private function scoreTickerPrimeCrypto(array $ticker, array $settings): array
    {
        $price = (float) ($ticker['lastPrice'] ?? $ticker['price'] ?? 0);
        $change = (float) ($ticker['priceChangePercent'] ?? 0);
        $quoteVolume = (float) ($ticker['quoteVolume'] ?? 0);
        $high = (float) ($ticker['highPrice'] ?? $price);
        $low = (float) ($ticker['lowPrice'] ?? $price);
        $openPrice = (float) ($ticker['openPrice'] ?? $price);
        $sequence = (array) ($ticker['sequence'] ?? []);
        $htf = (array) ($ticker['htf'] ?? []);
        $tf = strtolower((string) ($settings['timeframe'] ?? '5m'));

        $tradeSide = (string) ($settings['tradeDirection'] ?? 'Both');
        $allowLong = in_array($tradeSide, ['Both', 'Long Only'], true);
        $allowShort = in_array($tradeSide, ['Both', 'Short Only'], true);
        $threshold = (float) ($settings['tradeScoreThreshold'] ?? 72);
        $watchThreshold = (float) ($settings['watchScoreThreshold'] ?? 58);
        $useVwap = (bool) ($settings['useVWAP'] ?? true);
        $useDonchianScore = (bool) ($settings['useDonchianScore'] ?? true);
        $useVolume = (bool) ($settings['useVolume'] ?? true);
        $useVolatility = (bool) ($settings['useVolatilityFilter'] ?? true);
        $useChop = (bool) ($settings['useChopFilter'] ?? true);
        $useMomentumBreakout = (bool) ($settings['useMomentumBreakoutFilter'] ?? false);

        $ema9 = (float) ($sequence['ema9'] ?? 0.0);
        $ema21 = (float) ($sequence['ema21'] ?? 0.0);
        $ema50 = (float) ($sequence['ema50'] ?? 0.0);
        $ema200 = (float) ($sequence['ema200'] ?? 0.0);
        $sessionVwap = (float) ($sequence['sessionVwap'] ?? ($ticker['weightedAvgPrice'] ?? 0));
        $rsi14 = (float) ($sequence['rsi14'] ?? 50.0);
        $adx14 = (float) ($sequence['adx14'] ?? 0.0);
        $diPlus = (float) ($sequence['diPlus14'] ?? 0.0);
        $diMinus = (float) ($sequence['diMinus14'] ?? 0.0);
        $atr14 = (float) ($sequence['atr14'] ?? 0.0);
        $atrPct = (float) ($sequence['atrPercent'] ?? 0.0);
        $relVol = (float) ($sequence['relativeVolume'] ?? 1.0);
        $chop = (float) ($sequence['choppiness14'] ?? 100.0);
        $donBullCount = (int) ($sequence['donchianBullCount'] ?? 0);
        $donBearCount = (int) ($sequence['donchianBearCount'] ?? 0);

        $htfTrend1 = array_key_exists('trend1h', $htf)
            ? (int) $htf['trend1h']
            : (((bool) ($htf['emaBullish'] ?? false) && (bool) ($htf['ema200Bullish'] ?? false)) ? 1 : (((isset($htf['emaBullish'], $htf['ema200Bullish']) && ! $htf['emaBullish'] && ! $htf['ema200Bullish']) ? -1 : 0)));
        $htfTrend2 = array_key_exists('trend4h', $htf)
            ? (int) $htf['trend4h']
            : (((bool) ($htf['h4EmaBullish'] ?? false)) ? 1 : ((isset($htf['h4EmaBullish']) && ! $htf['h4EmaBullish']) ? -1 : 0));
        $htfBull = $htfTrend1 === 1 && $htfTrend2 === 1;
        $htfBear = $htfTrend1 === -1 && $htfTrend2 === -1;
        $htfMixed = ! $htfBull && ! $htfBear;

        $emaBull = $ema9 > 0 && $ema21 > 0 && $ema50 > 0 && $ema9 > $ema21 && $price > $ema50;
        $emaBear = $ema9 > 0 && $ema21 > 0 && $ema50 > 0 && $ema9 < $ema21 && $price < $ema50;
        $majorBull = $ema200 > 0 && $price > $ema200;
        $majorBear = $ema200 > 0 && $price < $ema200;
        $vwapBull = ! $useVwap || $sessionVwap <= 0 || $price > $sessionVwap;
        $vwapBear = ! $useVwap || $sessionVwap <= 0 || $price < $sessionVwap;
        $rsiBull = $rsi14 >= (float) ($settings['longRsiMin'] ?? 48) && $rsi14 <= (float) ($settings['longRsiMax'] ?? 72);
        $rsiBear = $rsi14 >= (float) ($settings['shortRsiMin'] ?? 28) && $rsi14 <= (float) ($settings['shortRsiMax'] ?? 52);
        $adxBull = $adx14 >= (float) ($settings['adxMin'] ?? 20) && $diPlus > $diMinus;
        $adxBear = $adx14 >= (float) ($settings['adxMin'] ?? 20) && $diMinus > $diPlus;
        $volumeOk = ! $useVolume || $relVol >= (float) ($settings['cryptoRelVolMin'] ?? 1.0);
        $volatilityOk = ! $useVolatility || ($atrPct >= (float) ($settings['cryptoMinAtrPct'] ?? 0.06) && $atrPct <= (float) ($settings['cryptoMaxAtrPct'] ?? 3.0));
        $chopOk = ! $useChop || $chop <= (float) ($settings['cryptoChopMax'] ?? 61.8);

        $donBullStrong = $donBullCount >= 8;
        $donBearStrong = $donBearCount >= 8;
        $donMixed = ! $donBullStrong && ! $donBearStrong;
        $donLongScore = $useDonchianScore ? ($donBullStrong ? 10 : ($donMixed ? 3 : 0)) : 0;
        $donShortScore = $useDonchianScore ? ($donBearStrong ? 10 : ($donMixed ? 3 : 0)) : 0;

        $momentumLongOk = ! $useMomentumBreakout || (bool) ($sequence['momentumLongOk'] ?? false);
        $momentumShortOk = ! $useMomentumBreakout || (bool) ($sequence['momentumShortOk'] ?? false);

        $longScore = 0.0;
        $longScore += $htfBull ? 20 : ($htfMixed ? 5 : 0);
        $longScore += $emaBull ? 15 : 0;
        $longScore += $majorBull ? 10 : 0;
        $longScore += $donLongScore;
        $longScore += $vwapBull ? 10 : 0;
        $longScore += $rsiBull ? 5 : 0;
        $longScore += $adxBull ? 12 : 0;
        $longScore += $volumeOk ? 5 : 0;
        $longScore += $volatilityOk ? 3 : 0;
        $longScore += $momentumLongOk ? 10 : 0;

        $shortScore = 0.0;
        $shortScore += $htfBear ? 20 : ($htfMixed ? 5 : 0);
        $shortScore += $emaBear ? 15 : 0;
        $shortScore += $majorBear ? 10 : 0;
        $shortScore += $donShortScore;
        $shortScore += $vwapBear ? 10 : 0;
        $shortScore += $rsiBear ? 5 : 0;
        $shortScore += $adxBear ? 12 : 0;
        $shortScore += $volumeOk ? 5 : 0;
        $shortScore += $volatilityOk ? 3 : 0;
        $shortScore += $momentumShortOk ? 10 : 0;

        $hardLong = $allowLong && $htfBull && $emaBull && $vwapBull && $adxBull && $volumeOk && $volatilityOk && $chopOk && $momentumLongOk;
        $hardShort = $allowShort && $htfBear && $emaBear && $vwapBear && $adxBear && $volumeOk && $volatilityOk && $chopOk && $momentumShortOk;

        $signal = 'WAIT';
        if ($hardLong && $longScore >= $threshold && $longScore > $shortScore) {
            $signal = 'BUY';
        } elseif ($hardShort && $shortScore >= $threshold && $shortScore > $longScore) {
            $signal = 'SELL';
        }

        $activeScore = $signal === 'SELL' ? $shortScore : ($signal === 'BUY' ? $longScore : max($longScore, $shortScore));
        $confidence = $this->clamp($activeScore / 100, 0.01, 0.99);
        $watchSignal = $signal === 'WAIT' && max($longScore, $shortScore) >= $watchThreshold
            ? ($longScore > $shortScore ? 'WATCH_BUY' : ($shortScore > $longScore ? 'WATCH_SELL' : 'WATCH'))
            : null;

        $dynamicSlMult = (float) ($settings['slAtrMultiplier'] ?? 1.4);
        $dynamicSlMult += $adx14 < (float) ($settings['adxMin'] ?? 20) ? 0.20 : 0.0;
        $dynamicSlMult += $atrPct > 1.20 ? 0.30 : 0.0;
        $riskDistance = $atr14 * $dynamicSlMult;
        $riskPercent = $price > 0 ? ($riskDistance / $price) * 100 : 0.0;

        $checks = [
            'htfBull' => $htfBull,
            'htfBear' => $htfBear,
            'emaBull' => $emaBull,
            'emaBear' => $emaBear,
            'vwapBull' => $vwapBull,
            'vwapBear' => $vwapBear,
            'adxBull' => $adxBull,
            'adxBear' => $adxBear,
            'volumeOk' => $volumeOk,
            'volatilityOk' => $volatilityOk,
            'chopOk' => $chopOk,
            'momentumLongOk' => $momentumLongOk,
            'momentumShortOk' => $momentumShortOk,
        ];

        $componentScores = [
            'htf' => $signal === 'SELL' ? ($htfBear ? 20 : ($htfMixed ? 5 : 0)) : ($htfBull ? 20 : ($htfMixed ? 5 : 0)),
            'ema' => $signal === 'SELL' ? ($emaBear ? 15 : 0) : ($emaBull ? 15 : 0),
            'major' => $signal === 'SELL' ? ($majorBear ? 10 : 0) : ($majorBull ? 10 : 0),
            'donchian' => $signal === 'SELL' ? $donShortScore : $donLongScore,
            'vwap' => $signal === 'SELL' ? ($vwapBear ? 10 : 0) : ($vwapBull ? 10 : 0),
            'rsi' => $signal === 'SELL' ? ($rsiBear ? 5 : 0) : ($rsiBull ? 5 : 0),
            'adx' => $signal === 'SELL' ? ($adxBear ? 12 : 0) : ($adxBull ? 12 : 0),
            'volume' => $volumeOk ? 5 : 0,
            'atrVolatility' => $volatilityOk ? 3 : 0,
            'momentum' => ($signal === 'SELL' ? $momentumShortOk : $momentumLongOk) ? 10 : 0,
        ];

        $positiveFactors = array_keys(array_filter($checks));
        $negativeFactors = [];
        foreach ($checks as $key => $passed) {
            if (! $passed) {
                $negativeFactors[] = $key;
            }
        }

        return [
            'symbol' => (string) ($ticker['symbol'] ?? 'BTCUSDT'),
            'signal' => $signal,
            'price' => $price,
            'changePercent' => round($change, 4),
            'intradayChange' => $openPrice > 0 ? round((($price - $openPrice) / $openPrice) * 100, 4) : round($change, 4),
            'volume' => $quoteVolume,
            'rangePercent' => $price > 0 ? round((($high - $low) / $price) * 100, 4) : 0.0,
            'rangePosition' => $high > $low ? round(($price - $low) / ($high - $low), 4) : 0.5,
            'vwapBullish' => $vwapBull,
            'sequence' => $sequence,
            'structure' => [],
            'confidence' => round($confidence, 4),
            'timeframe' => $tf,
            'rsi14' => round($rsi14, 2),
            'emaBullish' => $emaBull,
            'macdBullish' => null,
            'macdHistGrowing' => null,
            'htfBullish' => $htfBull,
            'htf4hBullish' => $htfTrend2 === 1,
            'symbolPerfAdj' => 0.0,
            'conflicted' => false,
            'strongSequence' => $momentumLongOk || $momentumShortOk,
            'fastTimeframeWeakSetup' => false,
            'positiveFactors' => $positiveFactors,
            'negativeFactors' => $negativeFactors,
            'componentScores' => $componentScores,
            'debug' => [
                'profile' => self::PROFILE_PRIME_V617_CRYPTO,
                'profileName' => (string) ($settings['signalProfileName'] ?? 'AutoFinX Prime v6.1.7 Crypto Stable Default'),
                'finalConfidence' => round($confidence, 4),
                'longScore' => round($longScore, 2),
                'shortScore' => round($shortScore, 2),
                'watchSignal' => $watchSignal,
                'hardLongFilter' => $hardLong,
                'hardShortFilter' => $hardShort,
                'checks' => $checks,
                'componentScores' => $componentScores,
                'componentBreakdown' => array_map(
                    fn (int|string $key, float|int $value): array => [
                        'key' => (string) $key,
                        'label' => (string) $key,
                        'score' => (float) $value,
                        'finalContribution' => (float) $value / 100,
                    ],
                    array_keys($componentScores),
                    $componentScores
                ),
                'risk' => [
                    'atr14' => round($atr14, 8),
                    'atrPercent' => round($atrPct, 4),
                    'dynamicSlMultiplier' => round($dynamicSlMult, 2),
                    'riskDistance' => round($riskDistance, 8),
                    'riskPercent' => round($riskPercent, 4),
                    'tp1RR' => (float) ($settings['tp1RR'] ?? 1.0),
                    'tp2RR' => (float) ($settings['tp2RR'] ?? 2.0),
                    'trailAtrMultiplier' => (float) ($settings['trailAtrMultiplier'] ?? 1.0),
                ],
                'positiveFactors' => $positiveFactors,
                'negativeFactors' => $negativeFactors,
            ],
            'reason' => $signal === 'WAIT'
                ? 'Prime v6.1.7 WAIT: hard filters or score threshold not met.'
                : sprintf('Prime v6.1.7 %s: score %.0f, opposite %.0f.', $signal, $activeScore, $signal === 'BUY' ? $shortScore : $longScore),
        ];
    }

    private function scoreTickerClassic(array $ticker, array $settings, array $snapshot): array
    {
        $price = (float) ($snapshot['price'] ?? 0.0);
        $change = (float) ($snapshot['change'] ?? 0.0);
        $quoteVolume = (float) ($snapshot['quoteVolume'] ?? 0.0);
        $rangePct = (float) ($snapshot['rangePct'] ?? 0.0);
        $intradayChangePct = (float) ($snapshot['intradayChangePct'] ?? 0.0);
        $rangePosition = (float) ($snapshot['rangePosition'] ?? 0.5);
        $openPrice = (float) ($snapshot['openPrice'] ?? $price);
        $sequence = (array) ($snapshot['sequence'] ?? []);
        $sequenceLastCandleTime = $sequence['lastCandleTime'] ?? null;

        $simpleScore = 0.0;
        if ($intradayChangePct >= 0.04) {
            $simpleScore += 1.0;
        } elseif ($intradayChangePct <= -0.04) {
            $simpleScore -= 1.0;
        }
        if ($change >= 0.08) {
            $simpleScore += 1.0;
        } elseif ($change <= -0.08) {
            $simpleScore -= 1.0;
        }
        if ($rangePosition >= 0.58) {
            $simpleScore += 1.0;
        } elseif ($rangePosition <= 0.42) {
            $simpleScore -= 1.0;
        }
        if ($price > $openPrice) {
            $simpleScore += 0.5;
        } elseif ($price < $openPrice) {
            $simpleScore -= 0.5;
        }

        $signal = 'WAIT';
        if ($simpleScore >= 1.5) {
            $signal = 'BUY';
        } elseif ($simpleScore <= -1.5) {
            $signal = 'SELL';
        }

        $moveStrength = min(
            0.45,
            (abs($intradayChangePct) * 0.45)
            + (abs($change) * 0.04)
            + (abs($rangePosition - 0.5) * 0.55)
        );
        $confidence = $this->clamp(0.42 + $moveStrength, 0.05, 0.95);
        if ($signal === 'WAIT') {
            $confidence = min($confidence, 0.49);
        }

        $bias = $signal === 'WAIT' ? ($simpleScore >= 0 ? 'BUY' : 'SELL') : $signal;
        $componentDebug = [
            'decision' => $signal,
            'decisionStatus' => $signal === 'WAIT' ? 'BLOCKED' : 'ELIGIBLE',
            'signalSide' => $bias,
            'finalConfidence' => round($confidence, 4),
            'positiveFactors' => array_values(array_filter([
                'Classic simple mode enabled',
                $price >= $openPrice ? 'Price trading above open' : 'Price trading below open',
                $rangePosition >= 0.5 ? 'Price holding upper half of range' : 'Price holding lower half of range',
            ])),
            'negativeFactors' => $signal === 'WAIT'
                ? ['Classic simple mode: price action not strong enough yet']
                : [],
            'componentScores' => [
                'classicSimpleSignal' => round($confidence, 4),
            ],
            'voteSummary' => [
                'dominantSignal' => $bias,
                'dominantScore' => (int) round(abs($simpleScore)),
                'minimumDirectionalScore' => 1,
                'voteGap' => (int) round(abs($simpleScore)),
                'minimumVoteGap' => 0,
                'corePassRule' => 0,
            ],
            'timing' => [
                'sequenceLastCandleTime' => $sequenceLastCandleTime,
                'higherTimeframeLastCandleTime' => null,
            ],
            'checks' => [
                'vwapAligned' => null,
                'emaAligned' => null,
                'adxValue' => null,
                'macdDirection' => null,
                'rsiValue' => null,
                'volumeAligned' => null,
                'volumeRatio' => null,
                'htfAligned' => null,
                'corePassRule' => 0,
                'buyCoreChecks' => [],
                'sellCoreChecks' => [],
                'classicSimpleMode' => true,
            ],
            'componentBreakdown' => [
                'classicSimpleSignal' => [
                    'key' => 'classicSimpleSignal',
                    'label' => 'Classic simple graph signal',
                    'rawScore' => round($confidence, 4),
                    'influenceLevel' => 1.0,
                    'finalContribution' => round($confidence, 4),
                    'impact' => $bias === 'SELL' ? 'negative' : 'positive',
                ],
            ],
        ];

        $reason = match ($signal) {
            'BUY' => 'Classic simple mode: bullish graph/price-action signal',
            'SELL' => 'Classic simple mode: bearish graph/price-action signal',
            default => 'Classic simple mode: waiting for clearer graph direction',
        };

        $this->safeDebugLog('[CONFIDENCE DEBUG]', [
            'symbol' => (string) ($ticker['symbol'] ?? 'BTCUSDT'),
            'timeframe' => $settings['timeframe'] ?? '5m',
            'price' => $price,
            'candleTime' => $sequenceLastCandleTime,
            'finalConfidence' => round($confidence, 4),
            'signal' => $signal,
            'dominantSignal' => $bias,
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
            'vwapBullish' => null,
            'sequence' => $sequence,
            'structure' => [],
            'confidence' => round($confidence, 4),
            'timeframe' => $settings['timeframe'] ?? '5m',
            'rsi14' => null,
            'emaBullish' => null,
            'macdBullish' => null,
            'macdHistGrowing' => null,
            'htfBullish' => null,
            'htf4hBullish' => null,
            'symbolPerfAdj' => 0.0,
            'conflicted' => false,
            'strongSequence' => abs($simpleScore) >= 1.5,
            'fastTimeframeWeakSetup' => $signal === 'WAIT',
            'positiveFactors' => $componentDebug['positiveFactors'],
            'negativeFactors' => $componentDebug['negativeFactors'],
            'componentScores' => $componentDebug['componentScores'],
            'debug' => $componentDebug,
            'reason' => $reason,
            'classicSimpleMode' => true,
        ];
    }

    private function marketSnapshot(FutureTradeBotConfig $config, array $settings): array
    {
        $symbols = array_map('strtoupper', (array) ($settings['symbols'] ?? []));
        $cacheKey = 'future_trade_market_snapshot_' . md5(implode(',', $symbols));
        $dataBase = null;
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
                        $trustedPrice = (float) ($row['lastPrice'] ?? $row['price'] ?? 0);
                        $candidatePrice = (float) $feedPrice['price'];
                        $deviation = $trustedPrice > 0 ? abs($candidatePrice - $trustedPrice) / $trustedPrice : 0.0;
                        if ($trustedPrice > 0 && $deviation > 0.20) {
                            Log::warning('[SYMBOL PRICE SANITY BLOCKED]', [
                                'symbol' => $symbol,
                                'candidatePrice' => $candidatePrice,
                                'trustedPrice' => $trustedPrice,
                                'deviationPercent' => round($deviation * 100, 4),
                                'source' => $feedPrice['source'] ?? null,
                            ]);
                        } else {
                            $row['lastPrice'] = (string) $candidatePrice;
                            $row['price'] = (string) $candidatePrice;
                        }
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
            $isTransient = $this->isTransientNetworkException($e);
            $derived = $this->fallbackMarketSnapshotFromCache($config, $symbols, $settings, (string) $dataBase);

            if ($isTransient && ! empty($derived)) {
                Cache::put($cacheKey, $derived, max(3, min(10, (int) ($settings['scanIntervalSeconds'] ?? 3) * 2)));

                return $derived;
            }

            if ($isTransient && ! empty($cached)) {
                return $cached;
            }

            $this->logThrottled(
                $config->fresh('state'),
                'MARKET_SNAPSHOT_FAILED',
                $config->state?->current_symbol,
                null,
                0,
                $e->getMessage(),
                [
                    'status' => $isTransient ? 'WARNING' : 'ERROR',
                    'cachedSymbols' => is_array($cached) ? count($cached) : 0,
                    'requestedSymbols' => count($symbols),
                    'transient' => $isTransient,
                ],
                $isTransient ? 180 : 60
            );

            return $cached;
        }
    }

    private function fallbackMarketSnapshotFromCache(FutureTradeBotConfig $config, array $symbols, array $settings, string $dataBase): array
    {
        try {
            $sequenceSnapshots = $this->sequenceSnapshots($symbols, $settings, $dataBase);
        } catch (Throwable) {
            $sequenceSnapshots = [];
        }

        try {
            $structureSnapshots = $this->structureSnapshots($symbols, $dataBase);
        } catch (Throwable) {
            $structureSnapshots = [];
        }

        try {
            $htfSnapshots = $this->higherTfSnapshots($symbols, $settings, $dataBase);
        } catch (Throwable) {
            $htfSnapshots = [];
        }
        $configId = (string) $config->id;
        $rows = [];

        foreach ($symbols as $symbol) {
            $feedPrice = $this->cachedLatestPrice($symbol);
            if (! $feedPrice || (float) ($feedPrice['price'] ?? 0) <= 0) {
                continue;
            }

            $price = (float) $feedPrice['price'];
            $rows[] = [
                'symbol' => $symbol,
                'lastPrice' => (string) $price,
                'price' => (string) $price,
                'priceChangePercent' => '0',
                'quoteVolume' => '0',
                'sequence' => $sequenceSnapshots[$symbol] ?? $this->emptySequenceSnapshot(),
                'structure' => $structureSnapshots[$symbol] ?? $this->emptyStructureSnapshot(),
                'htf' => $htfSnapshots[$symbol] ?? ['emaBullish' => null, 'h4EmaBullish' => null, 'ema200Bullish' => null, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0, 'ema200_1h' => 0.0, 'lastCandleTime_1h' => null, 'lastCandleTime_4h' => null],
                'symbolPerfAdjust' => $configId !== '' ? $this->symbolPerformanceAdjustment($symbol, $configId) : 0,
            ];
        }

        return $rows;
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
        $volatility = $this->clamp($volatility, 0.0, 12.0);

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
        $ema200 = 0.0;
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
            if (count($closes) >= 200) {
                $ema200 = $this->calcEma($closes, 200);
            }
        }

        // MACD(12,26,9) — requires 35+ candles
        $macd = count($closes) >= 35 ? $this->calcMacd($closes) : ['bullish' => null, 'histogram' => 0.0, 'histogramGrowing' => false];
        $atr14 = count($closes) >= 15 ? $this->calcAtr($highs, $lows, $closes, 14) : 0.0;
        $atrPercent = $last > 0 ? ($atr14 / $last) * 100 : 0.0;
        $sessionVwap = $this->calcSessionVwap($highs, $lows, $closes, $volumes);
        $volMa20 = count($volumes) >= 20 ? array_sum(array_slice($volumes, -20)) / 20 : (count($volumes) ? array_sum($volumes) / count($volumes) : 0.0);
        $lastVolume = (float) (end($volumes) ?: 0.0);
        $relativeVolume = $volMa20 > 0 ? $lastVolume / $volMa20 : 1.0;
        $volumeOk = $volMa20 > 0 ? $lastVolume > ($volMa20 * 1.05) : false;
        $adx14 = count($closes) >= 20 ? $this->calcAdx($highs, $lows, $closes, 14) : 0.0;
        $dmi14 = count($closes) >= 20 ? $this->calcDmi($highs, $lows, $closes, 14) : ['plusDi' => 0.0, 'minusDi' => 0.0, 'adx' => $adx14];
        $choppiness14 = $this->calcChoppiness($highs, $lows, $closes, 14);
        $donchian = $this->calcDonchianConsensus($highs, $lows, $closes, 20);
        $emaSlope = count($closes) > 5 ? $ema50 - (float) $this->calcEma(array_slice($closes, 0, -5), 50) : 0.0;
        $lastOpen = (float) (end($opens) ?: $last);
        $lastHigh = (float) (end($highs) ?: $last);
        $lastLow = (float) (end($lows) ?: $last);
        $minBodyRatio = (float) ($settings['minBodyRatio'] ?? 0.55);
        $bodyRatio = ($lastHigh - $lastLow) > 0 ? abs($last - $lastOpen) / ($lastHigh - $lastLow) : 0.0;
        $recentHighs = array_slice($highs, -6, 5);
        $recentLows = array_slice($lows, -6, 5);
        $momentumLongOk = $emaSlope > 0 && $last > $lastOpen && $bodyRatio >= $minBodyRatio && count($recentHighs) > 0 && $last > max($recentHighs);
        $momentumShortOk = $emaSlope < 0 && $last < $lastOpen && $bodyRatio >= $minBodyRatio && count($recentLows) > 0 && $last < min($recentLows);
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
            'ema200' => round($ema200, 6),
            'emaStackBullish' => $emaStackBullish,
            'emaStackBearish' => $emaStackBearish,
            'macdBullish' => $macd['bullish'],
            'macdHistogram' => round($macd['histogram'], 8),
            'macdHistogramGrowing' => $macd['histogramGrowing'],
            'atr14' => round($atr14, 8),
            'atrPercent' => round($atrPercent, 4),
            'adx14' => round($adx14, 4),
            'diPlus14' => round((float) ($dmi14['plusDi'] ?? 0.0), 4),
            'diMinus14' => round((float) ($dmi14['minusDi'] ?? 0.0), 4),
            'sessionVwap' => round($sessionVwap, 8),
            'volumeOk' => $volumeOk,
            'lastVolume' => round($lastVolume, 4),
            'volumeMa20' => round($volMa20, 4),
            'relativeVolume' => round($relativeVolume, 4),
            'choppiness14' => round($choppiness14, 4),
            'donchianBullCount' => (int) ($donchian['bullCount'] ?? 0),
            'donchianBearCount' => (int) ($donchian['bearCount'] ?? 0),
            'momentumLongOk' => $momentumLongOk,
            'momentumShortOk' => $momentumShortOk,
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
            'ema200' => 0.0,
            'emaStackBullish' => false,
            'emaStackBearish' => false,
            'macdBullish' => null,
            'macdHistogram' => 0.0,
            'macdHistogramGrowing' => false,
            'atr14' => 0.0,
            'atrPercent' => 0.0,
            'adx14' => 0.0,
            'diPlus14' => 0.0,
            'diMinus14' => 0.0,
            'sessionVwap' => 0.0,
            'volumeOk' => false,
            'lastVolume' => 0.0,
            'volumeMa20' => 0.0,
            'relativeVolume' => 1.0,
            'choppiness14' => 100.0,
            'donchianBullCount' => 0,
            'donchianBearCount' => 0,
            'momentumLongOk' => false,
            'momentumShortOk' => false,
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

    private function calcDmi(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $count = count($closes);
        if ($count < ($period * 2) || count($highs) !== $count || count($lows) !== $count) {
            return ['plusDi' => 0.0, 'minusDi' => 0.0, 'adx' => 0.0];
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

        $tr = array_sum(array_slice($trs, 0, $period));
        $plus = array_sum(array_slice($plusDm, 0, $period));
        $minus = array_sum(array_slice($minusDm, 0, $period));
        $dxValues = [];
        $plusDi = 0.0;
        $minusDi = 0.0;

        for ($i = $period; $i < count($trs); $i++) {
            $tr = $tr - ($tr / $period) + $trs[$i];
            $plus = $plus - ($plus / $period) + $plusDm[$i];
            $minus = $minus - ($minus / $period) + $minusDm[$i];
            if ($tr <= 0) {
                continue;
            }

            $plusDi = 100 * ($plus / $tr);
            $minusDi = 100 * ($minus / $tr);
            $sum = $plusDi + $minusDi;
            if ($sum > 0) {
                $dxValues[] = 100 * abs($plusDi - $minusDi) / $sum;
            }
        }

        $adx = 0.0;
        if (count($dxValues) >= $period) {
            $adx = array_sum(array_slice($dxValues, 0, $period)) / $period;
            for ($i = $period; $i < count($dxValues); $i++) {
                $adx = (($adx * ($period - 1)) + $dxValues[$i]) / $period;
            }
        } elseif (count($dxValues) > 0) {
            $adx = (float) end($dxValues);
        }

        return ['plusDi' => $plusDi, 'minusDi' => $minusDi, 'adx' => $adx];
    }

    private function calcChoppiness(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $count = count($closes);
        if ($count < $period + 1 || count($highs) !== $count || count($lows) !== $count) {
            return 100.0;
        }

        $start = $count - $period;
        $trSum = 0.0;
        for ($i = $start; $i < $count; $i++) {
            $trSum += max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
        }

        $rangeHigh = max(array_slice($highs, -$period));
        $rangeLow = min(array_slice($lows, -$period));
        $range = $rangeHigh - $rangeLow;
        if ($range <= 0 || $trSum <= 0 || $period <= 1) {
            return 100.0;
        }

        return $this->clamp(100 * log10($trSum / $range) / log10($period), 0.0, 100.0);
    }

    private function calcDonchianConsensus(array $highs, array $lows, array $closes, int $length = 20): array
    {
        $bull = 0;
        $bear = 0;
        $count = count($closes);
        $last = (float) (end($closes) ?: 0.0);

        for ($offset = 0; $offset < 10; $offset++) {
            $len = max(2, $length - $offset);
            if ($count <= $len) {
                continue;
            }

            $windowHighs = array_slice($highs, -($len + 1), $len);
            $windowLows = array_slice($lows, -($len + 1), $len);
            if ($windowHighs === [] || $windowLows === []) {
                continue;
            }

            if ($last > max($windowHighs)) {
                $bull++;
            } elseif ($last < min($windowLows)) {
                $bear++;
            }
        }

        return ['bullCount' => $bull, 'bearCount' => $bear];
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
        $componentBreakdown = [];
        $positiveFactors = [];
        $negativeFactors = [];
        $influenceWeights = (array) ($meta['influenceWeights'] ?? []);
        $labelMap = [
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

        $components = [
            'structure' => $this->weightedComponent(
                'structurePositive',
                match (true) {
                    $structureHits >= 3 => 0.05,
                    $structureHits === 2 => 0.03,
                    $structureHits === 1 => 0.01,
                    default => 0.0,
                },
                $influenceWeights,
                '15m structure context',
                'positive',
                ['structure']
            ),
            'vwap' => $this->weightedComponent('vwapPositive', ($checks['vwap'] ?? false) ? 0.09 : 0.0, $influenceWeights, 'VWAP alignment', 'positive', ['vwap']),
            'ema' => $this->weightedComponent('emaPositive', ($checks['ema'] ?? false) ? 0.09 : 0.0, $influenceWeights, '5m EMA(9/21/50) stack', 'positive', ['ema']),
            'htf' => $this->weightedComponent('htfPositive', ($checks['htf'] ?? false) ? 0.03 : 0.0, $influenceWeights, '1h EMA50/200 filter', 'positive', ['htf']),
            'macro' => $this->weightedComponent('macroPositive', ($checks['macro'] ?? false) ? 0.02 : 0.0, $influenceWeights, '4h EMA50 filter', 'positive', ['macro']),
            'rsi' => $this->weightedComponent('rsiPositive', ($checks['rsi'] ?? false) ? 0.03 : 0.0, $influenceWeights, 'RSI window', 'positive', ['rsi']),
            'macd' => $this->weightedComponent('macdPositive', ($checks['macd'] ?? false) ? 0.03 : 0.0, $influenceWeights, 'MACD direction', 'positive', ['macd']),
            'extension' => $this->weightedComponent('extensionPositive', ($checks['extension'] ?? false) ? 0.02 : 0.0, $influenceWeights, 'Not overextended', 'positive', ['extension']),
            'adx' => $this->weightedComponent('adxPositive', match (true) {
                $adx >= 22.0 => 0.08,
                $adx >= 18.0 => 0.06,
                $adx >= 15.0 => 0.04,
                default => 0.0,
            }, $influenceWeights, 'ADX readiness', 'positive', ['adx']),
            'volume' => $this->weightedComponent('volumePositive', match (true) {
                $volumeRatio >= 1.10 => 0.03,
                $volumeRatio >= 0.95 => 0.02,
                $volumeRatio >= 0.85 => 0.01,
                default => 0.0,
            }, $influenceWeights, 'Volume confirmation', 'positive', ['volume']),
            'sequence' => $this->weightedComponent('sequencePositive', match (true) {
                ($meta['strongDirectionalSequence'] ?? false) === true => 0.05,
                $directionalImpulseAligned && $sequenceConsistency >= ($isFastTimeframe ? 0.52 : 0.5) && $sequenceNetChange >= ($isFastTimeframe ? 0.08 : 0.06) => 0.02,
                default => 0.0,
            }, $influenceWeights, '5m directional sequence', 'positive', ['sequence']),
            'macdHistogramBonus' => $this->weightedComponent('macdHistogramBonus', ($meta['macdHistGrowing'] ?? false) ? 0.02 : 0.0, $influenceWeights, 'MACD histogram bonus', 'positive'),
            'directionalSignalBonus' => $this->weightedComponent('directionalSignalBonus', (($meta['signal'] ?? 'WAIT') !== 'WAIT' && ! ($meta['strictEntryGuards'] ?? false)) ? 0.08 : 0.0, $influenceWeights, 'Directional signal bonus', 'positive'),
            'trendContinuationBonus' => $this->weightedComponent('trendContinuationBonus', (
                (($meta['signal'] ?? 'WAIT') !== 'WAIT')
                && ($checks['vwap'] ?? false)
                && ($checks['ema'] ?? false)
                && ($checks['adx'] ?? false)
            ) ? 0.05 : 0.0, $influenceWeights, 'Trend continuation bonus', 'positive'),
        ];

        $symbolPerfAdjust = round((float) ($meta['symbolPerfAdjust'] ?? 0.0), 4);
        $components['symbolPerfAdjust'] = $this->weightedComponent(
            $symbolPerfAdjust >= 0 ? 'symbolPerfPositive' : 'symbolPerfPenalty',
            $symbolPerfAdjust,
            $influenceWeights,
            'Symbol performance adjustment',
            $symbolPerfAdjust >= 0 ? 'positive' : 'negative',
            ['symbolPerfAdjust']
        );

        $components['volumePenalty'] = $this->weightedComponent('volumePenalty', ! ($checks['volume'] ?? false) ? -0.03 : 0.0, $influenceWeights, 'Volume confirmation penalty', 'negative');
        $components['macdPenalty'] = $this->weightedComponent('macdPenalty', ! ($checks['macd'] ?? false) ? -0.03 : 0.0, $influenceWeights, 'MACD direction penalty', 'negative');
        $components['htfPenalty'] = $this->weightedComponent('htfPenalty', ! ($checks['htf'] ?? false) ? -0.04 : 0.0, $influenceWeights, '1h filter penalty', 'negative');
        $components['structurePenalty'] = $this->weightedComponent('structurePenalty', ($components['structure']['score'] ?? 0) > 0 ? 0.0 : -0.03, $influenceWeights, 'Structure penalty', 'negative');
        $components['extensionPenalty'] = $this->weightedComponent('extensionPenalty', (($meta['extendedFromEma'] ?? false) === true || ($meta['extendedFromVwap'] ?? false) === true) ? -0.04 : 0.0, $influenceWeights, 'Entry extension penalty', 'negative');
        $components['rsiPenalty'] = $this->weightedComponent('rsiPenalty', ! ($checks['rsi'] ?? false) ? -0.02 : 0.0, $influenceWeights, 'RSI penalty', 'negative');
        $components['choppyPenalty'] = $this->weightedComponent('choppyPenalty', (($meta['choppy'] ?? false) === true) ? -0.02 : 0.0, $influenceWeights, 'Choppy market penalty', 'negative');
        $components['weakSetupPenalty'] = $this->weightedComponent('weakSetupPenalty', (($meta['fiveMinuteWeakSetup'] ?? false) === true) ? -0.02 : 0.0, $influenceWeights, 'Weak setup penalty', 'negative');

        foreach ($components as $key => $payload) {
            $componentScores[$key] = $payload['score'];
            $componentBreakdown[$key] = $payload['breakdown'];
        }

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

        $finalConfidence = round($this->clamp(array_sum($componentScores), 0.01, 0.99), 4);

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
                'corePassRule' => (int) ($meta['corePassRule'] ?? 3),
            ],
            'timing' => [
                'sequenceLastCandleTime' => $meta['sequenceLastCandleTime'] ?? null,
                'higherTimeframeLastCandleTime' => $meta['htfLastCandleTime'] ?? null,
            ],
            'checks' => [
                'vwapAligned' => (bool) ($checks['vwap'] ?? false),
                'emaAligned' => (bool) ($checks['ema'] ?? false),
                'adxValue' => round((float) ($meta['adx14'] ?? 0), 2),
                'macdDirection' => (bool) ($checks['macd'] ?? false),
                'rsiValue' => round((float) ($meta['rsi14'] ?? 0), 2),
                'volumeAligned' => (bool) ($checks['volume'] ?? false),
                'volumeRatio' => round($volumeRatio, 4),
                'htfAligned' => (bool) ($checks['htf'] ?? false),
                'corePassRule' => (int) ($meta['corePassRule'] ?? 3),
                'buyCoreChecks' => (array) ($meta['buyCoreChecks'] ?? []),
                'sellCoreChecks' => (array) ($meta['sellCoreChecks'] ?? []),
            ],
            'componentBreakdown' => $componentBreakdown,
        ];
    }

    private function emitTradeDecisionDebug(
        FutureTradeBotConfig $config,
        array $signal,
        array $dynamic,
        string $finalDecision,
        array $hardBlockers = [],
        array $allowedBecause = []
    ): void {
        $softPenalties = $this->softPenaltiesFromSignal($signal);

        $this->safeDebugLog('[TRADE DECISION DEBUG]', [
            'workspaceId' => (string) $config->workspace_id,
            'symbol' => (string) ($signal['symbol'] ?? ''),
            'timeframe' => (string) ($signal['timeframe'] ?? data_get($dynamic, 'settings.timeframe', '5m')),
            'signal' => (string) ($signal['signal'] ?? 'WAIT'),
            'dominantSignal' => (string) ($signal['debug.voteSummary.dominantSignal'] ?? $signal['signal'] ?? 'WAIT'),
            'finalConfidence' => round((float) ($signal['confidence'] ?? 0), 4),
            'activeMinimum' => round((float) ($dynamic['effectiveMinConfidence'] ?? data_get($dynamic, 'settings.minConfidence', 0)), 4),
            'blocked' => in_array($finalDecision, ['BLOCKED', 'WAIT'], true),
            'hardBlockers' => array_values($hardBlockers),
            'softPenalties' => $softPenalties,
            'allowedBecause' => array_values($allowedBecause),
            'finalDecision' => $finalDecision,
        ]);
    }

    private function softPenaltiesFromSignal(array $signal): array
    {
        $negativeFactors = array_values((array) data_get($signal, 'debug.negativeFactors', []));

        return array_values(array_filter($negativeFactors, function (string $factor): bool {
            $normalized = strtolower($factor);

            return ! str_contains($normalized, 'confidence below')
                && ! str_contains($normalized, 'directional gate not cleared')
                && ! str_contains($normalized, 'weak-setup guard active');
        }));
    }

    private function influenceScore(string $key, float $base, array $weights = [], array $fallbackKeys = []): float
    {
        $multiplier = $this->influenceMultiplier($key, $weights, $fallbackKeys);

        return round($base * $multiplier, 4);
    }

    private function influenceMultiplier(string $key, array $weights = [], array $fallbackKeys = []): float
    {
        $candidates = array_values(array_unique(array_merge([$key], $fallbackKeys)));
        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $weights)) {
                continue;
            }

            $raw = (float) $weights[$candidate];
            $normalized = $raw > 2.0 ? ($raw / 5.0) : $raw;

            return $this->clamp($normalized, 0.0, 2.0);
        }

        return 1.0;
    }

    private function weightedComponent(
        string $key,
        float $base,
        array $weights,
        string $label,
        string $impact,
        array $fallbackKeys = []
    ): array {
        $multiplier = $this->influenceMultiplier($key, $weights, $fallbackKeys);
        $score = round($base * $multiplier, 4);

        return [
            'score' => $score,
            'breakdown' => [
                'key' => $key,
                'label' => $label,
                'rawScore' => round($base, 4),
                'influenceLevel' => round($multiplier, 4),
                'finalContribution' => $score,
                'impact' => $impact,
            ],
        ];
    }

    private function resolveCorePassRule(mixed $value): int
    {
        return (int) $this->clamp((float) ((int) $value ?: 3), 2.0, 4.0);
    }

    private function normalizeFutureTradeSettings(array $settings): array
    {
        $settings['signalProfile'] = (string) ($settings['signalProfile'] ?? config('future_trade.default_signal_profile', self::PROFILE_PRIME_V617_CRYPTO));
        $primeCryptoProfile = $this->isPrimeCryptoProfile($settings);
        if ($primeCryptoProfile) {
            $settings = array_merge([
                'tradeScoreThreshold' => 72,
                'watchScoreThreshold' => 58,
                'tradeDirection' => 'Both',
                'htf1' => '60',
                'htf2' => '240',
                'emaFast' => 9,
                'emaSlow' => 21,
                'emaTrend' => 50,
                'emaMajor' => 200,
                'donchianLength' => 20,
                'useVWAP' => true,
                'useDonchianScore' => true,
                'useVolume' => true,
                'useVolatilityFilter' => true,
                'useChopFilter' => true,
                'chopLength' => 14,
                'cryptoChopMax' => 61.8,
                'useMomentumBreakoutFilter' => false,
                'rsiLength' => 14,
                'longRsiMin' => 48,
                'longRsiMax' => 72,
                'shortRsiMin' => 28,
                'shortRsiMax' => 52,
                'adxLength' => 14,
                'adxSmoothing' => 14,
                'adxMin' => 20,
                'volumeLength' => 20,
                'cryptoRelVolMin' => 1.0,
                'atrLength' => 14,
                'atrRegimeLength' => 50,
                'cryptoMinAtrPct' => 0.06,
                'cryptoMaxAtrPct' => 3.0,
                'slAtrMultiplier' => 1.4,
                'tp1RR' => 1.0,
                'tp2RR' => 2.0,
                'useAtrTrailingSecondHalf' => true,
                'trailAtrMultiplier' => 1.0,
                'cooldownBars' => 3,
                'exitOnStrongOppositeScore' => true,
                'oppositeExitScore' => 78,
                'signalSource' => 'webhook',
                'pineWebhookFallbackTimeoutSeconds' => 1200,
                'webhookFallbackEnabled' => false,
                'primeMirrorLiveEnabled' => false,
                'primeMirrorExecutionEnabled' => false,
                'useWebhookRiskOverrides' => false,
                'useAtrRiskTargets' => false,
                'confirmedCandleOnly' => true,
                'reversalMode' => 'close_only',
            ], $settings);
            $settings['signalProfileName'] = 'AutoFinX Prime v6.1.7 Crypto Stable Default';
            $settings['marketProfile'] = 'Crypto';
            $settings['classicSimpleMode'] = false;
            $settings['confidenceMode'] = 'pine_mirror';
            $settings['dynamicSuggestionEnabled'] = false;
            $settings['bayesianFilter'] = false;
            $settings['useBayesianFilter'] = false;
            $settings['utBotFilter'] = false;
            $settings['useUtBotFilter'] = false;
            $settings['atrExpansionFilter'] = false;
            $settings['useAtrExpansionFilter'] = false;
            $settings['liquidityV7Engine'] = false;
            $settings['useLiquidityV7Engine'] = false;
            $settings['fvgEngine'] = false;
            $settings['useFvgEngine'] = false;
            $settings['scoreGapFilter'] = false;
            $settings['heavyAntiChopFilter'] = false;
            $settings['useMomentumBreakoutFilter'] = (bool) ($settings['useMomentumBreakoutFilter'] ?? false);
            $settings['avoidChoppyMarket'] = (bool) ($settings['avoidChoppyMarket'] ?? true);
            $settings['strictEntryGuards'] = (bool) ($settings['strictEntryGuards'] ?? true);
            $settings['weakSetupAction'] = 'hold';
            $settings['minConfidence'] = (float) ($settings['tradeScoreThreshold'] ?? 72) / 100;
            $settings['timeframe'] = '15m';
            $settings['secondaryAiWeighting'] = false;
            $settings['legacyWeightedScoring'] = false;
            $settings['mode'] = 'pine_mirror_or_webhook_executor';
            $settings['indicatorInfluence'] = [];
            $settings['indicatorControls'] = [];
            $symbols = array_values(array_map('strtoupper', (array) ($settings['symbols'] ?? [])));
            $legacyDefaultSymbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'AVAXUSDT'];
            sort($symbols);
            $sortedLegacy = $legacyDefaultSymbols;
            sort($sortedLegacy);
            if ($symbols === [] || $symbols === $sortedLegacy) {
                $settings['symbols'] = ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'];
            }
            $settings['signalSource'] = $this->normalizedSignalSource($settings);
            $settings['webhookFallbackEnabled'] = $this->webhookFallbackEnabled($settings);
            $settings['useWebhookRiskOverrides'] = filter_var($settings['useWebhookRiskOverrides'] ?? config('future_trade.webhook_risk_overrides', false), FILTER_VALIDATE_BOOLEAN);
            $settings['useAtrRiskTargets'] = filter_var($settings['useAtrRiskTargets'] ?? config('future_trade.atr_dynamic_risk_targets', false), FILTER_VALIDATE_BOOLEAN);
            $settings['confirmedCandleOnly'] = filter_var($settings['confirmedCandleOnly'] ?? config('future_trade.confirmed_candle_only', true), FILTER_VALIDATE_BOOLEAN);
            $settings['maxTradesPerDay'] = max(1, min(50, (int) ($settings['maxTradesPerDay'] ?? 50)));
            $settings['maxLeverage'] = max(1, min(2, (int) ($settings['maxLeverage'] ?? 2)));
            $settings['leverage'] = max(1, min((int) ($settings['leverage'] ?? 2), (int) $settings['maxLeverage']));
            $settings['tradeAmountUsdt'] = max(5, (float) ($settings['tradeAmountUsdt'] ?? 25));
            $settings['maxDailyLossPercent'] = max(0.01, min(10, (float) ($settings['maxDailyLossPercent'] ?? 0.5)));
            $settings['cooldownSeconds'] = max(0, (int) ($settings['cooldownSeconds'] ?? 180));
            $settings['reversalMode'] = in_array(strtolower((string) ($settings['reversalMode'] ?? 'close_only')), ['close_only', 'close_then_reverse'], true)
                ? strtolower((string) $settings['reversalMode'])
                : 'close_only';
            $requestedMaxOpen = max(1, min(2, (int) ($settings['maxOpenPositions'] ?? 1)));
            $hedgeVerified = filter_var($settings['hedgeModeVerified'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $settings['maxOpenPositions'] = $requestedMaxOpen > 1 && ! $hedgeVerified ? 1 : $requestedMaxOpen;
        }

        $settings = $this->indicatorIntelligence->normalizeSettings(
            IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
            $settings
        );

        if ($primeCryptoProfile) {
            $settings['indicatorInfluence'] = [];
            $settings['indicatorControls'] = [];
        }

        return $settings;
    }

    private function isPrimeCryptoProfile(array $settings): bool
    {
        return strtolower(trim((string) ($settings['signalProfile'] ?? ''))) === self::PROFILE_PRIME_V617_CRYPTO
            && strtolower(trim((string) ($settings['marketProfile'] ?? 'crypto'))) === 'crypto';
    }

    private function indicatorIntelligencePayload(FutureTradeBotConfig $config, array $settings, ?array $bestSignal): array
    {
        $settings = $this->normalizeFutureTradeSettings($settings);
        if ($this->isPrimeCryptoProfile($settings)) {
            return [
                'controls' => [],
                'hardBlockers' => [],
                'currentBreakdown' => [],
                'performance' => [],
                'validation' => [
                    'classicSimpleMode' => false,
                    'normalGraphModeWorks' => true,
                    'liveSignalFlowDetected' => true,
                    'blockers' => [],
                    'trackerAlignedIndicators' => 0,
                    'highRatedIndicators' => 0,
                    'overallReadinessScore' => 100,
                ],
                'suggestions' => [[
                    'key' => 'prime_v617_pine_mirror',
                    'title' => 'Prime v6.1.7 Pine Mirror active',
                    'reason' => 'Secondary AI weighting and legacy indicator influence controls are disabled for the default crypto profile.',
                    'action' => 'Use Pine score/debug components instead of MACD/RSI/symbol-performance weights.',
                ]],
                'integrationReadiness' => [
                    'pineScriptImportReady' => true,
                    'note' => 'Active Signal Brain: AutoFinX Prime v6.1.7 Pine Mirror. Secondary AI Weighting: Disabled.',
                ],
            ];
        }

        $controls = array_values($this->indicatorIntelligence->controls(
            IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
            $settings
        ));
        $currentBreakdown = array_values($this->indicatorIntelligence->annotateBreakdown(
            IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
            (array) data_get($bestSignal, 'debug.componentBreakdown', []),
            $settings
        ));
        usort($currentBreakdown, fn (array $left, array $right): int => abs((float) ($right['finalContribution'] ?? 0.0)) <=> abs((float) ($left['finalContribution'] ?? 0.0)));
        $trackerLogs = $this->cacheRemember(
            sprintf('future-trade:%s:indicator-tracker-logs', (string) $config->id),
            10,
            fn () => $config->logs()
                ->whereIn('event', ['BUY_EXECUTED', 'SELL_EXECUTED', 'LIVE_BUY_EXECUTED', 'LIVE_SELL_EXECUTED', 'SIGNAL_SKIPPED', 'TRADE_BLOCKED'])
                ->orderByDesc('occurred_at')
                ->limit(240)
                ->get(['event', 'context', 'occurred_at'])
        );

        $performance = $this->cacheRemember(
            sprintf('future-trade:%s:indicator-intelligence', (string) $config->id),
            15,
            fn () => $this->indicatorIntelligence->performance(
                IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
                $config->trades()
                    ->whereNotNull('net_pnl')
                    ->orderByDesc('created_at')
                    ->limit(120)
                    ->get(['net_pnl', 'meta', 'created_at']),
                $settings,
                $trackerLogs
            )
        );
        $validation = $this->indicatorValidationSnapshot($config, $settings, $bestSignal, $performance);

        return [
            'profile' => IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
            'controls' => $controls,
            'hardBlockers' => $this->indicatorIntelligence->hardBlockers(IndicatorIntelligenceService::PROFILE_FUTURE_TRADE),
            'currentBreakdown' => $currentBreakdown,
            'performance' => $performance,
            'validation' => $validation,
            'suggestions' => $this->indicatorIntelligence->suggestions(
                IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
                $settings,
                $performance
            ),
            'integrationReadiness' => [
                'pineScriptImportReady' => true,
                'note' => 'Indicator keys and contribution metadata are now normalized, so future Pine Script adapters can map imported outputs into this control system.',
            ],
        ];
    }

    private function researchLabPayload(FutureTradeBotConfig $config, array $settings): array
    {
        $lab = (array) ($settings['researchLab'] ?? []);
        $scripts = array_values((array) ($lab['scripts'] ?? []));
        $datasets = array_values((array) ($lab['datasets'] ?? []));
        $importedStats = $this->researchLab->summarizeImportedDatasets($datasets);
        $localPerformance = $this->cacheRemember(
            sprintf('future-trade:%s:research-local-performance', (string) $config->id),
            20,
            fn () => $this->indicatorIntelligence->performance(
                IndicatorIntelligenceService::PROFILE_FUTURE_TRADE,
                $config->trades()
                    ->whereNotNull('net_pnl')
                    ->orderByDesc('created_at')
                    ->limit(120)
                    ->get(['net_pnl', 'meta', 'created_at']),
                $settings
            )
        );

        $trainingSuggestions = [];
        foreach (array_slice($importedStats, 0, 8) as $item) {
            if (($item['samples'] ?? 0) < 4 || ! isset($item['winRate'])) {
                continue;
            }
            if ((float) $item['winRate'] >= 0.62) {
                $trainingSuggestions[] = [
                    'key' => (string) $item['key'],
                    'title' => sprintf('Imported data supports %s', strtoupper((string) $item['key'])),
                    'reason' => sprintf('Imported trades show %.0f%% win rate across %d samples when %s was active.', (float) $item['winRate'] * 100, (int) $item['samples'], strtoupper((string) $item['key'])),
                    'action' => 'consider_strengthen',
                ];
            } elseif ((float) $item['winRate'] <= 0.4) {
                $trainingSuggestions[] = [
                    'key' => (string) $item['key'],
                    'title' => sprintf('Imported data questions %s', strtoupper((string) $item['key'])),
                    'reason' => sprintf('Imported trades show only %.0f%% win rate across %d samples when %s was active.', (float) $item['winRate'] * 100, (int) $item['samples'], strtoupper((string) $item['key'])),
                    'action' => 'consider_reduce',
                ];
            }
        }

        $overlayHintGroups = array_map(
            fn (array $script): array => array_map(
                fn (array $hint): string => (string) ($hint['label'] ?? ''),
                (array) ($script['overlayHints'] ?? [])
            ),
            $scripts
        );
        $overlayHints = [];
        foreach ($overlayHintGroups as $group) {
            $overlayHints = array_merge($overlayHints, $group);
        }

        return [
            'scripts' => $scripts,
            'datasets' => array_map(fn (array $dataset): array => Arr::except($dataset, ['rows']), $datasets),
            'importedIndicatorStats' => $importedStats,
            'trainingSuggestions' => $trainingSuggestions,
            'localTradeSampleCount' => count($localPerformance),
            'overlayHints' => array_values(array_unique(array_filter($overlayHints))),
        ];
    }

    public function reviewPendingMissedSignalAudits(?string $workspaceId = null, ?int $reviewWindow = null, int $limit = 100): array
    {
        $query = FutureTradeMissedSignalAudit::query()
            ->with('config')
            ->where('status', 'PENDING_REVIEW')
            ->where('review_due_at', '<=', now())
            ->orderBy('review_due_at')
            ->limit(max(1, min(1000, $limit)));

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($reviewWindow !== null && $reviewWindow > 0) {
            $query->where('review_window_minutes', $reviewWindow);
        }

        $audits = $query->get();
        $reviewed = 0;
        $results = [];

        foreach ($audits as $audit) {
            $evaluation = $this->evaluateMissedTradeAudit($audit);
            if ($evaluation === null) {
                continue;
            }

            $audit->forceFill($evaluation + [
                'status' => 'REVIEWED',
                'reviewed_at' => now(),
            ])->save();

            $resultKey = (string) ($evaluation['would_have_result'] ?? 'UNKNOWN');
            $results[$resultKey] = ($results[$resultKey] ?? 0) + 1;
            $reviewed++;

            Log::info('[MISSED TRADE AUDIT REVIEWED]', [
                'workspace_id' => $audit->workspace_id,
                'symbol' => $audit->symbol,
                'side' => $audit->side,
                'window' => $audit->review_window_minutes,
                'result' => $evaluation['would_have_result'] ?? null,
                'pnl_percent' => $evaluation['would_have_pnl_percent'] ?? null,
            ]);
        }

        return [
            'reviewed' => $reviewed,
            'results' => $results,
            'pending' => FutureTradeMissedSignalAudit::query()
                ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
                ->where('status', 'PENDING_REVIEW')
                ->count(),
        ];
    }

    private function missedTradeAuditPayload(FutureTradeBotConfig $config, array $settings): array
    {
        $rows = $config->missedSignalAudits()
            ->latest('signal_time')
            ->limit(120)
            ->get();

        $reviewed = $rows->where('status', 'REVIEWED');
        $missedWins = $reviewed->where('would_have_result', 'MISSED_WIN')->count();
        $correctSkips = $reviewed->where('would_have_result', 'CORRECT_SKIP')->count();
        $avoidedLosses = $reviewed->where('would_have_result', 'AVOIDED_LOSS')->count();
        $neutral = $reviewed->where('would_have_result', 'NEUTRAL')->count();
        $badSignals = $reviewed->where('would_have_result', 'BAD_SIGNAL')->count();

        $blockerRows = [];
        foreach ($rows as $audit) {
            $blockers = array_values(array_filter(array_map(
                static fn ($value) => is_scalar($value) ? (string) $value : null,
                (array) ($audit->top_3_blockers ?? [])
            )));

            foreach ($blockers as $blocker) {
                $key = Str::slug($blocker, '_');
                $control = $this->resolveAuditBlockerControl($blocker, $settings);
                $blockerRows[$key] ??= [
                    'key' => $key,
                    'label' => $blocker,
                    'factor' => $control['factor'],
                    'currentWeight' => $control['currentWeight'],
                    'totalSkips' => 0,
                    'missedWins' => 0,
                    'correctSkips' => 0,
                    'avoidedLosses' => 0,
                    'neutral' => 0,
                ];
                $blockerRows[$key]['totalSkips']++;
                $result = (string) ($audit->would_have_result ?? '');
                if ($result === 'MISSED_WIN') {
                    $blockerRows[$key]['missedWins']++;
                } elseif ($result === 'CORRECT_SKIP') {
                    $blockerRows[$key]['correctSkips']++;
                } elseif ($result === 'AVOIDED_LOSS') {
                    $blockerRows[$key]['avoidedLosses']++;
                } elseif ($result === 'NEUTRAL') {
                    $blockerRows[$key]['neutral']++;
                }
            }
        }

        $analytics = array_values(array_map(function (array $row) {
            $reviewedCount = $row['missedWins'] + $row['correctSkips'] + $row['avoidedLosses'] + $row['neutral'];
            $missedWinRate = $reviewedCount > 0 ? $row['missedWins'] / $reviewedCount : 0.0;
            $protectiveRate = $reviewedCount > 0 ? ($row['correctSkips'] + $row['avoidedLosses']) / $reviewedCount : 0.0;
            $avoidedLossRate = $reviewedCount > 0 ? $row['avoidedLosses'] / $reviewedCount : 0.0;
            $sampleQuality = $row['totalSkips'] < 20 ? 'LOW' : ($row['totalSkips'] < 50 ? 'MEDIUM' : 'HIGH');
            $currentWeight = (int) ($row['currentWeight'] ?? 0);
            $recommendedWeight = $currentWeight;
            $reason = 'Mixed result; keep current';
            $recommendation = 'KEEP_CURRENT';

            if ($row['totalSkips'] < 20) {
                $recommendation = 'NEED_MORE_DATA';
                $reason = sprintf('%s has only %d samples.', $row['label'], $row['totalSkips']);
            } elseif ($missedWinRate > 0.60) {
                $recommendedWeight = max(0, $currentWeight - 2);
                $recommendation = 'REDUCE_BY_2';
                $reason = sprintf('%s caused %.0f%% missed wins over %d samples.', $row['label'], $missedWinRate * 100, $row['totalSkips']);
            } elseif ($missedWinRate >= 0.45) {
                $recommendedWeight = max(0, $currentWeight - 1);
                $recommendation = 'REDUCE_BY_1';
                $reason = sprintf('%s caused %.0f%% missed wins over %d samples.', $row['label'], $missedWinRate * 100, $row['totalSkips']);
            } elseif ($protectiveRate > 0.65) {
                $recommendedWeight = min(10, $currentWeight + 1);
                $recommendation = 'KEEP_OR_INCREASE';
                $reason = sprintf('%s correctly protected %.0f%% of reviewed skips.', $row['label'], $protectiveRate * 100);
            } elseif ($avoidedLossRate > 0.35) {
                $recommendation = 'KEEP_PROTECTION';
                $reason = sprintf('%s avoided losses in %.0f%% of reviewed skips.', $row['label'], $avoidedLossRate * 100);
            }

            return $row + [
                'accuracy' => round($protectiveRate, 4),
                'missedWinRate' => round($missedWinRate, 4),
                'correctSkipRate' => round($protectiveRate, 4),
                'avoidedLossRate' => round($avoidedLossRate, 4),
                'sampleSizeQuality' => $sampleQuality,
                'recommendation' => $recommendation,
                'recommendedWeight' => $recommendedWeight,
                'reason' => $reason,
            ];
        }, $blockerRows));

        usort($analytics, static fn (array $a, array $b) => ($b['missedWins'] <=> $a['missedWins']) ?: ($b['totalSkips'] <=> $a['totalSkips']));

        return [
            'reviewWindows' => array_values((array) ($settings['missedTradeAuditReviewWindows'] ?? [5, 10, 15, 30])),
            'defaultWindowMinutes' => (int) ($settings['missedTradeAuditWindowMinutes'] ?? 15),
            'totalSkippedSignals' => $rows->count(),
            'pendingReview' => $rows->where('status', 'PENDING_REVIEW')->count(),
            'reviewedSignals' => $reviewed->count(),
            'missedWins' => $missedWins,
            'correctSkips' => $correctSkips,
            'avoidedLosses' => $avoidedLosses,
            'neutral' => $neutral,
            'badSignals' => $badSignals,
            'missedWinRate' => $reviewed->count() > 0 ? round($missedWins / $reviewed->count(), 4) : null,
            'modeComparison' => $this->summarizeAuditModeComparisons($rows),
            'topBlockers' => array_slice($analytics, 0, 8),
            'recent' => $rows->take(12)->map(fn (FutureTradeMissedSignalAudit $audit) => [
                'id' => $audit->id,
                'symbol' => $audit->symbol,
                'side' => $audit->side,
                'signalTime' => optional($audit->signal_time)?->toIso8601String(),
                'finalConfidence' => (float) $audit->final_confidence,
                'activeMinimum' => (float) $audit->active_minimum,
                'confidenceGap' => (float) $audit->confidence_gap,
                'topSkipReason' => $audit->top_skip_reason,
                'topBlockers' => $audit->top_3_blockers ?? [],
                'status' => $audit->status,
                'reviewWindowMinutes' => (int) $audit->review_window_minutes,
                'firstHit' => $audit->first_hit,
                'wouldHaveResult' => $audit->would_have_result,
                'grossWouldHavePnlPercent' => $audit->gross_would_have_pnl_percent !== null ? (float) $audit->gross_would_have_pnl_percent : null,
                'netWouldHavePnlPercent' => $audit->net_would_have_pnl_percent !== null ? (float) $audit->net_would_have_pnl_percent : null,
                'wouldHavePnlPercent' => $audit->would_have_pnl_percent !== null ? (float) $audit->would_have_pnl_percent : null,
                'modeComparison' => $audit->mode_comparison_json ?? [],
            ])->values()->all(),
        ];
    }

    private function indicatorValidationSnapshot(FutureTradeBotConfig $config, array $settings, ?array $bestSignal, array $performance): array
    {
        $blockers = [];
        if (($settings['classicSimpleMode'] ?? false) === true) {
            $blockers[] = 'Indicators-off mode active: weighted indicators are intentionally bypassed.';
        }
        if (($bestSignal['signal'] ?? 'WAIT') === 'WAIT' && (float) ($bestSignal['confidence'] ?? 0) < 0.01) {
            $blockers[] = 'No usable ranked signal is present right now.';
        }

        $trackerAligned = count(array_filter($performance, fn (array $item): bool => ((float) ($item['trackerAlignmentRate'] ?? 0.0)) >= 0.55));
        $highRated = count(array_filter($performance, fn (array $item): bool => in_array((string) ($item['rating'] ?? 'E'), ['A', 'B'], true)));

        return [
            'classicSimpleMode' => (bool) ($settings['classicSimpleMode'] ?? false),
            'normalGraphModeWorks' => true,
            'liveSignalFlowDetected' => $bestSignal !== null,
            'blockers' => $blockers,
            'trackerAlignedIndicators' => $trackerAligned,
            'highRatedIndicators' => $highRated,
            'overallReadinessScore' => max(0, min(100, (int) round(($trackerAligned * 6) + ($highRated * 8) + ($bestSignal ? 18 : 0) + ((($settings['classicSimpleMode'] ?? false) ? 12 : 18)) - (count($blockers) * 10)))),
        ];
    }

    private function settingsDiff(array $before, array $after): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys(Arr::dot($before)), array_keys(Arr::dot($after))));

        foreach ($keys as $key) {
            $oldValue = data_get($before, $key);
            $newValue = data_get($after, $key);
            if ($oldValue === $newValue) {
                continue;
            }

            $changes[$key] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    private function liveStatusPayload(FutureTradeBotConfig $config, FutureTradeBotState $state, ?array $bestSignal, array $signals, array $settings): array
    {
        $lastChartPoint = Arr::last((array) ($state->chart_points ?? []));
        $ageSeconds = $this->ageSecondsSince($state->last_scan_at);
        $feedPrice = $state->current_symbol ? $this->cachedLatestPrice((string) $state->current_symbol) : null;
        $hasSharedFeed = $feedPrice !== null;
        $feedFreshness = $this->feedFreshnessSnapshot((string) ($state->current_symbol ?: ($bestSignal['symbol'] ?? '')));
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
            'updatedAt' => $this->isoTimestamp(now()),
            'confidence' => (float) ($bestSignal['confidence'] ?? $state->confidence ?? 0),
        ]);

        return [
            'mode' => $trueWebsocketLive
                ? 'TRUE_WEBSOCKET_CACHE_LIVE'
                : ((bool) ($feedFreshness['feedFresh'] ?? false) ? (string) ($feedFreshness['feedMode'] ?? 'SHARED_FEED_CACHE') : ($hasSharedFeed ? 'SHARED_FEED_CACHE' : 'REST_POLLING')),
            'priceUpdateMode' => 'SCAN_LOOP',
            'indicatorMode' => 'FORMING_CANDLE',
            'lastUpdateTime' => $this->isoTimestamp($state->last_scan_at),
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

    /**
     * Compact runtime-health snapshot for the dashboard. Surfaces blockers so
     * the user can see WHY the bot is sitting on a signal:
     *   - feedFresh / feedAgeSeconds
     *   - openLogicalTrades / activeBlockers
     *   - signal stability state (raw/confirmed/executable)
     */
    private function buildRuntimeHealthSnapshot(FutureTradeBotConfig $config, FutureTradeBotState $state, ?array $bestSignal): array
    {
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
        $webhookAuthority = in_array($this->normalizedSignalSource($settings), ['webhook', 'webhook_fallback'], true);
        $symbol = (string) ($state->current_symbol ?? ($bestSignal['symbol'] ?? ''));
        $feed = $this->feedFreshnessSnapshot($symbol);
        $freshness = $this->preExecutionFreshnessSnapshot($config, $bestSignal);
        $openLogical = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
            ->count();
        $reconcileRequired = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->where('status', FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED)
            ->count();

        $userStream = (array) ($freshness['userStream'] ?? $this->userStreamSvc()->snapshot($config));
        $restSync = (array) ($freshness['restSync'] ?? []);
        $isLive = $this->isLiveMode($config);

        $blockers = [];
        if (! $webhookAuthority && ! ($feed['feedFresh'] ?? false)) {
            $blockers[] = 'STALE_FEED';
        }
        if ($openLogical > 0) {
            $blockers[] = 'OPEN_POSITION_EXISTS';
        }
        if ($reconcileRequired > 0) {
            $blockers[] = 'RECONCILIATION_REQUIRED';
        }
        if ($state->cooldown_until && $state->cooldown_until->isFuture()) {
            $blockers[] = 'COOLDOWN_ACTIVE';
        }
        if ($state->daily_stop_until && $state->daily_stop_until->isFuture()) {
            $blockers[] = 'DAILY_LOSS_LOCKED';
        }
        foreach ((array) ($freshness['blockers'] ?? []) as $freshnessBlocker) {
            $blockers[] = Str::upper((string) $freshnessBlocker);
        }

        return [
            'feedFresh' => (bool) ($feed['feedFresh'] ?? false),
            'feedAgeSeconds' => (int) ($feed['feedAgeSeconds'] ?? 0),
            'feedMode' => $feed['feedMode'] ?? 'unknown',
            'lastTickAt' => $feed['lastTickAt'] ?? null,
            'lastTickPrice' => $feed['lastTickPrice'] ?? null,
            'openLogicalTrades' => $openLogical,
            'reconciliationRequired' => $reconcileRequired,
            'cooldownUntil' => optional($state->cooldown_until)->toIso8601String(),
            'dailyLossLocked' => (bool) ($state->daily_stop_until && $state->daily_stop_until->isFuture()),
            'liveMode' => $isLive,
            'userStream' => $userStream,
            'restSync' => $restSync,
            'freshnessGateSource' => $freshness['freshnessGateSource'] ?? 'blocked',
            'user_stream_last_seen_at' => $userStream['lastEventAt'] ?? null,
            'user_stream_heartbeat_age_sec' => $userStream['heartbeatAgeSeconds'] ?? null,
            'binance_state_synced_at' => $restSync['syncedAt'] ?? null,
            'binance_state_age_sec' => $restSync['ageSeconds'] ?? null,
            'freshness_gate_source' => $freshness['freshnessGateSource'] ?? 'blocked',
            'webhook' => $this->latestWebhookTelemetry($config),
            'activeBlockers' => array_values(array_unique($blockers)),
            'healthy' => $blockers === [],
        ];
    }

    private function latestWebhookTelemetry(FutureTradeBotConfig $config): array
    {
        $latest = $config->logs()
            ->whereIn('event', ['PINE_WEBHOOK_SIGNAL', 'PINE_WEBHOOK_WAIT', 'PINE_WEBHOOK_CLOSE', 'PINE_WEBHOOK_REJECTED'])
            ->orderByDesc('occurred_at')
            ->first();

        if (! $latest) {
            return [
                'state' => 'waiting',
                'summary' => 'No TradingView webhook received yet.',
                'latestEvent' => null,
                'ageSeconds' => null,
                'signalLatencyMs' => null,
                'transportLatencyMs' => null,
            ];
        }

        $context = $this->arrayContext($latest->context ?? []);
        $timing = (array) ($context['webhookTiming'] ?? []);
        $age = $this->ageSecondsSince($latest->occurred_at);
        $signalLatency = is_numeric($timing['signalLatencyMs'] ?? null) ? (float) $timing['signalLatencyMs'] : null;
        $transportLatency = is_numeric($timing['transportLatencyMs'] ?? null) ? (float) $timing['transportLatencyMs'] : null;
        $state = 'healthy';
        $summary = 'Latest webhook latency is acceptable.';

        if ((string) $latest->event === 'PINE_WEBHOOK_REJECTED') {
            $state = 'warning';
            $summary = 'Latest webhook was rejected; check profile, symbol, timeframe, and source mode.';
        } elseif ($signalLatency !== null && $signalLatency > 30000) {
            $state = 'warning';
            $summary = 'Webhook arrived more than 30 seconds after candle close.';
        } elseif ($age !== null && $age > 1800) {
            $state = 'stale';
            $summary = 'No recent webhook in the last 30 minutes.';
        }

        return [
            'state' => $state,
            'summary' => $summary,
            'latestEvent' => $latest->event,
            'symbol' => $latest->symbol,
            'signal' => $latest->signal,
            'reason' => $latest->reason,
            'occurredAt' => optional($latest->occurred_at)->toIso8601String(),
            'ageSeconds' => $age,
            'signalLatencyMs' => $signalLatency,
            'transportLatencyMs' => $transportLatency,
            'candleCloseAt' => $timing['candleCloseAt'] ?? ($context['candleClose'] ?? null),
        ];
    }

    /**
     * Compute the RAW / CONFIRMED / EXECUTABLE state for the dashboard's current
     * best signal so the UI can stop flashing 1-second BUY/SELL flips as the
     * executable signal.
     */
    private function buildSignalStabilitySnapshot(FutureTradeBotConfig $config, ?array $bestSignal, array $settings): array
    {
        if (! $bestSignal || ! isset($bestSignal['signal']) || $bestSignal['signal'] === '') {
            return [
                'raw' => 'WAIT',
                'confirmed' => 'WAIT',
                'executable' => 'WAIT',
                'reason' => 'no_signal',
                'confirmedScans' => 0,
                'sameCandleFlip' => false,
            ];
        }
        $tf = strtolower((string) ($settings['timeframe'] ?? '5m'));
        $state = $this->stabilitySvc()->observeRaw($config, [
            'symbol' => (string) ($bestSignal['symbol'] ?? ''),
            'side' => (string) ($bestSignal['signal'] ?? 'WAIT'),
            'timeframe' => $tf,
            'candle_open' => (string) ($bestSignal['candle_open'] ?? $this->stabilitySvc()->currentCandleOpenIso($tf)),
            'confidence' => (float) ($bestSignal['confidence'] ?? 0),
        ]);
        return $state;
    }

    private function feedFreshnessSnapshot(?string $symbol = null): array
    {
        $symbol = strtoupper(trim((string) $symbol));
        $latestPrice = $symbol !== '' ? $this->cachedLatestPrice($symbol) : null;
        $priceAge = null;
        if (is_array($latestPrice) && (int) ($latestPrice['timestamp_ms'] ?? 0) > 0) {
            $priceAge = $this->ageSecondsSinceTimestampMs((int) $latestPrice['timestamp_ms']);
        }

        $latestFeed = MarketFeedStatus::query()
            ->where('market_type', 'futures')
            ->when($symbol !== '', fn ($query) => $query->where('symbol', $symbol))
            ->whereNotNull('last_tick_at')
            ->orderByDesc('last_tick_at')
            ->first();

        $feedAge = $this->ageSecondsSince($latestFeed?->last_tick_at);
        $age = $priceAge !== null && $feedAge !== null ? min($priceAge, $feedAge) : ($priceAge ?? $feedAge);
        $lastTickAt = $latestPrice && (int) ($latestPrice['timestamp_ms'] ?? 0) > 0
            ? $this->isoTimestamp(Carbon::createFromTimestampMs((int) $latestPrice['timestamp_ms']))
            : $this->isoTimestamp($latestFeed?->last_tick_at);
        $lastTickPrice = (float) ($latestPrice['price'] ?? $latestFeed?->last_price ?? 0) ?: null;
        $source = (string) ($latestPrice['source'] ?? $latestFeed?->source ?? 'none');
        $fresh10 = $age !== null && $age <= 10;
        $fresh20 = $age !== null && $age <= 20;

        return [
            'feedMode' => $fresh10
                ? 'TRUE_WEBSOCKET_CACHE_LIVE'
                : ($fresh20 ? 'TRUE_WEBSOCKET_CACHE_LIVE_MANUAL' : ($age !== null && $age <= 60 ? 'REST_FALLBACK' : 'STALE')),
            'feedFresh' => $fresh20,
            'feedAgeSeconds' => (int) ($age ?? 0),
            'lastTickAt' => $lastTickAt,
            'lastTickPrice' => $lastTickPrice,
            'source' => $source,
        ];
    }

    private function ageSecondsSince(mixed $timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            $time = $timestamp instanceof \DateTimeInterface
                ? Carbon::instance($timestamp)
                : Carbon::parse((string) $timestamp, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return max(0, now(config('app.timezone'))->getTimestamp() - $time->getTimestamp());
    }

    private function ageSecondsSinceTimestampMs(int $timestampMs): ?int
    {
        if ($timestampMs <= 0) {
            return null;
        }

        return max(0, (int) floor((now(config('app.timezone'))->getTimestampMs() - $timestampMs) / 1000));
    }

    private function isoTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            $time = $timestamp instanceof \DateTimeInterface
                ? Carbon::instance($timestamp)
                : Carbon::parse((string) $timestamp, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $time->timezone(config('app.timezone'))->toIso8601String();
    }

    private function strategyModeLabel(array $settings): string
    {
        if ((bool) ($settings['classicSimpleMode'] ?? false)) {
            return 'CLASSIC_SIMPLE';
        }

        return ((int) ($settings['corePassRule'] ?? 3)) <= 2 ? 'CORE_ONLY' : 'FULL_INDICATOR';
    }

    private function cachedAccountSummaryForStatus(FutureTradeBotConfig $config): array
    {
        return Cache::remember('future_trade_light_account_' . $config->id, 10, fn () => $this->accountSummary($config));
    }

    private function minimumQuantityPrecheck(FutureTradeBotConfig $config, array $settings, FutureTradeBotState $state, ?array $bestSignal): ?array
    {
        $symbol = strtoupper(trim((string) ($state->current_symbol ?: ($bestSignal['symbol'] ?? ''))));
        $price = (float) ($state->current_price ?: ($bestSignal['price'] ?? 0));
        if ($symbol === '' || $price <= 0) {
            return null;
        }

        $filters = ['stepSize' => $symbol === 'BTCUSDT' ? 0.001 : 0.001, 'minNotional' => FuturesOrderSizingService::BINANCE_MIN_OPEN_NOTIONAL];
        $leverage = max(1, min((int) ($settings['leverage'] ?? 1), (int) ($settings['maxLeverage'] ?? 5)));
        $tradeAmount = (float) ($settings['tradeAmountUsdt'] ?? 25);
        $sizing = $this->orderSizing->evaluate(($tradeAmount * $leverage) / max($price, 0.00000001), $price, $filters);
        $recommended = $this->minimumSafeTradeAmountUsdt($tradeAmount, $leverage, $price, $filters);

        return [
            'symbol' => $symbol,
            'price' => $price,
            'stepSize' => (float) ($sizing['stepSize'] ?? $filters['stepSize']),
            'minQty' => (float) ($sizing['stepSize'] ?? $filters['stepSize']),
            'tradeAmountUsdt' => $tradeAmount,
            'leverage' => $leverage,
            'notional' => (float) ($sizing['notional'] ?? 0),
            'minimumRequiredTradeAmountUsdt' => $recommended,
            'recommendedTradeAmountUsdt' => $recommended,
            'canTrade' => (float) ($sizing['quantity_float'] ?? 0) > 0 && (float) ($sizing['notional'] ?? 0) >= (float) ($sizing['minOpenNotional'] ?? 0),
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
            return 0.0;
        }
        $winRate = (int) $stats->wins / (int) $stats->total;
        if ($winRate < 0.28) {
            return -0.04;
        }
        if ($winRate < 0.40) {
            return -0.02;
        }
        if ($winRate >= 0.65) {
            return +0.04;
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
        $settings = $this->normalizeFutureTradeSettings($settings);
        if ($this->isPrimeCryptoProfile($settings)) {
            return $this->primePineRuntimeDecision($settings, $best);
        }

        $mode = strtolower((string) ($settings['confidenceMode'] ?? 'fixed'));
        if (! in_array($mode, ['fixed', 'semi_dynamic', 'fully_dynamic'], true)) {
            $mode = 'fixed';
        }
        $classicSimpleMode = (bool) ($settings['classicSimpleMode'] ?? false);
        if ($classicSimpleMode) {
            $mode = 'fixed';
        }
        $strictEntryGuards = (bool) ($settings['strictEntryGuards'] ?? false);
        $timeframe = strtolower((string) ($settings['timeframe'] ?? '5m'));
        $isFastTimeframe = in_array($timeframe, ['1m', '3m', '5m'], true);

        $base = (float) ($settings['minConfidence'] ?? 0.72);
        $floor = (float) ($settings['dynamicMinConfidenceFloor'] ?? 0.68);
        $ceiling = (float) ($settings['dynamicMinConfidenceCeiling'] ?? 0.88);
        if ($classicSimpleMode) {
            $base = min($base, 0.50);
            $floor = min($floor, 0.45);
            $ceiling = min($ceiling, 0.55);
        }
        if (! $strictEntryGuards) {
            $base = min($base, 0.58);
            $floor = min($floor, 0.52);
            $ceiling = min($ceiling, 0.64);
        }
        if ($floor > $ceiling) {
            [$floor, $ceiling] = [$ceiling, $floor];
        }

        $analysis = $this->trendAnalysis($config, $state, $signals, $best, $settings);
        $effective = $base;
        $adjustments = [];
        $next = $settings;
        $baseTakeProfit = (float) ($settings['takeProfitPercent'] ?? 0.45);
        $baseStopLoss = (float) ($settings['stopLossPercent'] ?? 0.25);
        $baseTrailing = (float) ($settings['trailingTakeProfitPercent'] ?? 0.18);
        $baseRollback = (float) ($settings['rollbackLossPercent'] ?? 0.18);
        $baseCooldown = max(10, (int) ($settings['cooldownSeconds'] ?? 60));
        $baseTradeAmount = max(25.0, (float) ($settings['tradeAmountUsdt'] ?? 25));
        $dynamicWeights = (array) ($settings['indicatorInfluence'] ?? []);
        $recentAccuracyWeight = $this->influenceMultiplier('recentAccuracyWeight', $dynamicWeights);
        $lossStreakWeight = $this->influenceMultiplier('lossStreakWeight', $dynamicWeights);
        $atrWeight = $this->influenceMultiplier('atrWeight', $dynamicWeights);
        $volatilityWeight = $this->influenceMultiplier('volatilityWeight', $dynamicWeights);

        if ($mode !== 'fixed') {
            if ($mode === 'semi_dynamic') {
                $winRate = (float) ($analysis['winRate'] ?? 0);
                $sampleWeight = $this->clamp((float) ($analysis['winRateSampleWeight'] ?? 0), 0.0, 1.0);
                $signalQuality = (float) ($analysis['signalQuality'] ?? 0.0);
                if ($winRate > 0 && $sampleWeight >= 0.05) {
                    if ($winRate < 42) {
                        $effective += (0.008 + (0.018 * $sampleWeight)) * $recentAccuracyWeight;
                        $next['tradeAmountUsdt'] = max(25, $baseTradeAmount * (1 - (0.05 + (0.10 * $sampleWeight))));
                        $adjustments[] = sprintf('Semi-dynamic: weak recent accuracy over %d closes raises confidence slightly.', (int) ($analysis['totalTrades'] ?? 0));
                    } elseif ($winRate >= 58) {
                        $effective -= (0.004 + (0.012 * $sampleWeight)) * $recentAccuracyWeight;
                        $adjustments[] = sprintf('Semi-dynamic: healthy recent accuracy over %d closes allows a small threshold reduction.', (int) ($analysis['totalTrades'] ?? 0));
                    }
                }
                if ($signalQuality < 0.42) {
                    $effective += 0.018;
                    $adjustments[] = 'Semi-dynamic: weak signal quality asks for slightly cleaner entries.';
                } elseif ($signalQuality >= 0.68) {
                    $effective -= 0.01;
                    $adjustments[] = 'Semi-dynamic: strong live signal quality allows a small threshold reduction.';
                }
                if (($analysis['lastTradeReason'] ?? null) && str_contains((string) $analysis['lastTradeReason'], 'LOSS')) {
                    $effective += 0.015 * $lossStreakWeight;
                    $next['cooldownSeconds'] = max($baseCooldown, (int) round(75 * max(0.8, $lossStreakWeight)));
                    $adjustments[] = 'Semi-dynamic: recent loss applies a mild confidence and cooldown increase.';
                } elseif (($analysis['lastTradeReason'] ?? null) && str_contains((string) $analysis['lastTradeReason'], 'TAKE_PROFIT')) {
                    $effective += (float) ($settings['dynamicReentryBuffer'] ?? 0.02) * 0.5;
                    $adjustments[] = 'Semi-dynamic: recent take profit keeps a small re-entry buffer.';
                }
            } else {
                $signalQuality = (float) ($analysis['signalQuality'] ?? 0.0);
                if ($analysis['choppy']) {
                    $effective += 0.02;
                    $next['takeProfitPercent'] = max(0.55, $baseTakeProfit * 0.82);
                    $next['trailingTakeProfitPercent'] = max(0.03, $baseTrailing * 0.85);
                    $adjustments[] = 'Choppy market: require stronger confidence and take faster profit.';
                } elseif ($analysis['trendStrength'] >= 0.68 && $analysis['directionAgreement'] >= 0.58) {
                    $effective -= 0.045;
                    $next['takeProfitPercent'] = $baseTakeProfit * 1.20;
                    $next['trailingTakeProfitPercent'] = $baseTrailing * 1.08;
                    $adjustments[] = 'Strong aligned trend: allow earlier entry and let target breathe.';
                } else {
                    $effective += $isFastTimeframe ? 0.003 : 0.005;
                    $adjustments[] = 'Mixed signal: keep entry slightly stricter.';
                }
                if ($signalQuality < 0.40) {
                    $effective += 0.02;
                    $adjustments[] = 'Low signal quality: raise confidence until the scanner quality improves.';
                } elseif ($signalQuality >= 0.72) {
                    $effective -= 0.015;
                    $adjustments[] = 'High signal quality: allow earlier entries on strong live alignment.';
                }

                if ($strictEntryGuards && $analysis['confidenceGap'] < 0.025) {
                    $effective += 0.018;
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
                    $effective -= 0.012;
                    $adjustments[] = '5m scalp relief: aligned momentum allows a modest threshold reduction.';
                }

                if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                    $effective += 0.045 * $lossStreakWeight;
                    $next['cooldownSeconds'] = max($baseCooldown, (int) round(120 * max(0.8, $lossStreakWeight)));
                    $adjustments[] = 'Recent loss: raise threshold and extend cooldown.';
                } elseif ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'TAKE_PROFIT')) {
                    $effective += (float) ($settings['dynamicReentryBuffer'] ?? 0.02) * 0.7;
                    $adjustments[] = 'Recent take profit: require a re-entry buffer to avoid chasing.';
                }

                $winRate = $analysis['winRate'];
                $totalTrades = max(0, (int) ($analysis['totalTrades'] ?? 0));
                $sampleWeight = $this->clamp((float) ($analysis['winRateSampleWeight'] ?? 0), 0.0, 1.0);
                if ($totalTrades < 5) {
                    $adjustments[] = 'Limited trade sample: keep win-rate influence muted until more closes are recorded.';
                }

                if ($winRate > 0 && $winRate < 45 && $sampleWeight >= 0.05) {
                    $effective += (0.006 + (0.012 * $sampleWeight)) * $recentAccuracyWeight;
                    $next['tradeAmountUsdt'] = max(25, $baseTradeAmount * (1 - (0.10 + (0.15 * $sampleWeight))));
                    $adjustments[] = sprintf('Weak win rate over %d closed trades: reduce size and demand cleaner entries.', $totalTrades);
                } elseif ($winRate >= 62 && $analysis['trendStrength'] >= 0.55 && $sampleWeight >= 0.35) {
                    $effective -= (0.006 + (0.014 * $sampleWeight)) * $recentAccuracyWeight;
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
                $atrStop *= max(0.6, $atrWeight);
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
                $volatilityCooldown = (int) round($volatilityPercent * 160 * max(0.6, $volatilityWeight));
                $dynamicCooldown = (int) $this->clamp((float) max($cooldownBase, $volatilityCooldown), 45.0, 180.0);
                if ($analysis['lastTradeReason'] && str_contains($analysis['lastTradeReason'], 'LOSS')) {
                    $dynamicCooldown = max($dynamicCooldown, 120);
                }
                if ($strictEntryGuards && $totalTrades >= 6 && $winRate < 35) {
                    $effective = max($effective, 0.64);
                    $dynamicCooldown = max($dynamicCooldown, 180);
                    $next['tradeAmountUsdt'] = max(25, $baseTradeAmount * 0.8);
                    $adjustments[] = sprintf('Poor regime: %.2f%% win rate across %d trades forces stricter confidence and slower re-entry.', $winRate, $totalTrades);
                }
                if ($strictEntryGuards && (float) ($analysis['trendStrength'] ?? 0) < 0.45) {
                    $effective = max($effective, 0.62);
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
            if ($classicSimpleMode) {
                $adjustments[] = 'Classic simple mode: indicators, soft penalties, and dynamic inflation are bypassed for graph-based signals.';
            }
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

    private function primePineRuntimeDecision(array $settings, ?array $best): array
    {
        $scoreThreshold = (float) ($settings['tradeScoreThreshold'] ?? 72);
        $confidence = $scoreThreshold / 100;
        $risk = (array) data_get($best, 'debug.risk', []);
        $riskPercent = max(0.0, (float) ($risk['riskPercent'] ?? 0.0));
        $tp1RR = (float) ($settings['tp1RR'] ?? 1.0);
        $trailMultiplier = (float) ($settings['trailAtrMultiplier'] ?? 1.0);
        $useAtrRiskTargets = filter_var($settings['useAtrRiskTargets'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $useWebhookRiskOverrides = filter_var($settings['useWebhookRiskOverrides'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $webhookRisk = (array) data_get($best, 'debug.riskOverrides', []);

        $next = $settings;
        $next['confidenceMode'] = 'pine_mirror';
        $next['minConfidence'] = $confidence;
        $next['dynamicSuggestionEnabled'] = false;
        $next['strictEntryGuards'] = (bool) ($settings['strictEntryGuards'] ?? true);
        $next['avoidChoppyMarket'] = (bool) ($settings['avoidChoppyMarket'] ?? true);
        $next['weakSetupAction'] = 'hold';

        if ($riskPercent > 0 && $useAtrRiskTargets) {
            $next['stopLossPercent'] = round($riskPercent, 4);
            $next['rollbackLossPercent'] = round($riskPercent, 4);
            $next['takeProfitPercent'] = round(max($riskPercent * $tp1RR, $riskPercent), 4);
            $next['trailingTakeProfitPercent'] = round(max(0.01, $riskPercent * $trailMultiplier), 4);
        }
        if ($useWebhookRiskOverrides && $webhookRisk !== []) {
            foreach (['takeProfitPercent', 'stopLossPercent', 'trailingTakeProfitPercent', 'rollbackLossPercent'] as $key) {
                $value = $this->positivePercentOrNull($webhookRisk[$key] ?? null);
                if ($value !== null) {
                    $next[$key] = $value;
                }
            }
        }

        $public = [
            'mode' => 'pine_mirror',
            'baseMinConfidence' => $confidence,
            'effectiveMinConfidence' => $confidence,
            'recommended' => [
                'minConfidence' => $confidence,
                'takeProfitPercent' => (float) ($next['takeProfitPercent'] ?? $settings['takeProfitPercent'] ?? 0),
                'stopLossPercent' => (float) ($next['stopLossPercent'] ?? $settings['stopLossPercent'] ?? 0),
                'trailingTakeProfitPercent' => (float) ($next['trailingTakeProfitPercent'] ?? $settings['trailingTakeProfitPercent'] ?? 0),
                'cooldownSeconds' => (int) ($next['cooldownSeconds'] ?? 900),
                'tradeAmountUsdt' => (float) ($next['tradeAmountUsdt'] ?? 25),
            ],
            'analysis' => [
                'lookbackMinutes' => 15,
                'sampleCount' => 0,
                'usableSignalCount' => $best && ($best['signal'] ?? 'WAIT') !== 'WAIT' ? 1 : 0,
                'priceChangePercent' => (float) ($best['changePercent'] ?? 0),
                'volatilityPercent' => $riskPercent,
                'atrPercent' => (float) ($risk['atrPercent'] ?? 0),
                'direction' => (string) ($best['signal'] ?? 'WAIT'),
                'priceDirection' => (string) ($best['signal'] ?? 'WAIT'),
                'signalDirection' => (string) ($best['signal'] ?? 'WAIT'),
                'directionSource' => 'pine_v6_1_7_score',
                'directionAgreement' => 1.0,
                'confidenceGap' => max(0.0, (float) ($best['confidence'] ?? 0) - $confidence),
                'trendStrength' => max((float) data_get($best, 'debug.longScore', 0), (float) data_get($best, 'debug.shortScore', 0)) / 100,
                'choppy' => ! (bool) data_get($best, 'debug.checks.chopOk', true),
                'regime' => 'pine_mirror',
                'bestConfidence' => (float) ($best['confidence'] ?? 0),
                'winRate' => 0,
                'totalTrades' => 0,
                'winRateSampleWeight' => 0,
            ],
            'adjustments' => [
                $useWebhookRiskOverrides
                    ? 'Prime v6.1.7 Pine mirror: TradingView webhook decides direction; webhook risk values are allowed to override manual exits when present.'
                    : 'Prime v6.1.7 Pine mirror: TradingView webhook decides direction; manual Prime Risk values control exits unless ATR risk targets are explicitly enabled.',
            ],
            'suggestion' => 'Prime v6.1.7 Pine mirror is the active signal brain. Backend executes Pine score decisions and applies risk/freshness guards only.',
        ];

        return [
            'mode' => 'pine_mirror',
            'effectiveMinConfidence' => $confidence,
            'settings' => $next,
            'public' => $public,
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

    private function trendAnalysis(FutureTradeBotConfig $config, FutureTradeBotState $state, array $signals, ?array $best, array $settings): array
    {
        $scanInterval = max(1, (int) ($settings['scanIntervalSeconds'] ?? 3));
        $lookbackMinutes = max(1, min(10, (int) ($settings['dynamicLookbackMinutes'] ?? 10)));
        $limit = max(8, min(120, (int) ceil(($lookbackMinutes * 60) / $scanInterval)));
        $referenceSymbol = strtoupper((string) ($best['symbol'] ?? $state->current_symbol ?? ''));
        $allPoints = array_values((array) ($state->chart_points ?? []));
        $filteredPoints = $referenceSymbol !== ''
            ? array_values(array_filter($allPoints, fn (array $row) => strtoupper((string) ($row['symbol'] ?? '')) === $referenceSymbol))
            : $allPoints;
        $points = array_slice($filteredPoints !== [] ? $filteredPoints : $allPoints, -$limit);
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
        $signalQuality = $this->clamp(
            ($bestConfidence * 0.55)
            + ($agreement * 0.20)
            + min(0.12, $gap * 1.6)
            + min(0.13, max(0.0, $trendStrength - 0.25)),
            0.0,
            1.0
        );
        $stateSignal = (string) ($state->current_signal ?? '');
        $stateExitReasons = ['TAKE_PROFIT_HIT', 'TRAILING_PROFIT_HIT', 'STOP_LOSS_HIT', 'LOSS_ROLLBACK_TRIGGERED'];
        $stateReason = in_array($stateSignal, $stateExitReasons, true) ? $stateSignal : null;
        $lookbackTrades = max(6, min(50, (int) ($settings['recentAccuracyLookbackTrades'] ?? 20)));
        $recentTrades = $config->trades()
            ->whereNotNull('net_pnl')
            ->orderByDesc('created_at')
            ->limit($lookbackTrades)
            ->get(['net_pnl', 'reason']);
        $wins = $recentTrades->filter(fn ($trade) => (float) $trade->net_pnl > 0)->count();
        $losses = $recentTrades->filter(fn ($trade) => (float) $trade->net_pnl < 0)->count();
        $totalTrades = $wins + $losses;
        if ($totalTrades === 0) {
            $wins = max(0, (int) ($state->wins ?? 0));
            $losses = max(0, (int) ($state->losses ?? 0));
            $totalTrades = $wins + $losses;
        }
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
            'signalQuality' => round($signalQuality, 4),
            'choppy' => $choppy,
            'regime' => $regime,
            'bestConfidence' => round($bestConfidence, 4),
            'lastTradeReason' => $stateReason ?: $recentTrades->first()?->reason,
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

    private function positivePercentOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $numeric = (float) $value;
        if ($numeric <= 0) {
            return null;
        }

        return round($this->clamp($numeric, 0.01, 5.0), 4);
    }

    private function positionMoveStats(FutureTradeBotState $state): array
    {
        $entry = (float) ($state->entry_price ?? 0);
        $peak = (float) ($state->peak_price ?? 0);
        $bottom = (float) ($state->bottom_price ?? 0);
        $side = (string) ($state->position_side ?? '');

        if ($entry <= 0 || $side === '') {
            return [];
        }

        $peak = $peak > 0 ? $peak : $entry;
        $bottom = $bottom > 0 ? $bottom : $entry;
        $favorable = $side === 'SHORT'
            ? (($entry - $bottom) / $entry) * 100
            : (($peak - $entry) / $entry) * 100;
        $adverse = $side === 'SHORT'
            ? (($peak - $entry) / $entry) * 100
            : (($entry - $bottom) / $entry) * 100;

        return [
            'side' => $side,
            'entry' => round($entry, 8),
            'peak' => round($peak, 8),
            'bottom' => round($bottom, 8),
            'maxFavorableMovePercent' => round(max(0.0, $favorable), 4),
            'maxAdverseMovePercent' => round(max(0.0, $adverse), 4),
        ];
    }

    private function performanceFeedbackPayload(FutureTradeBotConfig $config): array
    {
        $trades = $config->trades()
            ->whereNotNull('exit_price')
            ->whereIn('status', ['FILLED', 'CLOSED'])
            ->where(function ($query): void {
                $query->where('side', 'like', 'CLOSE_%')
                    ->orWhere('side', 'like', 'LIVE_CLOSE_%');
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $sampleSize = $trades->count();
        if ($sampleSize === 0) {
            return [
                'sampleSize' => 0,
                'summary' => 'No closed trades yet. Collect 30-50 paper/testnet trades before tuning risk automatically.',
                'exitReasons' => [],
                'sides' => [],
                'latency' => null,
                'suggestions' => ['Collect closed trade data first.'],
            ];
        }

        $wins = 0;
        $net = 0.0;
        $latencies = [];
        $byReason = [];
        $bySide = [];
        $favorableBeforeLoss = [];

        foreach ($trades as $trade) {
            $tradeNet = (float) ($trade->net_pnl ?? 0);
            $isWin = $tradeNet > 0;
            $wins += $isWin ? 1 : 0;
            $net += $tradeNet;

            $reason = (string) ($trade->reason ?: 'UNKNOWN');
            $side = str_contains((string) $trade->side, 'SHORT') ? 'SHORT' : 'LONG';
            $meta = $this->arrayContext($trade->meta ?? []);
            $timing = (array) ($meta['webhook_timing'] ?? []);
            $signalLatency = $timing['signalLatencyMs'] ?? null;
            if (is_numeric($signalLatency)) {
                $latencies[] = (float) $signalLatency;
            }

            if (! isset($byReason[$reason])) {
                $byReason[$reason] = ['count' => 0, 'wins' => 0, 'netPnl' => 0.0];
            }
            $byReason[$reason]['count']++;
            $byReason[$reason]['wins'] += $isWin ? 1 : 0;
            $byReason[$reason]['netPnl'] += $tradeNet;

            if (! isset($bySide[$side])) {
                $bySide[$side] = ['count' => 0, 'wins' => 0, 'netPnl' => 0.0];
            }
            $bySide[$side]['count']++;
            $bySide[$side]['wins'] += $isWin ? 1 : 0;
            $bySide[$side]['netPnl'] += $tradeNet;

            if (! $isWin) {
                $moveStats = (array) ($meta['move_stats'] ?? []);
                if (is_numeric($moveStats['maxFavorableMovePercent'] ?? null)) {
                    $favorableBeforeLoss[] = (float) $moveStats['maxFavorableMovePercent'];
                }
            }
        }

        $formatBucket = static function (array $bucket): array {
            ksort($bucket);

            return array_map(static function (array $row): array {
                $count = max(1, (int) $row['count']);

                return [
                    'count' => (int) $row['count'],
                    'wins' => (int) $row['wins'],
                    'winRate' => round(((int) $row['wins'] / $count) * 100, 2),
                    'netPnl' => round((float) $row['netPnl'], 6),
                ];
            }, $bucket);
        };

        $stopHits = (int) ($byReason['STOP_LOSS_HIT']['count'] ?? 0);
        $tpHits = (int) ($byReason['TAKE_PROFIT_HIT']['count'] ?? 0);
        $trailHits = (int) ($byReason['TRAILING_PROFIT_HIT']['count'] ?? 0);
        $avgLatency = count($latencies) > 0 ? array_sum($latencies) / count($latencies) : null;
        $avgFavorableBeforeLoss = count($favorableBeforeLoss) > 0 ? array_sum($favorableBeforeLoss) / count($favorableBeforeLoss) : null;
        $suggestions = [];

        if ($sampleSize < 30) {
            $suggestions[] = 'Sample is still small; keep changes conservative until 30-50 closed trades.';
        }
        if ($stopHits / max(1, $sampleSize) > 0.45) {
            $suggestions[] = 'Stop-loss hits are high; reduce size or loosen SL only after checking max adverse move.';
        }
        if ($tpHits + $trailHits > $stopHits) {
            $suggestions[] = 'Profit exits are beating stop exits; keep current risk profile and monitor latency.';
        }
        if ($avgLatency !== null && $avgLatency > 30000) {
            $suggestions[] = 'Webhook signal latency is high; check TradingView alert delay and queue backlog.';
        }
        if ($suggestions === []) {
            $suggestions[] = 'No automatic risk change suggested from the current sample.';
        }

        return [
            'sampleSize' => $sampleSize,
            'wins' => $wins,
            'losses' => $sampleSize - $wins,
            'winRate' => round(($wins / max(1, $sampleSize)) * 100, 2),
            'netPnl' => round($net, 6),
            'avgNetPnl' => round($net / max(1, $sampleSize), 6),
            'exitReasons' => $formatBucket($byReason),
            'sides' => $formatBucket($bySide),
            'latency' => $avgLatency !== null ? [
                'avgSignalLatencyMs' => round($avgLatency, 2),
                'samples' => count($latencies),
            ] : null,
            'avgFavorableMoveBeforeLossPercent' => $avgFavorableBeforeLoss !== null ? round($avgFavorableBeforeLoss, 4) : null,
            'summary' => sprintf('Last %d closed trades: %.1f%% win rate, net %.4f USDT.', $sampleSize, ($wins / max(1, $sampleSize)) * 100, $net),
            'suggestions' => $suggestions,
        ];
    }

    private function arrayContext(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function sanitizeWebhookPayload(array $payload): array
    {
        $sanitized = $payload;
        foreach (['token', 'secret', 'webhook_token', 'api_key', 'api_secret', 'password'] as $key) {
            if (array_key_exists($key, $sanitized)) {
                $sanitized[$key] = '[masked]';
            }
        }

        return $sanitized;
    }

    private function maskWebhookRawBody(string $rawBody): string
    {
        if ($rawBody === '') {
            return '';
        }

        return (string) preg_replace(
            '/("(?:token|secret|webhook_token|api_key|api_secret|password)"\s*:\s*")[^"]*(")/i',
            '$1[masked]$2',
            $rawBody
        );
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

        return $this->clamp(max($chartAtr * 1.35, $signalRange * 0.22, $chartAtr > 0 ? 0.08 : 0.12), 0.08, 15.0);
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
            Cache::put($cacheKey, $feedCandles, now()->addSeconds(2));
            return $feedCandles;
        }

        try {
            $base = rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/');
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

            Cache::put($cacheKey, $candles, now()->addSeconds(4));

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
                'preset' => 'AutoFinX Prime v6.1.7',
                'state' => 'warming_up',
                'summary' => 'Not enough live candles for Prime v6.1.7 confirmation yet.',
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
            'preset' => 'AutoFinX Prime v6.1.7',
            'state' => $executionReady ? 'confirmed' : (in_array($signal, ['BUY', 'SELL'], true) ? 'mixed' : 'waiting'),
            'summary' => $executionReady
                ? 'Prime v6.1.7 confirmation layer agrees with the active scanner direction.'
                : (in_array($signal, ['BUY', 'SELL'], true)
                    ? 'Scanner found a direction, but Prime v6.1.7 confirmations are still mixed.'
                    : 'Prime v6.1.7 is waiting for a cleaner execution setup.'),
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

    private function runtimeContext(?array $dynamic, array $extra = [], ?array $signal = null, array $hardBlockers = []): array
    {
        $public = (array) ($dynamic['public'] ?? $dynamic ?? []);
        $recommended = (array) ($public['recommended'] ?? []);
        $analysis = (array) ($public['analysis'] ?? []);
        $signalDebug = (array) (($signal['debug'] ?? []));
        $componentScores = (array) ($signalDebug['componentScores'] ?? []);
        $softPenalties = $signal ? $this->softPenaltiesFromSignal($signal) : [];
        $checks = (array) ($signalDebug['checks'] ?? []);

        return array_filter(array_merge([
            'algorithmMode' => $public['mode'] ?? null,
            'algorithmFlag' => isset($public['mode']) ? $this->algorithmFlag((string) $public['mode']) : null,
            'effectiveMinConfidence' => $public['effectiveMinConfidence'] ?? null,
            'activeConfidenceMin' => $public['effectiveMinConfidence'] ?? null,
            'activeTakeProfitPercent' => $recommended['takeProfitPercent'] ?? null,
            'activeStopLossPercent' => $recommended['stopLossPercent'] ?? null,
            'activeTrailingTakeProfitPercent' => $recommended['trailingTakeProfitPercent'] ?? null,
            'activeCooldownSeconds' => $recommended['cooldownSeconds'] ?? null,
            'activeTradeAmountUsdt' => $recommended['tradeAmountUsdt'] ?? null,
            'trendStrength' => $analysis['trendStrength'] ?? null,
            'directionAgreement' => $analysis['directionAgreement'] ?? null,
            'choppy' => $analysis['choppy'] ?? null,
            'vwapAligned' => $checks['vwapAligned'] ?? null,
            'emaAligned' => $checks['emaAligned'] ?? null,
            'adxValue' => $checks['adxValue'] ?? null,
            'macdDirection' => $checks['macdDirection'] ?? null,
            'rsiValue' => $checks['rsiValue'] ?? null,
            'volumeAligned' => $checks['volumeAligned'] ?? null,
            'volumeRatio' => $checks['volumeRatio'] ?? null,
            'htfAligned' => $checks['htfAligned'] ?? null,
            'softPenaltyCount' => count($softPenalties),
            'softPenalties' => $softPenalties ?: null,
            'hardBlocker' => $hardBlockers[0] ?? null,
            'hardBlockers' => $hardBlockers ?: null,
            'positiveFactors' => $signalDebug['positiveFactors'] ?? null,
            'negativeFactors' => $signalDebug['negativeFactors'] ?? null,
            'componentScores' => $componentScores ?: null,
            'componentBreakdown' => $signalDebug['componentBreakdown'] ?? null,
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
            'pine_mirror' => 'PINE',
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
        $settings = $this->normalizeFutureTradeSettings($config->mergedSettings());
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
        // Source of truth: closed logical trades only. Falls back to legacy
        // future_trade_bot_trades rows when no logical trades exist (e.g. early
        // installs that have not opened a fresh logical trade yet) — but in
        // that fallback we filter to FILLED-status CLOSE rows to avoid the
        // historical double-count where entry rows shared net_pnl with close
        // rows.
        $realized = $this->pnlSvc()->realizedSummary($config);
        $botProfit = (float) $realized['botProfit'];
        $botLoss = (float) $realized['botLoss'];

        $summary = [
            'walletBalance' => null,
            'availableBalance' => null,
            'marginBalance' => null,
            'botProfit' => $botProfit,
            'botLoss' => $botLoss,
            'totalProfit' => $botProfit - $botLoss,
            'realizedSource' => ((int) ($realized['legacyClosedCount'] ?? 0) > 0 && (int) ($realized['logicalClosedCount'] ?? 0) > 0)
                ? 'LOGICAL_TRADES_WITH_LEGACY_CLOSE_ROWS'
                : ((int) ($realized['logicalClosedCount'] ?? 0) > 0 ? 'LOGICAL_TRADES' : 'LEGACY_CLOSE_ROWS'),
            'openLogicalTrades' => (int) $realized['openPositions'],
            'reconciliationRequired' => (int) $realized['reconciliationRequired'],
            'balanceSource' => $this->isLiveMode($config) ? 'BINANCE_FUTURES' : 'PAPER_LEDGER',
            'accountAsset' => 'USDT',
            'balanceError' => null,
            'balanceWarning' => null,
            'balanceTransient' => false,
            'binanceStateSyncedAt' => null,
            'binanceStateAgeSec' => null,
        ];

        if (! $this->isLiveMode($config)) {
            return $summary;
        }

        try {
            $cacheKey = 'future_trade_account_summary:' . $config->workspace_id . ':' . ($config->executionMode());
            $staleCacheKey = $cacheKey . ':stale';
            $metaCacheKey = $cacheKey . ':meta';
            $transientMarkerKey = $cacheKey . ':transient_warning';
            $account = $this->cacheRemember($cacheKey, 10, function () use ($config, $staleCacheKey, $metaCacheKey) {
                $fresh = $this->liveClient($config->mergedSettings())->fetchAccountInfo();
                $this->cachePut($staleCacheKey, $fresh, 300);
                $this->cachePut($metaCacheKey, ['synced_at' => now()->toIso8601String()], 300);

                return $fresh;
            });
            $meta = (array) ($this->cacheGet($metaCacheKey, []) ?: []);
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
            $summary['binanceStateSyncedAt'] = $meta['synced_at'] ?? null;
            $summary['binanceStateAgeSec'] = $summary['binanceStateSyncedAt']
                ? max(0, Carbon::parse((string) $summary['binanceStateSyncedAt'])->diffInSeconds(now()))
                : null;
            if ($this->cachePull($transientMarkerKey)) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'BINANCE_REST_RECOVERED',
                    $config->state?->current_symbol,
                    null,
                    0,
                    'Binance futures balance sync recovered after transient network error.',
                    ['status' => 'RECOVERED'],
                    300
                );
            }
        } catch (Throwable $e) {
            $cacheKey = 'future_trade_account_summary:' . $config->workspace_id . ':' . ($config->executionMode());
            $staleAccount = $this->cacheGet($cacheKey . ':stale');
            $meta = (array) ($this->cacheGet($cacheKey . ':meta', []) ?: []);
            $isTransient = $this->isTransientNetworkException($e);
            $summary['balanceTransient'] = $isTransient;
            $summary['balanceError'] = $e->getMessage();
            $summary['binanceStateSyncedAt'] = $meta['synced_at'] ?? null;
            $summary['binanceStateAgeSec'] = $summary['binanceStateSyncedAt']
                ? max(0, Carbon::parse((string) $summary['binanceStateSyncedAt'])->diffInSeconds(now()))
                : null;
            if (is_array($staleAccount) && $staleAccount !== []) {
                $asset = collect((array) ($staleAccount['assets'] ?? []))
                    ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === 'USDT');

                $summary['walletBalance'] = isset($staleAccount['totalWalletBalance'])
                    ? (float) $staleAccount['totalWalletBalance']
                    : (isset($asset['walletBalance']) ? (float) $asset['walletBalance'] : null);
                $summary['availableBalance'] = isset($staleAccount['availableBalance'])
                    ? (float) $staleAccount['availableBalance']
                    : (isset($asset['availableBalance']) ? (float) $asset['availableBalance'] : null);
                $summary['marginBalance'] = isset($staleAccount['totalMarginBalance'])
                    ? (float) $staleAccount['totalMarginBalance']
                    : (isset($asset['marginBalance']) ? (float) $asset['marginBalance'] : null);
                $summary['accountAsset'] = strtoupper((string) ($asset['asset'] ?? 'USDT')) ?: 'USDT';
                $summary['balanceSource'] = 'BINANCE_CACHE_STALE';
                $summary['balanceWarning'] = 'Using cached Binance futures balance due to temporary network/DNS issue.';
            } else {
                $summary['balanceSource'] = $isTransient ? 'BINANCE_TEMPORARILY_UNAVAILABLE' : 'BINANCE_UNAVAILABLE';
                $summary['balanceWarning'] = $isTransient ? 'Temporary Binance balance sync issue. Auto-retry will continue.' : null;
            }

            if ($isTransient) {
                $this->cachePut($cacheKey . ':transient_warning', true, 600);
            }

            if (str_contains(strtolower($e->getMessage()), 'binance_rest_circuit_open')) {
                $this->logThrottled(
                    $config->fresh('state'),
                    'BINANCE_REST_CIRCUIT_OPEN',
                    $config->state?->current_symbol,
                    null,
                    0,
                    $e->getMessage(),
                    ['status' => 'DEGRADED', 'balanceSource' => $summary['balanceSource']],
                    60
                );
            }

            $this->logThrottled(
                $config->fresh('state'),
                $isTransient ? 'BALANCE_SYNC_WARNING' : 'BALANCE_SYNC_FAILED',
                $config->state?->current_symbol,
                null,
                0,
                $e->getMessage(),
                ['status' => $isTransient ? 'WARNING' : 'ERROR', 'transient' => $isTransient, 'balanceSource' => $summary['balanceSource']],
                $isTransient ? 180 : 60
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

    private function primeMirrorExecutionBlocked(FutureTradeBotConfig $config, array $settings, array $signal): bool
    {
        if (! $this->isPrimeCryptoProfile($settings)) {
            return false;
        }

        if ((string) ($signal['source'] ?? '') === 'pine_webhook') {
            return false;
        }

        $signalSource = $this->normalizedSignalSource($settings);
        if ($signalSource === 'webhook') {
            return true;
        }
        if ($signalSource === 'webhook_fallback' && ! $this->webhookFallbackEnabled($settings)) {
            return true;
        }

        if ($this->isLiveMode($config)) {
            return ! $this->primeMirrorLiveEnabled($settings);
        }

        return ! $this->primeMirrorExecutionEnabled($settings);
    }

    private function normalizedSignalSource(array $settings): string
    {
        $source = strtolower(trim((string) ($settings['signalSource'] ?? 'webhook')));

        return match ($source) {
            'webhook', 'pine_webhook' => 'webhook',
            'webhook_fallback', 'webhook_with_mirror_fallback', 'hybrid', 'auto', 'fallback' => 'webhook_fallback',
            default => 'mirror',
        };
    }

    private function pineWebhookRecentlyReceived(FutureTradeBotConfig $config, array $settings): bool
    {
        $timeout = max(60, (int) ($settings['pineWebhookFallbackTimeoutSeconds'] ?? 1200));
        $latest = $config->logs()
            ->where('event', 'PINE_WEBHOOK_SIGNAL')
            ->orderByDesc('occurred_at')
            ->first(['occurred_at']);

        return $latest?->occurred_at !== null
            && $latest->occurred_at->greaterThanOrEqualTo(now()->subSeconds($timeout));
    }

    private function primeLiveExecutionBlocked(FutureTradeBotConfig $config, array $settings, array $signal): bool
    {
        return $this->primeMirrorExecutionBlocked($config, $settings, $signal);
    }

    private function primeMirrorExecutionEnabled(array $settings = []): bool
    {
        return filter_var($settings['primeMirrorExecutionEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var(config('future_trade.prime_mirror_execution_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function primeMirrorLiveEnabled(array $settings = []): bool
    {
        return filter_var($settings['primeMirrorLiveEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var(config('future_trade.prime_mirror_live_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function pineWebhookLiveEnabled(): bool
    {
        return filter_var(config('future_trade.pine_webhook_live_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function webhookFallbackEnabled(array $settings = []): bool
    {
        return filter_var($settings['webhookFallbackEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var(config('future_trade.webhook_fallback_enabled', false), FILTER_VALIDATE_BOOLEAN);
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

    private function isTransientNetworkException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach ([
            'curl error 6',
            'getaddrinfo',
            'could not resolve host',
            'temporary failure',
            'timed out',
            'connection refused',
            'connection reset',
            'failed to connect',
            'network is unreachable',
            'name or service not known',
            'binance_rest_circuit_open',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function cacheRemember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        if (Cache::getFacadeRoot() === null) {
            return $callback();
        }

        return Cache::remember($key, now()->addSeconds(max(1, $ttlSeconds)), $callback);
    }

    private function cacheGet(string $key, mixed $default = null): mixed
    {
        if (Cache::getFacadeRoot() === null) {
            return $default;
        }

        return Cache::get($key, $default);
    }

    private function cachePut(string $key, mixed $value, int $ttlSeconds): void
    {
        if (Cache::getFacadeRoot() === null) {
            return;
        }

        Cache::put($key, $value, now()->addSeconds(max(1, $ttlSeconds)));
    }

    private function cachePull(string $key, mixed $default = null): mixed
    {
        if (Cache::getFacadeRoot() === null) {
            return $default;
        }

        return Cache::pull($key, $default);
    }

    private function cacheForget(string $key): void
    {
        if (Cache::getFacadeRoot() === null) {
            return;
        }

        Cache::forget($key);
    }

    /**
     * Finalize the latency telemetry array (compute totalMs, emit WEBHOOK_LATENCY
     * log if total > slowMs threshold) and merge it into a webhook response.
     */
    private function finalizeWebhookLatency(
        FutureTradeBotConfig $config,
        array $signal,
        float $t0,
        array $latency,
        array $response,
        int $slowMs = 800
    ): array {
        $latency['totalMs'] = (int) round((microtime(true) - $t0) * 1000);
        if ($latency['validatedMs'] === null) {
            $latency['validatedMs'] = $latency['totalMs'];
        }
        if ($latency['totalMs'] >= $slowMs) {
            $this->logThrottled(
                $config,
                'WEBHOOK_LATENCY_BREAKDOWN',
                (string) ($signal['symbol'] ?? null),
                (string) ($signal['signal'] ?? null),
                0,
                sprintf('Slow webhook path: total %dms (validate %dms, state %sms, order %sms).',
                    $latency['totalMs'],
                    (int) ($latency['validatedMs'] ?? 0),
                    $latency['stateSyncedMs'] === null ? '-' : (string) $latency['stateSyncedMs'],
                    $latency['orderPlacedMs'] === null ? '-' : (string) $latency['orderPlacedMs']
                ),
                $latency,
                30
            );
        }
        // Always cache the latest latency snapshot so the UI panel can read it
        // even when no slow-path log was emitted.
        $this->cachePut('future_trade_last_webhook_latency:' . $config->workspace_id, $latency, 600);
        $response['latency'] = $latency;
        return $response;
    }

    /**
     * Cooldown is scoped to the side just closed. If the next webhook signal
     * is the opposite direction, treat it as a legitimate reversal entry and
     * bypass the cooldown. Same-side re-entry must wait out the cooldown.
     */
    private function lastClosedSideCacheKey(FutureTradeBotConfig $config): string
    {
        return 'future_trade_last_closed_side:' . $config->workspace_id . ':' . $config->id;
    }

    private function recordLastClosedSide(FutureTradeBotConfig $config, ?string $side, int $ttlSeconds): void
    {
        $normalized = strtoupper(trim((string) $side));
        if ($normalized !== 'LONG' && $normalized !== 'SHORT') {
            return;
        }
        $this->cachePut($this->lastClosedSideCacheKey($config), $normalized, max(60, $ttlSeconds));
    }

    private function readLastClosedSide(FutureTradeBotConfig $config): ?string
    {
        $value = $this->cacheGet($this->lastClosedSideCacheKey($config));
        if (! is_string($value) || $value === '') {
            return null;
        }
        $normalized = strtoupper($value);
        return in_array($normalized, ['LONG', 'SHORT'], true) ? $normalized : null;
    }

    /**
     * Returns true when the incoming signal direction is the opposite of the
     * most-recently-closed position. Used to bypass cooldown for legitimate
     * trend reversals from TradingView.
     */
    private function signalIsOppositeOfLastClose(FutureTradeBotConfig $config, ?string $signalSide): bool
    {
        $lastClosed = $this->readLastClosedSide($config);
        if ($lastClosed === null) {
            return false;
        }
        $incoming = strtoupper(trim((string) $signalSide));
        $incomingSide = match ($incoming) {
            'BUY', 'LONG' => 'LONG',
            'SELL', 'SHORT' => 'SHORT',
            default => null,
        };
        return $incomingSide !== null && $incomingSide !== $lastClosed;
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
        if (str_starts_with($event, 'PINE_WEBHOOK') || $event === 'WEBHOOK_RAW_RECEIVED') {
            Log::info('[' . $event . ']', [
                'workspace_id' => $config->workspace_id,
                'symbol' => $symbol,
                'signal' => $signal,
                'confidence' => $confidence,
                'reason' => $reason,
                'source' => $context['source'] ?? null,
                'profile' => $context['profile'] ?? null,
                'signal_source' => $context['signalSource'] ?? null,
                'candle_open' => $context['candleOpen'] ?? null,
                'candle_close' => $context['candleClose'] ?? null,
                'webhook_timing' => $context['webhookTiming'] ?? null,
                'parse_status' => $context['parseStatus'] ?? null,
                'token_status' => $context['tokenStatus'] ?? null,
                'raw_body_preview' => $event === 'WEBHOOK_RAW_RECEIVED' ? ($context['rawBodyPreview'] ?? null) : null,
            ]);
        }

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

    private function logScanStatus(FutureTradeBotConfig $config, array $signal, array $dynamic): void
    {
        $public = (array) ($dynamic['public'] ?? []);
        $recommended = (array) ($public['recommended'] ?? []);
        $analysis = (array) ($public['analysis'] ?? []);
        $checks = (array) data_get($signal, 'debug.checks', []);
        $signalReason = (string) ($signal['reason'] ?? '');
        $isBlockedSignal = (string) ($signal['signal'] ?? 'WAIT') === 'WAIT';

        $this->logThrottled(
            $config,
            'SCAN_STATUS',
            (string) ($signal['symbol'] ?? null),
            (string) ($signal['signal'] ?? 'WAIT'),
            (float) ($signal['confidence'] ?? 0),
            'Scanner refreshed current runtime values.',
            [
                'status' => (string) ($signal['signal'] ?? 'WAIT'),
                'algorithmFlag' => 'PINE',
                'effectiveMinConfidence' => (float) ($dynamic['effectiveMinConfidence'] ?? $signal['effectiveMinConfidence'] ?? 0),
                'activeTakeProfitPercent' => (float) ($recommended['takeProfitPercent'] ?? 0),
                'activeStopLossPercent' => (float) ($recommended['stopLossPercent'] ?? 0),
                'activeTrailingTakeProfitPercent' => (float) ($recommended['trailingTakeProfitPercent'] ?? 0),
                'activeCooldownSeconds' => (int) ($recommended['cooldownSeconds'] ?? 0),
                'activeTradeAmountUsdt' => (float) ($recommended['tradeAmountUsdt'] ?? 0),
                'trendStrength' => (float) ($analysis['trendStrength'] ?? 0),
                'choppy' => (bool) ($analysis['choppy'] ?? false),
                'vwapAligned' => $checks['vwapAligned'] ?? null,
                'emaAligned' => $checks['emaAligned'] ?? null,
                'adxValue' => $checks['adxValue'] ?? null,
                'macdDirection' => $checks['macdDirection'] ?? null,
                'rsiValue' => $checks['rsiValue'] ?? null,
                'volumeAligned' => $checks['volumeAligned'] ?? null,
                'volumeRatio' => $checks['volumeRatio'] ?? null,
                'htfAligned' => $checks['htfAligned'] ?? null,
                'decisionReason' => $signalReason,
                'hardBlocker' => $isBlockedSignal ? $signalReason : null,
                'hardBlockers' => $isBlockedSignal && $signalReason !== '' ? [$signalReason] : null,
            ],
            10
        );
    }

    private function emitConfigAuditLogs(FutureTradeBotConfig $config, array $changes, string|int|null $userId): void
    {
        foreach ($this->flattenAuditChanges($changes) as $entry) {
            Log::info('[FUTURE TRADE CONFIG UPDATED]', [
                'workspace_id' => $config->workspace_id,
                'setting_key' => $entry['key'],
                'old_value' => $entry['old'],
                'new_value' => $entry['new'],
                'timestamp' => now()->toIso8601String(),
                'user_id' => $userId,
            ]);
        }
    }

    private function flattenAuditChanges(array $changes, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($changes as $key => $value) {
            $path = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            if (is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value)) {
                $flattened[] = [
                    'key' => $path,
                    'old' => $value['old'],
                    'new' => $value['new'],
                ];
                continue;
            }

            if (is_array($value)) {
                $flattened = array_merge($flattened, $this->flattenAuditChanges($value, $path));
            }
        }

        return $flattened;
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

    private function recordMissedTradeAudit(FutureTradeBotConfig $config, array $signal, array $dynamic, array $hardBlockers = []): void
    {
        try {
            $settings = (array) ($dynamic['settings'] ?? $this->normalizeFutureTradeSettings($config->mergedSettings()));
            $snapshot = $this->runtimeContext($dynamic, ['status' => 'SKIPPED_AUDIT'], $signal, $hardBlockers);
            $blockers = $this->auditBlockersFromSignal($signal, $hardBlockers);
            $signalTime = $this->auditSignalTime($signal);
            $reviewWindow = max(1, (int) ($settings['missedTradeAuditWindowMinutes'] ?? 15));
            $confidence = (float) ($signal['confidence'] ?? 0);
            $activeMinimum = (float) ($dynamic['effectiveMinConfidence'] ?? ($settings['minConfidence'] ?? 0));

            FutureTradeMissedSignalAudit::query()->updateOrCreate(
                [
                    'future_trade_bot_config_id' => $config->id,
                    'symbol' => strtoupper((string) ($signal['symbol'] ?? '')),
                    'side' => strtoupper((string) ($signal['signal'] ?? 'WAIT')),
                    'signal_time' => $signalTime,
                ],
                [
                    'workspace_id' => $config->workspace_id,
                    'timeframe' => (string) ($signal['timeframe'] ?? ($settings['timeframe'] ?? '5m')),
                    'entry_reference_price' => (float) ($signal['price'] ?? 0),
                    'final_confidence' => $confidence,
                    'active_minimum' => $activeMinimum,
                    'confidence_gap' => round($confidence - $activeMinimum, 4),
                    'strategy_mode' => $this->resolveStrategyMode($settings),
                    'confidence_mode' => (string) ($dynamic['mode'] ?? ($settings['confidenceMode'] ?? 'fixed')),
                    'strict_entry_guards' => (bool) ($settings['strictEntryGuards'] ?? false),
                    'core_entry_passed' => (bool) data_get($signal, 'debug.checks.coreEntryPassed', false),
                    'core_passed_count' => (int) data_get($signal, 'debug.checks.corePassedCount', 0),
                    'review_window_minutes' => $reviewWindow,
                    'top_skip_reason' => $blockers[0] ?? (string) ($signal['reason'] ?? 'Skipped'),
                    'top_3_blockers' => array_slice($blockers, 0, 3),
                    'soft_penalties_json' => $this->softPenaltiesFromSignal($signal),
                    'hard_blockers_json' => $hardBlockers,
                    'component_scores_json' => (array) data_get($signal, 'debug.componentScores', []),
                    'component_breakdown_json' => (array) data_get($signal, 'debug.componentBreakdown', []),
                    'indicator_influence_json' => (array) data_get($signal, 'debug.indicatorControls', []),
                    'market_snapshot_json' => [
                        'price' => (float) ($signal['price'] ?? 0),
                        'timeframe' => (string) ($signal['timeframe'] ?? ($settings['timeframe'] ?? '5m')),
                        'config' => [
                            'missedTradeAuditFeePercentPerSide' => (float) ($settings['missedTradeAuditFeePercentPerSide'] ?? 0.04),
                            'missedTradeAuditRoundTripFeePercent' => (float) ($settings['missedTradeAuditRoundTripFeePercent'] ?? (($settings['missedTradeAuditEstimatedFeePercent'] ?? 0.08))),
                            'missedTradeAuditSlippagePercentPerSide' => (float) ($settings['missedTradeAuditSlippagePercentPerSide'] ?? 0.015),
                            'missedTradeAuditRoundTripSlippagePercent' => (float) ($settings['missedTradeAuditRoundTripSlippagePercent'] ?? (($settings['missedTradeAuditEstimatedSlippagePercent'] ?? 0.03))),
                            'missedTradeAuditRoundTripCostPercent' => (float) ($settings['missedTradeAuditRoundTripCostPercent'] ?? (($settings['missedTradeAuditEstimatedFeePercent'] ?? 0.08) + ($settings['missedTradeAuditEstimatedSlippagePercent'] ?? 0.03))),
                            'missedTradeAuditEstimatedFeePercent' => (float) ($settings['missedTradeAuditEstimatedFeePercent'] ?? 0.08),
                            'missedTradeAuditEstimatedSlippagePercent' => (float) ($settings['missedTradeAuditEstimatedSlippagePercent'] ?? 0.03),
                            'missedTradeAuditMinNetProfitPercent' => (float) ($settings['missedTradeAuditMinNetProfitPercent'] ?? 0.05),
                        ],
                        'timing' => (array) data_get($signal, 'debug.timing', []),
                        'context' => $snapshot,
                    ],
                    'status' => 'PENDING_REVIEW',
                    'review_due_at' => $signalTime->copy()->addMinutes($reviewWindow),
                ]
            );

            Log::info('[MISSED TRADE AUDIT CREATED]', [
                'workspace_id' => $config->workspace_id,
                'symbol' => strtoupper((string) ($signal['symbol'] ?? '')),
                'side' => strtoupper((string) ($signal['signal'] ?? 'WAIT')),
                'review_window_minutes' => $reviewWindow,
                'confidence' => $confidence,
                'active_minimum' => $activeMinimum,
                'top_blockers' => array_slice($blockers, 0, 3),
            ]);
        } catch (Throwable $e) {
            $this->safeDebugLog('[MISSED TRADE AUDIT CREATE FAILED]', [
                'workspace_id' => $config->workspace_id,
                'symbol' => $signal['symbol'] ?? null,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function evaluateMissedTradeAudit(FutureTradeMissedSignalAudit $audit): ?array
    {
        $start = $audit->signal_time;
        if (! $start) {
            return null;
        }

        $end = $start->copy()->addMinutes(max(1, (int) $audit->review_window_minutes));
        $candles = MarketOhlc::query()
            ->where('exchange', 'binance_futures')
            ->where('symbol', strtoupper((string) $audit->symbol))
            ->where('timeframe', '1m')
            ->whereBetween('ts', [$start, $end])
            ->orderBy('ts')
            ->get(['ts', 'high', 'low', 'close']);

        if ($candles->isEmpty()) {
            return null;
        }

        $entry = (float) $audit->entry_reference_price;
        if ($entry <= 0) {
            return null;
        }

        $side = strtoupper((string) $audit->side) === 'SELL' ? 'SHORT' : 'LONG';
        $settings = [
            'stopLossPercent' => (float) data_get($audit->market_snapshot_json, 'context.activeStopLossPercent', 0.28),
            'takeProfitPercent' => (float) data_get($audit->market_snapshot_json, 'context.activeTakeProfitPercent', 0.8),
        ];
        $feePercent = $this->resolveAuditRoundTripFeePercent($audit);
        $slippagePercent = $this->resolveAuditRoundTripSlippagePercent($audit);
        $minNetProfit = (float) data_get($audit->market_snapshot_json, 'config.missedTradeAuditMinNetProfitPercent', 0.05);
        $tpPrice = $this->takeProfitPrice($entry, $side, $settings);
        $slPrice = $this->stopLossPrice($entry, $side, $settings);

        $maxHigh = (float) $candles->max('high');
        $minLow = (float) $candles->min('low');
        $lastClose = (float) optional($candles->last())->close;

        if ($side === 'LONG') {
            $favorableMove = (($maxHigh - $entry) / $entry) * 100;
            $adverseMove = (($entry - $minLow) / $entry) * 100;
        } else {
            $favorableMove = (($entry - $minLow) / $entry) * 100;
            $adverseMove = (($maxHigh - $entry) / $entry) * 100;
        }

        $hitTpFirst = false;
        $hitSlFirst = false;
        $tpHitTime = null;
        $slHitTime = null;
        $firstHit = 'NONE';
        foreach ($candles as $candle) {
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            if ($side === 'LONG') {
                $tpHit = $high >= $tpPrice;
                $slHit = $low <= $slPrice;
            } else {
                $tpHit = $low <= $tpPrice;
                $slHit = $high >= $slPrice;
            }

            if ($tpHit && ! $slHit) {
                $hitTpFirst = true;
                $tpHitTime = optional($candle->ts)?->toIso8601String();
                $firstHit = 'TP';
                break;
            }
            if ($slHit && ! $tpHit) {
                $hitSlFirst = true;
                $slHitTime = optional($candle->ts)?->toIso8601String();
                $firstHit = 'SL';
                break;
            }
            if ($tpHit && $slHit) {
                $tpHitTime = optional($candle->ts)?->toIso8601String();
                $slHitTime = optional($candle->ts)?->toIso8601String();
                $firstHit = 'AMBIGUOUS_SAME_CANDLE';
                break;
            }
        }

        $grossPnlPercent = $side === 'LONG'
            ? (($lastClose - $entry) / $entry) * 100
            : (($entry - $lastClose) / $entry) * 100;
        $netPnlPercent = $grossPnlPercent - $feePercent - $slippagePercent;

        $result = 'NEUTRAL';
        if ($firstHit === 'TP' && $netPnlPercent >= $minNetProfit) {
            $result = 'MISSED_WIN';
        } elseif ($firstHit === 'AMBIGUOUS_SAME_CANDLE') {
            $result = 'AMBIGUOUS_SAME_CANDLE';
        } elseif ($firstHit === 'SL') {
            $result = $adverseMove > 0.05 ? 'AVOIDED_LOSS' : 'CORRECT_SKIP';
        } elseif (abs($netPnlPercent) < $minNetProfit) {
            $result = 'NEUTRAL';
        } elseif ($netPnlPercent > 0) {
            $result = 'BAD_SIGNAL';
        } elseif ($netPnlPercent < 0) {
            $result = 'CORRECT_SKIP';
        }

        $baseResult = [
            'max_favorable_move_percent' => round($favorableMove, 4),
            'max_adverse_move_percent' => round($adverseMove, 4),
            'take_profit_price' => round($tpPrice, 8),
            'stop_loss_price' => round($slPrice, 8),
            'tp_hit_time' => $tpHitTime,
            'sl_hit_time' => $slHitTime,
            'first_hit' => $firstHit,
            'would_hit_take_profit' => $hitTpFirst || $firstHit === 'AMBIGUOUS_SAME_CANDLE',
            'would_hit_stop_loss' => $hitSlFirst || $firstHit === 'AMBIGUOUS_SAME_CANDLE',
            'would_have_result' => $result,
            'estimated_fee_percent' => round($feePercent, 4),
            'estimated_slippage_percent' => round($slippagePercent, 4),
            'gross_would_have_pnl_percent' => round($grossPnlPercent, 4),
            'net_would_have_pnl_percent' => round($netPnlPercent, 4),
            'would_have_pnl_percent' => round($netPnlPercent, 4),
        ];

        $baseResult['mode_comparison_json'] = $this->buildAuditModeComparison($audit, $baseResult);

        return $baseResult;
    }

    private function auditBlockersFromSignal(array $signal, array $hardBlockers = []): array
    {
        $negative = array_values((array) data_get($signal, 'debug.negativeFactors', []));
        $soft = $this->softPenaltiesFromSignal($signal);
        $all = array_merge($hardBlockers, $negative, $soft);

        return array_values(array_slice(array_unique(array_filter(array_map(
            static fn ($value) => is_scalar($value) ? trim((string) $value) : '',
            $all
        ))), 0, 8));
    }

    private function auditSignalTime(array $signal): Carbon
    {
        $candidates = [
            data_get($signal, 'debug.timing.sequenceLastCandleTime'),
            data_get($signal, 'debug.timing.higherTimeframeLastCandleTime'),
            $signal['time'] ?? null,
            $signal['timestamp'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                try {
                    return Carbon::parse($candidate);
                } catch (Throwable) {
                }
            }
        }

        return now();
    }

    private function resolveStrategyMode(array $settings): string
    {
        if ((bool) ($settings['classicSimpleMode'] ?? false)) {
            return 'CLASSIC_SIMPLE_LIVE_SIGNAL';
        }
        if ((bool) ($settings['coreOnlyMode'] ?? false)) {
            return 'CORE_ONLY';
        }

        return 'FULL_INDICATOR_INTELLIGENCE';
    }

    private function resolveAuditBlockerControl(string $blocker, array $settings): array
    {
        $controls = $this->indicatorIntelligence->controls(IndicatorIntelligenceService::PROFILE_FUTURE_TRADE, $settings);
        $normalizedBlocker = Str::lower($blocker);

        foreach ($controls as $key => $control) {
            $label = Str::lower((string) ($control['label'] ?? ''));
            if ($label !== '' && (str_contains($normalizedBlocker, $label) || str_contains($label, $normalizedBlocker))) {
                return [
                    'factor' => (string) $key,
                    'currentWeight' => (int) ($control['weight'] ?? 0),
                ];
            }
        }

        $map = [
            'htf' => 'htfPenalty',
            'volume' => 'volumePenalty',
            'structure' => 'structurePenalty',
            'extension' => 'extensionPenalty',
            'rsi' => 'rsiPenalty',
            'macd' => 'macdPenalty',
            'choppy' => 'choppyPenalty',
            'weak setup' => 'weakSetupPenalty',
        ];
        foreach ($map as $needle => $factor) {
            if (str_contains($normalizedBlocker, $needle)) {
                return [
                    'factor' => $factor,
                    'currentWeight' => (int) data_get($controls, $factor . '.weight', 0),
                ];
            }
        }

        return [
            'factor' => Str::slug($blocker, '_'),
            'currentWeight' => 0,
        ];
    }

    private function resolveAuditRoundTripFeePercent(FutureTradeMissedSignalAudit $audit): float
    {
        $config = (array) data_get($audit->market_snapshot_json, 'config', []);
        $contextValue = (float) data_get($audit->market_snapshot_json, 'context.estimatedFeePercent', 0);
        if ($contextValue > 0) {
            return $contextValue;
        }

        $roundTrip = (float) ($config['missedTradeAuditRoundTripFeePercent'] ?? $config['missedTradeAuditEstimatedFeePercent'] ?? 0);
        if ($roundTrip > 0) {
            return $roundTrip;
        }

        return max(0.0, ((float) ($config['missedTradeAuditFeePercentPerSide'] ?? 0.04)) * 2);
    }

    private function resolveAuditRoundTripSlippagePercent(FutureTradeMissedSignalAudit $audit): float
    {
        $config = (array) data_get($audit->market_snapshot_json, 'config', []);
        $contextValue = (float) data_get($audit->market_snapshot_json, 'context.estimatedSlippagePercent', 0);
        if ($contextValue > 0) {
            return $contextValue;
        }

        $roundTrip = (float) ($config['missedTradeAuditRoundTripSlippagePercent'] ?? $config['missedTradeAuditEstimatedSlippagePercent'] ?? 0);
        if ($roundTrip > 0) {
            return $roundTrip;
        }

        return max(0.0, ((float) ($config['missedTradeAuditSlippagePercentPerSide'] ?? 0.015)) * 2);
    }

    private function recommendedModeFromAuditSummary($modeSummary, array $settings): string
    {
        if ($modeSummary->isEmpty()) {
            return $this->resolveStrategyMode($settings);
        }

        $best = $modeSummary->map(function (array $row) {
            $score = ((int) ($row['capturedMissedWins'] ?? 0) * 2)
                + ((int) ($row['correctSkips'] ?? 0))
                - ((int) ($row['allowedBadSignals'] ?? 0) * 2)
                + ((float) ($row['netWouldHavePnlPercent'] ?? 0) * 10);

            return $row + ['score' => round($score, 4)];
        })->sortByDesc('score')->first();

        return (string) ($best['mode'] ?? $this->resolveStrategyMode($settings));
    }

    private function configVersionsPayload(FutureTradeBotConfig $config): array
    {
        return $config->configVersions()
            ->latest('version_number')
            ->limit(12)
            ->get()
            ->map(fn (FutureTradeConfigVersion $version) => [
                'id' => $version->id,
                'versionNumber' => (int) $version->version_number,
                'label' => $version->label,
                'source' => $version->source,
                'summary' => $version->summary_json ?? [],
                'createdBy' => $version->created_by,
                'createdAt' => optional($version->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function storeConfigVersion(
        FutureTradeBotConfig $config,
        array $settings,
        string $notes,
        string $source,
        array $summary = [],
        string|int|null $userId = null,
        ?string $label = null
    ): void {
        try {
            $nextVersion = 1 + (int) ($config->configVersions()->max('version_number') ?? 0);
            FutureTradeConfigVersion::query()->create([
                'future_trade_bot_config_id' => $config->id,
                'workspace_id' => $config->workspace_id,
                'version_number' => $nextVersion,
                'label' => $label ?? sprintf('Version %d', $nextVersion),
                'source' => $source,
                'settings_json' => $settings,
                'algorithm_notes' => $notes,
                'summary_json' => $summary,
                'created_by' => $userId,
            ]);
        } catch (Throwable $e) {
            $this->safeDebugLog('[FUTURE TRADE CONFIG VERSION CREATE FAILED]', [
                'workspace_id' => $config->workspace_id,
                'source' => $source,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function buildAuditModeComparison(FutureTradeMissedSignalAudit $audit, array $result): array
    {
        $settings = $audit->config ? $this->normalizeFutureTradeSettings($audit->config->mergedSettings()) : $this->normalizeFutureTradeSettings(config('future_trade.defaults'));
        $activeMinimum = (float) ($audit->active_minimum ?? 0);
        $side = strtoupper((string) $audit->side);
        $hardBlocked = ! empty((array) ($audit->hard_blockers_json ?? []));
        $baseConfidence = (float) ($audit->final_confidence ?? 0);
        $breakdown = (array) ($audit->component_breakdown_json ?? []);
        $resultLabel = (string) ($result['would_have_result'] ?? '');
        $netPnl = (float) ($result['net_would_have_pnl_percent'] ?? 0);

        $nonCoreNegative = 0.0;
        $nonCorePositive = 0.0;
        foreach ($breakdown as $component) {
            $category = (string) ($component['category'] ?? '');
            $key = (string) ($component['key'] ?? '');
            $contribution = (float) ($component['finalContribution'] ?? $component['contribution'] ?? 0);
            $coreRelevant = $category === 'core' || in_array($key, ['directionalSignalBonus', 'trendContinuationBonus', 'macdPenalty'], true);
            if ($coreRelevant) {
                continue;
            }
            if ($contribution < 0) {
                $nonCoreNegative += abs($contribution);
            } elseif ($contribution > 0) {
                $nonCorePositive += $contribution;
            }
        }

        $coreConfidence = $this->clamp($baseConfidence + $nonCoreNegative - ($nonCorePositive * 0.4), 0.0, 1.0);
        $corePassed = (bool) ($audit->core_entry_passed ?? false);
        $coreCount = (int) ($audit->core_passed_count ?? 0);
        $classicConfidence = $this->clamp(
            $corePassed
                ? max($coreConfidence, min(0.95, 0.36 + ($coreCount * 0.08)))
                : max(0.0, min($coreConfidence, 0.34 + ($coreCount * 0.05))),
            0.0,
            1.0
        );

        $modes = [
            'FULL_INDICATOR_INTELLIGENCE' => [
                'confidence' => round($baseConfidence, 4),
                'activeMinimum' => round($activeMinimum, 4),
                'decision' => $this->auditModeDecision($hardBlocked, $side, $baseConfidence, $activeMinimum),
                'topSkipReason' => $this->auditModeSkipReason($audit, 'FULL_INDICATOR_INTELLIGENCE'),
            ],
            'CORE_ONLY' => [
                'confidence' => round($coreConfidence, 4),
                'activeMinimum' => round($activeMinimum, 4),
                'decision' => $this->auditModeDecision($hardBlocked, $side, $coreConfidence, $activeMinimum),
                'topSkipReason' => $this->auditModeSkipReason($audit, 'CORE_ONLY'),
            ],
            'CLASSIC_SIMPLE_LIVE_SIGNAL' => [
                'confidence' => round($classicConfidence, 4),
                'activeMinimum' => round($activeMinimum, 4),
                'decision' => $this->auditModeDecision($hardBlocked, $side, $classicConfidence, $activeMinimum, $corePassed),
                'topSkipReason' => $this->auditModeSkipReason($audit, 'CLASSIC_SIMPLE_LIVE_SIGNAL'),
            ],
        ];

        foreach ($modes as $key => $mode) {
            $wouldTrade = in_array($mode['decision'], ['TRADE_ALLOWED', 'REDUCED_SIZE_TRADE'], true);
            $modes[$key] += [
                'wouldCaptureMissedWin' => $wouldTrade && $resultLabel === 'MISSED_WIN',
                'wouldAllowBadSignal' => $wouldTrade && in_array($resultLabel, ['BAD_SIGNAL', 'AVOIDED_LOSS'], true),
                'wouldAllowAvoidedLoss' => $wouldTrade && $resultLabel === 'AVOIDED_LOSS',
                'wouldAllowCorrectSkip' => ! $wouldTrade && in_array($resultLabel, ['CORRECT_SKIP', 'AVOIDED_LOSS'], true),
                'netWouldHavePnlPercent' => round($netPnl, 4),
            ];
        }

        return $modes;
    }

    private function summarizeAuditModeComparisons($rows): array
    {
        $summary = [];
        foreach (['FULL_INDICATOR_INTELLIGENCE', 'CORE_ONLY', 'CLASSIC_SIMPLE_LIVE_SIGNAL'] as $mode) {
            $summary[$mode] = [
                'mode' => $mode,
                'wouldTrade' => 0,
                'capturedMissedWins' => 0,
                'allowedBadSignals' => 0,
                'correctSkips' => 0,
                'netWouldHavePnlPercent' => 0.0,
                'samples' => 0,
                'recommendation' => 'Collect more mode-comparison reviews',
            ];
        }

        foreach ($rows as $audit) {
            $comparison = (array) ($audit->mode_comparison_json ?? []);
            foreach ($summary as $mode => $item) {
                $row = (array) ($comparison[$mode] ?? []);
                if ($row === []) {
                    continue;
                }
                $summary[$mode]['samples']++;
                if (in_array((string) ($row['decision'] ?? 'SKIP'), ['TRADE_ALLOWED', 'REDUCED_SIZE_TRADE'], true)) {
                    $summary[$mode]['wouldTrade']++;
                }
                if ((bool) ($row['wouldCaptureMissedWin'] ?? false)) {
                    $summary[$mode]['capturedMissedWins']++;
                }
                if ((bool) ($row['wouldAllowBadSignal'] ?? false)) {
                    $summary[$mode]['allowedBadSignals']++;
                }
                if ((bool) ($row['wouldAllowCorrectSkip'] ?? false)) {
                    $summary[$mode]['correctSkips']++;
                }
                $summary[$mode]['netWouldHavePnlPercent'] += (float) ($row['netWouldHavePnlPercent'] ?? 0);
            }
        }

        foreach ($summary as $mode => $item) {
            $samples = max(1, (int) $item['samples']);
            $capturedRate = $item['capturedMissedWins'] / $samples;
            $badRate = $item['allowedBadSignals'] / $samples;
            $correctRate = $item['correctSkips'] / $samples;
            if ($capturedRate > 0.45 && $badRate < 0.15) {
                $summary[$mode]['recommendation'] = 'Strong candidate for fewer missed wins';
            } elseif ($badRate > 0.25) {
                $summary[$mode]['recommendation'] = 'Too permissive; keep stronger filters';
            } elseif ($correctRate > 0.55) {
                $summary[$mode]['recommendation'] = 'Protective mode; good at skipping bad setups';
            } else {
                $summary[$mode]['recommendation'] = 'Balanced; monitor with more samples';
            }
            $summary[$mode]['netWouldHavePnlPercent'] = round($item['netWouldHavePnlPercent'], 4);
        }

        return array_values($summary);
    }

    private function auditModeDecision(bool $hardBlocked, string $side, float $confidence, float $activeMinimum, bool $corePassed = false): string
    {
        if ($hardBlocked) {
            return 'HARD_BLOCKED';
        }
        if ($side === 'WAIT') {
            return 'SKIP';
        }
        if ($confidence >= $activeMinimum) {
            return 'TRADE_ALLOWED';
        }
        if ($corePassed && $confidence >= ($activeMinimum * 0.92)) {
            return 'REDUCED_SIZE_TRADE';
        }

        return 'SKIP';
    }

    private function auditModeSkipReason(FutureTradeMissedSignalAudit $audit, string $mode): ?string
    {
        if (! empty((array) ($audit->hard_blockers_json ?? []))) {
            return (string) ((array) $audit->hard_blockers_json)[0];
        }
        if ($mode === 'CORE_ONLY') {
            return 'Core threshold not cleared';
        }
        if ($mode === 'CLASSIC_SIMPLE_LIVE_SIGNAL') {
            return (bool) $audit->core_entry_passed ? 'Classic confidence below active minimum' : 'Core entry checks not passed';
        }

        return $audit->top_skip_reason;
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
