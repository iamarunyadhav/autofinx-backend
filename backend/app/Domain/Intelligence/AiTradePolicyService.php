<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Data\AiTradeProposal;
use App\Domain\Ops\LiveExecutionGuard;
use App\Models\AiDecision;
use RuntimeException;

class AiTradePolicyService
{
    public function __construct(
        private readonly LiveExecutionGuard $guard,
    ) {
    }

    public function evaluate(string $workspaceId, array $config, array $context, AiTradeProposal $proposal): array
    {
        $reasons = [];
        $requiresApproval = false;
        $canExecuteNow = false;

        if (! (bool) ($config['enabled'] ?? false)) {
            return $this->reject('config_disabled');
        }

        if (strtolower((string) ($config['exchange'] ?? 'binance')) !== 'binance') {
            return $this->reject('unsupported_exchange');
        }

        if (strtolower((string) ($config['market_type'] ?? 'spot')) !== 'spot') {
            return $this->reject('unsupported_market_type');
        }

        if (! empty($context['blockers'])) {
            return $this->reject('context_blockers_present', (array) $context['blockers']);
        }

        // REVIEW_REQUIRED always forces operator review regardless of mode.
        if ($proposal->requiresReview()) {
            return [
                'allowed' => true,
                'can_execute_now' => false,
                'requires_human_approval' => true,
                'reason_codes' => ['review_required_by_ai'],
                'estimated_notional' => 0.0,
                'estimated_qty' => 0.0,
            ];
        }

        // Non-trading, non-reduction advisory actions (HOLD, NO_TRADE) pass through without sizing.
        if (! $proposal->isTradingAction()) {
            return [
                'allowed' => true,
                'can_execute_now' => false,
                'requires_human_approval' => false,
                'reason_codes' => [],
                'estimated_notional' => 0.0,
                'estimated_qty' => 0.0,
            ];
        }

        $symbol = strtoupper($proposal->symbol);
        $allowedSymbols = $this->normalizeSymbolList($config['allowed_symbols'] ?? []);
        $blockedSymbols = $this->normalizeSymbolList($config['blocked_symbols'] ?? []);

        if ($allowedSymbols !== [] && ! in_array($symbol, $allowedSymbols, true)) {
            return $this->reject('symbol_not_in_operator_allowlist');
        }

        if (in_array($symbol, $blockedSymbols, true)) {
            return $this->reject('symbol_blocked_by_operator');
        }

        $operatorLimit = (float) ($config['max_trade_size_pct'] ?? 0);
        if ($operatorLimit > 0 && $proposal->sizePct > $operatorLimit) {
            return $this->reject('size_pct_exceeds_operator_limit');
        }

        $mode = strtolower((string) ($config['mode'] ?? 'advisor'));
        $minConfidence = (float) ($config['min_confidence'] ?? 0);
        $lowConfidence = $minConfidence > 0 && $proposal->confidence < $minConfidence;
        if ($mode === 'restricted_auto' && $lowConfidence) {
            return $this->reject('confidence_below_auto_threshold');
        }
        if ($mode === 'manual_approval' && $lowConfidence) {
            $reasons[] = 'confidence_below_auto_threshold';
        }

        if ((bool) ($config['require_stop_loss'] ?? true) && $proposal->stopLossPct <= 0) {
            if ($mode === 'restricted_auto') {
                return $this->reject('stop_loss_required');
            }
            $reasons[] = 'stop_loss_required';
        }

        if ((bool) ($config['require_take_profit'] ?? true) && $proposal->takeProfitPct <= 0) {
            if ($mode === 'restricted_auto') {
                return $this->reject('take_profit_required');
            }
            $reasons[] = 'take_profit_required';
        }

        if ((bool) ($config['require_time_stop'] ?? true) && $proposal->timeStopMinutes <= 0) {
            if ($mode === 'restricted_auto') {
                return $this->reject('time_stop_required');
            }
            $reasons[] = 'time_stop_required';
        }

        $pendingLimit = max(0, (int) ($config['max_pending_decisions_per_symbol'] ?? 0));
        if ($pendingLimit > 0) {
            $pendingCount = AiDecision::query()
                ->where('workspace_id', $workspaceId)
                ->where('symbol', $symbol)
                ->where('final_status', AiDecision::STATUS_PENDING_APPROVAL)
                ->count();

            if ($pendingCount >= $pendingLimit) {
                return $this->reject('pending_decision_limit_reached');
            }
        }

        $decisionCap = max(0, (int) ($config['max_decisions_per_hour_per_symbol'] ?? 0));
        if ($decisionCap > 0) {
            $recentCount = AiDecision::query()
                ->where('workspace_id', $workspaceId)
                ->where('symbol', $symbol)
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($recentCount >= $decisionCap) {
                return $this->reject('decision_rate_limit_reached');
            }
        }

        $cooldownMinutes = max(0, (int) ($config['cooldown_minutes'] ?? 0));
        if ($cooldownMinutes > 0) {
            $recentTradeDecision = AiDecision::query()
                ->where('workspace_id', $workspaceId)
                ->where('symbol', $symbol)
                ->whereIn('action', ['BUY', 'SELL'])
                ->whereIn('final_status', [
                    AiDecision::STATUS_PENDING_APPROVAL,
                    AiDecision::STATUS_APPROVED,
                    AiDecision::STATUS_EXECUTED,
                    AiDecision::STATUS_RECORDED,
                ])
                ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
                ->exists();

            if ($recentTradeDecision) {
                return $this->reject('symbol_cooldown_active');
            }
        }

        $latestPrice = (float) ($context['price']['latest'] ?? 0);
        $capitalBase = (float) ($context['balances']['capital_base'] ?? $context['balances']['available_quote'] ?? 0);
        if ($latestPrice <= 0 || $capitalBase <= 0) {
            return $this->reject('missing_execution_basis');
        }

        $estimatedNotional = round(($capitalBase * $proposal->sizePct) / 100, 8);
        if ($estimatedNotional <= 0) {
            return $this->reject('non_positive_notional');
        }

        $estimatedQty = round($estimatedNotional / $latestPrice, 8);
        if ($estimatedQty <= 0) {
            return $this->reject('non_positive_qty');
        }

        try {
            $this->guard->assertWorkspaceCanSubmit(
                $workspaceId,
                $symbol,
                $estimatedNotional,
                'binance',
                strtolower($proposal->action),
                'ai-' . $symbol . '-' . now()->format('YmdHis')
            );
        } catch (RuntimeException $e) {
            return $this->reject('live_guard_blocked', [$e->getMessage()]);
        }

        // REDUCE always requires human approval in advisor / manual_approval modes.
        // In restricted_auto, REDUCE can execute automatically if auto_trade_enabled and proposal permits.
        if ($proposal->isReductionAction()) {
            if ($mode === 'advisor') {
                $requiresApproval = true;
                $reasons[] = 'reduce_requires_approval_in_advisor_mode';
            } elseif ($mode === 'manual_approval') {
                $requiresApproval = true;
            } elseif ($mode === 'restricted_auto') {
                $requiresApproval = $proposal->requiresHumanApproval || ! (bool) ($config['auto_trade_enabled'] ?? false);
                $canExecuteNow = ! $requiresApproval;
            } else {
                return $this->reject('unsupported_operator_mode');
            }
        } elseif ($mode === 'advisor') {
            $requiresApproval = false;
        } elseif ($mode === 'manual_approval') {
            $requiresApproval = true;
        } elseif ($mode === 'restricted_auto') {
            $requiresApproval = $proposal->requiresHumanApproval || ! (bool) ($config['auto_trade_enabled'] ?? false);
            $canExecuteNow = ! $requiresApproval;
        } else {
            return $this->reject('unsupported_operator_mode');
        }

        return [
            'allowed' => true,
            'can_execute_now' => $canExecuteNow,
            'requires_human_approval' => $requiresApproval,
            'reason_codes' => array_values(array_unique($reasons)),
            'estimated_notional' => $estimatedNotional,
            'estimated_qty' => $estimatedQty,
        ];
    }

    private function reject(string $reason, array $extraReasons = []): array
    {
        return [
            'allowed' => false,
            'can_execute_now' => false,
            'requires_human_approval' => false,
            'reason_codes' => array_values(array_unique(array_merge([$reason], $extraReasons))),
            'estimated_notional' => 0.0,
            'estimated_qty' => 0.0,
        ];
    }

    private function normalizeSymbolList(array|string|null $symbols): array
    {
        $list = is_array($symbols) ? $symbols : explode(',', (string) $symbols);

        return array_values(array_filter(array_map(
            fn ($symbol) => strtoupper(trim((string) $symbol)),
            $list
        )));
    }
}
