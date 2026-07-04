<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Exchange\BinanceRestCircuitBreaker;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\FutureTradeLogicalTradeEvent;
use App\Models\WalletAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Per-config Binance Futures user-data-stream lifecycle + heartbeat surface.
 *
 * The Future Trade bot stores its credentials directly on the config
 * (settings.testnetApiKey/liveApiKey/...), independently of workspace-level
 * `integrations` rows. This service owns:
 *   - listen key create / keep-alive / close for that config
 *   - a cache-backed heartbeat the bot reads BEFORE every live entry
 *   - account/order event ingestion that updates linked logical_trades
 *
 * Heartbeat cache layout:
 *   autofinx_ft:user_stream:{config_id}:state  =>  ['status' => ..., 'last_event_at' => ISO, 'last_heartbeat_at' => ISO, 'last_keepalive_at' => ISO, 'listen_key' => ...]
 *
 * The bot treats the stream as "fresh" if either a real user-stream event or a
 * consumer heartbeat is within the freshness window (default 30s). This avoids
 * false blocks on shared-host cron consumers that stay connected but only see
 * events when orders actually fill.
 */
class FutureTradeUserStreamService
{
    public const FRESHNESS_SECONDS = 30;
    public const STATE_KEY = 'autofinx_ft:user_stream:%s:state';
    public const STATUS_CONNECTED = 'CONNECTED';
    public const STATUS_DISCONNECTED = 'DISCONNECTED';
    public const STATUS_DEGRADED = 'DEGRADED';
    public const STATUS_NOT_CONFIGURED = 'NOT_CONFIGURED';

    private const STATE_TTL_SECONDS = 3600;
    private const HEARTBEAT_FILE = 'framework/autofinx-user-stream-heartbeat.json';

    // Global throttle: max 1 createListenKey attempt per 1.2 s across all configs/processes.
    private const CLK_THROTTLE_KEY = 'autofinx_ft:create_listen_key_last_ms';
    private const CLK_MIN_INTERVAL_MS = 1200;

    public function __construct(private readonly FutureTradeFuturesCredentialResolver $credentials)
    {
    }

    /**
     * Resolve the per-config Binance Futures client. Config credentials are
     * preferred, with workspace Binance integration credentials as fallback.
     */
    public function clientFor(FutureTradeBotConfig $config): ?BinanceFuturesClient
    {
        return $this->credentials->clientForConfig($config);
    }

    /**
     * Return the WS URL the consumer should connect to (matching mode).
     */
    public function wsBase(FutureTradeBotConfig $config): string
    {
        $settings = $config->mergedSettings();
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        return (string) ($forceTestnet
            ? (config('services.binance.futures_testnet_ws_url') ?: 'wss://stream.binancefuture.com')
            : (config('services.binance.futures_ws_url') ?: 'wss://fstream.binance.com'));
    }

