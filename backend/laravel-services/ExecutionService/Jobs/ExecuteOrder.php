<?php

namespace Services\ExecutionService\Jobs;

use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Position;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\ExchangeConnection;
use Services\ExecutionService\Adapters\ExchangeAdapterFactory;
use App\Support\RiskGuardService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ExecuteOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'execution';

    public function __construct(
        public string $workspaceId,
        public string $instanceId,
        public object $signal,
        public array $context = []
    ) {
    }

    public function handle(): void
    {
        // Risk check before doing anything
        app(RiskGuardService::class)->enforceOrFail($this->workspaceId);

        $connectionId = $this->context['connection_id'] ?? null;
        $mode = $this->context['mode'] ?? ($connectionId ? 'live' : 'paper');
        $providerResponse = null;

        $symbol = Symbol::firstOrCreate(
            ['symbol' => $this->context['symbol'] ?? 'BTCUSDT'],
            ['id' => (string) Str::uuid(), 'type' => 'crypto']
        );

        $price = $this->context['price'] ?? 0;
        $quantity = $this->context['quantity'] ?? 0.01;
        $side = strtolower($this->signal->action) === 'sell' ? 'sell' : 'buy';

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspaceId,
            'instance_id' => $this->instanceId,
            'symbol_id' => $symbol->id,
            'side' => $side,
            'type' => 'market',
            'quantity' => $quantity,
            'price' => $price,
            'status' => $connectionId ? 'pending_provider' : 'filled',
            'meta' => [
                'mode' => $mode,
                'connection_id' => $connectionId,
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        if ($mode === 'live' && ! $connectionId) {
            $order->status = 'failed';
            $order->meta = array_merge($order->meta ?? [], [
                'provider_error' => 'No connection configured for live mode',
            ]);
            $order->save();
            return;
        }

        if ($connectionId) {
            $connection = ExchangeConnection::find($connectionId);
            if (! $connection) {
                $order->status = 'failed';
                $order->meta = array_merge($order->meta ?? [], [
                    'provider_error' => 'Connection not found',
                ]);
                $order->save();
                return;
            }

            try {
                $factory = app(ExchangeAdapterFactory::class);
                $adapter = $factory->makeFromConnection($connection);
                $providerResponse = $adapter->placeOrder([
                    'symbol' => $symbol->symbol,
                    'side' => strtoupper($side),
                    'type' => 'MARKET',
                    'quantity' => $quantity,
                    'price' => $price,
                    'idempotency_key' => $order->idempotency_key,
                ]);
                $order->meta = array_merge($order->meta ?? [], [
                    'provider' => $connection->provider,
                    'connection_id' => $connection->id,
                    'provider_response' => $providerResponse,
                ]);
                $order->status = ($providerResponse['status'] ?? 'filled') === 'pending' ? 'pending_provider' : 'filled';
                $order->save();
            } catch (\Throwable $e) {
                $order->status = 'failed';
                $order->meta = array_merge($order->meta ?? [], [
                    'provider_error' => $e->getMessage(),
                    'connection_id' => $connectionId,
                ]);
                $order->save();
                return;
            }
        }

        if ($order->status !== 'filled') {
            // Provider pending; do not mutate balances yet.
            return;
        }

        $trade = Trade::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'workspace_id' => $this->workspaceId,
            'price' => $price,
            'quantity' => $quantity,
            'fee' => 0,
            'executed_at' => now(),
        ]);

        $position = Position::firstOrNew([
            'workspace_id' => $this->workspaceId,
            'symbol_id' => $symbol->id,
        ]);

        $existingQty = $position->quantity ?? 0;
        $existingCost = ($position->avg_price ?? 0) * $existingQty;
        $tradeCost = $price * $quantity * ($side === 'buy' ? 1 : -1);
        $newQty = $existingQty + ($quantity * ($side === 'buy' ? 1 : -1));
        $newCost = $existingCost + $tradeCost;
        $position->fill([
            'id' => $position->id ?: (string) Str::uuid(),
            'quantity' => $newQty,
            'avg_price' => $newQty != 0 ? abs($newCost / $newQty) : 0,
            'unrealized_pl' => 0,
        ]);
        $position->save();

        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $this->workspaceId],
            ['id' => (string) Str::uuid(), 'balance' => 100000]
        );
        $delta = $price * $quantity * ($side === 'buy' ? -1 : 1);
        $ledger->balance += $delta;
        $ledger->save();

        LedgerTransaction::create([
            'id' => (string) Str::uuid(),
            'ledger_id' => $ledger->id,
            'type' => 'order',
            'amount' => $delta,
            'meta' => [
                'order_id' => $order->id,
                'connection_id' => $connectionId,
            ],
            'occurred_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
