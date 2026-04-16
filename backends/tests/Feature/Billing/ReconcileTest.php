<?php

use App\Jobs\Billing\ReconcilePendingPaymentsJob;
use App\Models\Payment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('pending payment remains pending provider when remote status is unverified', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'WS',
    ]);
    $payment = Payment::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'invoice_id' => null,
        'payment_method_id' => null,
        'amount' => 10,
        'status' => 'pending',
        'provider' => 'payhere',
        'reference' => 'ph_test',
        'metadata' => ['currency' => 'USD'],
        'idempotency_key' => 'idem_reconcile',
    ]);

    (new ReconcilePendingPaymentsJob())->handle(app(\App\Domain\Billing\GatewayRouter::class));

    $payment->refresh();
    expect($payment->status)->toBe('pending_provider');
});
