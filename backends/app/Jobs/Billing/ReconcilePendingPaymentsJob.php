<?php

namespace App\Jobs\Billing;

use App\Domain\Billing\GatewayRouter;
use App\Models\Invoice;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\Payment;
use App\Models\WalletAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ReconcilePendingPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('billing');
    }

    public function handle(GatewayRouter $router): void
    {
        $pending = Payment::whereIn('status', [
            Payment::STATUS_PENDING,
            Payment::STATUS_PENDING_PROVIDER,
            Payment::STATUS_PENDING_WEBHOOK,
        ])->get();
        foreach ($pending as $payment) {
            $status = $router->fetchStatus($payment->provider, $payment->reference ?? '');
            $remoteStatus = strtolower((string) ($status['status'] ?? Payment::STATUS_PENDING));
            if (! in_array($remoteStatus, ['confirmed', 'succeeded', 'settled'], true)) {
                $payment->status = in_array($remoteStatus, ['failed', 'expired', 'reversed'], true)
                    ? $remoteStatus
                    : Payment::STATUS_PENDING_PROVIDER;
                $payment->save();
                continue;
            }
            $payment->status = Payment::STATUS_CONFIRMED;
            $payment->save();

            if ($payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);
                if ($invoice) {
                    $invoice->status = 'paid';
                    $invoice->save();
                }
            }

            $this->creditLedger($payment);
        }
    }

    private function creditLedger(Payment $payment): void
    {
        $wallet = WalletAccount::firstOrCreate(
            ['workspace_id' => $payment->workspace_id, 'currency' => $payment->metadata['currency'] ?? 'USD', 'type' => 'cash'],
            ['id' => (string) Str::uuid(), 'name' => 'Primary', 'status' => 'active', 'balance' => 0, 'available' => 0]
        );
        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $payment->workspace_id],
            ['id' => (string) Str::uuid(), 'balance' => 0, 'meta' => ['currency' => $payment->metadata['currency'] ?? 'USD']]
        );

        $delta = $payment->amount;
        $ledger->balance += $delta;
        $ledger->save();
        $wallet->balance += $delta;
        $wallet->available += $delta;
        $wallet->save();

        LedgerTransaction::firstOrCreate(
            ['idempotency_key' => $payment->idempotency_key],
            [
                'id' => (string) Str::uuid(),
                'ledger_id' => $ledger->id,
                'wallet_account_id' => $wallet->id,
                'type' => 'payment',
                'amount' => $delta,
                'meta' => ['payment_id' => $payment->id],
                'occurred_at' => now(),
                'idempotency_key' => $payment->idempotency_key,
            ]
        );
    }
}
