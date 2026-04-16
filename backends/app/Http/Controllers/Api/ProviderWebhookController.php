<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Support\OrderFinalizer;

class ProviderWebhookController extends Controller
{
    public function coinbase(Request $request, OrderFinalizer $finalizer)
    {
        $eventType = $request->input('type');
        $orderId = $request->input('data.order_id') ?? $request->input('data.order.id');
        $status = strtolower($request->input('data.order.status') ?? '');

        if (!$orderId || !$status) {
            return response()->json(['error' => 'missing fields'], 400);
        }

        $order = Order::where('idempotency_key', $orderId)->orWhere('meta->provider_response->order_id', $orderId)->first();
        if (!$order) {
            return response()->json(['ok' => true, 'message' => 'no-op']);
        }

        $previous = $order->status;
        if (in_array($status, ['filled', 'filled_fully', 'done'])) {
            $order->status = Order::STATUS_FILLED;
        } elseif (in_array($status, ['rejected', 'failed', 'canceled'])) {
            $order->status = Order::STATUS_FAILED;
        } else {
            $order->status = Order::STATUS_PENDING_PROVIDER;
        }

        $meta = $order->meta ?? [];
        $meta['provider_webhook'] = $request->all();
        $order->meta = $meta;
        $order->save();

        if ($previous !== Order::STATUS_FILLED && $order->status === Order::STATUS_FILLED) {
            $finalizer->finalize($order);
        }

        return response()->json(['ok' => true]);
    }
}
