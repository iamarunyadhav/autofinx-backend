<?php

namespace App\Domain\Risk;

use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Models\Guardrails;
use App\Models\Ledger;
use App\Models\Order;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\WalletAccount;
use RuntimeException;

class WorkspaceLiveRiskService
{
    public function enforceOrFail(string $workspaceId, array $context = []): array
    {
        $rules = $this->resolveRules($workspaceId, $context);
        $symbol = BinanceSymbolNormalizer::normalize((string) ($context['symbol'] ?? ''));
        $side = strtolower((string) ($context['side'] ?? 'buy'));
        $provider = strtolower((string) ($context['provider'] ?? 'binance'));
        $notional = max(0.0, (float) ($context['notional'] ?? 0));
        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));

        if ($symbol !== '') {
            $this->enforceSymbolPolicy($rules, $symbol);
        }

        $this->enforceDailyMaxOrders($workspaceId, $provider, $rules);
        $this->enforceMaxOpenOrders($workspaceId, $provider, $rules);

        if ($symbol !== '' && $side !== '') {
            $this->enforceDuplicatePendingOrder($workspaceId, $provider, $symbol, $side, $idempotencyKey);
        }

        if ($side === 'buy') {
            $this->enforceMaxOpenPositions($workspaceId, $rules);
        }

        if ($notional > 0) {
            $equity = $this->currentEquity($workspaceId);
            $this->enforceMaxTradePct($rules, $notional, $equity);
            $this->enforceMaxNotionalPerOrder($rules, $notional);
            $this->enforceMaxAccountExposure($workspaceId, $rules, $notional, $equity);

            if ($symbol !== '') {
                $this->enforceMaxPositionPct($workspaceId, $symbol, $side, $rules, $notional, $equity);
            }
        }

        return $rules;
    }

    public function resolveRules(string $workspaceId, array $context = []): array
    {
        $profile = $this->resolveProfile($workspaceId, $context);
        $profileRules = $this->normalizeProfileRules($profile);
        $guardrails = Guardrails::find($workspaceId);

        $rules = array_merge($this->defaultRules(), $profileRules);

        if ($guardrails) {
            $rules['max_open_orders'] = $this->strictestPositive(
                $rules['max_open_orders'] ?? null,
                (int) $guardrails->max_open_orders
            );
            $rules['daily_loss_pct'] = $this->strictestPositive(
                $rules['daily_loss_pct'] ?? null,
                (float) $guardrails->max_daily_loss_pct
            );
            $rules['slippage_bps'] = $this->strictestPositive(
                $rules['slippage_bps'] ?? null,
                (float) $guardrails->max_slippage_pct * 100
            );

            if (($rules['take_profit_pct'] ?? null) === null && $guardrails->take_profit_pct !== null) {
                $rules['take_profit_pct'] = (float) $guardrails->take_profit_pct;
            }
            if (($rules['trailing_stop_pct'] ?? null) === null && $guardrails->trailing_stop_pct !== null) {
                $rules['trailing_stop_pct'] = (float) $guardrails->trailing_stop_pct;
            }
            if (($rules['volatility_block_pct'] ?? null) === null && $guardrails->volatility_block_pct !== null) {
                $rules['volatility_block_pct'] = (float) $guardrails->volatility_block_pct;
            }
        }

        $rules['_risk_profile_id'] = $profile?->id;
        $rules['_risk_profile_name'] = $profile?->name;
        $rules['allowed_symbols'] = $this->normalizeSymbolList($rules['allowed_symbols'] ?? []);
        $rules['blocked_symbols'] = $this->normalizeSymbolList($rules['blocked_symbols'] ?? []);

        return $rules;
    }

    private function resolveProfile(string $workspaceId, array $context): ?RiskProfile
    {
        $requestedProfileId = trim((string) ($context['risk_profile_id'] ?? ''));
        if ($requestedProfileId !== '') {
            return RiskProfile::where('workspace_id', $workspaceId)
                ->whereKey($requestedProfileId)
                ->first();
        }

        return RiskProfile::where('workspace_id', $workspaceId)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();
    }

    private function normalizeProfileRules(?RiskProfile $profile): array
    {
        if (! $profile) {
            return [];
        }

        $rules = (array) ($profile->rules ?? []);

        $maxTrade = $this->firstNumeric($rules, ['max_trade_pct', 'max_trade_percent']);
        if ($maxTrade !== null) {
            $rules['max_trade_pct'] = $maxTrade;
        }

        $maxPosition = $this->firstNumeric($rules, ['max_position_pct', 'max_position_percent']);
        if ($maxPosition !== null) {
            $rules['max_position_pct'] = $maxPosition;
        }

        $dailyLossPct = $this->firstNumeric($rules, ['daily_loss_pct', 'max_daily_loss_pct', 'max_daily_loss_percent']);
        if ($dailyLossPct !== null) {
            $rules['daily_loss_pct'] = $dailyLossPct;
        }

        $drawdownPct = $this->firstNumeric($rules, ['drawdown_pct', 'max_drawdown_pct', 'max_drawdown_percent']);
        if ($drawdownPct !== null) {
            $rules['drawdown_pct'] = $drawdownPct;
            $rules['max_drawdown_pct'] = $drawdownPct;
        } elseif ($profile->max_drawdown_pct !== null) {
            $rules['drawdown_pct'] = (float) $profile->max_drawdown_pct;
            $rules['max_drawdown_pct'] = (float) $profile->max_drawdown_pct;
        }

        $maxLeverage = $this->firstNumeric($rules, ['max_leverage']);
        if ($maxLeverage !== null) {
            $rules['max_leverage'] = $maxLeverage;
        }

        $stopLossPct = $this->firstNumeric($rules, ['stop_loss_pct', 'stop_loss_percent']);
        if ($stopLossPct !== null) {
            $rules['stop_loss_pct'] = $stopLossPct;
        }

        $takeProfitPct = $this->firstNumeric($rules, ['take_profit_pct', 'take_profit_percent']);
        if ($takeProfitPct !== null) {
            $rules['take_profit_pct'] = $takeProfitPct;
        }

        $trailingStopPct = $this->firstNumeric($rules, ['trailing_stop_pct', 'trailing_stop_percent']);
        if ($trailingStopPct !== null) {
            $rules['trailing_stop_pct'] = $trailingStopPct;
        }

        $slippageBps = $this->firstNumeric($rules, ['slippage_bps']);
        if ($slippageBps === null) {
            $slippagePercent = $this->firstNumeric($rules, ['max_slippage_pct_default']);
            if ($slippagePercent === null) {
                $slippagePercent = $this->slippagePercentFromTolerance($rules['slippage_tolerance'] ?? null);
            }
            if ($slippagePercent === null && $profile->max_slippage_pct_default !== null) {
                $slippagePercent = (float) $profile->max_slippage_pct_default;
            }
            if ($slippagePercent !== null) {
                $slippageBps = $slippagePercent * 100;
            }
        }
        if ($slippageBps !== null) {
            $rules['slippage_bps'] = $slippageBps;
        }

        $dailyMaxOrders = $this->firstInteger($rules, ['daily_max_orders']);
        if ($dailyMaxOrders !== null) {
            $rules['daily_max_orders'] = $dailyMaxOrders;
        }

        $maxOpenOrders = $this->firstInteger($rules, ['max_open_orders']);
        if ($maxOpenOrders !== null) {
            $rules['max_open_orders'] = $maxOpenOrders;
        }

        $maxOpenPositions = $this->firstInteger($rules, ['max_open_positions']);
        if ($maxOpenPositions !== null) {
            $rules['max_open_positions'] = $maxOpenPositions;
        } elseif ($profile->max_open_positions !== null) {
            $rules['max_open_positions'] = (int) $profile->max_open_positions;
        }

        $maxNotionalPerOrder = $this->firstNumeric($rules, ['max_notional_per_order']);
        if ($maxNotionalPerOrder !== null) {
            $rules['max_notional_per_order'] = $maxNotionalPerOrder;
        }

        $maxAccountExposurePct = $this->firstNumeric($rules, ['max_account_exposure_pct', 'max_exposure_pct']);
        if ($maxAccountExposurePct !== null) {
            $rules['max_account_exposure_pct'] = $maxAccountExposurePct;
        }

        $rules['allowed_symbols'] = $this->normalizeSymbolList($rules['allowed_symbols'] ?? []);
        $rules['blocked_symbols'] = $this->normalizeSymbolList($rules['blocked_symbols'] ?? []);

        return $rules;
    }

    private function defaultRules(): array
    {
        return [
            'max_trade_pct' => null,
            'max_position_pct' => null,
            'daily_loss_pct' => null,
            'drawdown_pct' => null,
            'max_leverage' => null,
            'stop_loss_pct' => null,
            'take_profit_pct' => null,
            'trailing_stop_pct' => null,
            'slippage_bps' => null,
            'daily_max_orders' => null,
            'max_open_orders' => null,
            'max_open_positions' => null,
            'max_notional_per_order' => null,
            'max_account_exposure_pct' => null,
            'allowed_symbols' => [],
            'blocked_symbols' => [],
        ];
    }

    private function enforceSymbolPolicy(array $rules, string $symbol): void
    {
        $allow = $rules['allowed_symbols'] ?? [];
        $block = $rules['blocked_symbols'] ?? [];

        if (! empty($allow) && ! in_array($symbol, $allow, true)) {
            throw new RuntimeException("Symbol {$symbol} is not allowed by workspace risk policy.");
        }

        if (in_array($symbol, $block, true)) {
            throw new RuntimeException("Symbol {$symbol} is blocked by workspace risk policy.");
        }
    }

    private function enforceDailyMaxOrders(string $workspaceId, string $provider, array $rules): void
    {
        $limit = (int) ($rules['daily_max_orders'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $count = Order::where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($count >= $limit) {
            throw new RuntimeException('Daily max live order limit reached.');
        }
    }

    private function enforceMaxOpenOrders(string $workspaceId, string $provider, array $rules): void
    {
        $limit = (int) ($rules['max_open_orders'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $count = $this->activeOrderQuery($workspaceId, $provider)->count();
        if ($count >= $limit) {
            throw new RuntimeException('Max open live orders reached.');
        }
    }

    private function enforceDuplicatePendingOrder(
        string $workspaceId,
        string $provider,
        string $symbol,
        string $side,
        string $idempotencyKey
    ): void {
        $query = $this->activeOrderQuery($workspaceId, $provider)
            ->where('asset_code', $symbol)
            ->where('side', strtolower($side));

        if ($idempotencyKey !== '') {
            $query->where(function ($candidate) use ($idempotencyKey): void {
                $candidate->whereNull('idempotency_key')
                    ->orWhere('idempotency_key', '!=', $idempotencyKey);
            });
        }

        if ($query->exists()) {
            throw new RuntimeException('Duplicate live order already pending for this symbol and side.');
        }
    }

    private function enforceMaxOpenPositions(string $workspaceId, array $rules): void
    {
        $limit = (int) ($rules['max_open_positions'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $count = Position::where('workspace_id', $workspaceId)
            ->whereRaw('ABS(COALESCE(qty, quantity, 0)) > 0')
            ->count();

        if ($count >= $limit) {
            throw new RuntimeException('Max open positions reached.');
        }
    }

    private function enforceMaxTradePct(array $rules, float $notional, float $equity): void
    {
        $limit = (float) ($rules['max_trade_pct'] ?? 0);
        if ($limit <= 0 || $equity <= 0) {
            return;
        }

        if (($notional / $equity) * 100 > $limit) {
            throw new RuntimeException('Live order exceeds max trade percent.');
        }
    }

    private function enforceMaxNotionalPerOrder(array $rules, float $notional): void
    {
        $limit = (float) ($rules['max_notional_per_order'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        if ($notional > $limit) {
            throw new RuntimeException('Live order exceeds max notional limit.');
        }
    }

    private function enforceMaxAccountExposure(string $workspaceId, array $rules, float $notional, float $equity): void
    {
        $limit = (float) ($rules['max_account_exposure_pct'] ?? 0);
        if ($limit <= 0 || $equity <= 0) {
            return;
        }

        $currentExposure = $this->currentExposureNotional($workspaceId);
        if ((($currentExposure + $notional) / $equity) * 100 > $limit) {
            throw new RuntimeException('Live order exceeds max account exposure.');
        }
    }

    private function enforceMaxPositionPct(
        string $workspaceId,
        string $symbol,
        string $side,
        array $rules,
        float $notional,
        float $equity
    ): void {
        $limit = (float) ($rules['max_position_pct'] ?? 0);
        if ($limit <= 0 || $equity <= 0) {
            return;
        }

        $current = $this->currentSymbolExposureNotional($workspaceId, $symbol);
        $projected = $side === 'sell' ? max(0, $current - $notional) : $current + $notional;
        if (($projected / $equity) * 100 > $limit) {
            throw new RuntimeException('Live order exceeds max position percent.');
        }
    }

    private function currentEquity(string $workspaceId): float
    {
        $ledgerBalance = (float) (Ledger::where('workspace_id', $workspaceId)->value('balance') ?? 0);
        $walletBalance = (float) WalletAccount::where('workspace_id', $workspaceId)->sum('balance');
        $baseBalance = max($ledgerBalance, $walletBalance);

        return $baseBalance + $this->currentExposureNotional($workspaceId);
    }

    private function currentExposureNotional(string $workspaceId): float
    {
        return Position::where('workspace_id', $workspaceId)
            ->get()
            ->reduce(function (float $carry, Position $position): float {
                $qty = abs((float) ($position->qty ?? $position->quantity ?? 0));
                $mark = (float) ($position->last_mark_price ?? $position->avg_entry ?? $position->avg_price ?? 0);

                return $carry + ($qty * max($mark, 0));
            }, 0.0);
    }

    private function currentSymbolExposureNotional(string $workspaceId, string $symbol): float
    {
        return Position::where('workspace_id', $workspaceId)
            ->whereHas('symbol', function ($query) use ($symbol): void {
                $query->where('symbol', $symbol);
            })
            ->get()
            ->reduce(function (float $carry, Position $position): float {
                $qty = abs((float) ($position->qty ?? $position->quantity ?? 0));
                $mark = (float) ($position->last_mark_price ?? $position->avg_entry ?? $position->avg_price ?? 0);

                return $carry + ($qty * max($mark, 0));
            }, 0.0);
    }

    private function activeOrderQuery(string $workspaceId, string $provider)
    {
        return Order::where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->whereNotIn('status', [
                Order::STATUS_FILLED,
                Order::STATUS_FAILED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
                strtoupper(Order::STATUS_FILLED),
                strtoupper(Order::STATUS_FAILED),
            ]);
    }

    private function strictestPositive($current, $candidate)
    {
        $current = $current !== null ? (float) $current : null;
        $candidate = $candidate !== null ? (float) $candidate : null;

        if ($candidate === null || $candidate <= 0) {
            return $current;
        }
        if ($current === null || $current <= 0) {
            return $candidate;
        }

        return min($current, $candidate);
    }

    private function firstNumeric(array $values, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== '') {
                return (float) $values[$key];
            }
        }

        return null;
    }

    private function firstInteger(array $values, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== '') {
                return (int) $values[$key];
            }
        }

        return null;
    }

    private function normalizeSymbolList(array $symbols): array
    {
        $normalized = array_map(
            static fn ($symbol) => BinanceSymbolNormalizer::normalize((string) $symbol),
            $symbols
        );

        return array_values(array_filter(array_unique($normalized)));
    }

    private function slippagePercentFromTolerance($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (float) $value;
        if ($numeric <= 0) {
            return null;
        }

        return $numeric <= 1 ? $numeric * 100 : $numeric;
    }
}
