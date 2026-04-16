<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayPalGateway implements GatewayInterface
{
    public function initiateTopup(array $payload): array
    {
        $reference = 'pp_' . Str::random(10);
        $checkout = env('PAYPAL_ENABLED', false)
            ? 'https://www.sandbox.paypal.com/checkoutnow?token=' . $reference
            : 'https://www.sandbox.paypal.com/checkoutnow?demo=' . $reference;

        return [
            'status' => 'pending',
            'reference' => $reference,
            'checkout_url' => $checkout,
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $resource = $request->input('resource', []);
        $eventId = $request->input('id') ?? $resource['id'] ?? Str::uuid()->toString();
        $statusRaw = strtolower($resource['status'] ?? $request->input('event_type', ''));
        $status = str_contains($statusRaw, 'completed') || str_contains($statusRaw, 'paid') ? 'succeeded' : (str_contains($statusRaw, 'denied') ? 'failed' : 'pending');
        $amount = (float) ($resource['amount']['value'] ?? 0);
        $currency = strtoupper($resource['amount']['currency_code'] ?? 'USD');
        return [
            'event_id' => $eventId,
            'status' => $status,
            'workspace_id' => $resource['custom_id'] ?? null,
            'invoice_id' => $resource['invoice_id'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $eventId,
            'direction' => 'credit',
        ];
    }

    public function fetchPaymentStatus(string $externalId): array
    {
        return [
            'status' => 'pending',
            'reference' => $externalId,
        ];
    }
}
