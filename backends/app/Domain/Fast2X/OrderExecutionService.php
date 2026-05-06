<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;
use App\Models\Fast2XState;
use Illuminate\Support\Str;

class OrderExecutionService
{
    public function __construct(
        private readonly TradeLedgerService $ledger,
        private readonly DecisionLogService $logs,
        private readonly BinanceSpotProvider $provider,
    ) {}

    public function executeBuy(Fast2XConfig $config, array $params): array
    {
        if ($config->executionMode() !== 'paper') {
            return $this->executeVenueBuy($config, $params);
        }

        return $this->executePaperBuy($config, $params);
    }

    public function executeSell(Fast2XConfig $config, array $params): array
    {
        if ($config->executionMode() !== 'paper') {
            return $this->executeVenueSell($config, $params);
        }

        return $this->executePaperSell($config, $params);
    }

    private function executePaperBuy(Fast2XConfig $config, array $params): array
    {
        $settings = $config->mergedSettings();
        $state = $config->state;
        $price = (float) $params['price'];
        $amount = (float) ($params['amount'] ?? $settings['tradeAmountValue'] ?? 10);
        $quantity = $amount > 1 ? $amount / $price : max(0.00000001, $amount);
        $fee = ($price * $quantity) * ((float) ($settings['feePercent'] ?? 0.1) / 100);
        $slippage = ($price * $quantity) * ((float) ($settings['estimatedSlippagePercent'] ?? 0.02) / 100);

        $state?->update([
            'state' => Fast2XState::IN_POSITION,
            'decision' => 'BUY_EXECUTED',
            'entry_price' => $price,
            'current_price' => $price,
            'quantity' => $quantity,
            'current_peak' => $price,
            'current_bottom' => null,
            'order_in_progress' => false,
            'last_signal_key' => $params['signalKey'] ?? null,
            'trade_count_today' => (int) ($state->trade_count_today ?? 0) + 1,
        ]);

        $trade = $this->ledger->record($config, [
            'side' => 'BUY',
            'entry_price' => $price,
            'quantity' => $quantity,
            'fees' => $fee,
            'slippage' => $slippage,
            'reason' => $params['reason'] ?? 'MICRO_ENTRY_SIGNAL',
            'order_id' => 'paper-buy-' . Str::uuid(),
        ]);
        $this->logs->log($config->fresh('state'), 'BUY_EXECUTED', ['currentPrice' => $price, 'reason' => $trade->reason]);

        return ['status' => 'FILLED', 'trade' => $trade];
    }

    private function executePaperSell(Fast2XConfig $config, array $params): array
    {
        $settings = $config->mergedSettings();
        $state = $config->state;
        $entry = (float) ($state?->entry_price ?? $params['entryPrice'] ?? 0);
        $price = (float) $params['price'];
        $qty = (float) ($state?->quantity ?? 0);
        $gross = ($price - $entry) * $qty;
        $fee = ($price * $qty) * ((float) ($settings['feePercent'] ?? 0.1) / 100);
        $slippage = ($price * $qty) * ((float) ($settings['estimatedSlippagePercent'] ?? 0.02) / 100);
        $net = $gross - $fee - $slippage;

        $state?->update([
            'state' => Fast2XState::COOLDOWN,
            'decision' => $params['reason'] ?? 'SELL_EXECUTED',
            'entry_price' => null,
            'current_price' => $price,
            'quantity' => 0,
            'current_peak' => null,
            'current_bottom' => $price,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) ($state->realized_pnl ?? 0) + $net,
            'net_pnl' => (float) ($state->net_pnl ?? 0) + $net,
            'daily_profit' => $net > 0 ? (float) ($state->daily_profit ?? 0) + $net : (float) ($state->daily_profit ?? 0),
            'daily_loss' => $net < 0 ? (float) ($state->daily_loss ?? 0) + abs($net) : (float) ($state->daily_loss ?? 0),
            'wins' => $net > 0 ? (int) ($state->wins ?? 0) + 1 : (int) ($state->wins ?? 0),
            'losses' => $net < 0 ? (int) ($state->losses ?? 0) + 1 : (int) ($state->losses ?? 0),
            'cooldown_until' => now()->addSeconds((int) ($settings['cooldownSeconds'] ?? 3)),
            'order_in_progress' => false,
            'last_signal_key' => $params['signalKey'] ?? null,
            'trade_count_today' => (int) ($state->trade_count_today ?? 0) + 1,
        ]);

        $trade = $this->ledger->record($config, [
            'side' => 'SELL',
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => $qty,
            'gross_pnl' => $gross,
            'fees' => $fee,
            'slippage' => $slippage,
            'net_pnl' => $net,
            'reason' => $params['reason'] ?? 'SELL_EXECUTED',
            'order_id' => 'paper-sell-' . Str::uuid(),
        ]);
        $this->logs->log($config->fresh('state'), 'SELL_EXECUTED', ['currentPrice' => $price, 'reason' => $trade->reason]);

