<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PayHereGateway implements GatewayInterface
{
    public function initiateTopup(array $payload): array
    {
        $merchantId = env('PAYHERE_MERCHANT_ID');
        $secret = env('PAYHERE_MERCHANT_SECRET');
        $orderId = $payload['invoice_id'] ?? ('ph_' . Str::random(10));
        $amount = number_format($payload['amount'] ?? 0, 2, '.', '');
        $currency = strtoupper($payload['currency'] ?? 'USD');

        $hash = $merchantId && $secret
            ? strtoupper(md5($merchantId . $orderId . $amount . $currency . strtoupper(md5($secret))))
            : null;

        $returnUrl = env('PAYHERE_RETURN_URL', url('/billing/return/payhere'));
        $cancelUrl = env('PAYHERE_CANCEL_URL', url('/billing/cancel/payhere'));
        $notifyUrl = env('PAYHERE_NOTIFY_URL', url('/api/v1/billing/webhook/payhere'));

        return [
            'status' => 'pending',
            'reference' => $orderId,
            'checkout_url' => env('PAYHERE_ENABLED', false)
                ? "https://sandbox.payhere.lk/pay?merchant_id={$merchantId}&order_id={$orderId}&amount={$amount}&currency={$currency}&hash={$hash}&return_url={$returnUrl}&cancel_url={$cancelUrl}&notify_url={$notifyUrl}"
                : "https://sandbox.payhere.lk/pay/demo?order_id={$orderId}",
            'meta' => ['hash' => $hash],
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $eventId = $request->input('order_id') ?? $request->input('payment_id');
        $status = strtolower($request->input('status_code') ?? '2') === '2' ? 'succeeded' : 'failed';
        $amount = (float) ($request->input('payhere_amount') ?? $request->input('amount') ?? 0);
        $currency = strtoupper($request->input('payhere_currency') ?? 'USD');
        $workspaceId = $request->input('custom_1') ?? $request->input('workspace_id');
        $invoiceId = $request->input('custom_2') ?? $request->input('invoice_id');

        return [
            'event_id' => $eventId,
            'status' => $status,
            'workspace_id' => $workspaceId,
            'invoice_id' => $invoiceId,
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
