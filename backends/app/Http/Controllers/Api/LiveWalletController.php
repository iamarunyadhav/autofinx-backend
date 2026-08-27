<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Domain\Trading\TradingIntegrationResolver;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Support\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveWalletController extends Controller
{
    public function balances(string $workspaceId)
    {
        $accounts = WalletAccount::where('workspace_id', $workspaceId)
            ->orderBy('currency')
            ->get();

        return response()->json($accounts);
    }

    /**
     * Real balance pulled live from the connected exchange for the workspace's
     * active integration (testnet or live), so the UI can show the actual
     * account equity instead of the internal/seeded wallet ledger.
     */
    public function exchangeBalance(
        Request $request,
        string $workspaceId,
        TradingModuleSettingsService $settings,
        TradingIntegrationResolver $integrations,
        LiveTradeProviderRegistry $providers,
    ) {
        $asset = strtoupper(trim((string) $request->query('asset', 'USDT'))) ?: 'USDT';
        $state = $settings->tradingState($workspaceId);

        if (($state['mode'] ?? 'paper') !== 'live') {
            return response()->json([
                'source' => 'internal',
                'mode' => $state['mode'] ?? 'paper',
                'environment' => null,
                'available' => null,
                'asset' => $asset,
            ]);
        }

        try {
            $provider = (string) ($state['preferred_provider'] ?? 'binance');
            $integration = $integrations->resolve($workspaceId, $provider, $state['preferred_integration_id'] ?? null);
            $balance = $providers->for($provider)->availableBalance($integration, $asset);

            return response()->json([
                'source' => 'exchange',
                'mode' => 'live',
                'environment' => $state['exchange_environment'] ?? ($integration->environment ?? 'live'),
                'provider' => $provider,
                'asset' => $balance['asset'] ?? 'USDT',
                'balance' => (float) ($balance['balance'] ?? 0),
                'available' => (float) ($balance['available'] ?? 0),
                'as_of' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'source' => 'exchange',
                'mode' => 'live',
                'environment' => $state['exchange_environment'] ?? null,
                'asset' => $asset,
                'available' => null,
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    public function depositIntent(Request $request, PaymentGatewayService $gateway)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:10',
            'gateway' => 'nullable|string|in:payhere,stripe,bank_transfer,bank,paypal,payoneer,card,credit_card,debit_card',
            'environment' => 'nullable|string|in:sandbox,live',
        ]);

        $provider = $this->normalizePayoutMethod((string) ($data['gateway'] ?? 'payhere'));
        $currency = strtoupper((string) $data['currency']);
        $intent = $gateway->createCheckout($provider, [
            'workspace_id' => $data['workspace_id'],
            'amount' => (float) $data['amount'],
            'currency' => $currency,
            'environment' => $data['environment'] ?? null,
        ]);

        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'invoice_id' => null,
            'payment_method_id' => null,
            'amount' => (float) $data['amount'],
            'status' => $this->depositIntentStatus($provider, $intent),
            'provider' => $provider,
            'reference' => $intent['reference'] ?? ('dep_' . Str::random(10)),
            'metadata' => [
                'currency' => $currency,
                'mode' => 'deposit',
                'environment' => $data['environment'] ?? null,
                'created_by' => $request->user()->id ?? null,
                'checkout' => $intent,
                'provider_state' => $intent['status'] ?? null,
            ],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return response()->json([
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'checkout' => $intent,
        ], 201);
    }

    public function payHereWebhook(Request $request)
    {
        $merchantSecret = (string) env('PAYHERE_MERCHANT_SECRET', '');
        $merchantId = (string) ($request->input('merchant_id') ?? '');
        $orderId = (string) ($request->input('order_id') ?? '');
        $amount = (string) ($request->input('payhere_amount') ?? $request->input('amount') ?? '0');
        $currency = strtoupper((string) ($request->input('payhere_currency') ?? 'USD'));
        $statusCode = (string) ($request->input('status_code') ?? '');
        $receivedSig = strtoupper((string) ($request->input('md5sig') ?? ''));

        if ($merchantSecret !== '') {
            $localSecret = strtoupper(md5($merchantSecret));
            $expected = strtoupper(md5($merchantId . $orderId . $amount . $currency . $statusCode . $localSecret));
            if (! hash_equals($expected, $receivedSig)) {
                return response()->json(['message' => 'Invalid PayHere signature'], 401);
            }
        }

        $payment = Payment::where('reference', $orderId)->first();
        if (! $payment) {
            return response()->json(['message' => 'Unknown payment reference'], 404);
        }

        if ($payment->status === Payment::STATUS_CONFIRMED) {
            return response()->json(['ok' => true, 'status' => 'already_processed']);
        }

        if ($statusCode !== '2') {
            $payment->status = Payment::STATUS_FAILED;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'webhook' => $request->all(),
            ]);
            $payment->save();
            return response()->json(['ok' => true, 'status' => 'ignored_non_success']);
        }

        DB::transaction(function () use ($payment, $currency, $amount, $request): void {
            if ($payment->status === Payment::STATUS_CONFIRMED) {
                return;
            }

            $this->creditWalletDeposit(
                payment: $payment,
                amount: (float) $amount,
                currency: $currency,
                userId: $payment->metadata['created_by'] ?? null,
                provider: 'payhere',
                meta: ['webhook' => $request->all()]
            );

            $payment->status = Payment::STATUS_CONFIRMED;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'webhook' => $request->all(),
            ]);
            $payment->save();
        });

        return response()->json(['ok' => true, 'status' => 'credited']);
    }

    public function stripeWebhook(Request $request)
    {
        $payload = (string) $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $mode = config('billing.mode', 'sandbox') === 'live' ? 'live' : 'sandbox';
        $signingSecret = (string) config("billing.signing_secrets.stripe.{$mode}", '');

        if ($signingSecret !== '' && ! $this->verifyStripeSignature($payload, $signature, $signingSecret)) {
            return response()->json(['message' => 'Invalid Stripe signature'], 401);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || ! isset($event['type'])) {
            return response()->json(['message' => 'Invalid Stripe payload'], 422);
        }

        $type = (string) $event['type'];
        $object = $event['data']['object'] ?? null;
        if (! is_array($object)) {
            return response()->json(['ok' => true, 'status' => 'ignored']);
        }

        $terminalFailureEvents = [
            'checkout.session.expired',
            'checkout.session.async_payment_failed',
        ];
        $successEvents = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ];

        if (! in_array($type, array_merge($successEvents, $terminalFailureEvents), true)) {
            return response()->json(['ok' => true, 'status' => 'ignored']);
        }

        $reference = (string) ($object['id'] ?? '');
        $payment = $reference !== '' ? Payment::where('reference', $reference)->first() : null;

        if (! $payment) {
            return response()->json(['ok' => true, 'status' => 'unknown_reference']);
        }

        if (in_array($type, $terminalFailureEvents, true)) {
            if (in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PENDING_PROVIDER, Payment::STATUS_PENDING_WEBHOOK], true)) {
                $payment->status = $type === 'checkout.session.expired'
                    ? Payment::STATUS_EXPIRED
                    : Payment::STATUS_FAILED;
                $payment->metadata = array_merge($payment->metadata ?? [], [
                    'stripe_event_type' => $type,
                    'stripe_event_id' => $event['id'] ?? null,
                    'stripe_payment_status' => $object['payment_status'] ?? null,
                ]);
                $payment->save();
            }

            return response()->json(['ok' => true, 'status' => 'marked_failed']);
        }

        if ($payment->status === Payment::STATUS_CONFIRMED) {
            return response()->json(['ok' => true, 'status' => 'already_processed']);
        }

        $currency = strtoupper((string) ($payment->metadata['currency'] ?? $object['currency'] ?? 'USD'));
        $amount = (float) $payment->amount;
        if ($amount <= 0 && isset($object['amount_total']) && is_numeric($object['amount_total'])) {
            $amount = ((float) $object['amount_total']) / 100;
        }

        DB::transaction(function () use ($payment, $amount, $currency, $type, $event, $object): void {
            if ($payment->status === Payment::STATUS_CONFIRMED) {
                return;
            }

            $this->creditWalletDeposit(
                payment: $payment,
                amount: $amount,
                currency: $currency,
                userId: $payment->metadata['created_by'] ?? null,
                provider: 'stripe',
                meta: [
                    'stripe_event_type' => $type,
                    'stripe_event_id' => $event['id'] ?? null,
                    'stripe_session_id' => $object['id'] ?? null,
                    'stripe_payment_status' => $object['payment_status'] ?? null,
                ]
            );

            $payment->status = Payment::STATUS_CONFIRMED;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'stripe_event_type' => $type,
                'stripe_event_id' => $event['id'] ?? null,
                'stripe_session_id' => $object['id'] ?? null,
                'stripe_payment_status' => $object['payment_status'] ?? null,
            ]);
            $payment->save();
        });

        return response()->json(['ok' => true, 'status' => 'credited']);
    }

    public function withdraw(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:10',
            'method' => 'nullable|string|in:bank,bank_transfer,payhere,stripe,paypal,payoneer,manual',
            'destination_meta' => 'required|array',
        ]);

        $currency = strtoupper((string) $data['currency']);
        $amount = (float) $data['amount'];
        $reviewState = $this->withdrawalReviewState((string) $data['workspace_id']);

        return DB::transaction(function () use ($request, $data, $currency, $amount, $reviewState) {
            $account = WalletAccount::where('workspace_id', $data['workspace_id'])
                ->where('currency', $currency)
                ->where('type', 'cash')
                ->lockForUpdate()
                ->first();
            if (! $account) {
                return response()->json(['message' => 'Wallet account not found for selected currency.'], 404);
            }

            $available = (float) ($account->available ?? $account->balance ?? 0);
            if ($available < $amount) {
                return response()->json(['message' => 'Insufficient available balance.'], 422);
            }

            $account->available = $available - $amount;
            $account->locked_balance = (float) ($account->locked_balance ?? 0) + $amount;
            $account->save();

            $withdrawal = WithdrawalRequest::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $data['workspace_id'],
                'user_id' => $request->user()->id ?? null,
                'amount' => $amount,
                'currency' => $currency,
                'status' => WithdrawalRequest::STATUS_UNDER_REVIEW,
                'method' => $this->normalizePayoutMethod((string) ($data['method'] ?? 'bank_transfer')),
                'reference' => [
                    'source' => 'live_wallet',
                    'requested_at' => now()->toIso8601String(),
                    'destination' => $data['destination_meta'] ?? null,
                    'review_state' => $reviewState,
                ],
                'requires_2fa' => true,
            ]);

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'type' => 'WITHDRAW',
                'amount' => -$amount,
                'reference_id' => $withdrawal->id,
                'meta_json' => [
                    'status' => WithdrawalRequest::STATUS_UNDER_REVIEW,
                    'method' => $withdrawal->method,
                    'destination' => $data['destination_meta'] ?? null,
                    'review_state' => $reviewState,
                ],
                'ts' => now(),
                'trace_id' => (string) Str::uuid(),
            ]);

            return response()->json([
                'withdrawal' => $withdrawal,
                'account' => $account->fresh(),
            ], 201);
        });
    }

    public function withdrawWebhook(Request $request)
    {
        $token = (string) env('WITHDRAW_WEBHOOK_TOKEN', '');
        if ($token === '') {
            return response()->json(['message' => 'Withdrawal webhook is not configured.'], 503);
        }

        $provided = (string) $request->header('X-Withdraw-Token', '');
        if (! hash_equals($token, $provided)) {
            return response()->json(['message' => 'Invalid withdraw webhook token'], 401);
        }

        $data = $request->validate([
            'withdrawal_id' => 'required|uuid|exists:withdrawal_requests,id',
            'status' => 'required|string|in:completed,failed,cancelled',
            'provider_reference' => 'nullable|string|max:120',
            'meta' => 'nullable|array',
        ]);

        return DB::transaction(function () use ($data) {
            $withdrawal = WithdrawalRequest::lockForUpdate()->findOrFail($data['withdrawal_id']);
            if (in_array($withdrawal->status, [WithdrawalRequest::STATUS_SETTLED, WithdrawalRequest::STATUS_FAILED, WithdrawalRequest::STATUS_REVERSED, WithdrawalRequest::STATUS_REJECTED], true)) {
                return response()->json([
                    'ok' => true,
                    'status' => $withdrawal->status,
                    'idempotent' => true,
                ]);
            }

            if (! in_array($withdrawal->status, [WithdrawalRequest::STATUS_UNDER_REVIEW, WithdrawalRequest::STATUS_APPROVED, WithdrawalRequest::STATUS_SUBMITTED_TO_PROVIDER, WithdrawalRequest::STATUS_PROVIDER_PENDING], true)) {
                return response()->json([
                    'message' => 'Withdrawal is not in a provider-trackable state.',
                ], 422);
            }

            $account = WalletAccount::where('workspace_id', $withdrawal->workspace_id)
                ->where('currency', $withdrawal->currency)
                ->where('type', 'cash')
                ->lockForUpdate()
                ->firstOrFail();

            $amount = (float) $withdrawal->amount;
            $status = $this->mapWithdrawalWebhookStatus((string) $data['status']);

            if ($status === WithdrawalRequest::STATUS_SETTLED) {
                $account->balance = (float) $account->balance - $amount;
                $account->locked_balance = max(0, (float) $account->locked_balance - $amount);
            } else {
                $account->available = (float) $account->available + $amount;
                $account->locked_balance = max(0, (float) $account->locked_balance - $amount);

                WalletLedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'account_id' => $account->id,
                    'type' => 'ADJUST',
                    'amount' => $amount,
                        'reference_id' => $withdrawal->id,
                        'meta_json' => [
                            'reason' => 'withdrawal_reversal',
                            'status' => $status,
                        ],
                    'ts' => now(),
                    'trace_id' => (string) Str::uuid(),
                ]);
            }
            $account->save();

            $withdrawal->status = $status;
            $withdrawal->reference = array_merge($withdrawal->reference ?? [], [
                'provider_reference' => $data['provider_reference'] ?? null,
                'webhook_meta' => $data['meta'] ?? null,
                'updated_at' => now()->toIso8601String(),
            ]);
            $withdrawal->save();

            return response()->json([
                'ok' => true,
                'status' => $withdrawal->status,
                'withdrawal_id' => $withdrawal->id,
            ]);
        });
    }

    private function normalizePayoutMethod(string $method): string
    {
        $method = strtolower(trim($method));

        return match ($method) {
            'bank', 'manual' => 'bank_transfer',
            'card', 'credit_card', 'debit_card' => 'stripe',
            default => $method,
        };
    }

    private function depositIntentStatus(string $provider, array $intent): string
    {
        $provider = strtolower($provider);
        $status = strtolower((string) ($intent['status'] ?? Payment::STATUS_PENDING));

        if (in_array($provider, ['payhere', 'stripe', 'paypal', 'payoneer'], true)) {
            return Payment::STATUS_PENDING_WEBHOOK;
        }

        return $status === Payment::STATUS_CONFIRMED
            ? Payment::STATUS_CONFIRMED
            : Payment::STATUS_PENDING_PROVIDER;
    }

    private function withdrawalReviewState(string $workspaceId): string
    {
        $setting = Setting::query()
            ->where('workspace_id', $workspaceId)
            ->where('group', 'compliance')
            ->where('key', 'withdrawals_kyc_verified')
            ->first();

        return (bool) ($setting?->value_json ?? false) ? 'kyc_verified' : 'pending_review';
    }

    private function mapWithdrawalWebhookStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => WithdrawalRequest::STATUS_SETTLED,
            'cancelled' => WithdrawalRequest::STATUS_REVERSED,
            default => WithdrawalRequest::STATUS_FAILED,
        };
    }

    private function verifyStripeSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($key === 't' && $value !== null) {
                $timestamp = (int) $value;
            }
            if ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function creditWalletDeposit(
        Payment $payment,
        float $amount,
        string $currency,
        ?string $userId,
        string $provider,
        array $meta = [],
    ): void {
        $account = WalletAccount::firstOrCreate(
            [
                'workspace_id' => $payment->workspace_id,
                'currency' => strtoupper($currency),
                'type' => 'cash',
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'name' => strtoupper($currency) . ' Wallet',
                'status' => 'active',
                'balance' => 0,
                'available' => 0,
                'locked_balance' => 0,
            ]
        );

        $delta = max(0, $amount);
        $account->balance = (float) $account->balance + $delta;
        $account->available = (float) $account->available + $delta;
        $account->save();

        WalletLedgerEntry::create([
            'id' => (string) Str::uuid(),
            'account_id' => $account->id,
            'type' => 'DEPOSIT',
            'amount' => $delta,
            'reference_id' => $payment->id,
            'meta_json' => array_merge([
                'provider' => $provider,
            ], $meta),
            'ts' => now(),
            'trace_id' => (string) Str::uuid(),
        ]);
    }
}
