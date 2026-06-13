<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\FutureTradeLogicalTradeEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciles local logical trades against Binance Futures REST state.
 *
 * - Fetches positions, open orders, and recent fills per symbol that has an
 *   active or recently-active logical trade.
 * - If local says OPEN but exchange says flat → close logical trade using the
 *   matching fill (or mark RECONCILIATION_REQUIRED if no fill is found).
 * - If local says CLOSED but the exit price diverges materially from the
 *   exchange-reported avg fill → correct the row and emit a RECONCILED event.
 * - Never invents an exit price from local data.
 */
class FutureTradeReconciliationService
{
    public function __construct(private readonly FutureTradePnLService $pnl) {}

    public function reconcileConfig(FutureTradeBotConfig $config, BinanceFuturesClient $client, ?string $singleSymbol = null): array
    {
        $report = [
            'config_id' => $config->id,
            'symbols' => [],
            'closed' => 0,
            'corrected' => 0,
            'flagged' => 0,
            'state_cleared' => 0,
            'errors' => [],
        ];

        $query = FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->whereIn('status', [
                FutureTradeLogicalTrade::STATUS_OPEN,
                FutureTradeLogicalTrade::STATUS_CLOSING,
                FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED,
            ]);
        if ($singleSymbol) {
            $query->where('symbol', strtoupper($singleSymbol));
        }

        $tradesBySymbol = $query->get()->groupBy('symbol');

        foreach ($tradesBySymbol as $symbol => $trades) {
            try {
                $report['symbols'][] = $symbol;
                $position = $this->safeFetch(fn () => $client->fetchPositionRisk($symbol)) ?? [];
                $exchangeQty = $this->extractPositionQuantity($position, $symbol);
                $exchangeSide = $this->extractPositionSide($position, $symbol);
                $fills = $this->safeFetch(fn () => $client->fetchUserTrades($symbol, now()->subDays(2)->getTimestampMs(), 200)) ?? [];

                foreach ($trades as $trade) {
                    if ($exchangeQty <= 0 && $trade->isOpen()) {
                        $matched = $this->matchExitFill($trade, $fills);
                        if ($matched === null) {
                            $trade->update([
                                'status' => FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED,
                                'reconciled_at' => now(),
                            ]);
                            $this->event($trade, FutureTradeLogicalTradeEvent::EVENT_RECONCILED, [
                                'note' => 'Exchange flat but no matching fill — manual review required.',
                            ]);
                            if ($this->clearRuntimePositionState($config, (string) $symbol, 'EXCHANGE_FLAT_RECONCILE_REQUIRED')) {
                                $report['state_cleared']++;
                            }
                            $report['flagged']++;
                            continue;
                        }
                        $close = $this->closeFromFill($trade, $matched, 'EXCHANGE_RECONCILED');
                        if ($this->clearRuntimePositionState($config, (string) $symbol, 'EXCHANGE_RECONCILED', $close)) {
                            $report['state_cleared']++;
                        }
                        $report['closed']++;
                        continue;
                    }

                    if ($trade->status === FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED) {
                        $matched = $this->matchExitFill($trade, $fills);
                        if ($matched !== null) {
                            $close = $this->closeFromFill($trade, $matched, 'EXCHANGE_RECONCILED');
                            if ($this->clearRuntimePositionState($config, (string) $symbol, 'EXCHANGE_RECONCILED', $close)) {
                                $report['state_cleared']++;
                            }
                            $report['closed']++;
                        } elseif ($exchangeQty <= 0 && $this->clearRuntimePositionState($config, (string) $symbol, 'EXCHANGE_FLAT_RECONCILE_REQUIRED')) {
                            $report['state_cleared']++;
                        }
                    }
                }
            } catch (Throwable $e) {
                $report['errors'][] = ['symbol' => $symbol, 'error' => $e->getMessage()];
                Log::warning('[FT RECONCILE ERROR]', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            }
        }

        return $report;
    }

    private function matchExitFill(FutureTradeLogicalTrade $trade, array $fills): ?array
    {
        // Preferred: fill whose orderId or clientOrderId matches the exit_*_order_id we recorded.
        foreach ($fills as $fill) {
            $orderId = (string) ($fill['orderId'] ?? '');
            $clientId = (string) ($fill['clientOrderId'] ?? '');
            if (
                ($trade->exit_exchange_order_id && $orderId !== '' && $orderId === (string) $trade->exit_exchange_order_id)
                || ($trade->exit_client_order_id && $clientId !== '' && $clientId === (string) $trade->exit_client_order_id)
            ) {
                return $fill;
            }
        }

        // Fallback: most recent fill on the opposing side (close side) that came after entry.
        $entrySideOpposite = $trade->side === 'LONG' ? 'SELL' : 'BUY';
        $openedAtMs = $trade->opened_at ? Carbon::parse($trade->opened_at)->getTimestampMs() : 0;
        $candidates = array_filter($fills, function ($f) use ($entrySideOpposite, $openedAtMs) {
            return strtoupper((string) ($f['side'] ?? '')) === $entrySideOpposite
                && (int) ($f['time'] ?? 0) >= $openedAtMs;
        });
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn ($a, $b) => (int) ($b['time'] ?? 0) <=> (int) ($a['time'] ?? 0));
        return $candidates[0];
    }

