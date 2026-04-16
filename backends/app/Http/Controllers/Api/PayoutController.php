<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayoutController extends Controller
{
    public function show(string $id)
    {
        $payment = Payment::find($id);
        if (! $payment) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'payout' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->metadata['currency'] ?? 'USD',
                'status' => $payment->status,
                'provider' => $payment->provider,
                'reference' => $payment->reference,
                'created_at' => $payment->created_at,
                'events' => $this->buildEvents($payment),
            ],
        ]);
    }

    public function retry(string $id)
    {
        $payment = Payment::find($id);
        if (! $payment) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $payment->status = Payment::STATUS_PENDING;
        $meta = $payment->metadata ?? [];
        $meta['retry_requested_at'] = now()->toIso8601String();
        $payment->metadata = $meta;
        $payment->idempotency_key = (string) Str::uuid();
        $payment->save();

        \App\Jobs\ProcessPayout::dispatch($payment->id);

        return response()->json(['ok' => true, 'payout_id' => $payment->id]);
    }

    private function buildEvents(Payment $payment): array
    {
        $events = [];
        $events[] = [
            'title' => 'Payout requested',
            'status' => 'pending',
            'at' => $payment->created_at,
        ];

        if (!empty($payment->metadata['retry_requested_at'])) {
            $events[] = [
                'title' => 'Retry requested',
                'status' => 'pending',
                'at' => $payment->metadata['retry_requested_at'],
            ];
        }

        if (!empty($payment->metadata['external_reference'])) {
            $events[] = [
                'title' => 'Provider accepted',
                'status' => 'pending',
                'at' => $payment->updated_at,
                'body' => 'Ref: ' . $payment->metadata['external_reference'],
            ];
        }

        if ($payment->status === Payment::STATUS_SETTLED) {
            $events[] = [
                'title' => 'Completed',
                'status' => 'success',
                'at' => $payment->updated_at,
            ];
        }

        if ($payment->status === Payment::STATUS_PROVIDER_PENDING) {
            $events[] = [
                'title' => 'Awaiting provider settlement',
                'status' => 'pending',
                'at' => $payment->updated_at,
            ];
        }

        if ($payment->status === Payment::STATUS_FAILED) {
            $events[] = [
                'title' => 'Failed',
                'status' => 'error',
                'at' => $payment->updated_at,
                'body' => $payment->metadata['failure_reason'] ?? '',
            ];
        }

        if ($payment->status === Payment::STATUS_REVERSED) {
            $events[] = [
                'title' => 'Reversed',
                'status' => 'warning',
                'at' => $payment->updated_at,
            ];
        }

        return $events;
    }
}
