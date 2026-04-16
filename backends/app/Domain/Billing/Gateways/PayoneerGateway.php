<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayoneerGateway implements GatewayInterface
{
    public function initiateTopup(array $payload): array
    {
        return [
            'status' => 'pending',
            'reference' => 'po_' . Str::random(10),
            'instructions' => 'Manual payoneer collection required',
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $eventId = $request->input('paymentId') ?? Str::uuid()->toString();
        $statusRaw = strtolower($request->input('status', 'pending'));
        $status = in_array($statusRaw, ['paid', 'completed', 'succeeded']) ? 'succeeded' : ($statusRaw === 'failed' ? 'failed' : 'pending');
        return [
            'event_id' => $eventId,
            'status' => $status,
            'workspace_id' => $request->input('workspace_id'),
            'invoice_id' => $request->input('invoice_id'),
            'amount' => (float) ($request->input('amount') ?? 0),
            'currency' => strtoupper($request->input('currency') ?? 'USD'),
            'reference' => $eventId,
            'direction' => 'credit',
        ];
    }

    public function fetchPaymentStatus(string $externalId): array
    {
        return ['status' => 'pending', 'reference' => $externalId];
    }
}
