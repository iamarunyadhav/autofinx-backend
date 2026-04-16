<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookInbox;
use App\Models\WalletAccount;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Models\AuditLog;
use Throwable;
use App\Support\PaymentGatewayService;
use App\Domain\Billing\GatewayRouter;

class BillingController extends Controller
{
    public function plans()
    {
        if (Plan::count() === 0) {
            Plan::insert([
                ['id' => (string) Str::uuid(), 'name' => 'Starter', 'price' => 0, 'interval' => 'monthly', 'features' => json_encode(['paper_trading' => true]), 'created_at' => now(), 'updated_at' => now()],
                ['id' => (string) Str::uuid(), 'name' => 'Pro', 'price' => 49, 'interval' => 'monthly', 'features' => json_encode(['live_trading' => true, 'priority_support' => true]), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
        return Plan::all();
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'plan_id' => 'required|exists:plans,id',
            'gateway' => 'sometimes|string|in:stripe,paypal,payhere,payoneer,bank_transfer',
            'currency' => 'sometimes|string|max:10',
            'payment_method_id' => 'nullable|uuid',
            'email' => 'nullable|email',
        ]);
        $gateway = $data['gateway'] ?? 'stripe';
        $currency = strtoupper($data['currency'] ?? 'USD');
        $plan = Plan::findOrFail($data['plan_id']);

        $subscription = Subscription::updateOrCreate(
            ['workspace_id' => $data['workspace_id']],
            [
                'id' => (string) Str::uuid(),
                'plan_id' => $data['plan_id'],
                'status' => 'pending',
                'renews_at' => now()->addMonth(),
            ]
        );

        $invoice = Invoice::create([
            'id' => (string) Str::uuid(),
            'invoice_number' => 'INV-' . strtoupper(Str::random(8)),
            'workspace_id' => $data['workspace_id'],
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => $currency,
            'status' => 'pending',
            'due_date' => now()->addDays(3),
            'metadata' => [
                'plan' => $data['plan_id'],
                'gateway' => $gateway,
            ],
        ]);

        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'invoice_id' => $invoice->id,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'amount' => $plan->price,
            'status' => 'pending',
            'provider' => $gateway,
            'reference' => null,
            'metadata' => [
                'currency' => $currency,
                'plan_id' => $plan->id,
                'workspace_id' => $data['workspace_id'],
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $checkout = app(PaymentGatewayService::class)->createCheckout($gateway, [
            'amount' => $plan->price,
            'currency' => $currency,
            'workspace_id' => $data['workspace_id'],
            'invoice_id' => $invoice->id,
            'return_url' => url('/billing/return'),
            'cancel_url' => url('/billing/cancel'),
            'customer_email' => $data['email'] ?? null,
        ]);
        $paymentStatus = $plan->price <= 0
            ? Payment::STATUS_CONFIRMED
            : $this->normalizeGatewayPaymentStatus((string) ($checkout['status'] ?? Payment::STATUS_PENDING));

        $invoice->metadata = array_merge($invoice->metadata ?? [], [
            'checkout_url' => $checkout['checkout_url'] ?? null,
            'client_secret' => $checkout['client_secret'] ?? null,
            'gateway_reference' => $checkout['reference'] ?? null,
        ]);
        $invoice->status = in_array($paymentStatus, [Payment::STATUS_CONFIRMED, Payment::STATUS_SETTLED], true)
            ? 'paid'
            : 'pending';
        $invoice->save();

        $payment->reference = $checkout['reference'] ?? null;
        $payment->status = $paymentStatus;
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'checkout_url' => $checkout['checkout_url'] ?? null,
            'client_secret' => $checkout['client_secret'] ?? null,
            'gateway' => $gateway,
        ]);
        $payment->save();

        if ($invoice->status === 'paid') {
            $subscription->status = 'active';
            $subscription->renews_at = now()->addMonth();
            $subscription->save();
        }

        $invoice = $this->ensureInvoicePdf($invoice, $plan->name);

        $this->logAudit($request, 'billing.subscribe', $data['workspace_id'], [
            'plan' => $data['plan_id'],
            'gateway' => $gateway,
            'invoice_id' => $invoice->id,
        ]);

        return response()->json([
            'subscription' => $subscription,
            'invoice' => $invoice,
            'payment' => $payment,
            'checkout' => $checkout,
        ], 201);
    }

    public function cancel(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
        ]);
        Subscription::where('workspace_id', $data['workspace_id'])->update(['status' => 'canceled']);
        $this->logAudit($request, 'billing.cancel', $data['workspace_id']);
        return response()->json(['status' => 'canceled']);
    }

    public function invoices(Request $request)
    {
        $workspaceId = $request->input('workspace_id');
        $invoices = Invoice::where('workspace_id', $workspaceId)->orderByDesc('created_at')->get();
        return $invoices->map(function (Invoice $invoice) {
            if (!$invoice->pdf_url) {
                $invoice = $this->ensureInvoicePdf($invoice);
            }
            return $invoice;
        });
    }

    public function topup(Request $request)
    {
        return response()->json([
            'message' => 'Legacy wallet top-up is disabled. Use /wallet/deposit/intent for provider-backed deposits.',
        ], 410);
    }

    public function withdraw(Request $request)
    {
        return response()->json([
            'message' => 'Legacy billing withdrawal is disabled. Use /wallet/withdraw/request for review-based withdrawals.',
        ], 410);
    }

    public function webhook(Request $request, string $gateway, GatewayRouter $router)
    {
        $mode = $this->currentMode();
        $idempotencyKey = $request->header('Idempotency-Key', (string) Str::uuid());
        $normalized = array_merge([
            'event_id' => $request->input('id') ?? $request->input('event_id'),
            'workspace_id' => null,
            'invoice_id' => null,
            'amount' => 0,
            'currency' => 'USD',
            'status' => Payment::STATUS_PENDING,
            'reference' => null,
            'direction' => 'credit',
            'metadata' => [],
        ], $router->webhook($gateway, $request));
        $normalized['metadata'] = array_merge([
            'mode' => $mode,
            'gateway' => strtolower($gateway),
        ], (array) ($normalized['metadata'] ?? []));
        $eventId = $normalized['event_id'] ?? $request->input('id') ?? $request->input('event_id');
        $payload = $request->all();

        $webhook = WebhookInbox::firstOrCreate(
            ['source' => $gateway, 'event_id' => $eventId ?? $idempotencyKey],
            [
                'id' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'payload' => $payload,
                'status' => 'received',
            ]
        );

        if ($webhook->status === 'processed') {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            if (!$normalized['workspace_id']) {
                throw new \RuntimeException('workspace_id missing in webhook payload');
            }

            DB::transaction(function () use ($normalized, $gateway, $idempotencyKey, $request) {
                $paymentStatus = $this->normalizeGatewayPaymentStatus((string) ($normalized['status'] ?? Payment::STATUS_PENDING));
                $payment = Payment::idempotent($idempotencyKey)->first();
                if (! $payment) {
                    $payment = Payment::create([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $normalized['workspace_id'],
                        'invoice_id' => $normalized['invoice_id'],
                        'payment_method_id' => null,
                        'amount' => $normalized['amount'],
                        'status' => $paymentStatus,
                        'provider' => $gateway,
                        'reference' => $normalized['reference'],
                        'metadata' => $normalized['metadata'],
                        'idempotency_key' => $idempotencyKey,
                    ]);
                } else {
                    $payment->fill([
                        'status' => $paymentStatus,
                        'metadata' => array_merge($payment->metadata ?? [], $normalized['metadata']),
                        'reference' => $payment->reference ?: $normalized['reference'],
                    ])->save();
                }

                if ($normalized['invoice_id']) {
                    $invoice = Invoice::firstOrCreate(
                        ['id' => $normalized['invoice_id']],
                        [
                            'workspace_id' => $normalized['workspace_id'],
                            'amount' => $normalized['amount'],
                            'status' => 'pending',
                            'due_date' => now(),
                            'metadata' => $normalized['metadata'],
                        ]
                    );

                    $invoiceStatus = in_array($paymentStatus, [Payment::STATUS_CONFIRMED, Payment::STATUS_SETTLED], true)
                        ? 'paid'
                        : ($paymentStatus === Payment::STATUS_FAILED ? 'failed' : 'pending');
                    $invoice->status = $invoiceStatus;
                    $invoice->save();

                    $subscription = Subscription::where('workspace_id', $invoice->workspace_id)->first();
                    if ($subscription) {
                        if ($invoiceStatus === 'paid') {
                            $subscription->status = 'active';
                            $subscription->renews_at = now()->addMonth();
                        } elseif ($invoiceStatus === 'failed') {
                            $subscription->status = 'past_due';
                        }
                        $subscription->save();
                    }
                }

                if (in_array($paymentStatus, [Payment::STATUS_CONFIRMED, Payment::STATUS_SETTLED], true) && $normalized['amount'] > 0) {
                    $this->applyLedgerMovement(
                        $normalized['workspace_id'],
                        $normalized['amount'],
                        $normalized['currency'],
                        $normalized['direction'],
                        $payment,
                        $idempotencyKey
                    );
                    $this->notifyWorkspace(
                        $normalized['workspace_id'],
                        [
                            'type' => 'payment',
                            'title' => 'Payout Completed',
                            'body' => strtoupper($normalized['currency']) . ' ' . number_format($normalized['amount'], 2) . ' ' . ($normalized['direction'] === 'debit' ? 'debited' : 'credited'),
                            'meta' => [
                                'provider' => $gateway,
                                'reference' => $normalized['reference'],
                                'invoice_id' => $normalized['invoice_id'],
                            ],
                        ]
                    );
                }

                if ($paymentStatus === Payment::STATUS_FAILED) {
                    $this->notifyWorkspace(
                        $normalized['workspace_id'],
                        [
                            'type' => 'payment',
                            'title' => 'Payment Failed',
                            'body' => 'Reference: ' . ($normalized['reference'] ?? 'n/a') . ' via ' . $gateway,
                            'meta' => [
                                'provider' => $gateway,
                                'reference' => $normalized['reference'],
                                'invoice_id' => $normalized['invoice_id'],
                            ],
                        ]
                    );
                }

                $this->logAudit($request, 'billing.webhook', $normalized['workspace_id'], [
                    'gateway' => $gateway,
                    'event_id' => $normalized['event_id'] ?? null,
                    'reference' => $normalized['reference'] ?? null,
                    'status' => $paymentStatus,
                    'direction' => $normalized['direction'],
                    'mode' => $this->currentMode(),
                ]);
            });

            $webhook->status = 'processed';
            $webhook->processed_at = now();
            $webhook->save();

            return response()->json(['ok' => true, 'mode' => $mode]);
        } catch (Throwable $e) {
            $webhook->status = 'failed';
            $webhook->payload = array_merge((array) $webhook->payload, [
                '_error' => $e->getMessage(),
            ]);
            $webhook->save();

            return response()->json(['error' => $e->getMessage(), 'ok' => false], 422);
        }
    }

    private function logAudit(Request $request, string $action, ?string $workspaceId = null, array $meta = []): void
    {
        $user = $request->user();

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $user?->id,
            'actor_type' => $user ? 'user' : 'system',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function normalizeGatewayPaymentStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match (true) {
            in_array($normalized, [Payment::STATUS_CONFIRMED, Payment::STATUS_SETTLED], true) => $normalized,
            in_array($normalized, ['succeeded', 'success', 'completed', 'paid'], true) => Payment::STATUS_CONFIRMED,
            in_array($normalized, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED, Payment::STATUS_REVERSED], true) => $normalized,
            in_array($normalized, [Payment::STATUS_PENDING_PROVIDER, Payment::STATUS_PROVIDER_PENDING], true) => Payment::STATUS_PENDING_PROVIDER,
            $normalized === Payment::STATUS_PENDING_WEBHOOK => Payment::STATUS_PENDING_WEBHOOK,
            default => Payment::STATUS_PENDING,
        };
    }

    private function currentMode(): string
    {
        $mode = config('billing.mode', 'sandbox');
        return $mode === 'live' ? 'live' : 'sandbox';
    }

    private function normalizeWebhook(string $gateway, array $payload, string $mode): array
    {
        $gateway = strtolower($gateway);
        $base = [
            'workspace_id' => null,
            'invoice_id' => null,
            'amount' => 0,
            'currency' => 'USD',
            'status' => 'pending',
            'reference' => null,
            'direction' => 'credit',
            'metadata' => ['mode' => $mode, 'raw_gateway' => $gateway],
            'event_id' => $payload['id'] ?? $payload['event_id'] ?? null,
        ];

        if ($gateway === 'stripe') {
            $object = $payload['data']['object'] ?? [];
            $meta = $object['metadata'] ?? [];
            $base['workspace_id'] = $meta['workspace_id'] ?? null;
            $base['invoice_id'] = $meta['invoice_id'] ?? $object['invoice'] ?? null;
            $base['amount'] = isset($object['amount_total']) ? $object['amount_total'] / 100 : ($object['amount'] ?? 0) / 100;
            $base['currency'] = strtoupper($object['currency'] ?? 'USD');
            $base['reference'] = $object['id'] ?? $payload['id'] ?? null;
            $base['status'] = $object['payment_status'] ?? ($payload['type'] ?? 'pending');
            $base['direction'] = $meta['direction'] ?? 'credit';
            $base['metadata'] = array_merge($base['metadata'], $meta);
            $base['status'] = str_contains($base['status'], 'failed') ? 'failed' : (str_contains($base['status'], 'succeeded') ? 'succeeded' : 'pending');
        } elseif ($gateway === 'paypal') {
            $resource = $payload['resource'] ?? [];
            $base['workspace_id'] = $resource['custom_id'] ?? $resource['custom'] ?? null;
            $base['invoice_id'] = $resource['invoice_id'] ?? $resource['billing_agreement_id'] ?? null;
            $amount = $resource['amount']['value'] ?? $resource['seller_receivable_breakdown']['gross_amount']['value'] ?? 0;
            $base['amount'] = (float) $amount;
            $base['currency'] = strtoupper($resource['amount']['currency_code'] ?? $resource['amount']['currency'] ?? 'USD');
            $base['reference'] = $resource['id'] ?? $payload['id'] ?? null;
            $base['status'] = strtolower($resource['status'] ?? $payload['event_type'] ?? 'pending');
            $base['direction'] = $resource['payment_mode'] === 'DEBIT' ? 'debit' : 'credit';
            $base['metadata'] = array_merge($base['metadata'], [
                'paypal' => [
                    'event_type' => $payload['event_type'] ?? null,
                ],
            ]);
            $base['status'] = str_contains($base['status'], 'completed') || str_contains($base['status'], 'paid') ? 'succeeded' : (str_contains($base['status'], 'denied') ? 'failed' : 'pending');
        } elseif ($gateway === 'payoneer') {
            $base['workspace_id'] = $payload['workspace_id'] ?? $payload['accountId'] ?? null;
            $base['invoice_id'] = $payload['invoice_id'] ?? $payload['paymentId'] ?? null;
            $base['amount'] = (float) ($payload['amount'] ?? 0);
            $base['currency'] = strtoupper($payload['currency'] ?? 'USD');
            $base['reference'] = $payload['paymentId'] ?? $payload['id'] ?? null;
            $status = strtolower($payload['status'] ?? 'pending');
            $base['status'] = in_array($status, ['paid', 'completed', 'succeeded']) ? 'succeeded' : ($status === 'failed' ? 'failed' : 'pending');
            $base['direction'] = ($payload['direction'] ?? 'credit') === 'debit' ? 'debit' : 'credit';
            $base['metadata'] = array_merge($base['metadata'], ['payoneer_event' => $payload['eventType'] ?? null]);
        } else {
            // generic fallback
            $base['workspace_id'] = $payload['workspace_id'] ?? null;
            $base['invoice_id'] = $payload['invoice_id'] ?? null;
            $base['amount'] = (float) ($payload['amount'] ?? 0);
            $base['currency'] = strtoupper($payload['currency'] ?? 'USD');
            $base['reference'] = $payload['reference'] ?? $payload['id'] ?? null;
            $status = strtolower($payload['status'] ?? 'pending');
            $base['status'] = $status;
            $base['direction'] = $payload['direction'] ?? 'credit';
            $base['metadata'] = array_merge($base['metadata'], $payload['metadata'] ?? []);
        }

        return $base;
    }

    private function applyLedgerMovement(string $workspaceId, float $amount, string $currency, string $direction, Payment $payment, string $idempotencyKey): void
    {
        $wallet = WalletAccount::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'currency' => strtoupper($currency ?: 'USD'),
                'type' => 'cash',
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Primary Wallet',
                'status' => 'active',
                'balance' => 0,
                'available' => 0,
                'metadata' => ['auto_created' => true],
            ]
        );

        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['id' => (string) Str::uuid(), 'balance' => 0, 'meta' => ['currency' => $currency]]
        );

        $already = LedgerTransaction::idempotent($idempotencyKey)->first();
        if ($already) {
            return;
        }

        $delta = $direction === 'debit' ? -1 * abs($amount) : abs($amount);
        $wallet->balance = ($wallet->balance ?? 0) + $delta;
        $wallet->available = ($wallet->available ?? 0) + $delta;
        $wallet->save();

        $ledger->balance = ($ledger->balance ?? 0) + $delta;
        $ledger->save();

        LedgerTransaction::create([
            'id' => (string) Str::uuid(),
            'ledger_id' => $ledger->id,
            'wallet_account_id' => $wallet->id,
            'type' => $direction === 'debit' ? 'withdrawal' : 'payment',
            'amount' => $amount,
            'meta' => [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'reference' => $payment->reference,
                'direction' => $direction,
            ],
            'occurred_at' => now(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function verifyStripeSignature(?string $signatureHeader, string $secret, string $payload): bool
    {
        if (! $signatureHeader) {
            return false;
        }
        $parts = collect(explode(',', $signatureHeader))
            ->mapWithKeys(function ($part) {
                [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
                return [$k => $v];
            });

        $timestamp = $parts->get('t');
        $signature = $parts->get('v1');
        if (! $timestamp || ! $signature) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$payload}";
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $signature);
    }

    private function verifyHmacHeader(?string $signatureHeader, string $secret, string $eventId, string $payload): bool
    {
        if (! $signatureHeader) {
            return false;
        }

        $data = "{$eventId}.{$payload}";
        $expected = hash_hmac('sha256', $data, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    private function notifyWorkspace(string $workspaceId, array $content): void
    {
        $members = WorkspaceMember::where('workspace_id', $workspaceId)->pluck('user_id');
        foreach ($members as $userId) {
            Notification::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'type' => $content['type'] ?? 'info',
                'content' => $content,
            ]);
        }
    }

    private function ensureInvoicePdf(Invoice $invoice, ?string $planName = null): Invoice
    {
        if ($invoice->pdf_url) {
            return $invoice;
        }

        $planLabel = $planName ?? $invoice->metadata['plan'] ?? 'Plan';
        $contents = "AutoFinX Invoice\n\nInvoice #: {$invoice->invoice_number}\nWorkspace: {$invoice->workspace_id}\nPlan: {$planLabel}\nAmount: {$invoice->amount} {$invoice->currency}\nStatus: {$invoice->status}\nDue: {$invoice->due_date}\n\nThis is an auto-generated invoice for your records.";

        $path = "invoices/{$invoice->id}.pdf";
        Storage::disk('public')->put($path, $contents);
        $invoice->pdf_url = Storage::disk('public')->url($path);
        $invoice->save();

        return $invoice;
    }
}
