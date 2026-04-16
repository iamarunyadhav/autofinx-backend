<?php

namespace App\Jobs;

use App\Domain\Exchange\BinanceUserStreamService;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KeepAliveUserStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('reconcile');
    }

    public function handle(BinanceUserStreamService $streams, BinanceOrderSyncService $sync): void
    {
        $connectionIds = Bot::where('status', 'RUNNING')
            ->where('is_live', true)
            ->whereNotNull('connection_id')
            ->distinct()
            ->pluck('connection_id');

        foreach ($connectionIds as $connectionId) {
            $connection = ExchangeConnection::find($connectionId);
            if (! $connection || ! $connection->is_active) {
                continue;
            }

            try {
                $listenKey = $streams->keepAlive($connection);
                if (! $listenKey) {
                    $streams->start($connection);
                    $sync->syncForConnection($connection);
                }
            } catch (\Throwable $e) {
                $streams->markError($connection, $e->getMessage());

                try {
                    $streams->start($connection);
                    $sync->syncForConnection($connection);
                } catch (\Throwable $restartError) {
                    $streams->markError($connection, $restartError->getMessage());
                }
            }
        }
    }
}
