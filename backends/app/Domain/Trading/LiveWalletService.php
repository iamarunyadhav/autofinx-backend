<?php

namespace App\Domain\Trading;

use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Models\Integration;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LiveWalletService
{
    public function __construct(private readonly LiveTradeProviderRegistry $providers)
    {
    }

    public function resolveAccount(
        string $workspaceId,
        Integration $integration,
        ?string $walletAccountId = null,
        string $currency = 'USDT'
    ): WalletAccount {
        if ($walletAccountId) {
            $account = WalletAccount::where('workspace_id', $workspaceId)->find($walletAccountId);
            if (! $account) {
                throw new RuntimeException('Selected live wallet account was not found.');
            }

            return $this->bootstrapProviderSnapshot($account, $integration);
        }

        $currency = strtoupper($currency);
        $account = WalletAccount::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'currency' => $currency,
                'type' => 'exchange',
            ],
            [
                'id' => (string) Str::uuid(),
                'name' => strtoupper((string) $integration->provider_key) . ' ' . $currency . ' Wallet',
                'status' => 'active',
                'wallet_type' => 'provider',
                'balance' => 0,
                'available' => 0,
                'locked_balance' => 0,
                'metadata' => [
                    'provider' => strtolower((string) $integration->provider_key),
                    'integration_id' => $integration->id,
                    'mode' => 'live',
                ],
            ]
        );

        return $this->bootstrapProviderSnapshot($account, $integration);
    }

    public function reserveStake(
        string $workspaceId,
        Integration $integration,
        float $stakeAmount,
        ?string $walletAccountId = null,
        string $currency = 'USDT',
        array $meta = [],
    ): WalletAccount {
        return DB::transaction(function () use ($workspaceId, $integration, $stakeAmount, $walletAccountId, $currency, $meta) {
            $account = $this->resolveAccount($workspaceId, $integration, $walletAccountId, $currency);
            $account = $this->refreshProviderSnapshot($account, $integration);
            $account = WalletAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ((float) $account->available < $stakeAmount) {
                throw new RuntimeException(sprintf(
                    'Insufficient live wallet balance for this trade. Available %.8f %s, required %.8f %s.',
                    (float) $account->available,
                    strtoupper((string) $account->currency),
                    $stakeAmount,
                    strtoupper((string) $account->currency)
                ));
            }

            $account->available = (float) $account->available - $stakeAmount;
            $account->locked_balance = (float) ($account->locked_balance ?? 0) + $stakeAmount;
            $account->metadata = array_merge((array) ($account->metadata ?? []), [
                'provider' => strtolower((string) $integration->provider_key),
                'integration_id' => $integration->id,
                'last_reserved_at' => now()->toIso8601String(),
            ]);
            $account->save();

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'type' => 'quick_trade_live_reserve',
                'amount' => 0,
                'reference_id' => (string) ($meta['quick_trade_id'] ?? $meta['trace_id'] ?? Str::uuid()),
                'meta_json' => array_merge($meta, [
                    'provider' => strtolower((string) $integration->provider_key),
                    'integration_id' => $integration->id,
                    'stake_amount' => $stakeAmount,
                    'currency' => $account->currency,
                ]),
                'ts' => now(),
                'trace_id' => (string) ($meta['trace_id'] ?? Str::uuid()),
            ]);

            $this->syncLedgerForAccount($workspaceId, $account, [
                'provider' => strtolower((string) $integration->provider_key),
                'integration_id' => $integration->id,
                'mode' => 'live',
            ]);

            return $account->fresh();
        });
    }

    public function cancelReservation(
        string $workspaceId,
        ?string $walletAccountId,
        float $stakeAmount,
        array $meta = [],
    ): WalletAccount {
        return DB::transaction(function () use ($workspaceId, $walletAccountId, $stakeAmount, $meta) {
            $account = WalletAccount::where('workspace_id', $workspaceId)
                ->whereKey($walletAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            $account->available = (float) $account->available + $stakeAmount;
            $account->locked_balance = max(0, (float) ($account->locked_balance ?? 0) - $stakeAmount);
            $account->save();

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'type' => 'quick_trade_live_reserve_release',
                'amount' => 0,
                'reference_id' => (string) ($meta['quick_trade_id'] ?? $meta['trace_id'] ?? Str::uuid()),
                'meta_json' => array_merge($meta, [
                    'stake_amount' => $stakeAmount,
                    'currency' => $account->currency,
                    'reason' => 'provider_open_failed',
                ]),
                'ts' => now(),
                'trace_id' => (string) ($meta['trace_id'] ?? Str::uuid()),
            ]);

            $this->syncLedgerForAccount($workspaceId, $account, [
                'mode' => 'live',
                'currency' => $account->currency,
            ]);

            return $account->fresh();
        });
    }

    public function settleReservation(
        string $workspaceId,
        ?string $walletAccountId,
        float $stakeAmount,
        float $pnl,
        array $meta = [],
    ): WalletAccount {
        return DB::transaction(function () use ($workspaceId, $walletAccountId, $stakeAmount, $pnl, $meta) {
            $account = WalletAccount::where('workspace_id', $workspaceId)
                ->whereKey($walletAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            $account->locked_balance = max(0, (float) ($account->locked_balance ?? 0) - $stakeAmount);
            $account->balance = (float) $account->balance + $pnl;
            $account->available = max(0, (float) $account->available + $stakeAmount + $pnl);
            $account->metadata = array_merge((array) ($account->metadata ?? []), [
                'last_settled_at' => now()->toIso8601String(),
            ]);
            $account->save();

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'type' => 'quick_trade_live_reserve_release',
                'amount' => 0,
                'reference_id' => (string) ($meta['quick_trade_id'] ?? $meta['trace_id'] ?? Str::uuid()),
                'meta_json' => array_merge($meta, [
                    'stake_amount' => $stakeAmount,
                    'currency' => $account->currency,
                    'reason' => 'trade_settled',
                ]),
                'ts' => now(),
                'trace_id' => (string) ($meta['trace_id'] ?? Str::uuid()),
            ]);

            if (abs($pnl) > 0.00000001) {
                WalletLedgerEntry::create([
                    'id' => (string) Str::uuid(),
                    'account_id' => $account->id,
                    'type' => 'quick_trade_live_pnl',
                    'amount' => $pnl,
                    'reference_id' => (string) ($meta['quick_trade_id'] ?? $meta['trace_id'] ?? Str::uuid()),
                    'meta_json' => array_merge($meta, [
                        'currency' => $account->currency,
                    ]),
                    'ts' => now(),
                    'trace_id' => (string) ($meta['trace_id'] ?? Str::uuid()),
                ]);
            }

            $ledger = $this->syncLedgerForAccount($workspaceId, $account, [
                'mode' => 'live',
                'currency' => $account->currency,
            ]);

            if (abs($pnl) > 0.00000001) {
                LedgerTransaction::create([
                    'id' => (string) Str::uuid(),
                    'ledger_id' => $ledger->id,
                    'wallet_account_id' => $account->id,
                    'type' => 'quick_trade_live_pnl',
                    'amount' => $pnl,
                    'meta' => array_merge($meta, [
                        'currency' => $account->currency,
                    ]),
                    'occurred_at' => now(),
                    'idempotency_key' => (string) ($meta['idempotency_key'] ?? Str::uuid()),
                ]);
            }

            return $account->fresh();
        });
    }

    private function bootstrapProviderSnapshot(WalletAccount $account, Integration $integration): WalletAccount
    {
        $hasEntries = WalletLedgerEntry::where('account_id', $account->id)->exists();
        $isUntouched = ! $hasEntries
            && abs((float) $account->balance) < 0.000001
            && abs((float) $account->available) < 0.000001
            && abs((float) ($account->locked_balance ?? 0)) < 0.000001;

        if (! $isUntouched) {
            return $account;
        }

        return $this->refreshProviderSnapshot($account, $integration);
    }

    public function refreshProviderSnapshot(WalletAccount $account, Integration $integration): WalletAccount
    {
        // Defence in depth: exchange balances never overwrite a paper wallet.
        if (strtolower((string) $account->wallet_type) === 'paper') {
            return $account;
        }
        $provider = $this->providers->for((string) $integration->provider_key);
        $snapshot = $provider->availableBalance($integration, (string) ($account->currency ?: 'USDT'));
        $balance = (float) ($snapshot['balance'] ?? 0);
        $available = (float) ($snapshot['available'] ?? $balance);

        $account->fill([
            'balance' => $balance,
            'available' => $available,
            'locked_balance' => max(0, $balance - $available),
            'metadata' => array_merge((array) ($account->metadata ?? []), [
                'provider' => strtolower((string) $integration->provider_key),
                'integration_id' => $integration->id,
                'last_provider_sync_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->syncLedgerForAccount($account->workspace_id, $account, [
            'provider' => strtolower((string) $integration->provider_key),
            'integration_id' => $integration->id,
            'mode' => 'live',
        ]);

        return $account->fresh();
    }

    private function syncLedgerForAccount(string $workspaceId, WalletAccount $account, array $meta = []): Ledger
    {
        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                'balance' => 0,
                'meta' => ['currency' => $account->currency, 'mode' => 'live'],
            ]
        );

        $ledger->balance = (float) $account->balance;
        $ledger->meta = array_merge((array) ($ledger->meta ?? []), $meta, [
            'currency' => $account->currency,
            'wallet_account_id' => $account->id,
        ]);
        $ledger->save();

        return $ledger;
    }
}
