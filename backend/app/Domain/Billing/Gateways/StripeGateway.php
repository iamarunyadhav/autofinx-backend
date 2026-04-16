<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StripeGateway implements GatewayInterface
{
    public function initiateTopup(array $payload): array
    {
        $reference = 'st_' . Str::random(10);
        return [
            'status' => env('STRIPE_ENABLED', false) ? 'pending' : 'disabled',
            'reference' => $reference,
            'client_secret' => env('STRIPE_ENABLED', false) ? 'cs_' . Str::random(12) : null,
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $data = $request->input('data.object', []);
        $eventId = $request->input('id') ?? $data['id'] ?? Str::uuid()->toString();
        $status = $data['payment_status'] ?? $request->input('type', 'pending');
        $status = str_contains($status, 'succeeded') ? 'succeeded' : (str_contains($status, 'failed') ? 'failed' : 'pending');

        return [
            'event_id' => $eventId,
            'status' => $status,
            'workspace_id' => $data['metadata']['workspace_id'] ?? null,
            'invoice_id' => $data['metadata']['invoice_id'] ?? null,
            'amount' => isset($data['amount_total']) ? $data['amount_total'] / 100 : 0,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'reference' => $eventId,
            'direction' => 'credit',
        ];
    }

    public function fetchPaymentStatus(string $externalId): array
    {
        return ['status' => 'pending', 'reference' => $externalId];
    }
}
