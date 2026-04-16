<?php

namespace App\Domain\Portfolio;

use App\Models\PortfolioIntelligenceConfig;

class PortfolioRiskBudgetService
{
    public function evaluate(array $portfolioContext, mixed $config = []): array
    {
        $config = $this->normalizeConfig($config);
        $equity = max(0.0, (float) data_get($portfolioContext, 'portfolio.capital.equity_estimate', 0));
        $allocatedCapital = max(0.0, (float) data_get($portfolioContext, 'portfolio.capital.allocated_capital', 0));
        $freeCapital = max(0.0, (float) data_get($portfolioContext, 'portfolio.capital.free_capital', 0));
        $cashAvailable = max(0.0, (float) data_get($portfolioContext, 'portfolio.capital.cash_available', 0));
        $spotPositions = (int) data_get($portfolioContext, 'portfolio.exposure.spot_positions', 0);
        $openOrders = (int) data_get($portfolioContext, 'portfolio.exposure.open_orders', 0);
        $openAiDecisions = (int) data_get($portfolioContext, 'portfolio.status.open_ai_decisions', 0);
        $dailyTotalPnl = (float) data_get($portfolioContext, 'portfolio.capital.daily_total_pnl', 0);
        $peakEquity = max($equity, (float) data_get($portfolioContext, 'portfolio.capital.peak_equity_estimate', $equity));
        $drawdownPct = $peakEquity > 0 ? max(0.0, (($peakEquity - $equity) / $peakEquity) * 100) : 0.0;
        $dailyLossPct = $equity > 0 && $dailyTotalPnl < 0 ? abs($dailyTotalPnl / $equity) * 100 : 0.0;
        $maxTotalAllocatedPct = $this->clampPct((float) ($config['max_total_allocated_pct'] ?? 35));
        $maxSymbolAllocatedPct = $this->clampPct((float) ($config['max_symbol_allocated_pct'] ?? 8));
        $maxCorrelatedGroupAllocatedPct = $this->clampPct((float) ($config['max_correlated_group_allocated_pct'] ?? 15));
        $maxSimultaneousNewTrades = max(0, (int) ($config['max_simultaneous_new_trades'] ?? 3));
        $reserveCashBufferPct = $this->clampPct((float) ($config['reserve_cash_buffer_pct'] ?? 15));
        $maxOpenPositions = isset($config['max_open_positions']) ? max(0, (int) $config['max_open_positions']) : null;
        $maxOpenDecisionCount = isset($config['max_open_decision_count']) ? max(0, (int) $config['max_open_decision_count']) : null;
        $maxTotalNotional = isset($config['max_total_notional']) && $config['max_total_notional'] !== null
            ? max(0.0, (float) $config['max_total_notional'])
            : null;
        $maxDailyLossPct = isset($config['max_daily_loss_pct']) && $config['max_daily_loss_pct'] !== null
            ? max(0.0, (float) $config['max_daily_loss_pct'])
            : null;
        $maxDrawdownPct = isset($config['max_drawdown_pct']) && $config['max_drawdown_pct'] !== null
            ? max(0.0, (float) $config['max_drawdown_pct'])
            : null;

        $reasonCodes = [];
        $configValid = true;

        if ($maxSymbolAllocatedPct > 0 && $maxTotalAllocatedPct > 0 && $maxSymbolAllocatedPct > $maxTotalAllocatedPct) {
            $reasonCodes[] = 'invalid_allocation_limits';
            $configValid = false;
        }

        if ($reserveCashBufferPct >= 100) {
            $reasonCodes[] = 'invalid_cash_buffer';
            $configValid = false;
        }

        if ($equity <= 0) {
            $reasonCodes[] = 'insufficient_equity';
        }

        $capitalCap = $maxTotalNotional !== null
            ? min($maxTotalNotional, $equity * ($maxTotalAllocatedPct / 100))
            : $equity * ($maxTotalAllocatedPct / 100);
        $symbolCap = $equity * ($maxSymbolAllocatedPct / 100);
        $groupCap = $equity * ($maxCorrelatedGroupAllocatedPct / 100);
        $reservedCash = $cashAvailable * ($reserveCashBufferPct / 100);
        $remainingCap = max(0.0, min($capitalCap, $freeCapital) - $allocatedCapital);
        $remainingNewTrades = max(0, $maxSimultaneousNewTrades - $spotPositions);

        $riskState = 'healthy';
        $allowed = $configValid && $equity > 0;
        $newEntriesAllowed = $allowed;
        $reductionAllowed = true;
        $reduceRiskMode = false;

        if ((bool) data_get($portfolioContext, 'portfolio.risk.kill_switch.active', false)) {
            $reasonCodes[] = 'kill_switch_active';
            $allowed = false;
            $newEntriesAllowed = false;
        }

        if ((bool) data_get($portfolioContext, 'portfolio.risk.provider_breaker.active', false)) {
            $reasonCodes[] = 'exchange_provider_breaker_active';
            $newEntriesAllowed = false;
            $riskState = 'constrained';
        }

        if ((bool) data_get($portfolioContext, 'portfolio.risk.stale_feed_breaker.active', false)) {
            $reasonCodes[] = 'stale_feed_active';
            $newEntriesAllowed = false;
            $riskState = 'blocked';
        }

        if ($maxOpenPositions !== null && $spotPositions >= $maxOpenPositions) {
            $reasonCodes[] = 'max_open_positions_reached';
            $newEntriesAllowed = false;
            $riskState = 'constrained';
        }

        if ($maxOpenDecisionCount !== null && $openAiDecisions >= $maxOpenDecisionCount) {
            $reasonCodes[] = 'max_open_decisions_reached';
            $newEntriesAllowed = false;
            $riskState = 'constrained';
        }

        if ($maxDailyLossPct !== null && $dailyLossPct >= $maxDailyLossPct) {
            $reasonCodes[] = 'daily_loss_limit_breached';
            $newEntriesAllowed = false;
            $reduceRiskMode = true;
            $riskState = 'blocked';
        } elseif ($maxDailyLossPct !== null && $dailyLossPct >= ($maxDailyLossPct * 0.75)) {
            $reasonCodes[] = 'daily_loss_warning';
            $reduceRiskMode = true;
            $riskState = 'constrained';
        }

        if ($maxDrawdownPct !== null && $drawdownPct >= $maxDrawdownPct) {
            $reasonCodes[] = 'drawdown_limit_breached';
            $newEntriesAllowed = false;
            $reduceRiskMode = true;
            $riskState = 'blocked';
        } elseif ($maxDrawdownPct !== null && $drawdownPct >= ($maxDrawdownPct * 0.75)) {
            $reasonCodes[] = 'drawdown_warning';
            $reduceRiskMode = true;
            $riskState = 'constrained';
        }

        if ($remainingNewTrades <= 0) {
            $reasonCodes[] = 'max_simultaneous_new_trades_reached';
            $newEntriesAllowed = false;
        }

        if ($remainingCap <= 0) {
            $reasonCodes[] = 'no_capital_remaining';
            $newEntriesAllowed = false;
        }

        if (data_get($portfolioContext, 'portfolio.mode.portfolio_intelligence_enabled') === false) {
            $reasonCodes[] = 'portfolio_intelligence_disabled';
            $newEntriesAllowed = false;
            $riskState = $riskState === 'blocked' ? 'blocked' : 'constrained';
        }

        if (data_get($portfolioContext, 'portfolio.mode.ai_mode') === 'restricted_auto' && $reduceRiskMode) {
            $reasonCodes[] = 'reduce_risk_mode_active';
        }

        if ((bool) data_get($portfolioContext, 'portfolio.risk.portfolio_intelligence.reduce_size_under_external_risk', false)) {
            if (($external = (array) data_get($portfolioContext, 'portfolio.external_context', [])) !== []) {
                if ((bool) data_get($external, 'pause_review_hint', false) || (bool) data_get($external, 'no_trade_hint', false)) {
                    $reasonCodes[] = 'external_context_recommendation';
                    $reduceRiskMode = true;
                }
            }
        }

        if ($configValid === false) {
            $riskState = 'blocked';
            $newEntriesAllowed = false;
            $reduceRiskMode = false;
        }

        if ($allowed && $newEntriesAllowed && $riskState === 'healthy' && $reasonCodes !== []) {
            $riskState = 'constrained';
        }

        if (! $allowed) {
            $riskState = 'blocked';
        }

        if (! $newEntriesAllowed) {
            $allowed = false;
        }

        return [
            'config_valid' => $configValid,
            'state' => $riskState,
            'allowed' => $allowed,
            'new_entries_allowed' => $newEntriesAllowed && $allowed,
            'reduction_allowed' => $reductionAllowed,
            'reduce_risk_mode' => $reduceRiskMode,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'limits' => [
                'max_total_allocated_pct' => $maxTotalAllocatedPct,
                'max_symbol_allocated_pct' => $maxSymbolAllocatedPct,
                'max_correlated_group_allocated_pct' => $maxCorrelatedGroupAllocatedPct,
                'max_simultaneous_new_trades' => $maxSimultaneousNewTrades,
                'reserve_cash_buffer_pct' => $reserveCashBufferPct,
                'max_open_positions' => $maxOpenPositions,
                'max_open_decision_count' => $maxOpenDecisionCount,
                'max_total_notional' => $maxTotalNotional,
                'max_daily_loss_pct' => $maxDailyLossPct,
                'max_drawdown_pct' => $maxDrawdownPct,
            ],
            'usage' => [
                'equity_estimate' => $equity,
                'allocated_capital' => $allocatedCapital,
                'allocated_capital_pct' => $equity > 0 ? round(($allocatedCapital / $equity) * 100, 4) : null,
                'free_capital' => $freeCapital,
                'cash_available' => $cashAvailable,
                'reserved_cash' => $reservedCash,
                'spot_positions' => $spotPositions,
                'open_orders' => $openOrders,
                'open_ai_decisions' => $openAiDecisions,
                'daily_loss_pct' => round($dailyLossPct, 4),
                'drawdown_pct' => round($drawdownPct, 4),
            ],
            'remaining' => [
                'capital_notional' => round($remainingCap, 8),
                'new_trades' => $remainingNewTrades,
                'symbol_cap' => round($symbolCap, 8),
                'group_cap' => round($groupCap, 8),
            ],
        ];
    }

    private function normalizeConfig(mixed $config): array
    {
        if ($config instanceof PortfolioIntelligenceConfig) {
            return $config->toArray();
        }

        return is_array($config) ? $config : [];
    }

    private function clampPct(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