    private function closeFromFill(FutureTradeLogicalTrade $trade, array $fill, string $reason): array
    {
        $exitPrice = (float) ($fill['price'] ?? 0);
        $qty = (float) ($fill['qty'] ?? $trade->quantity);
        $commission = (float) ($fill['commission'] ?? 0);
        $direction = $trade->side === 'LONG' ? 1 : -1;
        $gross = ($exitPrice - (float) $trade->entry_price) * $qty * $direction;
        $existingFees = (float) ($trade->fees ?? 0);
        $fees = $existingFees + abs($commission);
        $net = $gross - $fees;

        $trade->update([
            'exit_price' => $exitPrice,
            'gross_pnl' => $gross,
            'fees' => $fees,
            'net_pnl' => $net,
            'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
            'close_reason' => $reason,
            'closed_at' => now(),
            'reconciled_at' => now(),
            'exit_exchange_order_id' => (string) ($fill['orderId'] ?? $trade->exit_exchange_order_id),
            'exit_client_order_id' => (string) ($fill['clientOrderId'] ?? $trade->exit_client_order_id),
        ]);

        $this->event($trade, FutureTradeLogicalTradeEvent::EVENT_EXIT_FILLED, [
            'fill' => $fill,
            'reason' => $reason,
        ]);

        return [
            'exit_price' => $exitPrice,
            'net_pnl' => $net,
        ];
    }

    private function event(FutureTradeLogicalTrade $trade, string $type, array $payload = []): void
    {
        FutureTradeLogicalTradeEvent::create([
            'future_trade_logical_trade_id' => $trade->id,
            'future_trade_bot_config_id' => $trade->future_trade_bot_config_id,
            'symbol' => $trade->symbol,
            'event_type' => $type,
            'payload' => $payload,
        ]);
    }

    private function clearRuntimePositionState(FutureTradeBotConfig $config, string $symbol, string $reason, ?array $close = null): bool
    {
        $state = $config->fresh('state')->state;
        if (! $state) {
            return false;
        }

        $symbol = strtoupper($symbol);
        $localSymbol = strtoupper((string) ($state->current_symbol ?? ''));
        if ($localSymbol !== '' && $localSymbol !== $symbol) {
            return false;
        }

        $localOpen = (float) ($state->quantity ?? 0) > 0
            || in_array((string) ($state->state ?? ''), ['IN_POSITION', 'LIVE_IN_POSITION'], true);
        if (! $localOpen) {
            return false;
        }

        $netPnl = (float) ($close['net_pnl'] ?? 0);
        $exitPrice = (float) ($close['exit_price'] ?? $state->current_price ?? 0);
        $patch = [
            'state' => $config->enabled ? 'SCANNING' : 'STOPPED',
            'status' => $config->enabled ? 'Running' : 'Stopped',
            'current_symbol' => null,
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'peak_price' => null,
            'bottom_price' => null,
            'current_price' => $exitPrice,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'current_signal' => $reason,
            'connection_status' => 'Connected',
        ];

        if ($close !== null) {
            $patch += [
                'realized_pnl' => (float) ($state->realized_pnl ?? 0) + $netPnl,
                'daily_profit' => $netPnl > 0 ? (float) ($state->daily_profit ?? 0) + $netPnl : (float) ($state->daily_profit ?? 0),
                'daily_loss' => $netPnl < 0 ? (float) ($state->daily_loss ?? 0) + abs($netPnl) : (float) ($state->daily_loss ?? 0),
                'wins' => $netPnl > 0 ? (int) ($state->wins ?? 0) + 1 : (int) ($state->wins ?? 0),
                'losses' => $netPnl < 0 ? (int) ($state->losses ?? 0) + 1 : (int) ($state->losses ?? 0),
            ];
        }

        $state->update($patch);
        Log::info('[FT RECONCILE STATE CLEARED]', [
            'config_id' => (string) $config->id,
            'symbol' => $symbol,
            'reason' => $reason,
            'netPnl' => $netPnl,
        ]);

        return true;
    }

    private function safeFetch(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::warning('[FT RECONCILE FETCH ERROR]', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function extractPositionQuantity(array $rows, string $symbol): float
    {
        foreach ((array) $rows as $row) {
            if (strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol)) {
                return abs((float) ($row['positionAmt'] ?? 0));
            }
        }
        return 0.0;
    }

    private function extractPositionSide(array $rows, string $symbol): ?string
    {
        foreach ((array) $rows as $row) {
            if (strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol)) {
                $amt = (float) ($row['positionAmt'] ?? 0);
                if ($amt === 0.0) {
                    return null;
                }
                return $amt > 0 ? 'LONG' : 'SHORT';
            }
        }
        return null;
    }
}
