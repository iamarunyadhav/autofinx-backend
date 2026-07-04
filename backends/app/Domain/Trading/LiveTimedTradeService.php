<?php

namespace App\Domain\Trading;

use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Models\Integration;
use App\Models\Order;
use App\Models\QuickTrade;
use App\Models\Trade;
use Illuminate\Support\Str;

class LiveTimedTradeService
{
    public function __construct(
        private readonly LiveTradeProviderRegistry $providers,
        private readonly TradingMarketService $market,
    ) {
    }

    public function executeEntry(
        Integration $integration,
        string $symbol,
        string $side,
        float $stakeAmount,
        string $idempotencyKey,
    ): array {
        return $this->providers
            ->for((string) $integration->provider_key)
            ->openTimedTrade($integration, $symbol, $side, $stakeAmount, $idempotencyKey);
    }

    public function executeExit(Integration $integration, QuickTrade $trade, string $idempotencyKey): array
    {
        return $this->executeClose(
            $integration,
            $trade->symbol,
            (string) $trade->side,
            (float) ($trade->quantity ?? 0),
            $idempotencyKey,
            (string) ($trade->provider_key ?: $integration->provider_key),
        );
    }

    public function executeClose(
        Integration $integration,
        string $symbol,
        string $entrySide,
        float $quantity,
        string $idempotencyKey,
        ?string $providerKey = null,
    ): array {
        return $this->providers
            ->for((string) ($providerKey ?: $integration->provider_key))
            ->closeTimedTrade(
                $integration,
                $symbol,
                $entrySide,
                $quantity,
                $idempotencyKey
            );
    }

