<?php

namespace App\Domain\Risk;

use App\Models\Ledger;
use App\Models\Position;
use App\Models\RiskEvent;
use App\Models\Trade;
use App\Models\WalletAccount;

class FuturesRiskService
{
    public function evaluate(string $workspaceId, array $config, array $proposal, array $market = []): array
    {
        $reasons = [];
        $warnings = [];

        $leverage = max(0.0, (float) ($proposal['leverage'] ?? 0));
        $notional = max(0.0, (float) ($proposal['notional'] ?? 0));
        $marginMode = strtolower((string) ($proposal['margin_mode'] ?? 'isolated'));
        $positionMode = strtolower((string) ($proposal['position_mode'] ?? 'one_way'));
        $reduceOnly = (bool) ($proposal['reduce_only'] ?? false);
        $protectiveExit = (bool) ($proposal['protective_exit'] ?? false);
        $liquidationBuffer = $market['liquidation_buffer_pct'] ?? null;
        $equity = $this->currentEquity($workspaceId);

        $maxLeverage = (float) ($config['max_leverage'] ?? 0);
        if ($maxLeverage > 0 && $leverage > $maxLeverage) {
            $reasons[] = 'leverage_exceeds_operator_max';
        }

        $maxNotional = (float) ($config['max_futures_notional'] ?? 0);
        if ($maxNotional > 0 && $notional > $maxNotional) {
            $reasons[] = 'futures_notional_exceeds_max';
        }

        $tradeSizePct = (float) ($config['max_futures_trade_size_pct'] ?? 0);
        if ($tradeSizePct > 0 && $equity > 0 && (($notional / $equity) * 100) > $tradeSizePct) {
            $reasons[] = 'futures_trade_size_exceeds_pct';
        }

        if (! in_array($marginMode, (array) ($config['allowed_margin_modes'] ?? []), true)) {
            $reasons[] = 'margin_mode_not_allowed';
        }
        if (! in_array($positionMode, (array) ($config['allowed_position_modes'] ?? []), true)) {
            $reasons[] = 'position_mode_not_allowed';
        }

        if ($protectiveExit && ! $reduceOnly && ! empty($config['require_reduce_only_for_protective_exit'])) {
            $reasons[] = 'reduce_only_required_for_protective_exit';
        }

        $openPositions = Position::query()
            ->where('workspace_id', $workspaceId)
            ->where('market_type', 'futures')
            ->whereRaw('ABS(COALESCE(qty, quantity, 0)) > 0')
            ->count();
        $maxOpenPositions = (int) ($config['max_open_futures_positions'] ?? 0);
        if (! $reduceOnly && $maxOpenPositions > 0 && $openPositions >= $maxOpenPositions) {
            $reasons[] = 'max_open_futures_positions_reached';
        }

        if ($liquidationBuffer === null) {
            $liquidationBuffer = $this->estimateLiquidationBuffer($proposal, $market);
            if ($liquidationBuffer === null && ! $reduceOnly) {
                $reasons[] = 'insufficient_liquidation_safety_data';
            }
        }

        $minBuffer = (float) ($config['min_liquidation_buffer_pct'] ?? 0);
        if ($liquidationBuffer !== null && $minBuffer > 0 && $liquidationBuffer < $minBuffer) {
            $reasons[] = 'liquidation_buffer_below_threshold';
        }

        $dailyLossPct = $this->dailyLossPct($workspaceId, $equity);
        $maxDailyLoss = (float) ($config['max_futures_daily_loss_pct'] ?? 0);
        if ($maxDailyLoss > 0 && $dailyLossPct >= $maxDailyLoss) {
            $reasons[] = 'futures_daily_loss_threshold_breached';
        }

        $drawdownPct = $this->drawdownPct($workspaceId, $equity);
        $maxDrawdown = (float) ($config['max_futures_drawdown_pct'] ?? 0);
        if ($maxDrawdown > 0 && $drawdownPct >= $maxDrawdown) {
            $reasons[] = 'futures_drawdown_threshold_breached';
        }

        $failureCount = RiskEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'futures_order_failure')
            ->where('triggered_at', '>=', now()->subHours(6))
            ->count();
        if ($failureCount >= 3) {
            $warnings[] = 'recent_futures_order_failures';
        }

        return [
            'allow' => $reasons === [],
            'reasons' => $reasons,
            'warnings' => $warnings,
            'equity' => $equity,
            'daily_loss_pct' => round($dailyLossPct, 4),
            'drawdown_pct' => round($drawdownPct, 4),
            'liquidation_buffer_pct' => $liquidationBuffer !== null ? round((float) $liquidationBuffer, 4) : null,
            'open_positions' => $openPositions,
        ];
    }

    public function estimateLiquidationBuffer(array $proposal, array $market = []): ?float
    {
        $markPrice = (float) ($market['mark_price'] ?? $proposal['mark_price'] ?? 0);
        $entryPrice = (float) ($proposal['entry_price'] ?? $markPrice);
        $side = strtolower((string) ($proposal['side'] ?? 'buy'));
        $leverage = max(0.0, (float) ($proposal['leverage'] ?? 0));
        $marginMode = strtolower((string) ($proposal['margin_mode'] ?? 'isolated'));

        if ($markPrice <= 0 || $entryPrice <= 0 || $leverage <= 0) {
            return null;
        }

        if ($marginMode !== 'isolated') {
            return null;
        }

        $liquidationPrice = $side === 'sell'
            ? $entryPrice * (1 + (1 / $leverage))
            : $entryPrice * max(0.0, 1 - (1 / $leverage));

        if ($side === 'sell') {
            return (($liquidationPrice - $markPrice) / $markPrice) * 100;
        }

        return (($markPrice - $liquidationPrice) / $markPrice) * 100;
    }

    private function currentEquity(string $workspaceId): float
    {
        $ledgerBalance = (float) (Ledger::where('workspace_id', $workspaceId)->value('balance') ?? 0);
        $walletBalance = (float) WalletAccount::where('workspace_id', $workspaceId)->sum('balance');

        return max($ledgerBalance, $walletBalance);
    }

    private function dailyLossPct(string $workspaceId, float $equity): float
    {
        if ($equity <= 0) {
            return 0.0;
        }

        $loss = abs(min(0.0, (float) Trade::query()
            ->where('workspace_id', $workspaceId)
            ->where('meta->market_type', 'futures')
            ->whereDate('executed_at', now()->toDateString())
            ->sum('realized_pnl')));

        return $loss > 0 ? ($loss / $equity) * 100 : 0.0;
    }

    private function drawdownPct(string $workspaceId, float $equity): float
    {
        if ($equity <= 0) {
            return 0.0;
        }

        $drawdown = abs(min(0.0, (float) Position::query()
            ->where('workspace_id', $workspaceId)
            ->where('market_type', 'futures')
            ->sum('unrealized_pnl')));

        return $drawdown > 0 ? ($drawdown / $equity) * 100 : 0.0;
    }
}
