<?php

namespace App\Domain\Trading;

use App\Domain\Billing\AutoWithdrawalService;
use App\Models\Bot;
use App\Models\Order;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WalletSweepRule;
use App\Models\WalletSweepRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderToCashSweepService
{
    public function __construct(
        private readonly BotLogService $logs,
        private readonly AutoWithdrawalService $autoWithdrawal,
    ) {
    }

    public function runForOrder(Order $order, ?string $traceId = null): array
    {
        $traceId = $traceId ?: ((string) ($order->trace_id ?: Str::uuid()));
        $side = strtolower((string) $order->side);
        if ($side !== 'sell') {
            return [
                'status' => 'skipped',
                'reason' => 'order_side_not_sell',
                'runs' => [],
            ];
        }

        $workspaceId = (string) $order->workspace_id;
        $provider = strtolower((string) ($order->provider ?: $order->exchange ?: 'binance'));
        $currency = strtoupper((string) ($order->quote_currency ?: $this->inferQuoteCurrency((string) ($order->asset_code ?: $order->symbol ?: 'USD'))));
        $assetClass = strtoupper((string) ($order->asset_class ?: 'CRYPTO'));
        $assetCode = strtoupper((string) ($order->asset_code ?: $order->symbol ?: ''));

        $rules = WalletSweepRule::where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->where(function ($q) use ($provider): void {
                $q->where('provider', $provider)->orWhereNull('provider');
            })
            ->where(function ($q) use ($currency): void {
                $q->where('currency', $currency)->orWhereNull('currency');
            })
            ->where(function ($q) use ($assetClass): void {
                $q->where('asset_class', $assetClass)->orWhereNull('asset_class');
            })
            ->where(function ($q) use ($order): void {
                $q->whereNull('bot_id')->orWhere('bot_id', $order->bot_id);
            })
            ->where(function ($q) use ($assetCode): void {
                $q->whereNull('asset_code')->orWhere('asset_code', $assetCode);
            })
            ->orderByDesc('created_at')
            ->get();

        if ($rules->isEmpty()) {
            return [
                'status' => 'skipped',
                'reason' => 'no_active_sweep_rules',
                'runs' => [],
            ];
        }

        $runs = [];
        foreach ($rules as $rule) {
            $runs[] = $this->runRule($rule, $traceId, [
                'source' => 'order_fill',
                'order_id' => $order->id,
                'asset_code' => $assetCode,
                'asset_class' => $assetClass,
                'provider' => $provider,
                'currency' => $currency,
            ]);
        }

        return [
            'status' => collect($runs)->contains(fn ($run) => ($run['status'] ?? null) === 'succeeded') ? 'succeeded' : 'skipped',
            'runs' => $runs,
        ];
    }

    public function runManual(
        string $workspaceId,
        ?string $ruleId = null,
        ?string $provider = null,
        ?string $currency = null,
        ?string $traceId = null
    ): array {
        $traceId = $traceId ?: (string) Str::uuid();
        $rules = $this->queryRules($workspaceId);
        if ($ruleId) {
            $rules->where('id', $ruleId);
        }
        if ($provider) {
            $rules->where('provider', strtolower($provider));
        }
        if ($currency) {
            $rules->where('currency', strtoupper($currency));
        }

        /** @var Collection<int, WalletSweepRule> $rows */
        $rows = $rules->get();
        $results = [];
        foreach ($rows as $rule) {
            $results[] = $this->runRule($rule, $traceId, [
                'source' => 'manual',
            ]);
        }

        return [
            'checked' => $rows->count(),
            'results' => $results,
        ];
    }

    public function queryRules(string $workspaceId)
    {
        return WalletSweepRule::where('workspace_id', $workspaceId)
            ->orderByDesc('created_at');
    }

    private function runRule(WalletSweepRule $rule, string $traceId, array $meta = []): array
    {
        $provider = strtolower((string) ($rule->provider ?: 'binance'));
        $currency = strtoupper((string) ($rule->currency ?: 'USD'));
        $idempotencyKey = sprintf(
            'sweep:%s:%s:%s:%s',
            $rule->workspace_id,
            $provider,
            $currency,
            now()->toDateString()
        );

        $existing = WalletSweepRun::where('idempotency_key', $idempotencyKey)
            ->where('workspace_id', $rule->workspace_id)
            ->where('status', 'succeeded')
            ->first();
        if ($existing) {
            return [
                'status' => 'idempotent',
                'run_id' => $existing->id,
                'idempotency_key' => $idempotencyKey,
            ];
        }

        if (! $this->passesOperationalGuards($rule)) {
            $run = WalletSweepRun::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $rule->workspace_id,
                'rule_id' => $rule->id,
                'bot_id' => $rule->bot_id,
                'provider' => $provider,
                'asset_class' => $rule->asset_class,
                'asset_code' => $rule->asset_code,
                'currency' => $currency,
                'amount' => 0,
                'status' => 'blocked',
                'trace_id' => $traceId,
                'meta_json' => array_merge($meta, [
                    'reason' => 'operational_guard_blocked',
                ]),
                'swept_at' => now(),
            ]);

            $rule->last_status = 'blocked';
            $rule->last_error = 'operational_guard_blocked';
            $rule->last_run_at = now();
            $rule->save();

            return [
                'status' => 'blocked',
                'run_id' => $run->id,
                'reason' => 'operational_guard_blocked',
            ];
        }

        $result = DB::transaction(function () use ($rule, $provider, $currency, $idempotencyKey, $traceId, $meta): array {
            $providerAccount = $this->resolveProviderAccount((string) $rule->workspace_id, $currency);
            if (! $providerAccount) {
                return $this->recordRun(
                    $rule,
                    provider: $provider,
                    currency: $currency,
                    amount: 0,
                    status: 'skipped',
                    traceId: $traceId,
                    idempotencyKey: null,
                    meta: array_merge($meta, ['reason' => 'provider_wallet_missing'])
                );
            }

            $minAmount = max(0.0, (float) $rule->min_amount);
            $available = max(0.0, (float) ($providerAccount->available ?? $providerAccount->balance ?? 0));
            if ($available < $minAmount || $available <= 0) {
                return $this->recordRun(
                    $rule,
                    provider: $provider,
                    currency: $currency,
                    amount: 0,
                    status: 'skipped',
                    traceId: $traceId,
                    idempotencyKey: null,
                    meta: array_merge($meta, [
                        'reason' => 'below_min_amount',
                        'available' => $available,
                        'min_amount' => $minAmount,
                    ])
                );
            }

            $sweepAmount = round($available, 8);
            $cashAccount = $this->resolveCashAccount((string) $rule->workspace_id, $currency, $providerAccount->user_id);

            $providerAccount->balance = round((float) $providerAccount->balance - $sweepAmount, 8);
            $providerAccount->available = round((float) $providerAccount->available - $sweepAmount, 8);
            if ((float) $providerAccount->balance < 0) {
                $providerAccount->balance = 0;
            }
            if ((float) $providerAccount->available < 0) {
                $providerAccount->available = 0;
            }
            $providerAccount->save();

            $cashAccount->balance = round((float) $cashAccount->balance + $sweepAmount, 8);
            $cashAccount->available = round((float) $cashAccount->available + $sweepAmount, 8);
            $cashAccount->save();

            $run = WalletSweepRun::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $rule->workspace_id,
                'rule_id' => $rule->id,
                'bot_id' => $rule->bot_id,
                'provider' => $provider,
                'asset_class' => $rule->asset_class,
                'asset_code' => $rule->asset_code,
                'currency' => $currency,
                'amount' => $sweepAmount,
                'status' => 'succeeded',
                'idempotency_key' => $idempotencyKey,
                'trace_id' => $traceId,
                'meta_json' => array_merge($meta, [
                    'from_account_id' => $providerAccount->id,
                    'to_account_id' => $cashAccount->id,
                    'min_amount' => $minAmount,
                ]),
                'swept_at' => now(),
            ]);

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $providerAccount->id,
                'type' => 'SWEEP_OUT',
                'amount' => -$sweepAmount,
                'reference_id' => $run->id,
                'meta_json' => [
                    'provider' => $provider,
                    'currency' => $currency,
                    'direction' => 'provider_to_cash',
                ],
                'ts' => now(),
                'trace_id' => $traceId,
            ]);

            WalletLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'account_id' => $cashAccount->id,
                'type' => 'SWEEP_IN',
                'amount' => $sweepAmount,
                'reference_id' => $run->id,
                'meta_json' => [
                    'provider' => $provider,
                    'currency' => $currency,
                    'direction' => 'provider_to_cash',
                ],
                'ts' => now(),
                'trace_id' => $traceId,
            ]);

            $rule->last_status = 'succeeded';
            $rule->last_error = null;
            $rule->last_run_at = now();
            $rule->save();

            return [
                'status' => 'succeeded',
                'run_id' => $run->id,
                'amount' => $sweepAmount,
                'currency' => $currency,
                'idempotency_key' => $idempotencyKey,
                'auto_withdraw_enabled' => (bool) $rule->auto_withdraw_enabled,
            ];
        });

        if (($result['status'] ?? null) === 'succeeded' && (bool) $rule->auto_withdraw_enabled) {
            // Trigger due schedules once funds are swept into the cash wallet.
            $this->autoWithdrawal->runDue((string) $rule->workspace_id, 20);
        }

        if ($rule->bot_id) {
            $this->logs->log((string) $rule->bot_id, (string) $rule->workspace_id, 'INFO', 'Provider cash sweep executed.', [
                'provider' => $provider,
                'currency' => $currency,
                'result' => $result,
            ], $traceId);
        }

        return $result;
    }

    private function passesOperationalGuards(WalletSweepRule $rule): bool
    {
        if ($rule->bot_id) {
            $bot = Bot::find($rule->bot_id);
            if ($bot && strtoupper((string) $bot->status) === 'PAUSED') {
                return false;
            }
        }

        $query = Order::where('workspace_id', $rule->workspace_id)
            ->whereIn('status', ['NEW', 'PARTIAL']);
        if ($rule->bot_id) {
            $query->where('bot_id', $rule->bot_id);
        }
        if ($rule->asset_code) {
            $query->where('asset_code', strtoupper((string) $rule->asset_code));
        }

        return ! $query->exists();
    }

    private function resolveProviderAccount(string $workspaceId, string $currency): ?WalletAccount
    {
        return WalletAccount::where('workspace_id', $workspaceId)
            ->where('currency', strtoupper($currency))
            ->where('type', 'exchange')
            ->lockForUpdate()
            ->first();
    }

    private function resolveCashAccount(string $workspaceId, string $currency, ?string $userId): WalletAccount
    {
        return WalletAccount::firstOrCreate(
            [
                'workspace_id' => $workspaceId,
                'currency' => strtoupper($currency),
                'type' => 'cash',
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'name' => strtoupper($currency) . ' Cash Wallet',
                'status' => 'active',
                'wallet_type' => 'cash',
                'balance' => 0,
                'available' => 0,
                'locked_balance' => 0,
            ]
        );
    }

    private function recordRun(
        WalletSweepRule $rule,
        string $provider,
        string $currency,
        float $amount,
        string $status,
        string $traceId,
        ?string $idempotencyKey,
        array $meta
    ): array {
        $run = WalletSweepRun::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $rule->workspace_id,
            'rule_id' => $rule->id,
            'bot_id' => $rule->bot_id,
            'provider' => $provider,
            'asset_class' => $rule->asset_class,
            'asset_code' => $rule->asset_code,
            'currency' => $currency,
            'amount' => $amount,
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
            'trace_id' => $traceId,
            'meta_json' => $meta,
            'swept_at' => now(),
        ]);

        $rule->last_status = $status;
        $rule->last_error = (string) ($meta['reason'] ?? null);
        $rule->last_run_at = now();
        $rule->save();

        return [
            'status' => $status,
            'run_id' => $run->id,
            'amount' => $amount,
            'reason' => $meta['reason'] ?? null,
        ];
    }

    private function inferQuoteCurrency(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        foreach (['USDT', 'USDC', 'BUSD', 'USD', 'LKR', 'EUR', 'BTC', 'ETH'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return $quote;
            }
        }

        return 'USD';
    }
}
