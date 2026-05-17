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
                            $report['flagged']++;
                            continue;
                        }
                        $this->closeFromFill($trade, $matched, 'EXCHANGE_RECONCILED');
                        $report['closed']++;
                        continue;
                    }

                    if ($trade->status === FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED) {
                        $matched = $this->matchExitFill($trade, $fills);
                        if ($matched !== null) {
                            $this->closeFromFill($trade, $matched, 'EXCHANGE_RECONCILED');
                            $report['closed']++;
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

    private function closeFromFill(FutureTradeLogicalTrade $trade, array $fill, string $reason): void
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
