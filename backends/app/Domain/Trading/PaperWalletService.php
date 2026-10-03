<?php

namespace App\Domain\Trading;

use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\QuickTrade;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaperWalletService
{
    public function __construct(private readonly TradingModuleSettingsService $settings)
    {
    }

    public function resolveAccount(string $workspaceId, ?string $walletAccountId = null, string $currency = 'USD'): WalletAccount
    {
        if ($walletAccountId) {
            $account = WalletAccount::where('workspace_id', $workspaceId)->find($walletAccountId);
            if (! $account) {
                throw new RuntimeException('Selected wallet account was not found.');
            }

            return $account;
        }

        $currency = strtoupper($currency);
        $account = WalletAccount::where('workspace_id', $workspaceId)
            ->where('wallet_type', 'paper')
            ->where('currency', $currency)
            ->where('type', 'cash')
            ->first();

        if ($account) {
            return $this->bootstrapPaperState($workspaceId, $account, $currency);
        }

        $account = WalletAccount::where('workspace_id', $workspaceId)
            ->where('currency', $currency)
            ->where('type', 'cash')
            ->first();

        if ($account) {
            return $this->bootstrapPaperState($workspaceId, $account, $currency);
        }

        $walletConfig = $this->settings->walletConfig($workspaceId);
        $initialBalance = (float) ($walletConfig['initial_balance'] ?? 10000);

        $account = WalletAccount::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'name' => 'Paper Wallet',
            'currency' => $currency,
            'type' => 'cash',
            'wallet_type' => 'paper',
            'status' => 'active',
            'balance' => $initialBalance,
            'available' => $initialBalance,
            'locked_balance' => 0,
            'metadata' => ['mode' => 'paper'],
        ]);

        return $this->bootstrapPaperState($workspaceId, $account, $currency, $initialBalance);
    }

    /**
     * Reset the workspace's paper wallet to the configured starting balance
     * (paper_wallet.initial_balance, default 10,000). Creates the wallet if
     * it does not exist yet. Recorded as a normal ledger adjustment so the
     * paper ledger stays consistent. Refused while any paper trade is still
     * open — its settlement would otherwise land on top of the reset.
     */
    public function reset(string $workspaceId): WalletAccount
    {
        if (QuickTrade::where('workspace_id', $workspaceId)->where('mode', 'paper')->where('status', 'open')->exists()) {
            throw new RuntimeException('Close or wait for all open paper trades to settle before resetting the paper wallet.');
        }
        $account = $this->resolveAccount($workspaceId);
        $initial = (float) ($this->settings->walletConfig($workspaceId)['initial_balance'] ?? 10000);
        $delta = round($initial - (float) $account->balance, 8);
        if (abs($delta) < 0.000001) {
            return $account->fresh();
        }

        return $this->postAmount($workspaceId, $account->id, $delta, 'paper_wallet_reset', [
            'reason' => 'manual_paper_wallet_reset',
            'reset_to' => $initial,
        ]);
    }

    public function debitStake(string $workspaceId, ?string $walletAccountId, float $amount, array $meta = []): WalletAccount
    {
        return $this->postAmount($workspaceId, $walletAccountId, -1 * abs($amount), 'quick_trade_stake', $meta);
    }

    public function creditSettlement(string $workspaceId, ?string $walletAccountId, float $amount, array $meta = []): WalletAccount
    {
        return $this->postAmount($workspaceId, $walletAccountId, abs($amount), 'quick_trade_settlement', $meta);
    }

    private function postAmount(string $workspaceId, ?string $walletAccountId, float $amount, string $type, array $meta): WalletAccount
    {
        return DB::transaction(function () use ($workspaceId, $walletAccountId, $amount, $type, $meta) {
            $account = $this->resolveAccount($workspaceId, $walletAccountId);
            $account = WalletAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();

            $newBalance = (float) $account->balance + $amount;
            if ($newBalance < -0.000001) {
                throw new RuntimeException('Insufficient paper wallet balance for this trade.');
            }

            $account->balance = $newBalance;
            $account->available = $newBalance;
            $account->save();

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'type' => $type,
                'amount' => $amount,
                'reference_id' => (string) ($meta['quick_trade_id'] ?? $meta['robot_session_id'] ?? Str::uuid()),
                'meta_json' => $meta,
                'ts' => now(),
                'trace_id' => (string) ($meta['trace_id'] ?? Str::uuid()),
            ]);

            $ledger = Ledger::firstOrCreate(
                ['workspace_id' => $workspaceId],
                [
                    'id' => (string) Str::uuid(),
                    'balance' => 0,
                    'meta' => ['currency' => $account->currency, 'mode' => 'paper'],
                ]
            );

            $ledger->balance = (float) $ledger->balance + $amount;
            $ledger->save();

            LedgerTransaction::create([
                'id' => (string) Str::uuid(),
                'ledger_id' => $ledger->id,
                'wallet_account_id' => $account->id,
                'type' => $type,
                'amount' => $amount,
                'meta' => $meta,
                'occurred_at' => now(),
                'idempotency_key' => (string) Str::uuid(),
            ]);

            return $account->fresh();
        });
    }

    private function bootstrapPaperState(string $workspaceId, WalletAccount $account, string $currency, ?float $initialBalance = null): WalletAccount
    {
        $initialBalance ??= (float) ($this->settings->walletConfig($workspaceId)['initial_balance'] ?? 10000);
        $ledger = Ledger::where('workspace_id', $workspaceId)->first();
        $hasLedgerTransactions = $ledger
            ? LedgerTransaction::where('ledger_id', $ledger->id)->exists()
            : false;
        $hasAccountEntries = WalletLedgerEntry::where('account_id', $account->id)->exists();

        $accountIsUntouched = abs((float) $account->balance) < 0.000001
            && abs((float) $account->available) < 0.000001
            && abs((float) ($account->locked_balance ?? 0)) < 0.000001
            && ! $hasAccountEntries;

        if ($accountIsUntouched) {
            $metadata = (array) ($account->metadata ?? []);
            $metadata['mode'] = 'paper';

            $account->fill([
                'name' => $account->name ?: 'Paper Wallet',
                'wallet_type' => 'paper',
                'status' => $account->status ?: 'active',
                'balance' => $initialBalance,
                'available' => $initialBalance,
                'locked_balance' => 0,
                'metadata' => $metadata,
            ])->save();
        }

        if (! $ledger) {
            Ledger::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'balance' => $account->balance,
                'meta' => [
                    'currency' => $currency,
                    'mode' => 'paper',
                    'starting_balance' => (float) $account->balance,
                ],
            ]);

            return $account->fresh();
        }

        if (! $hasLedgerTransactions && abs((float) $ledger->balance) < 0.000001) {
            $meta = (array) ($ledger->meta ?? []);
            $meta['currency'] = $meta['currency'] ?? $currency;
            $meta['mode'] = $meta['mode'] ?? 'paper';
            $meta['starting_balance'] = (float) ($meta['starting_balance'] ?? ((float) $account->balance ?: $initialBalance));

            $ledger->fill([
                'balance' => ((float) $account->balance ?: $initialBalance),
                'meta' => $meta,
            ])->save();
        }

        return $account->fresh();
    }
}
