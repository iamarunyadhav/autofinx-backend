<?php

namespace App\Domain\RatioBot;

use App\Domain\Exchange\BinanceSpotClient;
use App\Domain\Exchange\DTO\ExchangeOrder;
use App\Domain\Ops\LiveExecutionGuard;
use App\Models\ExchangeConnection;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Executes ratio-bot BUY / SELL orders safely.
 *
 * Rules enforced here:
 * - All orders pass through LiveExecutionGuard (kill-switch, risk gate)
 * - Every order has a unique, stable clientOrderId derived from decision ID
 * - Binance -2010 duplicate responses → EXEC_DUPLICATE_BLOCKED (not an error, not re-submitted)
 * - Sandbox mode → simulated execution (no real orders)
 * - On uncertainty, marks decision as awaiting reconciliation
 */
class RatioBotExecutionService
{
    public function __construct(
        private readonly LiveExecutionGuard $guard,
        private readonly RatioBotStateService $stateService,
    ) {
    }

    /**
     * Submit the order for an evaluated decision (BUY or SELL).
     * Updates state and decision record based on outcome.
     */
    public function execute(RatioBotConfig $config, RatioBotDecision $decision): void
    {
        if (! in_array($decision->decision, [RatioBotDecision::DECISION_BUY, RatioBotDecision::DECISION_SELL], true)) {
            Log::debug('[RatioBotExec] Non-trade decision — skip execution', ['decision' => $decision->decision]);
            return;
        }

        $side     = $decision->decision; // BUY or SELL
        $symbol   = $config->symbol;
        $qty      = $this->resolveQuantity($config, $decision, $side);
        $notional = ($decision->evaluated_price ?? 0) * $qty;

        // --- stable idempotency key from decision ID ---
        $clientOrderId = 'rb-' . substr(str_replace('-', '', $decision->id), 0, 28);

        Log::info('[RatioBotExec] Submitting order', [
            'config_id'       => $config->id,
            'symbol'          => $symbol,
            'side'            => $side,
            'qty'             => $qty,
            'notional'        => $notional,
            'client_order_id' => $clientOrderId,
            'exec_mode'       => $config->execution_mode,
        ]);

        // --- Sandbox simulation ---
        if ($config->isSandbox()) {
            $this->simulateSandboxExecution($config, $decision, $side, (float) $decision->evaluated_price, $qty, $clientOrderId);
            return;
        }

        // --- Live execution ---
        try {
            // 1. Check all safety guards
            $this->guard->assertWorkspaceCanSubmit(
                workspaceId: (string) $config->workspace_id,
                symbol:      $symbol,
                notional:    $notional,
                provider:    $config->exchange,
                side:        strtolower($side),
                idempotencyKey: $clientOrderId,
            );
        } catch (Throwable $e) {
            Log::warning('[RatioBotExec] Guard rejected order', ['reason' => $e->getMessage(), 'config_id' => $config->id]);
            $this->stateService->onOrderFailed($config, $decision, 'guard_rejection: ' . $e->getMessage());
            return;
        }

        // 2. Build Binance client
        $client = $this->buildClient($config);
        if (! $client) {
            $this->stateService->onOrderFailed($config, $decision, 'no_exchange_connection');
            return;
        }

        // 3. Mark awaiting before submitting (so polling won't double-fire)
        $this->stateService->onAwaitingExecution($config, $decision);

        try {
            $order = $client->placeMarketOrder($symbol, $side, $qty, $clientOrderId);
            $this->handleFill($config, $decision, $order, $side, $clientOrderId);
        } catch (Throwable $e) {
            $this->handleOrderException($config, $decision, $e, $clientOrderId);
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function handleFill(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        ExchangeOrder $order,
        string $side,
        string $clientOrderId
    ): void {
        $status = strtoupper($order->status);

        if (in_array($status, ['FILLED', 'PARTIALLY_FILLED'], true)) {
            $executedPrice = $this->avgFillPrice($order);
            $executedQty   = $order->executedQty;

            if ($side === 'BUY') {
                $this->stateService->onBuyFilled($config, $decision, $executedPrice, $executedQty, $order->orderId, $clientOrderId);
            } else {
                $this->stateService->onSellFilled($config, $decision, $executedPrice, $executedQty, $order->orderId, $clientOrderId);
            }

            Log::info('[RatioBotExec] Order filled', [
                'config_id' => $config->id,
                'order_id'  => $order->orderId,
                'side'      => $side,
                'price'     => $executedPrice,
                'qty'       => $executedQty,
            ]);
            return;
        }

        // Any other status — mark as error requiring review
        $this->stateService->block($config, 'unexpected_order_status', "Order {$order->orderId} returned status {$status}");
        Log::error('[RatioBotExec] Unexpected order status', ['status' => $status, 'config_id' => $config->id]);
    }

    private function handleOrderException(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        Throwable $e,
        string $clientOrderId
    ): void {
        $message = $e->getMessage();

        // Binance -2010: duplicate order — idempotency already covered, do NOT retry
        if ($this->isDuplicateOrderError($message)) {
            $decision->update([
                'execution_status' => RatioBotDecision::EXEC_DUPLICATE_BLOCKED,
                'reason'           => 'duplicate_order_blocked: ' . $message,
            ]);
            Log::warning('[RatioBotExec] Duplicate order blocked safely', ['config_id' => $config->id, 'client_order_id' => $clientOrderId]);

            // Restore state to pre-await (keep the last known state)
            $state = $this->stateService->getOrCreate($config);
            $resumeState = $state->last_trade_side === 'SELL'
                ? \App\Models\RatioBotState::STATE_FLAT
                : \App\Models\RatioBotState::STATE_HOLDING;
            $state->update(['position_state' => $resumeState]);
            return;
        }

        Log::error('[RatioBotExec] Order submission failed', ['error' => $message, 'config_id' => $config->id]);
        $this->stateService->onOrderFailed($config, $decision, $message);
    }

    private function simulateSandboxExecution(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        string $side,
        float $price,
        float $qty,
        string $clientOrderId
    ): void {
        $fakeOrderId = 'SANDBOX-' . strtoupper(Str::random(12));

        Log::info('[RatioBotExec] Sandbox simulation', [
            'config_id' => $config->id,
            'side'      => $side,
            'price'     => $price,
            'qty'       => $qty,
        ]);

        if ($side === 'BUY') {
            $this->stateService->onBuyFilled($config, $decision, $price, $qty, $fakeOrderId, $clientOrderId);
        } else {
            $this->stateService->onSellFilled($config, $decision, $price, $qty, $fakeOrderId, $clientOrderId);
        }
    }

    private function resolveQuantity(RatioBotConfig $config, RatioBotDecision $decision, string $side): float
    {
        if ($side === 'SELL') {
            // Sell the quantity we're holding
            $state = $this->stateService->getOrCreate($config);
            $qty   = (float) ($state->last_buy_quantity ?? 0);
            if ($qty <= 0) {
                throw new RuntimeException('Cannot sell: last_buy_quantity is zero');
            }
            return $qty;
        }

        // BUY: use base_quote_amount / evaluated_price
        $quoteAmount = (float) $config->base_quote_amount;
        $price       = (float) ($decision->evaluated_price ?? 0);
        if ($price <= 0 || $quoteAmount <= 0) {
            throw new RuntimeException("Cannot buy: invalid price ({$price}) or quote amount ({$quoteAmount})");
        }

        return round($quoteAmount / $price, 8);
    }

    private function avgFillPrice(ExchangeOrder $order): float
    {
        // Use fills from raw response if available
        $fills = $order->raw['fills'] ?? [];
        if (! empty($fills)) {
            $totalQty   = 0.0;
            $totalQuote = 0.0;
            foreach ($fills as $fill) {
                $q = (float) ($fill['qty'] ?? 0);
                $p = (float) ($fill['price'] ?? 0);
                $totalQty   += $q;
                $totalQuote += $q * $p;
            }
            if ($totalQty > 0) {
                return $totalQuote / $totalQty;
            }
        }

        // Fallback: cummulativeQuoteQty / executedQty
        $cumQuote  = (float) ($order->raw['cummulativeQuoteQty'] ?? 0);
        $execQty   = $order->executedQty;
        if ($execQty > 0 && $cumQuote > 0) {
            return $cumQuote / $execQty;
        }

        // Last resort: order price or evaluated price
        return (float) ($order->price ?? 0);
    }

    private function isDuplicateOrderError(string $message): bool
    {
        // Binance error code -2010
        return str_contains($message, '-2010') || str_contains($message, 'Duplicate order');
    }

    private function buildClient(RatioBotConfig $config): ?BinanceSpotClient
    {
        $connectionId = $config->connection_id;
        if (! $connectionId) {
            Log::warning('[RatioBotExec] No connection_id on config', ['config_id' => $config->id]);
            return null;
        }

        $conn = ExchangeConnection::find($connectionId);
        if (! $conn || ! $conn->is_active) {
            Log::warning('[RatioBotExec] Connection not found or inactive', ['connection_id' => $connectionId]);
            return null;
        }

        $baseUrl = config('services.binance.base_url', 'https://api.binance.com');
        $wsUrl   = config('services.binance.ws_url', 'wss://stream.binance.com:9443');

        try {
            return new BinanceSpotClient(
                apiKey:    decrypt($conn->api_key_encrypted ?? $conn->api_key),
                apiSecret: decrypt($conn->api_secret_encrypted ?? $conn->api_secret),
                baseUrl:   $baseUrl,
                wsUrl:     $wsUrl,
            );
        } catch (Throwable $e) {
            Log::error('[RatioBotExec] Failed to build Binance client', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
