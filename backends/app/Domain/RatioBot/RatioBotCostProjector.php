<?php

namespace App\Domain\RatioBot;

use RuntimeException;

/** Reporting only. Never changes positions, orders, balances, or strategy state. */
class RatioBotCostProjector
{
    public function project(array $trades, array $income, array $orders, string $symbol, string $quote,
        ?float $currentAccountQty, bool $historyComplete, bool $futures): array
    {
        $unique = [];
        foreach ($trades as $row) {
            foreach (['id', 'orderId', 'symbol', 'time', 'price', 'qty', 'commission', 'commissionAsset'] as $key) {
                if (! isset($row[$key])) {
                    throw new RuntimeException('Incomplete exchange fill: '.$key);
                }
            }
            foreach (['price', 'qty', 'commission', 'time'] as $key) {
                if (! is_numeric($row[$key]) || ! is_finite((float) $row[$key])) {
                    throw new RuntimeException('Invalid exchange fill number: '.$key);
                }
            }
            if ($row['symbol'] !== $symbol || (float) $row['qty'] <= 0 || (float) $row['price'] <= 0) {
                throw new RuntimeException('Exchange fill symbol/quantity mismatch.');
            }
            if ($futures && (! isset($row['realizedPnl']) || ! is_numeric($row['realizedPnl']) || ! is_finite((float) $row['realizedPnl']))) {
                throw new RuntimeException('Missing exchange realized PnL.');
            }
            $row['side'] = strtoupper((string) ($row['side'] ?? (isset($row['isBuyer']) ? ($row['isBuyer'] ? 'BUY' : 'SELL') : '')));
            if (! in_array($row['side'], ['BUY', 'SELL'], true)) {
                throw new RuntimeException('Missing exchange fill side.');
            }
            $unique[(string) $row['id']] = $row;
        }
        $trades = array_values($unique);
        usort($trades, fn ($a, $b) => ($a['time'] <=> $b['time']) ?: ($a['id'] <=> $b['id']));

        // Prove a flat start from a stable current snapshot and the complete signed fill history.
        $delta = array_sum(array_map(fn ($r) => ($r['side'] === 'BUY' ? 1 : -1) * (float) $r['qty'], $trades));
        $exclusive = $futures && $historyComplete && $currentAccountQty !== null
            && abs($delta - $currentAccountQty) < 1e-8;
        $inventory = 0.0;
        $external = [];
        foreach ($trades as $row) {
            $qty = ($row['side'] === 'BUY' ? 1 : -1) * (float) $row['qty'];
            if (($row['positionSide'] ?? 'BOTH') !== 'BOTH') {
                $exclusive = false; // aggregate FUNDING_FEE has no hedge-leg attribution
            }
            if (! isset($orders[(string) $row['orderId']])) {
                // A manual/exchange close is attributable only when the entire position
                // was exclusively ours. Unknown openings/reversals invalidate that proof.
                if ($inventory * $qty < 0 && abs($qty) <= abs($inventory) + 1e-8) {
                    $external[(string) $row['orderId']] = true;
                } else {
                    $exclusive = false;
                }
            }
            $inventory += $qty;
        }
        if (! $exclusive) {
            $external = [];
        }

        $fee = 0.0;
        $gross = 0.0;
        $audit = [];
        $feesComplete = true;
        $spotQty = 0.0;
        $spotBasis = 0.0;
        $spotBasisComplete = true;
        foreach ($trades as $row) {
            $id = (string) $row['orderId'];
            if (! isset($orders[$id]) && ! isset($external[$id])) {
                continue;
            }
            $cost = (float) $row['commission'];
            $asset = (string) $row['commissionAsset'];
            $converted = $asset === $quote ? $cost : null;
            // Base-asset spot commission can be valued at its own execution price.
            if (! $futures && $asset === substr($symbol, 0, -strlen($quote))) {
                $converted = $cost * (float) $row['price'];
            }
            $feesComplete = $feesComplete && $converted !== null;
            $fee += $converted ?? 0;
            $gross += $futures ? (float) $row['realizedPnl'] : 0;
            if (! $futures) {
                $qty = (float) $row['qty'];
                $price = (float) $row['price'];
                if ($row['side'] === 'BUY') {
                    $spotQty += $qty;
                    $spotBasis += $qty * $price;
                } elseif ($qty > $spotQty + 1e-8 || $spotQty <= 0) {
                    $spotBasisComplete = false; // onboarded/untracked inventory has no proven cost basis
                } else {
                    $average = $spotBasis / $spotQty;
                    $gross += ($price - $average) * $qty;
                    $spotQty -= $qty;
                    $spotBasis -= $average * $qty;
                }
                if ($asset === substr($symbol, 0, -strlen($quote)) && $cost != 0) {
                    if ($cost < 0 || $cost > $spotQty + 1e-8 || $spotQty <= 0) {
                        $spotBasisComplete = false;
                    } else {
                        $average = $spotBasis / $spotQty;
                        // Base commission consumes inventory. Its disposal gain plus the
                        // fee expense avoids counting this loss twice at the eventual exit.
                        $gross += ($price - $average) * $cost;
                        $spotQty -= $cost;
                        $spotBasis -= $average * $cost;
                    }
                }
            }
            $entry = $audit[$id] ?? ['exchange_order_id' => $id, 'filled_qty' => 0.0,
                'first_fill_time' => (int) $row['time'], 'last_fill_time' => (int) $row['time'],
                'quote_qty' => 0.0, 'commission_quote' => 0.0, 'commission_by_asset' => [],
                'exchange_realized_pnl' => $futures ? 0.0 : null, 'source' => 'real',
                'attribution' => isset($external[$id]) ? 'exclusive_position_external_close' : 'order_id'];
            $entry['filled_qty'] += (float) $row['qty'];
            $entry['last_fill_time'] = (int) $row['time'];
            $entry['quote_qty'] += (float) $row['qty'] * (float) $row['price'];
            $entry['commission_quote'] += $converted ?? 0;
            $entry['commission_by_asset'][$asset] = ($entry['commission_by_asset'][$asset] ?? 0) + $cost;
            if ($futures) {
                $entry['exchange_realized_pnl'] += (float) $row['realizedPnl'];
            }
            if ($converted === null) {
                $entry['source'] = 'partial';
            }
            $audit[$id] = $entry;
        }
        foreach ($orders as $id => $order) {
            $actual = $audit[$id]['filled_qty'] ?? 0;
            if (isset($order['qty']) && (float) $order['qty'] > 0 && ($actual <= 0 || abs($actual - (float) $order['qty']) > 1e-8)) {
                $feesComplete = false;
            }
        }
        foreach ($audit as $id => &$entry) {
            $entry['fill_price'] = $entry['quote_qty'] / $entry['filled_qty'];
            $reference = (float) ($orders[$id]['reference_price'] ?? 0);
            $entry['reference_price'] = $reference > 0 ? $reference : null;
            $entry['slippage_quote'] = $reference > 0 ? $entry['quote_qty'] - $reference * $entry['filled_qty'] : null;
        }
        unset($entry);

        $funding = 0.0;
        $fundingAudit = [];
        foreach ($income as $row) {
            if (($row['symbol'] ?? '') !== $symbol || ($row['incomeType'] ?? '') !== 'FUNDING_FEE'
                || ! isset($row['tranId'], $row['time'], $row['income'], $row['asset'])
                || ! is_numeric($row['income']) || ! is_finite((float) $row['income'])) {
                throw new RuntimeException('Invalid funding income evidence.');
            }
            $position = 0.0;
            $boundaryAmbiguous = false;
            foreach ($trades as $trade) {
                if ((int) $trade['time'] === (int) $row['time']) {
                    $boundaryAmbiguous = true;
                }
                if ($trade['time'] < $row['time']) {
                    $position += ($trade['side'] === 'BUY' ? 1 : -1) * (float) $trade['qty'];
                }
            }
            $allocated = $exclusive && ! $boundaryAmbiguous && abs($position) > 1e-8 && $row['asset'] === $quote;
            $fundingAudit[(string) $row['tranId']] = [
                'transaction_id' => (string) $row['tranId'], 'time' => $row['time'],
                'income' => (float) $row['income'], 'asset' => $row['asset'],
                'cost' => $allocated ? -(float) $row['income'] : null,
                'source' => $allocated ? 'real' : 'unavailable',
            ];
        }
        $fundingComplete = ! $futures || ($exclusive && $historyComplete);
        foreach ($fundingAudit as $row) {
            $funding += $row['cost'] ?? 0;
            $fundingComplete = $fundingComplete && $row['source'] === 'real';
        }
        $feesComplete = $feesComplete && $audit !== [];
        $basisComplete = $futures ? $exclusive : $spotBasisComplete;
        $complete = $feesComplete && $fundingComplete && $basisComplete;
        return [
            'fee_source' => $feesComplete ? 'real' : ($audit === [] ? 'unavailable' : 'partial'),
            'funding_source' => ! $futures ? 'not_applicable' : ($fundingComplete ? 'real' : 'unavailable'),
            'cost_source' => $complete ? 'real' : ($audit === [] ? 'unavailable' : 'partial'),
            'commission_actual_known' => round($fee, 8), 'funding_actual_known' => round($funding, 8),
            'gross_pnl_actual' => $basisComplete ? round($gross, 8) : null,
            'net_pnl_actual' => $complete ? round($gross - $fee - $funding, 8) : null,
            'pnl_basis' => $futures ? 'exchange_realized_pnl' : 'weighted_average_actual_fills',
            'ownership_verified' => $exclusive, 'orders' => array_values($audit),
            'funding_events' => array_values($fundingAudit),
            'reason' => $complete ? null : 'Incomplete fills, fee conversion, history or exclusive position ownership; retry required.',
        ];
    }
}
