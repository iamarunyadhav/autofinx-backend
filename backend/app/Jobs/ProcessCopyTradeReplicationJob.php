<?php

namespace App\Jobs;

use App\Domain\Execution\ExecutionEngine;
use App\Models\CopyTradeReplication;
use App\Models\CopyTradingProfile;
use App\Models\Ledger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ProcessCopyTradeReplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $replicationId)
    {
        $this->onQueue('copytrading');
    }

    public function handle(ExecutionEngine $execution): void
    {
        $replication = CopyTradeReplication::find($this->replicationId);
        if (! $replication || $replication->order_id) {
            return;
        }

        $meta = $replication->meta ?? [];
        $symbol = $meta['symbol'] ?? 'BTCUSDT';
        $side = $meta['side'] ?? 'buy';
        $price = (float) ($meta['price'] ?? 0);
        $qty = (float) ($meta['qty'] ?? 0);
        if ($price <= 0 || $qty <= 0) {
            return;
        }

        $profileId = $meta['profile_id'] ?? null;
        $profile = $profileId ? CopyTradingProfile::find($profileId) : null;
        if (! $profile || $profile->paused) {
            return;
        }
        if ($profile->allowed_symbols && ! in_array($symbol, $profile->allowed_symbols)) {
            return;
        }

        $ledger = Ledger::where('workspace_id', $profile->follower_workspace_id)->first();
        $equity = $ledger?->balance ?? 10000;

        $targetNotional = $price * $qty;
        if ($profile->allocation_type === 'PERCENT_EQUITY' && $profile->allocation_value > 0) {
            $targetNotional = $equity * ($profile->allocation_value / 100);
        } elseif ($profile->allocation_type === 'FIXED_AMOUNT' && $profile->allocation_value > 0) {
            $targetNotional = $profile->allocation_value;
        }

        $followerQty = max(0, $targetNotional / $price);
        if ($profile->allocation_type === 'MIRROR_EXACT') {
            $followerQty = $qty;
        }

        if ($profile->max_trade_size && $followerQty * $price > $profile->max_trade_size) {
            $followerQty = $profile->max_trade_size / $price;
        }

        if ($followerQty <= 0) {
            return;
        }

        $idempotencyKey = "{$replication->master_trade_id}-{$profile->follower_workspace_id}";
        $order = $execution->executePaper(
            $profile->follower_workspace_id,
            null,
            $symbol,
            $side,
            $followerQty,
            $price,
            $idempotencyKey
        );

        $replication->order_id = $order->id;
        $replication->meta = array_merge($meta, [
            'executed_qty' => $followerQty,
            'idempotency_key' => $idempotencyKey,
        ]);
        $replication->save();
    }
}