    /**
     * Compact snapshot the bot/UI use to decide whether to allow live execution.
     */
    public function snapshot(FutureTradeBotConfig $config): array
    {
        $resolved = $this->credentials->resolveForConfig($config);
        $hasCreds = (bool) ($resolved['configured'] ?? false);

        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        if ($state === []) {
            $fileState = $this->readHeartbeatFile($config);
            if ($fileState !== []) {
                $state = $fileState;
            }
        }
        $lastEventAt = isset($state['last_event_at']) ? Carbon::parse((string) $state['last_event_at']) : null;
        $lastHeartbeatAt = isset($state['last_heartbeat_at']) ? Carbon::parse((string) $state['last_heartbeat_at']) : null;
        $ageSeconds = $lastEventAt ? max(0, $lastEventAt->diffInSeconds(now())) : null;
        $heartbeatAgeSeconds = $lastHeartbeatAt ? max(0, $lastHeartbeatAt->diffInSeconds(now())) : null;
        $status = (string) ($state['status'] ?? ($hasCreds ? self::STATUS_DISCONNECTED : self::STATUS_NOT_CONFIGURED));
        $eventFresh = $ageSeconds !== null && $ageSeconds <= self::FRESHNESS_SECONDS;
        $heartbeatFresh = $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds <= self::FRESHNESS_SECONDS;
        $fresh = $eventFresh || $heartbeatFresh;
        // If creds aren't configured we still mark "fresh = true for PAPER-only"
        // — the gate logic decides whether to enforce.
        return [
            'configured' => $hasCreds,
            'credentialSource' => (string) ($resolved['source'] ?? 'none'),
            'credentialMode' => (string) ($resolved['mode'] ?? 'unknown'),
            'credentialReason' => $hasCreds ? null : (string) ($resolved['reason'] ?? 'not_configured'),
            'integrationId' => $resolved['integration_id'] ?? null,
            'status' => $status,
            'lastEventAt' => $lastEventAt?->toIso8601String(),
            'lastHeartbeatAt' => $lastHeartbeatAt?->toIso8601String(),
            'ageSeconds' => $ageSeconds,
            'heartbeatAgeSeconds' => $heartbeatAgeSeconds,
            'eventFresh' => $eventFresh,
            'heartbeatFresh' => $heartbeatFresh,
            'fresh' => $fresh,
            'lastKeepAliveAt' => isset($state['last_keepalive_at']) ? (string) $state['last_keepalive_at'] : null,
        ];
    }