        return ['status' => 'FILLED', 'trade' => $trade];
    }

    private function executeVenueBuy(Fast2XConfig $config, array $params): array
    {
        $settings = $config->mergedSettings();
        $state = $config->state;
        $referencePrice = (float) $params['price'];
        $quoteAmount = (float) ($params['amount'] ?? $settings['tradeAmountValue'] ?? 10);
        $response = $this->provider->configuredMarketBuy($config, $quoteAmount, $referencePrice);
        $price = (float) ($response['price'] ?? $referencePrice);
        $quantity = (float) ($response['quantity'] ?? 0);
        $fee = $this->feeFromOrder($response['order']['raw'] ?? [], $price, $quantity, $settings);
        $slippage = ($price * $quantity) * ((float) ($settings['estimatedSlippagePercent'] ?? 0.02) / 100);

        $state?->update([
            'state' => Fast2XState::IN_POSITION,
            'decision' => 'BUY_EXECUTED',
            'entry_price' => $price,
            'current_price' => $price,
            'quantity' => $quantity,
            'current_peak' => $price,
            'current_bottom' => null,
            'order_in_progress' => false,
            'last_signal_key' => $params['signalKey'] ?? null,
            'trade_count_today' => (int) ($state->trade_count_today ?? 0) + 1,
        ]);

        $trade = $this->ledger->record($config, [
            'side' => 'BUY',
            'entry_price' => $price,
            'quantity' => $quantity,
            'fees' => $fee,
            'slippage' => $slippage,
            'reason' => $params['reason'] ?? 'MICRO_ENTRY_SIGNAL',
            'order_id' => (string) ($response['order']['orderId'] ?? 'venue-buy-' . Str::uuid()),
            'status' => (string) ($response['order']['status'] ?? 'FILLED'),
            'meta' => [
                'exchange_order' => $response['order'],
                'quote_amount' => $quoteAmount,
            ],
        ]);

        $this->logs->log($config->fresh('state'), 'BUY_EXECUTED', [
            'currentPrice' => $price,
            'reason' => $trade->reason,
            'executionMode' => strtoupper($config->executionMode()),
        ]);

        return ['status' => 'FILLED', 'trade' => $trade, 'order' => $response['order']];
    }

    private function executeVenueSell(Fast2XConfig $config, array $params): array
    {
        $settings = $config->mergedSettings();
        $state = $config->state;
        $entry = (float) ($state?->entry_price ?? $params['entryPrice'] ?? 0);
        $referencePrice = (float) $params['price'];
        $quantity = (float) ($state?->quantity ?? 0);
        $response = $this->provider->configuredMarketSell($config, $quantity, $referencePrice);
        $price = (float) ($response['price'] ?? $referencePrice);
        $filledQty = (float) ($response['quantity'] ?? $quantity);
        $gross = ($price - $entry) * $filledQty;
        $fee = $this->feeFromOrder($response['order']['raw'] ?? [], $price, $filledQty, $settings);
        $slippage = ($price * $filledQty) * ((float) ($settings['estimatedSlippagePercent'] ?? 0.02) / 100);
        $net = $gross - $fee - $slippage;

        $state?->update([
            'state' => Fast2XState::COOLDOWN,
            'decision' => $params['reason'] ?? 'SELL_EXECUTED',
            'entry_price' => null,
            'current_price' => $price,
            'quantity' => 0,
            'current_peak' => null,
            'current_bottom' => $price,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) ($state->realized_pnl ?? 0) + $net,
            'net_pnl' => (float) ($state->net_pnl ?? 0) + $net,
            'daily_profit' => $net > 0 ? (float) ($state->daily_profit ?? 0) + $net : (float) ($state->daily_profit ?? 0),
            'daily_loss' => $net < 0 ? (float) ($state->daily_loss ?? 0) + abs($net) : (float) ($state->daily_loss ?? 0),
            'wins' => $net > 0 ? (int) ($state->wins ?? 0) + 1 : (int) ($state->wins ?? 0),
            'losses' => $net < 0 ? (int) ($state->losses ?? 0) + 1 : (int) ($state->losses ?? 0),
            'cooldown_until' => now()->addSeconds((int) ($settings['cooldownSeconds'] ?? 3)),
            'order_in_progress' => false,
            'last_signal_key' => $params['signalKey'] ?? null,
            'trade_count_today' => (int) ($state->trade_count_today ?? 0) + 1,
        ]);

        $trade = $this->ledger->record($config, [
            'side' => 'SELL',
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => $filledQty,
            'gross_pnl' => $gross,
            'fees' => $fee,
            'slippage' => $slippage,
            'net_pnl' => $net,
            'reason' => $params['reason'] ?? 'SELL_EXECUTED',
            'order_id' => (string) ($response['order']['orderId'] ?? 'venue-sell-' . Str::uuid()),
            'status' => (string) ($response['order']['status'] ?? 'FILLED'),
            'meta' => [
                'exchange_order' => $response['order'],
            ],
        ]);

        $this->logs->log($config->fresh('state'), 'SELL_EXECUTED', [
            'currentPrice' => $price,
            'reason' => $trade->reason,
            'executionMode' => strtoupper($config->executionMode()),
        ]);

        return ['status' => 'FILLED', 'trade' => $trade, 'order' => $response['order']];
    }

    private function feeFromOrder(array $rawOrder, float $price, float $quantity, array $settings): float
    {
        $fills = (array) ($rawOrder['fills'] ?? []);
        if ($fills !== []) {
            $fee = 0.0;
            foreach ($fills as $fill) {
                $fee += (float) ($fill['commission'] ?? 0);
            }

            if ($fee > 0) {
                return $fee;
            }
        }

        return ($price * $quantity) * ((float) ($settings['feePercent'] ?? 0.1) / 100);
    }
}
