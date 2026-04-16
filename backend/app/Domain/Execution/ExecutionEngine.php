<?php

namespace App\Domain\Execution;

use App\Domain\Execution\Adapters\BinanceAdapter;
use App\Domain\Execution\Adapters\CoinbaseAdapter;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Position;
use App\Models\StrategyInstanceRuntime;
use App\Models\Symbol;
use App\Models\Trade;
use App\Support\Clock\Clock;
use Illuminate\Support\Str;

class ExecutionEngine
{
    public function __construct(
        private Clock $clock,
        private ?BinanceAdapter $binanceAdapter = null,
        private ?CoinbaseAdapter $coinbaseAdapter = null,
        private ?\App\Domain\Ops\SafeLiveGate $safeLiveGate = null,
    ) {
    }

    public function executePaper(string $workspaceId, ?string $instanceId, string $symbolCode, string $side, float $qty, float $price, string $idempotencyKey): Order
    {
        $existing = Order::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $now = $this->clock->now();
        $symbol = Symbol::firstOrCreate(
            ['symbol' => $symbolCode],
            [
                'id' => (string) Str::uuid(),
                'type' => 'crypto',
                'exchange' => 'demo',
                'metadata' => ['auto_created' => true],
            ]
        );

        $slippageBps = env('RISK_DEFAULT_SLIPPAGE_BPS', 50);
        $fillPrice = $price * (1 + ($side === 'buy' ? $slippageBps : -$slippageBps) / 10000);
        $feeBps = env('PAPER_FEE_BPS', 10);
        $fee = $fillPrice * $qty * ($feeBps / 10000);

        $order = Order::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'instance_id' => $instanceId,
            'symbol_id' => $symbol->id,
            'side' => $side,
            'type' => 'market',
            'quantity' => $qty,
            'price' => $fillPrice,
            'status' => Order::STATUS_FILLED,
            'meta' => ['mode' => 'paper', 'fee' => $fee],
            'idempotency_key' => $idempotencyKey,
        ]);

        Trade::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'workspace_id' => $workspaceId,
            'price' => $fillPrice,
            'quantity' => $qty,
            'meta' => ['mode' => 'paper', 'side' => $side],
            'executed_at' => $now,
        ]);

        $this->updatePosition($workspaceId, $symbol->id, $side, $qty, $fillPrice);
        $this->updateLedger($workspaceId, $order, $side, $qty, $fillPrice, $fee, $now);
        if ($instanceId) {
            $this->updateRuntime($instanceId, $now);
        }

        return $order;
    }

    public function executeLive(string $adapter, string $symbol, string $side, float $qty, string $type, string $idempotencyKey): array
    {
        throw new \RuntimeException('Legacy ExecutionEngine::executeLive is disabled. Route live orders through the canonical live order service.');
    }

    private function updatePosition(string $workspaceId, string $symbolId, string $side, float $qty, float $price): void
    {
        $position = Position::firstOrNew([
            'workspace_id' => $workspaceId,
            'symbol_id' => $symbolId,
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
    }

    private function updateLedger(string $workspaceId, Order $order, string $side, float $qty, float $price, float $fee, $now): void
    {
        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['id' => (string) Str::uuid(), 'balance' => 100000, 'meta' => ['currency' => 'USD']]
        );
        $delta = $price * $qty * ($side === 'buy' ? -1 : 1) - $fee;
        $ledger->balance += $delta;
        $ledger->save();

        LedgerTransaction::updateOrCreate(
            ['idempotency_key' => $order->idempotency_key],
            [
                'id' => LedgerTransaction::where('idempotency_key', $order->idempotency_key)->first()?->id ?? (string) Str::uuid(),
                'ledger_id' => $ledger->id,
                'wallet_account_id' => null,
                'type' => 'order_paper',
                'amount' => $delta,
                'meta' => [
                    'order_id' => $order->id,
                    'side' => $side,
                    'fee' => $fee,
                ],
                'occurred_at' => $now,
                'idempotency_key' => $order->idempotency_key,
            ]
        );
    }

    private function updateRuntime(string $instanceId, $now): void
    {
        $runtime = StrategyInstanceRuntime::firstOrCreate(
            ['strategy_instance_id' => $instanceId],
            ['id' => (string) Str::uuid()]
        );

        $runtime->last_trade_at = $now;
        $runtime->trades_in_current_hour = ($runtime->trades_in_current_hour ?? 0) + 1;
        $runtime->trades_in_current_day = ($runtime->trades_in_current_day ?? 0) + 1;
        $runtime->hour_bucket = $now->format('Y-m-d H:00');
        $runtime->day_bucket = $now->format('Y-m-d');
        $runtime->save();
    }
}