    public function setStatus(FutureTradeBotConfig $config, string $status, array $extra = []): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state = array_merge($state, ['status' => $status], $extra);
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
    }

    public function recordEvent(FutureTradeBotConfig $config, ?array $payload = null): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state['status'] = self::STATUS_CONNECTED;
        $state['last_event_at'] = now()->toIso8601String();
        $state['last_heartbeat_at'] = now()->toIso8601String();
        $state['last_heartbeat_source'] = 'event';
        if ($payload !== null) {
            $state['last_event_type'] = (string) ($payload['e'] ?? '');
        }
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
        $this->writeHeartbeatFile($config, $state);
    }

    public function recordHeartbeat(FutureTradeBotConfig $config, string $source = 'runtime'): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state['status'] = self::STATUS_CONNECTED;
        $state['last_heartbeat_at'] = now()->toIso8601String();
        $state['last_heartbeat_source'] = $source;
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
        $this->writeHeartbeatFile($config, $state);
    }

    public function recordKeepAlive(FutureTradeBotConfig $config): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state['last_keepalive_at'] = now()->toIso8601String();
        $state['last_heartbeat_at'] = now()->toIso8601String();
        $state['last_heartbeat_source'] = 'keepalive';
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
        $this->writeHeartbeatFile($config, $state);
    }

    public function rememberListenKey(FutureTradeBotConfig $config, string $listenKey): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state['listen_key'] = $listenKey;
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
    }

    /**
     * Ingest one decoded user-stream payload and update linked logical_trades.
     * Returns true if any logical trade was updated.
     */
    public function ingestEvent(FutureTradeBotConfig $config, array $payload): bool
    {
        $this->recordEvent($config, $payload);
        $eventType = (string) ($payload['e'] ?? '');
        if ($eventType === 'ACCOUNT_UPDATE') {
            return $this->ingestAccountUpdate($config, $payload);
        }
        if ($eventType !== 'ORDER_TRADE_UPDATE') {
            return false;
        }
        $order = (array) ($payload['o'] ?? []);
        if ($order === []) {
            return false;
        }

        $symbol = strtoupper((string) ($order['s'] ?? ''));
        $clientOrderId = (string) ($order['c'] ?? '');
        $orderId = (string) ($order['i'] ?? '');
        $status = strtoupper((string) ($order['X'] ?? ''));
        $execType = strtoupper((string) ($order['x'] ?? ''));
        $avgPrice = (float) ($order['ap'] ?? $order['L'] ?? 0);
        $cumQty = (float) ($order['z'] ?? 0);
        $realizedPnl = (float) ($order['rp'] ?? 0);
        $commission = (float) ($order['n'] ?? 0);
        $side = strtoupper((string) ($order['S'] ?? ''));

        $trade = null;
        if ($clientOrderId !== '' || $orderId !== '') {
            $trade = FutureTradeLogicalTrade::query()
                ->where('future_trade_bot_config_id', $config->id)
                ->where(function ($q) use ($clientOrderId, $orderId) {
                    if ($clientOrderId !== '') {
                        $q->where('entry_client_order_id', $clientOrderId)
                            ->orWhere('exit_client_order_id', $clientOrderId);
                    }
                    if ($orderId !== '') {
                        $q->orWhere('entry_exchange_order_id', $orderId)
                            ->orWhere('exit_exchange_order_id', $orderId);
                    }
                })
                ->orderByDesc('opened_at')
                ->first();
        }
        $matchedByExternalCloseSide = false;
        if (! $trade && $symbol !== '' && $side !== '') {
            $tradeSide = $side === 'SELL' ? 'LONG' : ($side === 'BUY' ? 'SHORT' : null);
            if ($tradeSide !== null) {
                $trade = FutureTradeLogicalTrade::query()
                    ->where('future_trade_bot_config_id', $config->id)
                    ->where('symbol', $symbol)
                    ->where('side', $tradeSide)
                    ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
                    ->orderByDesc('opened_at')
                    ->first();
                $matchedByExternalCloseSide = $trade !== null;
            }
        }
        if (! $trade) {
            return false;
        }

        $isEntryMatch = $clientOrderId !== '' && $clientOrderId === (string) $trade->entry_client_order_id;
        $isExitMatch = $clientOrderId !== '' && $clientOrderId === (string) $trade->exit_client_order_id;

        if ($isEntryMatch) {
            if (in_array($status, ['FILLED', 'PARTIALLY_FILLED'], true) && $avgPrice > 0) {
                $trade->update([
                    'entry_price' => $avgPrice ?: $trade->entry_price,
                    'quantity' => $cumQty ?: $trade->quantity,
                    'entry_exchange_order_id' => $orderId ?: $trade->entry_exchange_order_id,
                    'status' => $status === 'FILLED' ? FutureTradeLogicalTrade::STATUS_OPEN : $trade->status,
                ]);
            } elseif ($status === 'CANCELED' || $status === 'REJECTED' || $status === 'EXPIRED') {
                $trade->update(['status' => FutureTradeLogicalTrade::STATUS_CANCELLED]);
            }

            FutureTradeLogicalTradeEvent::create([
                'future_trade_logical_trade_id' => $trade->id,
                'future_trade_bot_config_id' => $config->id,
                'symbol' => $symbol,
                'event_type' => $status === 'FILLED'
                    ? FutureTradeLogicalTradeEvent::EVENT_ENTRY_FILLED
                    : FutureTradeLogicalTradeEvent::EVENT_ENTRY_SUBMITTED,
                'client_order_id' => $clientOrderId,
                'exchange_order_id' => $orderId,
                'exchange_status' => $status,
                'price' => $avgPrice ?: null,
                'quantity' => $cumQty ?: null,
                'payload' => ['execType' => $execType, 'order' => $order],
            ]);
            return true;
        }

        if ($isExitMatch || $matchedByExternalCloseSide || (! $isEntryMatch && $trade->isOpen() && $side !== '' && $side === ($trade->side === 'LONG' ? 'SELL' : 'BUY'))) {
            // Idempotency: a re-delivered FILLED event must not double the
            // commission/realized PnL. If the trade is already CLOSED on our
            // side, skip re-processing.
            if ($trade->status === FutureTradeLogicalTrade::STATUS_CLOSED) {
                return false;
            }
            if (in_array($status, ['FILLED', 'PARTIALLY_FILLED'], true) && $avgPrice > 0) {
                $existingFees = (float) ($trade->fees ?? 0);
                $direction = $trade->side === 'LONG' ? 1 : -1;
                $entry = (float) $trade->entry_price;
                $qty = $cumQty ?: (float) $trade->quantity;
                $gross = ($avgPrice - $entry) * $qty * $direction;
                $fees = $existingFees + abs($commission);
                $net = ($realizedPnl !== 0.0) ? $realizedPnl - $fees : $gross - $fees;

                $trade->update([
                    'exit_price' => $avgPrice,
                    'exit_exchange_order_id' => $orderId ?: $trade->exit_exchange_order_id,
                    'exit_client_order_id' => $clientOrderId ?: $trade->exit_client_order_id,
                    'gross_pnl' => $gross,
                    'fees' => $fees,
                    'net_pnl' => $net,
                    'status' => $status === 'FILLED' ? FutureTradeLogicalTrade::STATUS_CLOSED : FutureTradeLogicalTrade::STATUS_CLOSING,
                    'closed_at' => $status === 'FILLED' ? now() : $trade->closed_at,
                ]);
                if ($status === 'FILLED') {
                    $this->markRuntimePositionClosed(
                        $config,
                        $trade->fresh(),
                        $avgPrice,
                        $net,
                        $matchedByExternalCloseSide ? 'BINANCE_EXTERNAL_CLOSE_FILLED' : 'BINANCE_EXIT_FILLED'
                    );
                }

                FutureTradeLogicalTradeEvent::create([
                    'future_trade_logical_trade_id' => $trade->id,
                    'future_trade_bot_config_id' => $config->id,
                    'symbol' => $symbol,
                    'event_type' => $status === 'FILLED'
                        ? FutureTradeLogicalTradeEvent::EVENT_EXIT_FILLED
                        : FutureTradeLogicalTradeEvent::EVENT_EXIT_SUBMITTED,
                    'client_order_id' => $clientOrderId,
                    'exchange_order_id' => $orderId,
                    'exchange_status' => $status,
                    'price' => $avgPrice,
                    'quantity' => $cumQty,
                    'payload' => [
                        'execType' => $execType,
                        'order' => $order,
                        'realizedPnl' => $realizedPnl,
                        'matchedByExternalCloseSide' => $matchedByExternalCloseSide,
                    ],
                ]);
                return true;
            }
        }

        // Exchange-fired SL/TP: check if this order matches IDs stored in logical trade meta.
        // This path fires when Binance executed our STOP_MARKET or TAKE_PROFIT_MARKET order
        // directly — i.e. the server loop was down or websocket dropped.
        if ($clientOrderId !== '' && $status === 'FILLED' && $avgPrice > 0 && $trade->isOpen()) {
            $meta     = (array) ($trade->meta ?? []);
            $isSLHit  = $clientOrderId === (string) ($meta['sl_client_order_id'] ?? '');
            $isTPHit  = $clientOrderId === (string) ($meta['tp_client_order_id'] ?? '');

            if ($isSLHit || $isTPHit) {
                $existingFees = (float) ($trade->fees ?? 0);
                $direction    = $trade->side === 'LONG' ? 1 : -1;
                $entryPrice   = (float) $trade->entry_price;
                $qty          = $cumQty ?: (float) $trade->quantity;
                $gross        = ($avgPrice - $entryPrice) * $qty * $direction;
                $fees         = $existingFees + abs($commission);
                $net          = ($realizedPnl !== 0.0) ? $realizedPnl - $fees : $gross - $fees;
                $reason       = $isSLHit ? 'EXCHANGE_SL_FILLED' : 'EXCHANGE_TP_FILLED';

                $trade->update([
                    'exit_price'              => $avgPrice,
                    'exit_exchange_order_id'  => $orderId,
                    'exit_client_order_id'    => $clientOrderId,
                    'gross_pnl'               => $gross,
                    'fees'                    => $fees,
                    'net_pnl'                 => $net,
                    'status'                  => FutureTradeLogicalTrade::STATUS_CLOSED,
                    'closed_at'               => now(),
                ]);
                $this->markRuntimePositionClosed($config, $trade->fresh(), $avgPrice, $net, $reason);

                FutureTradeLogicalTradeEvent::create([
                    'future_trade_logical_trade_id' => $trade->id,
                    'future_trade_bot_config_id'    => $config->id,
                    'symbol'                        => $symbol,
                    'event_type'                    => FutureTradeLogicalTradeEvent::EVENT_EXIT_FILLED,
                    'client_order_id'               => $clientOrderId,
                    'exchange_order_id'             => $orderId,
                    'exchange_status'               => $status,
                    'price'                         => $avgPrice,
                    'quantity'                      => $cumQty,
                    'payload'                       => ['execType' => $execType, 'order' => $order, 'realizedPnl' => $realizedPnl, 'reason' => $reason],
                ]);

                // Cancel the sibling order (TP if SL fired, SL if TP fired).
                // Binance usually auto-cancels when closePosition=true closes the position,
                // but an explicit cancel prevents any edge-case double-fill.
                $siblingClientId = $isSLHit
                    ? (string) ($meta['tp_client_order_id'] ?? '')
                    : (string) ($meta['sl_client_order_id'] ?? '');

                if ($siblingClientId !== '') {
                    try {
                        $client = $this->clientFor($config);
                        if ($client) {
                            $client->cancelOrder($symbol, null, $siblingClientId);
                        }
                    } catch (Throwable $e) {
                        Log::warning('[EXCHANGE_SLTP_SIBLING_CANCEL_FAILED]', [
                            'symbol'   => $symbol,
                            'sibling'  => $siblingClientId,
                            'reason'   => $reason,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Convenience: start (or re-use) a listen key.
     */
    public function ensureListenKey(FutureTradeBotConfig $config): ?string
    {
        // Bail immediately if Binance has globally banned this IP.
        try {
            (new BinanceRestCircuitBreaker())->assertAvailable();
        } catch (Throwable $e) {
            Log::warning('[FT USER STREAM] createListenKey skipped — global ban active', [
                'config_id' => $config->id,
                'error'     => $e->getMessage(),
            ]);
            $this->setStatus($config, self::STATUS_DEGRADED, ['last_error' => $e->getMessage()]);
            return null;
        }

        // Global throttle: at most 1 createListenKey call per CLK_MIN_INTERVAL_MS across
        // all processes.  Prevents simultaneous reconnects from triggering a new IP ban.
        $nowMs   = (int) floor(microtime(true) * 1000);
        $lastMs  = (int) Cache::get(self::CLK_THROTTLE_KEY, 0);
        $waitMs  = max(0, self::CLK_MIN_INTERVAL_MS - ($nowMs - $lastMs));
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
        Cache::put(self::CLK_THROTTLE_KEY, (int) floor(microtime(true) * 1000), 60);

        $client = $this->clientFor($config);
        if (! $client) {
            $this->setStatus($config, self::STATUS_NOT_CONFIGURED);
            return null;
        }
        try {
            $listenKey = $client->createListenKey();
            $this->rememberListenKey($config, $listenKey);
            $this->setStatus($config, self::STATUS_CONNECTED);
            $this->recordHeartbeat($config, 'listen_key');
            return $listenKey;
        } catch (Throwable $e) {
            Log::warning('[FT USER STREAM] createListenKey failed', [
                'config_id' => $config->id,
                'error'     => $e->getMessage(),
            ]);
            $this->setStatus($config, self::STATUS_DEGRADED, ['last_error' => $e->getMessage()]);
            return null;
        }
    }

    public function keepAlive(FutureTradeBotConfig $config): bool
    {
        $client = $this->clientFor($config);
        if (! $client) {
            return false;
        }
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $listenKey = (string) ($state['listen_key'] ?? '');
        if ($listenKey === '') {
            return false;
        }
        try {
            $client->keepAliveListenKey($listenKey);
            $this->recordKeepAlive($config);
            return true;
        } catch (Throwable $e) {
            $this->setStatus($config, self::STATUS_DEGRADED, ['last_error' => $e->getMessage()]);
            return false;
        }
    }

    public function closeStream(FutureTradeBotConfig $config): void
    {
        $client = $this->clientFor($config);
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $listenKey = (string) ($state['listen_key'] ?? '');
        if ($client && $listenKey !== '') {
            try {
                $client->closeListenKey($listenKey);
            } catch (Throwable) {
                // best effort
            }
        }
        $this->setStatus($config, self::STATUS_DISCONNECTED, ['listen_key' => null]);
    }

    private function stateKey(string $configId): string
    {
        return sprintf(self::STATE_KEY, $configId);
    }

    private function writeHeartbeatFile(FutureTradeBotConfig $config, array $state): void
    {
        try {
            $payload = array_merge($state, [
                'workspace_id' => (string) $config->workspace_id,
                'config_id' => (string) $config->id,
                'server_now' => now()->toIso8601String(),
                'heartbeat_epoch' => time(),
            ]);
            @file_put_contents(storage_path(self::HEARTBEAT_FILE), json_encode($payload, JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // File heartbeat is a supervisor/runtime-master fallback only.
        }
    }

    private function readHeartbeatFile(FutureTradeBotConfig $config): array
    {
        try {
            $path = storage_path(self::HEARTBEAT_FILE);
            if (! is_file($path)) {
                return [];
            }
            $payload = json_decode((string) file_get_contents($path), true);
            if (! is_array($payload)) {
                return [];
            }
            if ((string) ($payload['config_id'] ?? '') !== (string) $config->id) {
                return [];
            }

            return $payload;
        } catch (Throwable) {
            return [];
        }
    }

    private function ingestAccountUpdate(FutureTradeBotConfig $config, array $payload): bool
    {
        $balanceUpdated = $this->syncWalletFromAccountUpdate($config, $payload);
        $positions = (array) data_get($payload, 'a.P', []);
        $updated = false;

        foreach ($positions as $position) {
            if (! is_array($position)) {
                continue;
            }

            $symbol = strtoupper((string) ($position['s'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $positionAmount = (float) ($position['pa'] ?? 0);
            if (abs($positionAmount) > 0.0) {
                $updated = $this->syncRuntimePositionFromAccountUpdate($config, $position) || $updated;
                continue;
            }

            $activeTrade = FutureTradeLogicalTrade::query()
                ->where('future_trade_bot_config_id', $config->id)
                ->where('symbol', $symbol)
                ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
                ->orderByDesc('opened_at')
                ->first();
            $state = $config->state;
            $localShowsSymbolOpen = $state
                && strtoupper((string) ($state->current_symbol ?? '')) === $symbol
                && ((float) ($state->quantity ?? 0) > 0 || in_array((string) ($state->state ?? ''), ['IN_POSITION', 'LIVE_IN_POSITION'], true));

            if (! $activeTrade && ! $localShowsSymbolOpen) {
                continue;
            }

            if ($activeTrade) {
                $activeTrade->update([
                    'status' => FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED,
                    'reconciled_at' => now(),
                    'meta' => array_merge((array) ($activeTrade->meta ?? []), [
                        'account_update_flat_detected_at' => now()->toIso8601String(),
                        'account_update_position' => $position,
                    ]),
                ]);
                FutureTradeLogicalTradeEvent::create([
                    'future_trade_logical_trade_id' => $activeTrade->id,
                    'future_trade_bot_config_id' => $config->id,
                    'symbol' => $symbol,
                    'event_type' => FutureTradeLogicalTradeEvent::EVENT_RECONCILED,
                    'payload' => [
                        'reason' => 'ACCOUNT_UPDATE_EXCHANGE_FLAT',
                        'note' => 'Binance account update shows the position is flat; waiting for fill reconciliation.',
                        'position' => $position,
                    ],
                ]);
            }

            $this->clearRuntimePositionState($config, $symbol, 'ACCOUNT_UPDATE_EXCHANGE_FLAT');
            $updated = true;
        }

        return $updated || $balanceUpdated;
    }

    private function syncWalletFromAccountUpdate(FutureTradeBotConfig $config, array $payload): bool
    {
        $balances = (array) data_get($payload, 'a.B', []);
        $usdt = collect($balances)->first(fn ($row) => is_array($row) && strtoupper((string) ($row['a'] ?? '')) === 'USDT');
        if (! is_array($usdt)) {
            return false;
        }

        $walletBalance = (float) ($usdt['wb'] ?? 0);
        $crossWalletBalance = (float) ($usdt['cw'] ?? $walletBalance);
        $balanceChange = (float) ($usdt['bc'] ?? 0);
        $existing = WalletAccount::query()
            ->where('workspace_id', (string) $config->workspace_id)
            ->where('currency', 'USDT')
            ->where('type', 'exchange')
            ->first();
        $available = $existing
            ? min($walletBalance, max(0, (float) $existing->available + $balanceChange))
            : $crossWalletBalance;

        WalletAccount::updateOrCreate(
            [
                'workspace_id' => (string) $config->workspace_id,
                'currency' => 'USDT',
                'type' => 'exchange',
            ],
            [
                'id' => $existing?->id ?: (string) Str::uuid(),
                'name' => 'BINANCE USDT Futures Wallet',
                'status' => 'active',
                'wallet_type' => 'provider',
                'balance' => $walletBalance,
                'available' => $available,
                'locked_balance' => max(0, $walletBalance - $available),
                'metadata' => array_merge((array) ($existing?->metadata ?? []), [
                    'provider' => 'binance',
                    'market_type' => 'futures',
                    'last_user_stream_sync_at' => now()->toIso8601String(),
                    'cross_wallet_balance' => $crossWalletBalance,
                    'balance_change' => $balanceChange,
                    'source' => 'binance_user_stream_account_update',
                ]),
            ]
        );

        Cache::put(sprintf(FutureTradeAccountSyncService::STATE_KEY, (string) $config->workspace_id), [
            'ok' => true,
            'status' => 'OK',
            'workspace_id' => (string) $config->workspace_id,
            'config_id' => (string) $config->id,
            'credential_source' => 'user_stream',
            'wallet_balance' => $walletBalance,
            'available_balance' => $available,
            'margin_balance' => $crossWalletBalance,
            'unrealized_pnl' => null,
            'open_position_count' => null,
            'last_sync_at' => now()->toIso8601String(),
            'age_seconds' => 0,
        ], 900);

        return true;
    }

    private function syncRuntimePositionFromAccountUpdate(FutureTradeBotConfig $config, array $position): bool
    {
        $symbol = strtoupper((string) ($position['s'] ?? ''));
        if ($symbol === '') {
            return false;
        }

        $positionAmount = (float) ($position['pa'] ?? 0);
        $quantity = abs($positionAmount);
        if ($quantity <= 0.0) {
            return false;
        }

        $state = $config->state;
        if (! $state) {
            return false;
        }

        $entryPrice = (float) ($position['ep'] ?? $state->entry_price ?? 0);
        $markPrice = (float) ($position['mp'] ?? $state->current_price ?? 0);
        $unrealized = (float) ($position['up'] ?? $state->unrealized_pnl ?? 0);
        $side = $positionAmount > 0 ? 'LONG' : 'SHORT';
        $unrealizedPercent = $entryPrice > 0 && $markPrice > 0
            ? (($markPrice - $entryPrice) / $entryPrice) * ($side === 'LONG' ? 100 : -100)
            : (float) ($state->unrealized_pnl_percent ?? 0);

        $state->update([
            'state' => 'LIVE_IN_POSITION',
            'status' => 'Running',
            'current_symbol' => $symbol,
            'position_side' => $side,
            'entry_price' => $entryPrice ?: $state->entry_price,
            'quantity' => $quantity,
            'current_price' => $markPrice ?: $state->current_price,
            'unrealized_pnl' => $unrealized,
            'unrealized_pnl_percent' => $unrealizedPercent,
            'connection_status' => 'Connected',
            'current_signal' => 'BINANCE_ACCOUNT_POSITION_SYNCED',
        ]);

        return true;
    }

    private function markRuntimePositionClosed(
        FutureTradeBotConfig $config,
        FutureTradeLogicalTrade $trade,
        float $exitPrice,
        float $netPnl,
        string $reason
    ): void {
        $symbol = strtoupper((string) $trade->symbol);
        $state = $config->state;
        if (! $state) {
            return;
        }

        $localSymbol = strtoupper((string) ($state->current_symbol ?? ''));
        if ($localSymbol !== '' && $localSymbol !== $symbol) {
            return;
        }

        $settings = $config->mergedSettings();
        $cooldownSeconds = max(0, (int) ($settings['cooldownSeconds'] ?? 0));
        $patch = [
            'state' => $cooldownSeconds > 0 ? 'COOLDOWN' : ($config->enabled ? 'SCANNING' : 'STOPPED'),
            'status' => $config->enabled ? 'Running' : 'Stopped',
            'current_symbol' => null,
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'peak_price' => null,
            'bottom_price' => null,
            'current_price' => $exitPrice > 0 ? $exitPrice : (float) ($state->current_price ?? 0),
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) ($state->realized_pnl ?? 0) + $netPnl,
            'daily_profit' => $netPnl > 0 ? (float) ($state->daily_profit ?? 0) + $netPnl : (float) ($state->daily_profit ?? 0),
            'daily_loss' => $netPnl < 0 ? (float) ($state->daily_loss ?? 0) + abs($netPnl) : (float) ($state->daily_loss ?? 0),
            'wins' => $netPnl > 0 ? (int) ($state->wins ?? 0) + 1 : (int) ($state->wins ?? 0),
            'losses' => $netPnl < 0 ? (int) ($state->losses ?? 0) + 1 : (int) ($state->losses ?? 0),
            'cooldown_until' => $cooldownSeconds > 0 ? now()->addSeconds($cooldownSeconds) : null,
            'current_signal' => $reason,
            'connection_status' => 'Connected',
        ];

        $state->update($patch);
        Log::info('[FT USER STREAM] runtime state closed from Binance event', [
            'config_id' => (string) $config->id,
            'symbol' => $symbol,
            'reason' => $reason,
            'exitPrice' => $exitPrice,
            'netPnl' => $netPnl,
        ]);
    }

    private function clearRuntimePositionState(FutureTradeBotConfig $config, string $symbol, string $reason): void
    {
        $state = $config->state;
        if (! $state) {
            return;
        }

        $localSymbol = strtoupper((string) ($state->current_symbol ?? ''));
        if ($localSymbol !== '' && $localSymbol !== strtoupper($symbol)) {
            return;
        }

        $state->update([
            'state' => $config->enabled ? 'SCANNING' : 'STOPPED',
            'status' => $config->enabled ? 'Running' : 'Stopped',
            'current_symbol' => null,
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'peak_price' => null,
            'bottom_price' => null,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'connection_status' => 'Connected',
            'current_signal' => $reason,
        ]);

        Log::warning('[FT USER STREAM] runtime state cleared because Binance is flat', [
            'config_id' => (string) $config->id,
            'symbol' => strtoupper($symbol),
            'reason' => $reason,
        ]);
    }
}
