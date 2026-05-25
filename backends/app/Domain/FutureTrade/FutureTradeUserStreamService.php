<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\FutureTradeLogicalTradeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /**
     * Resolve the per-config Binance Futures client using the credentials
     * stored on this config's settings.
     */
    public function clientFor(FutureTradeBotConfig $config): ?BinanceFuturesClient
    {
        $settings = $config->mergedSettings();
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        $apiKey = (string) ($forceTestnet ? ($settings['testnetApiKey'] ?? '') : ($settings['liveApiKey'] ?? ''));
        $apiSecret = (string) ($forceTestnet ? ($settings['testnetApiSecret'] ?? '') : ($settings['liveApiSecret'] ?? ''));
        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }
        $baseUrl = (string) ($forceTestnet
            ? ($settings['testnetApiUrl'] ?? config('services.binance.futures_testnet_api_url') ?? 'https://testnet.binancefuture.com')
            : ($settings['liveApiUrl'] ?? config('services.binance.futures_api_url') ?? 'https://fapi.binance.com'));

        return new BinanceFuturesClient(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            baseUrl: $baseUrl,
        );
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
        $settings = $config->mergedSettings();
        $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
        $hasCreds = (string) ($forceTestnet ? ($settings['testnetApiKey'] ?? '') : ($settings['liveApiKey'] ?? '')) !== ''
            && (string) ($forceTestnet ? ($settings['testnetApiSecret'] ?? '') : ($settings['liveApiSecret'] ?? '')) !== '';

        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
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
    }

    public function recordHeartbeat(FutureTradeBotConfig $config, string $source = 'runtime'): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state['status'] = self::STATUS_CONNECTED;
        $state['last_heartbeat_at'] = now()->toIso8601String();
        $state['last_heartbeat_source'] = $source;
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
    }

    public function recordKeepAlive(FutureTradeBotConfig $config): void
    {
        $state = (array) Cache::get($this->stateKey((string) $config->id), []);
        $state['last_keepalive_at'] = now()->toIso8601String();
        $state['last_heartbeat_at'] = now()->toIso8601String();
        $state['last_heartbeat_source'] = 'keepalive';
        Cache::put($this->stateKey((string) $config->id), $state, self::STATE_TTL_SECONDS);
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

        if ($isExitMatch || (! $isEntryMatch && $trade->isOpen() && $side !== '' && $side === ($trade->side === 'LONG' ? 'SELL' : 'BUY'))) {
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
                    'payload' => ['execType' => $execType, 'order' => $order, 'realizedPnl' => $realizedPnl],
                ]);
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
                'error' => $e->getMessage(),
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
}
