<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentGatewayService
{
    public function createCheckout(string $gateway, array $payload): array
    {
        $requestedMode = strtolower((string) ($payload['environment'] ?? ''));
        $mode = in_array($requestedMode, ['sandbox', 'live'], true)
            ? $requestedMode
            : (config('billing.mode', 'sandbox') === 'live' ? 'live' : 'sandbox');
        $gateway = strtolower($gateway);
        $amount = $payload['amount'] ?? 0;
        $currency = strtolower($payload['currency'] ?? 'usd');
        $workspaceId = $payload['workspace_id'] ?? null;
        $invoiceId = $payload['invoice_id'] ?? null;

        if ($gateway === 'stripe') {
            return $this->stripeCheckout($amount, $currency, $workspaceId, $invoiceId, $mode);
        }
        if ($gateway === 'paypal') {
            return $this->paypalCheckout($amount, $currency, $workspaceId, $invoiceId, $mode, $payload);
        }
        if ($gateway === 'payhere') {
            return $this->payhereCheckout($amount, $currency, $workspaceId, $invoiceId, $mode);
        }
        if ($gateway === 'payoneer') {
            return $this->payoneerCheckout($amount, $currency, $workspaceId, $invoiceId, $mode);
        }
        if ($gateway === 'bank_transfer') {
            return $this->bankTransferCheckout($amount, $currency, $workspaceId, $invoiceId, $mode);
        }

        // fallback: simulate a payment link in sandbox
        $reference = "{$gateway}_" . Str::random(10);
        return [
            'status' => 'pending_provider',
            'checkout_url' => ($mode === 'sandbox')
                ? "https://sandbox.example.local/pay/{$reference}"
                : null,
            'client_secret' => null,
            'reference' => $reference,
            'gateway' => $gateway,
        ];
    }

    public function payout(Payment $payment): array
    {
        $mode = config('billing.mode', 'sandbox') === 'live' ? 'live' : 'sandbox';
        $provider = strtolower($payment->provider ?? 'bank_transfer');
        $currency = $payment->metadata['currency'] ?? 'USD';

        if ($provider === 'stripe') {
            return $this->payoutStripe($payment, $mode, $currency);
        }
        if ($provider === 'paypal') {
            return $this->payoutPaypal($payment, $mode, $currency);
        }
        if ($provider === 'payoneer') {
            return $this->payoutPayoneer($payment, $mode, $currency);
        }

        // default to bank transfer rail
        return $this->payoutBank($payment, $mode, $currency);
    }

    private function payoutStripe(Payment $payment, string $mode, string $currency): array
    {
        $secret = config("billing.credentials.stripe.{$mode}.secret_key");
        if (!$secret) {
            throw new \RuntimeException('Stripe secret key not configured');
        }

        // Minimal example: create a Payout (would normally need a connected account)
        $resp = Http::withToken($secret)
            ->asForm()
            ->post('https://api.stripe.com/v1/payouts', [
                'amount' => (int) round($payment->amount * 100),
                'currency' => strtolower($currency),
                'description' => 'Payout ' . $payment->id,
                'metadata' => [
                    'workspace_id' => $payment->workspace_id,
                    'payment_id' => $payment->id,
                ],
            ]);

        if (!$resp->successful()) {
            throw new \RuntimeException('Stripe payout failed: ' . $resp->body());
        }

        $data = $resp->json();
        return [
            'reference' => $data['id'] ?? ('st_' . Str::random(12)),
            'status' => ($data['status'] ?? '') === 'paid' ? 'succeeded' : 'pending',
            'raw' => $data,
        ];
    }

    private function payoutPaypal(Payment $payment, string $mode, string $currency): array
    {
        $clientId = config("billing.credentials.paypal.{$mode}.client_id");
        $secret = config("billing.credentials.paypal.{$mode}.client_secret");
        if (!$clientId || !$secret) {
            throw new \RuntimeException('PayPal credentials not configured');
        }

        $base = $mode === 'live' ? 'https://api.paypal.com' : 'https://api-m.sandbox.paypal.com';

        $tokenRes = Http::asForm()->withBasicAuth($clientId, $secret)->post("{$base}/v1/oauth2/token", [
            'grant_type' => 'client_credentials',
        ]);
        if (!$tokenRes->successful()) {
            throw new \RuntimeException('PayPal auth failed: ' . $tokenRes->body());
        }
        $token = $tokenRes->json()['access_token'] ?? null;
        if (!$token) {
            throw new \RuntimeException('PayPal token missing');
        }

        $resp = Http::withToken($token)->post("{$base}/v1/payments/payouts", [
            'sender_batch_header' => [
                'sender_batch_id' => 'pb_' . Str::random(10),
                'email_subject' => 'You have a payout',
            ],
            'items' => [[
                'recipient_type' => 'EMAIL',
                'amount' => [
                    'value' => number_format($payment->amount, 2, '.', ''),
                    'currency' => strtoupper($currency),
                ],
                'receiver' => $payment->metadata['receiver'] ?? 'demo@example.com',
                'note' => 'Payout ' . $payment->id,
            ]],
        ]);

        if (!$resp->successful()) {
            throw new \RuntimeException('PayPal payout failed: ' . $resp->body());
        }

        $data = $resp->json();
        return [
            'reference' => $data['batch_header']['payout_batch_id'] ?? ('pp_' . Str::random(12)),
            'status' => 'pending', // PayPal batches are async; will confirm via webhook
            'raw' => $data,
        ];
    }

    private function payoutPayoneer(Payment $payment, string $mode, string $currency): array
    {
        $programId = config("billing.credentials.payoneer.{$mode}.program_id");
        $username = config("billing.credentials.payoneer.{$mode}.username");
        $password = config("billing.credentials.payoneer.{$mode}.password");
        if (!$programId || !$username || !$password) {
            throw new \RuntimeException('Payoneer credentials not configured');
        }

        // Placeholder REST call; replace with official SDK if available.
        $endpoint = $mode === 'live'
            ? 'https://api.payoneer.com/v4/programs/' . $programId . '/payments'
            : 'https://api.sandbox.payoneer.com/v4/programs/' . $programId . '/payments';

        $resp = Http::withBasicAuth($username, $password)->post($endpoint, [
            'payee_id' => $payment->metadata['payee_id'] ?? 'demo-payee',
            'amount' => number_format($payment->amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'client_reference_id' => $payment->id,
        ]);

        if (!$resp->successful()) {
            throw new \RuntimeException('Payoneer payout failed: ' . $resp->body());
        }

        $data = $resp->json();
        return [
            'reference' => $data['payment_id'] ?? ('po_' . Str::random(12)),
            'status' => $data['status'] ?? 'pending',
            'raw' => $data,
        ];
    }

    private function payoutBank(Payment $payment, string $mode, string $currency): array
    {
        $url = config("billing.credentials.bank.{$mode}.api_url");
        $key = config("billing.credentials.bank.{$mode}.api_key");

        if (!$url || !$key) {
            return [
                'reference' => 'bank_' . Str::random(10),
                'status' => 'provider_pending',
                'raw' => ['provider_configured' => false, 'mode' => $mode],
            ];
        }

        $resp = Http::withHeaders(['X-API-Key' => $key])->post($url, [
            'amount' => $payment->amount,
            'currency' => strtoupper($currency),
            'account' => $payment->metadata['account'] ?? null,
            'routing' => $payment->metadata['routing'] ?? null,
            'reference' => $payment->id,
        ]);

        if (!$resp->successful()) {
            throw new \RuntimeException('Bank payout failed: ' . $resp->body());
        }

        $data = $resp->json();
        return [
            'reference' => $data['reference'] ?? ('bank_' . Str::random(8)),
            'status' => $data['status'] ?? 'provider_pending',
            'raw' => $data,
        ];
    }

    private function stripeCheckout(float $amount, string $currency, ?string $workspaceId, ?string $invoiceId, string $mode): array
    {
        $secret = config("billing.credentials.stripe.{$mode}.secret_key");
        $successBase = (string) (config("billing.checkout_urls.stripe.{$mode}.success_url") ?: env('APP_URL'));
        $cancelBase = (string) (config("billing.checkout_urls.stripe.{$mode}.cancel_url") ?: $successBase);

        if (!$secret) {
            if ($mode === 'sandbox') {
                $reference = 'cs_test_' . Str::random(12);
                return [
                    'status' => 'requires_action',
                    'checkout_url' => rtrim($successBase, '/') . '?gateway=stripe&mode=sandbox&checkout=' . $reference,
                    'client_secret' => null,
                    'reference' => $reference,
                    'gateway' => 'stripe',
                ];
            }
            throw new \RuntimeException('Stripe secret key not configured');
        }

        $successUrl = $this->withQuery($successBase, [
            'gateway' => 'stripe',
            'checkout' => '{CHECKOUT_SESSION_ID}',
            'status' => 'success',
        ]);
        $cancelUrl = $this->withQuery($cancelBase, [
            'gateway' => 'stripe',
            'status' => 'cancelled',
        ]);

        $resp = Http::withToken($secret)->asForm()->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][unit_amount]' => (int) round($amount * 100),
            'line_items[0][price_data][product_data][name]' => 'AutoFinX Wallet Deposit',
            'line_items[0][price_data][product_data][description]' => 'Secure wallet top-up',
            'payment_intent_data[description]' => 'AutoFinX wallet deposit',
            'metadata[workspace_id]' => $workspaceId,
            'metadata[invoice_id]' => $invoiceId,
            'metadata[purpose]' => 'wallet_deposit',
            'payment_intent_data[metadata][workspace_id]' => $workspaceId,
            'payment_intent_data[metadata][invoice_id]' => $invoiceId,
            'payment_intent_data[metadata][purpose]' => 'wallet_deposit',
        ]);

        if (!$resp->successful()) {
            throw new \RuntimeException('Stripe checkout session failed: ' . $resp->body());
        }

        $data = $resp->json();
        return [
            'status' => $data['payment_status'] ?? 'pending',
            'checkout_url' => $data['url'] ?? null,
            'client_secret' => null,
            'reference' => $data['id'] ?? ('cs_' . Str::random(10)),
            'gateway' => 'stripe',
        ];
    }

    private function withQuery(string $baseUrl, array $query): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return rtrim($baseUrl, '/') . $separator . http_build_query($query);
    }

    private function paypalCheckout(float $amount, string $currency, ?string $workspaceId, ?string $invoiceId, string $mode, array $options = []): array
    {
        $clientId = config("billing.credentials.paypal.{$mode}.client_id");
        $secret = config("billing.credentials.paypal.{$mode}.client_secret");
        if (!$clientId || !$secret) {
            if ($mode === 'sandbox') {
                return [
                    'status' => 'pending',
                    'checkout_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=demo',
                    'client_secret' => null,
                    'reference' => 'pp_' . Str::random(10),
                    'gateway' => 'paypal',
                ];
            }
            throw new \RuntimeException('PayPal credentials not configured');
        }

        $base = $mode === 'live' ? 'https://api.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $tokenRes = Http::asForm()->withBasicAuth($clientId, $secret)->post("{$base}/v1/oauth2/token", [
            'grant_type' => 'client_credentials',
        ]);
        if (!$tokenRes->successful()) {
            throw new \RuntimeException('PayPal auth failed: ' . $tokenRes->body());
        }
        $token = $tokenRes->json()['access_token'] ?? null;
        if (!$token) {
            throw new \RuntimeException('PayPal token missing');
        }

        $orderRes = Http::withToken($token)->post("{$base}/v2/checkout/orders", [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'custom_id' => $workspaceId,
                'invoice_id' => $invoiceId,
            ]],
            'application_context' => [
                'return_url' => $options['return_url'] ?? url('/billing/return'),
                'cancel_url' => $options['cancel_url'] ?? url('/billing/cancel'),
            ],
        ]);

        if (!$orderRes->successful()) {
            throw new \RuntimeException('PayPal order failed: ' . $orderRes->body());
        }

        $data = $orderRes->json();
        $approve = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        return [
            'status' => $data['status'] ?? 'pending',
            'checkout_url' => $approve,
            'client_secret' => null,
            'reference' => $data['id'] ?? ('pp_' . Str::random(10)),
            'gateway' => 'paypal',
        ];
    }

    private function payhereCheckout(float $amount, string $currency, ?string $workspaceId, ?string $invoiceId, string $mode): array
    {
        $merchantId = config("billing.credentials.payhere.{$mode}.merchant_id");
        $secret = config("billing.credentials.payhere.{$mode}.merchant_secret");
        $redirect = config("billing.credentials.payhere.{$mode}.redirect_url") ?: 'https://sandbox.payhere.lk/pay';

        $reference = 'ph_' . Str::random(8);
        if (!$merchantId || !$secret) {
            return [
                'status' => 'pending',
                'checkout_url' => "{$redirect}?order_id={$reference}",
                'client_secret' => null,
                'reference' => $reference,
                'gateway' => 'payhere',
            ];
        }

        // PayHere expects a signature, but here we simply craft the redirect URL.
        $checkoutUrl = "{$redirect}?merchant_id={$merchantId}&order_id={$reference}&amount=" . number_format($amount, 2, '.', '') . "&currency=" . strtoupper($currency);

        return [
            'status' => 'pending',
            'checkout_url' => $checkoutUrl,
            'client_secret' => null,
            'reference' => $reference,
            'gateway' => 'payhere',
        ];
    }

    private function payoneerCheckout(float $amount, string $currency, ?string $workspaceId, ?string $invoiceId, string $mode): array
    {
        $reference = 'po_' . Str::random(10);
        $url = $mode === 'live'
            ? (string) config('billing.credentials.payoneer.live.checkout_url', '')
            : (string) config('billing.credentials.payoneer.sandbox.checkout_url', 'https://sandbox.payoneer.example/checkout');

        return [
            'status' => 'pending',
            'checkout_url' => $url !== '' ? "{$url}?reference={$reference}" : null,
            'client_secret' => null,
            'reference' => $reference,
            'gateway' => 'payoneer',
        ];
    }

    private function bankTransferCheckout(float $amount, string $currency, ?string $workspaceId, ?string $invoiceId, string $mode): array
    {
        $reference = 'bank_' . Str::random(10);
        return [
            'status' => 'pending_provider',
            'checkout_url' => null,
            'client_secret' => null,
            'reference' => $reference,
            'gateway' => 'bank_transfer',
            'instructions' => [
                'currency' => strtoupper($currency),
                'amount' => $amount,
                'reference' => $reference,
                'note' => 'Use this reference in your transfer remark.',
            ],
        ];
    }
}
