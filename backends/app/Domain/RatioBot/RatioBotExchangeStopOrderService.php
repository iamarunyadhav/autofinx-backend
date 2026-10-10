<?php

namespace App\Domain\RatioBot;

use App\Domain\Exchange\BinanceConditionalOrderException;
use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\Position;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\Symbol;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Exchange-side reduce-only stop order (Part C.1) -- the real backstop
 * against a dead daemon or crashed server: this order lives on Binance
 * itself, so Binance watches the price and executes the close even if
 * nothing in this app is running. Independent of, and complementary to,
 * RatioBotLiquidationGuardService's app-side monitoring (different failure
 * modes: an app-logic bug vs. the app process being down entirely) --
 * keep both; this does not replace that.
 *
 * This reduces liquidation risk, it does not eliminate it: a genuine flash
 * move can still fill worse than stopPrice. Never describe this as a
 * guarantee anywhere it is surfaced.
 */
class RatioBotExchangeStopOrderService
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly RatioBotLiquidationGuardService $liquidationGuard,
    ) {
    }

    /**
     * Attached to every event this service emits (Part C.3's "always on"
     * detection scope) -- an operator must never be blind to two bots
     * looking at the same exchange position, on any event this feature
     * produces, not only Part A's own.
     */
    private function sharedPositionContext(RatioBotConfig $config): array
    {
        $siblings = $this->liquidationGuard->sharedPositionSiblings($config);

        return [
            'shared_position' => $siblings !== [],
            'sibling_bots' => $siblings,
        ];
    }

    /**
     * Call after every futures buy fill that opens or adds to a position.
     * Recomputes stopPrice from the bot's own critical buffer against the
     * freshly-synced real liquidation price (already refreshed by the
     * FuturesOrderIntentService::place() call that produced this fill),
     * and only cancels + replaces when the cost basis actually changed --
     * re-placing an unchanged stop on every tick would just be exchange
     * API noise, and the basis comparison is what makes a retried job
     * idempotent instead of accumulating duplicate orders.
     */
    public function syncStopOrder(RatioBotConfig $config, Integration $integration, array $workspaceConfig): void
    {
        if (! $config->liquidationGuardEnabled()) {
            return;
        }

        $state = $config->state()->first();
        if (! $state || (float) ($state->last_position_qty ?? 0) <= 0) {
            return;
        }

        $position = $this->syncedPosition($config);
        $liquidationPrice = $position?->liquidation_price !== null ? (float) $position->liquidation_price : null;
        if ($liquidationPrice === null || $liquidationPrice <= 0) {
            return; // nothing to compute a stop against yet -- the next fill/reconcile will retry
        }

        $isShort = strtolower((string) ($state->last_position_side ?? 'long')) === 'short';
        $critical = $config->liquidationCriticalBufferPct() / 100;
        $stopPrice = $isShort
            ? $liquidationPrice / (1 + $critical)
            : $liquidationPrice / (1 - $critical);

        if ($stopPrice <= 0) {
            return;
        }

        $basisPrice = (float) ($state->entry_price ?? 0);
        $basisQty = (float) ($state->last_position_qty ?? 0);
        $unchanged = $state->futures_stop_order_id !== null
            && abs($basisPrice - (float) ($state->futures_stop_basis_price ?? 0)) < 0.00000001
            && abs($basisQty - (float) ($state->futures_stop_basis_qty ?? 0)) < 0.00000001;

        if ($unchanged) {
            return;
        }

        $wasReplace = $state->futures_stop_order_id !== null;
        if ($wasReplace) {
            $this->cancelExchangeOrder($config, $integration, $workspaceConfig, (string) $state->futures_stop_client_order_id);
        }

        $side = $isShort ? 'BUY' : 'SELL';
        $clientOrderId = $this->newClientOrderId($config);

        try {
            $response = $this->client($integration, $workspaceConfig)
                ->placeReduceOnlyStopOrder($config->normalizedSymbol(), $side, 'STOP_MARKET', $stopPrice, $clientOrderId);
        } catch (Throwable $e) {
            // Logging only (no behaviour change): the exact Binance code/message and a critical line
            // so "position has no exchange-side stop" is never a silent warning.
            $conditional = $e instanceof BinanceConditionalOrderException ? $e : null;
            Log::critical('[RatioBotStopOrder] Failed to place exchange-side stop - position has NO confirmed exchange-side stop', [
                'config_id' => $config->id,
                'client_order_id' => $clientOrderId,
                'binance_code' => $conditional?->binanceCode,
                'binance_msg' => $conditional?->binanceMsg,
                'endpoint' => $conditional?->endpoint,
                'outcome' => $conditional?->outcome,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $state->update([
            'futures_stop_order_id' => (string) ($response['orderId'] ?? ''),
            'futures_stop_client_order_id' => $clientOrderId,
            'futures_stop_price' => round($stopPrice, 8),
            'futures_stop_basis_price' => $basisPrice,
            'futures_stop_basis_qty' => $basisQty,
        ]);

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => $wasReplace ? RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_REPLACED : RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_PLACED,
            'context' => [
                'stop_price' => round($stopPrice, 8),
                'side' => $side,
                'client_order_id' => $clientOrderId,
                'exchange_order_id' => $response['orderId'] ?? null,
                'liquidation_price' => $liquidationPrice,
            ] + $this->sharedPositionContext($config),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Call when a position closes -- either a normal EXIT fill or the
     * app-side Liquidation Guard's own force-exit (see
     * RatioBotRealtimeEngine::forceExitDecision()). A closed position
     * should never have a dangling reduce-only stop left on the account.
     */
    public function cancelStopOrder(RatioBotConfig $config, Integration $integration, array $workspaceConfig, string $reason): void
    {
        $state = $config->state()->first();
        if (! $state || $state->futures_stop_order_id === null) {
            return;
        }

        $this->cancelExchangeOrder($config, $integration, $workspaceConfig, (string) $state->futures_stop_client_order_id);

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_CANCELLED,
            'context' => ['reason' => $reason, 'client_order_id' => $state->futures_stop_client_order_id] + $this->sharedPositionContext($config),
            'occurred_at' => now(),
        ]);

        $state->update([
            'futures_stop_order_id' => null,
            'futures_stop_client_order_id' => null,
            'futures_stop_price' => null,
            'futures_stop_basis_price' => null,
            'futures_stop_basis_qty' => null,
        ]);
    }

    private function cancelExchangeOrder(RatioBotConfig $config, Integration $integration, array $workspaceConfig, string $clientOrderId): void
    {
        if ($clientOrderId === '') {
            return;
        }

        try {
            $this->client($integration, $workspaceConfig)->cancelOrder($config->normalizedSymbol(), null, $clientOrderId);
        } catch (Throwable $e) {
            // The order may already be gone (filled/expired) -- a cancel
            // failing for an already-closed stop is not itself an
            // emergency, just worth logging for the audit trail.
            Log::info('[RatioBotStopOrder] Cancel attempt failed (order may already be closed/filled)', [
                'config_id' => $config->id,
                'client_order_id' => $clientOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function newClientOrderId(RatioBotConfig $config): string
    {
        return substr('rbstop-' . str_replace('-', '', (string) $config->id) . '-' . now()->format('His'), 0, 36);
    }

    private function syncedPosition(RatioBotConfig $config): ?Position
    {
        $symbol = Symbol::query()->where('symbol', $config->normalizedSymbol())->first();
        if (! $symbol) {
            return null;
        }

        return Position::query()
            ->where('workspace_id', (string) $config->workspace_id)
            ->where('symbol_id', $symbol->id)
            ->where('market_type', 'futures')
            ->first();
    }

    private function client(Integration $integration, array $config): BinanceFuturesClient
    {
        $creds = $this->integrations->decryptSecrets($integration->secrets_encrypted);
        if ($creds === []) {
            throw new RuntimeException('Binance futures credentials are missing.');
        }

        return new BinanceFuturesClient(
            apiKey: (string) ($creds['api_key'] ?? $creds['key'] ?? ''),
            apiSecret: (string) ($creds['api_secret'] ?? $creds['secret'] ?? ''),
            baseUrl: (string) (($config['futures_force_testnet'] ?? true)
                ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
                : config('services.binance.futures_api_url', 'https://fapi.binance.com')),
        );
    }
}
