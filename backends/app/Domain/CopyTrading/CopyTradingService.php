<?php

namespace App\Domain\CopyTrading;

use App\Jobs\ProcessCopyTradeReplicationJob;
use App\Models\CopyTradeReplication;
use App\Models\CopyTradingProfile;
use App\Models\Order;
use Illuminate\Support\Str;

class CopyTradingService
{
    public function profilesForMaster(string $masterInstanceId)
    {
        return CopyTradingProfile::where('master_instance_id', $masterInstanceId)
            ->where('paused', false)
            ->get();
    }

    public function replicate(string $masterTradeId, string $orderIdOrInstanceId, string $symbol, float $price, float $qty, string $side): void
    {
        // orderIdOrInstanceId may be order id or instance id (paper flow)
        $order = Order::find($orderIdOrInstanceId);
        $masterInstanceId = $order?->instance_id ?? $orderIdOrInstanceId;
        $profiles = $this->profilesForMaster($masterInstanceId);
        foreach ($profiles as $profile) {
            // For phase 1: record replication intent; actual order placement would be in a job
            $replication = CopyTradeReplication::firstOrCreate(
                [
                    'master_trade_id' => $masterTradeId,
                    'follower_workspace_id' => $profile->follower_workspace_id,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'order_id' => null,
                    'meta' => [
                        'symbol' => $symbol,
                        'side' => $side,
                        'price' => $price,
                        'qty' => $qty,
                        'allocation_type' => $profile->allocation_type,
                        'allocation_value' => $profile->allocation_value,
                        'profile_id' => $profile->id,
                        'max_trade_size' => $profile->max_trade_size,
                        'allowed_symbols' => $profile->allowed_symbols,
                    ],
                ]
            );

            if ($replication->wasRecentlyCreated) {
                ProcessCopyTradeReplicationJob::dispatch($replication->id);
            }
        }
    }
}
