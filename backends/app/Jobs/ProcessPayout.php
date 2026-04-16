<?php

namespace App\Jobs;

use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\WalletAccount;
use App\Models\WorkspaceMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\PaymentGatewayService;

class ProcessPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $paymentId;

    public function __construct(string $paymentId)
    {
        $this->paymentId = $paymentId;
    }

    public function handle(): void
    {
        $payment = Payment::find($this->paymentId);
        if (! $payment || in_array($payment->status, [Payment::STATUS_SETTLED, Payment::STATUS_FAILED, Payment::STATUS_REVERSED], true)) {
            return;
        }

        $currency = $payment->metadata['currency'] ?? 'USD';
        $amount = (float) $payment->amount;
        $direction = $payment->metadata['direction'] ?? 'debit';

        try {
            $result = app(PaymentGatewayService::class)->payout($payment);
            $externalRef = $result['reference'] ?? 'PO-' . strtoupper(Str::random(8));
            $status = strtolower((string) ($result['status'] ?? Payment::STATUS_PROVIDER_PENDING));

            if (in_array($status, ['succeeded', 'settled', Payment::STATUS_SETTLED], true)) {
                DB::transaction(function () use ($payment, $amount, $currency, $direction, $externalRef) {
                    $ledger = Ledger::firstOrCreate(
                        ['workspace_id' => $payment->workspace_id],
                        ['id' => (string) Str::uuid(), 'balance' => 0]
                    );

                    $wallet = WalletAccount::firstOrCreate(
                        [
                            'workspace_id' => $payment->workspace_id,
                            'currency' => $currency,
                            'type' => 'cash',
                        ],
                        [
                            'id' => (string) Str::uuid(),
                            'name' => 'Primary Wallet',
                            'status' => 'active',
                            'balance' => 0,
                            'available' => 0,
                        ]
                    );

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
                        'type' => 'withdrawal',
                        'amount' => $amount,
                        'meta' => [
                            'payment_id' => $payment->id,
                            'provider' => $payment->provider,
                            'reference' => $externalRef,
                            'direction' => $direction,
                        ],
                        'occurred_at' => now(),
                        'idempotency_key' => $payment->idempotency_key ?? (string) Str::uuid(),
                    ]);

                    $payment->status = Payment::STATUS_SETTLED;
                    $payment->reference = $payment->reference ?: $externalRef;
                    $payment->metadata = array_merge($payment->metadata ?? [], ['external_reference' => $externalRef]);
                    $payment->save();
                });

                $this->notify($payment, 'Withdrawal completed', 'Funds sent to your payout rail.');
                return;
            }

            $payment->status = Payment::STATUS_PROVIDER_PENDING;
            $payment->reference = $payment->reference ?: $externalRef;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'external_reference' => $externalRef,
                'provider_status' => $result['status'] ?? Payment::STATUS_PROVIDER_PENDING,
                'provider_raw' => $result['raw'] ?? null,
            ]);
            $payment->save();

            $this->notify($payment, 'Withdrawal submitted', 'Payout request submitted for provider confirmation.');
        } catch (\Throwable $e) {
            $payment->status = Payment::STATUS_FAILED;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'failure_reason' => $e->getMessage(),
            ]);
            $payment->save();
            $this->notify($payment, 'Withdrawal failed', 'Payout rejected by provider. Please retry or update payout method.');
        }
    }

    private function notify(Payment $payment, string $title, string $body): void
    {
        $users = WorkspaceMember::where('workspace_id', $payment->workspace_id)->pluck('user_id');
        foreach ($users as $userId) {
            Notification::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'workspace_id' => $payment->workspace_id,
                'type' => 'payout',
                'content' => [
                    'title' => $title,
                    'body' => $body,
                    'amount' => $payment->amount,
                    'currency' => $payment->metadata['currency'] ?? 'USD',
                    'provider' => $payment->provider,
                    'status' => $payment->status,
                    'reference' => $payment->reference,
                ],
            ]);
        }
    }
}
