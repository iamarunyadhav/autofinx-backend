<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\LiveExecutionService;
use App\Domain\Trading\UncertainLiveOrderException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LiveOrderController extends Controller
{
    public function store(Request $request, LiveExecutionService $service)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'integration_id' => 'required|uuid',
            'symbol' => 'required|string',
            'side' => 'required|string',
            'type' => 'required|string',
            'qty' => 'required|numeric',
            'price' => 'numeric|nullable',
            'instance_id' => 'uuid|nullable',
            'idempotency_key' => 'string|nullable',
        ]);

        try {
            $order = $service->placeOrder($data);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (UncertainLiveOrderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'order' => $e->order->fresh(),
                'mode' => 'live',
            ], 202);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => $order,
            'mode' => 'live',
        ], 201);
    }

    public function destroy(Request $request, Order $order, LiveExecutionService $service)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
        ]);

        try {
            $order = $service->cancelOrder((string) $data['workspace_id'], $order);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => $order,
            'mode' => 'live',
        ]);
    }
}
