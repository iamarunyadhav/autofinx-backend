<?php

namespace App\Support;

use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Position;
use App\Models\Trade;
use Illuminate\Support\Str;

class OrderFinalizer
{
    /**
     * Idempotently finalize a filled order by booking trades, positions, and ledger.
     */
    public function finalize(Order $order): void
    {
        $meta = $order->meta ?? [];
        if (!empty($meta['finalized_at'])) {
            return; // already applied
        }

        $symbol = $order->symbol;
        $price = (float) ($order->price ?? 0);
        $qty = (float) ($order->quantity ?? 0);
        if (!$symbol || $price <= 0 || $qty <= 0) {
            return;
        }

        $side = strtolower($order->side) === 'sell' ? 'sell' : 'buy';

        $trade = Trade::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'workspace_id' => $order->workspace_id,
            'price' => $price,
            'quantity' => $qty * ($side === 'buy' ? 1 : -1),
            'fee' => 0,
            'executed_at' => now(),
        ]);

        $position = Position::firstOrNew([
            'workspace_id' => $order->workspace_id,
            'symbol_id' => $symbol->id,
        ]);
        $existingQty = $position->quantity ?? 0;
        $existingCost = ($position->avg_price ?? 0) * $existingQty;
        $tradeCost = $price * $qty * ($side === 'buy' ? 1 : -1);
        $newQty = $existingQty + ($qty * ($side === 'buy' ? 1 : -1));
        $newCost = $existingCost + $tradeCost;
        $position->fill([
            'id' => $position->id ?: (string) Str::uuid(),
            'quantity' => $newQty,
            'avg_price' => $newQty != 0 ? abs($newCost / $newQty) : 0,
            'unrealized_pl' => 0,
        ]);
        $position->save();

        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $order->workspace_id],
            ['id' => (string) Str::uuid(), 'balance' => 0]
        );
        $delta = $price * $qty * ($side === 'buy' ? -1 : 1);
        $ledger->balance = ($ledger->balance ?? 0) + $delta;
        $ledger->save();

        LedgerTransaction::create([
            'id' => (string) Str::uuid(),
            'ledger_id' => $ledger->id,
            'wallet_account_id' => null,
            'type' => 'order_live',
            'amount' => $delta,
            'meta' => [
                'order_id' => $order->id,
                'provider' => $order->meta['provider'] ?? null,
                'connection_id' => $order->meta['connection_id'] ?? null,
            ],
            'occurred_at' => now(),
            'idempotency_key' => $order->idempotency_key ?? (string) Str::uuid(),
        ]);

        $meta['finalized_at'] = now()->toIso8601String();
        $order->meta = $meta;
        $order->save();
    }
}