    public function bookEntry(
        Integration $integration,
        array $payload,
        array $execution,
        string $traceId,
    ): array {
        $symbol = strtoupper((string) $payload['symbol']);
        $side = strtolower((string) $payload['side']);
        $quantity = round((float) ($execution['quantity'] ?? 0), 8);
        $price = round((float) ($execution['price'] ?? 0), 8);
        $quoteCurrency = strtoupper((string) ($execution['quote_currency'] ?? 'USDT'));
        $symbolModel = $this->market->resolveSymbol($symbol);
        $rawResponse = (array) ($execution['response'] ?? []);
        $fee = round((float) ($execution['estimated_fee'] ?? 0), 8);
        $slippagePct = array_key_exists('slippage_pct', $execution) ? $execution['slippage_pct'] : null;

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $payload['workspace_id'],
            'integration_id' => $integration->id,
            'symbol_id' => $symbolModel->id,
            'side' => $side,
            'type' => 'timed_market_entry',
            'quantity' => $quantity,
            'qty' => $side === 'buy' ? $quantity : -1 * $quantity,
            'requested_qty' => $quantity,
            'filled_qty' => $quantity,
            'price' => $price,
            'limit_price' => $price,
            'avg_fill_price' => $price,
            'fee_amount' => $fee,
            'fee_asset' => $quoteCurrency,
            'slippage_pct' => $slippagePct,
            'status' => 'FILLED',
            'meta' => [
                'source' => $payload['source'],
                'mode' => 'live',
                'stake_amount' => $payload['stake_amount'],
                'expiry_seconds' => $payload['expiry_seconds'],
                'timeframe' => $payload['timeframe'],
                'provider_execution' => 'timed_futures',
                'symbol_display' => $payload['symbol_display'] ?? $symbol,
                'reference_price' => $execution['reference_price'] ?? null,
                'notional' => $execution['notional'] ?? null,
                'estimated_fee_rate' => $execution['estimated_fee_rate'] ?? null,
                'estimated_fee' => $fee,
                'slippage_pct' => $slippagePct,
            ],
            'raw_json' => $rawResponse,
            'idempotency_key' => $payload['idempotency_key'],
            'trace_id' => $traceId,
            'asset_class' => strtoupper((string) ($payload['asset_class'] ?? 'CRYPTO')),
            'asset_code' => $symbol,
            'quote_currency' => $quoteCurrency,
            'provider' => strtolower((string) $integration->provider_key),
            'exchange_order_id' => (string) ($rawResponse['orderId'] ?? ''),
            'placed_ts' => now(),
            'filled_ts' => now(),
        ]);

        $trade = Trade::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'workspace_id' => $payload['workspace_id'],
            'integration_id' => $integration->id,
            'asset_class' => strtoupper((string) ($payload['asset_class'] ?? 'CRYPTO')),
            'asset_code' => $symbol,
            'quote_currency' => $quoteCurrency,
            'provider' => strtolower((string) $integration->provider_key),
            'symbol' => $symbol,
            'side' => strtoupper($side),
            'price' => $price,
            'avg_price' => $price,
            'quantity' => $side === 'buy' ? $quantity : -1 * $quantity,
            'qty' => $quantity,
            'fee' => $fee,
            'fees' => $fee,
            'realized_pnl' => 0,
            'executed_at' => now(),
            'ts' => now(),
            'trace_id' => $traceId,
            'exchange_trade_id' => (string) ($rawResponse['orderId'] ?? ''),
            'meta' => [
                'source' => $payload['source'],
                'execution_type' => 'entry',
                'reference_price' => $execution['reference_price'] ?? null,
                'notional' => $execution['notional'] ?? null,
                'estimated_fee_rate' => $execution['estimated_fee_rate'] ?? null,
                'estimated_fee' => $fee,
                'slippage_pct' => $slippagePct,
            ],
        ]);

        return [
            'order' => $order,
            'trade' => $trade,
        ];
    }

    public function bookExit(
        QuickTrade $quickTrade,
        Integration $integration,
        array $execution,
        string $traceId,
        float $profitLoss,
    ): array {
        $entrySide = strtolower((string) $quickTrade->side);
        $closeSide = $entrySide === 'sell' ? 'buy' : 'sell';
        $quantity = round((float) ($execution['quantity'] ?? $quickTrade->quantity ?? 0), 8);
        $price = round((float) ($execution['price'] ?? 0), 8);
        $quoteCurrency = strtoupper((string) ($execution['quote_currency'] ?? $quickTrade->quote_currency ?? 'USDT'));
        $rawResponse = (array) ($execution['response'] ?? []);
        $symbolModel = $quickTrade->order?->symbol ?: $this->market->resolveSymbol($quickTrade->symbol);
        $fee = round((float) ($execution['estimated_fee'] ?? 0), 8);
        $slippagePct = array_key_exists('slippage_pct', $execution) ? $execution['slippage_pct'] : null;

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $quickTrade->workspace_id,
            'integration_id' => $integration->id,
            'symbol_id' => $symbolModel->id,
            'side' => $closeSide,
            'type' => 'timed_market_exit',
            'quantity' => $quantity,
            'qty' => $closeSide === 'buy' ? $quantity : -1 * $quantity,
            'requested_qty' => $quantity,
            'filled_qty' => $quantity,
            'price' => $price,
            'limit_price' => $price,
            'avg_fill_price' => $price,
            'fee_amount' => $fee,
            'fee_asset' => $quoteCurrency,
            'slippage_pct' => $slippagePct,
            'status' => 'FILLED',
            'meta' => [
                'source' => $quickTrade->source,
                'mode' => 'live',
                'quick_trade_id' => $quickTrade->id,
                'execution_type' => 'expiry_close',
                'reference_price' => $execution['reference_price'] ?? null,
                'notional' => $execution['notional'] ?? null,
                'estimated_fee_rate' => $execution['estimated_fee_rate'] ?? null,
                'estimated_fee' => $fee,
                'slippage_pct' => $slippagePct,
            ],
            'raw_json' => $rawResponse,
            'idempotency_key' => (string) Str::uuid(),
            'trace_id' => $traceId,
            'asset_class' => $quickTrade->asset_class,
            'asset_code' => $quickTrade->symbol,
            'quote_currency' => $quoteCurrency,
            'provider' => strtolower((string) $integration->provider_key),
            'exchange_order_id' => (string) ($rawResponse['orderId'] ?? ''),
            'placed_ts' => now(),
            'filled_ts' => now(),
        ]);

        $trade = Trade::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'workspace_id' => $quickTrade->workspace_id,
            'integration_id' => $integration->id,
            'asset_class' => $quickTrade->asset_class,
            'asset_code' => $quickTrade->symbol,
            'quote_currency' => $quoteCurrency,
            'provider' => strtolower((string) $integration->provider_key),
            'symbol' => $quickTrade->symbol,
            'side' => strtoupper($closeSide),
            'price' => $price,
            'avg_price' => $price,
            'quantity' => $closeSide === 'buy' ? $quantity : -1 * $quantity,
            'qty' => $quantity,
            'fee' => $fee,
            'fees' => $fee,
            'realized_pnl' => $profitLoss,
            'executed_at' => now(),
            'ts' => now(),
            'trace_id' => $traceId,
            'exchange_trade_id' => (string) ($rawResponse['orderId'] ?? ''),
            'meta' => [
                'source' => $quickTrade->source,
                'quick_trade_id' => $quickTrade->id,
                'execution_type' => 'exit',
                'entry_price' => (float) $quickTrade->entry_price,
                'exit_price' => $price,
                'reference_price' => $execution['reference_price'] ?? null,
                'notional' => $execution['notional'] ?? null,
                'estimated_fee_rate' => $execution['estimated_fee_rate'] ?? null,
                'estimated_fee' => $fee,
                'slippage_pct' => $slippagePct,
            ],
        ]);

        return [
            'order' => $order,
            'trade' => $trade,
        ];
    }

    public function pnlFor(QuickTrade $trade, float $exitPrice): float
    {
        $entryPrice = (float) $trade->entry_price;
        $quantity = (float) ($trade->quantity ?? 0);
        if ($quantity <= 0) {
            return 0.0;
        }

        $delta = strtolower((string) $trade->side) === 'sell'
            ? ($entryPrice - $exitPrice)
            : ($exitPrice - $entryPrice);

        return round($delta * $quantity, 8);
    }
}
