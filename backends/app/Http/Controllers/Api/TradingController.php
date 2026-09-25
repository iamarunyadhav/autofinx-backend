<?php

namespace App\Http\Controllers\Api;

use App\Domain\Execution\ExecutionEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\Position;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use App\Support\RiskGuardService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Orders/trades/positions/ledger and paper order placement. Every action
 * asserts workspace membership (RatioBotController pattern).
 */
class TradingController extends Controller
{
    use AssertsWorkspaceMembership;

    public function orders(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        return Order::where('workspace_id', $workspaceId)->with('symbol')->latest()->get();
    }

    public function trades(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        return Trade::where('workspace_id', $workspaceId)->latest('executed_at')->get();
    }

    public function positions(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        return Position::where('workspace_id', $workspaceId)->with('symbol')->get();
    }

    public function ledger(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['id' => (string) Str::uuid(), 'balance' => 0, 'meta' => ['currency' => 'USD']]
        );
        return $ledger->load('transactions');
    }

    public function exportLedger(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $transactions = LedgerTransaction::whereHas('ledger', fn ($q) => $q->where('workspace_id', $workspaceId))->get();
        $csv = "type,amount,occurred_at,meta\n";
        foreach ($transactions as $t) {
            $csv .= "{$t->type},{$t->amount},{$t->occurred_at},".json_encode($t->meta)."\n";
        }
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=ledger.csv',
        ]);
    }

    public function placeOrder(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'instance_id' => 'nullable|uuid',
            'symbol' => 'required|string',
            'side' => 'required|in:buy,sell',
            'order_type' => 'nullable|string',
            'mode' => 'nullable|in:paper,live',
            'quantity' => 'required|numeric',
            'price' => 'required|numeric',
            'idempotency_key' => 'nullable|string',
        ]);

        $this->assertWorkspaceMembership($request, (string) $data['workspace_id']);

        if (($data['mode'] ?? 'paper') !== 'paper') {
            return response()->json([
                'message' => 'The /orders endpoint is paper-only. Use /live/orders for live execution.',
            ], 422);
        }

        try {
            app(RiskGuardService::class)->enforceOrFail($data['workspace_id']);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $order = app(ExecutionEngine::class)->executePaper(
            $data['workspace_id'],
            $data['instance_id'] ?? null,
            strtoupper((string) $data['symbol']),
            strtolower((string) $data['side']),
            (float) $data['quantity'],
            (float) $data['price'],
            (string) ($data['idempotency_key'] ?? Str::uuid())
        );

        $trade = Trade::where('order_id', $order->id)->latest('executed_at')->first();
        $position = Position::where('workspace_id', $order->workspace_id)
            ->where('symbol_id', $order->symbol_id)
            ->first();
        $ledger = Ledger::where('workspace_id', $order->workspace_id)->first();

        $this->logAudit($request, 'order.create', $order->workspace_id, ['order_id' => $order->id]);

        return response()->json([
            'mode' => 'paper',
            'message' => 'Paper order executed through the canonical paper execution engine.',
            'order' => $order,
            'trade' => $trade,
            'position' => $position,
            'balance' => $ledger?->balance,
        ], 201);
    }

    private function logAudit(Request $request, string $action, ?string $workspaceId = null, array $meta = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
