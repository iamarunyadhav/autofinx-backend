<?php

namespace App\Domain\Intelligence;

use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\Order;
use App\Models\RiskEvent;

class AiAnomalyDetectionService
{
    public function detect(string $workspaceId, string $symbol, array $config, array $context, array $stateSnapshot = []): array
    {
        $anomalies = [];
        $capitalBase = max(0.0, (float) data_get($context, 'balances.capital_base', 0));
        $realized24h = (float) data_get($context, 'pnl.realized_24h', 0);
        $unrealizedOpen = (float) data_get($context, 'pnl.unrealized_open', 0);
        $riskRules = (array) data_get($context, 'risk.rules', []);
        $dailyLossPct = $capitalBase > 0 && $realized24h < 0 ? abs($realized24h) / $capitalBase * 100 : 0.0;
        $drawdownEstimatePct = $capitalBase > 0 && ($realized24h + $unrealizedOpen) < 0
            ? abs($realized24h + $unrealizedOpen) / $capitalBase * 100
            : 0.0;

        if ((bool) data_get($context, 'ops.kill_switch.active', false)) {
            $anomalies[] = $this->anomaly('KILL_SWITCH', 'critical', 'Kill switch is active for this workspace.');
        }
        if ((bool) data_get($context, 'ops.stale_feed_breaker.active', false)) {
            $anomalies[] = $this->anomaly('STALE_FEED', 'critical', 'Live market feed is stale for this workspace.');
        }
        if ((bool) data_get($context, 'ops.provider_breaker.active', false)) {
            $anomalies[] = $this->anomaly('EXCHANGE_UNHEALTHY', 'critical', 'Exchange/provider breaker is active.');
        }

        if ((bool) ($config['auto_pause_on_daily_loss'] ?? true)) {
            $dailyLossLimit = max(0.0, (float) ($riskRules['daily_loss_pct'] ?? 0));
            if ($dailyLossLimit > 0 && $dailyLossPct >= $dailyLossLimit) {
                $anomalies[] = $this->anomaly('DAILY_LOSS_LIMIT', 'critical', 'Estimated daily loss limit reached.', [
                    'observed_pct' => round($dailyLossPct, 4),
                    'limit_pct' => round($dailyLossLimit, 4),
                ]);
            }
        }

        if ((bool) ($config['auto_pause_on_drawdown'] ?? true)) {
            $drawdownLimit = max(0.0, (float) ($riskRules['drawdown_pct'] ?? $riskRules['max_drawdown_pct'] ?? 0));
            if ($drawdownLimit > 0 && $drawdownEstimatePct >= $drawdownLimit) {
                $anomalies[] = $this->anomaly('DRAWDOWN_LIMIT', 'critical', 'Estimated drawdown threshold reached.', [
                    'observed_pct' => round($drawdownEstimatePct, 4),
                    'limit_pct' => round($drawdownLimit, 4),
                ]);
            }
        }

        $failureStreak = (int) data_get($stateSnapshot, 'symbol_state.consecutive_order_failures', 0);
        $failureThreshold = max(0, (int) ($config['auto_pause_on_order_failure_streak'] ?? 0));
        if ($failureThreshold > 0 && $failureStreak >= $failureThreshold) {
            $anomalies[] = $this->anomaly('ORDER_FAILURE_STREAK', 'high', 'Recent AI/live order failures reached the configured streak threshold.', [
                'failure_streak' => $failureStreak,
                'threshold' => $failureThreshold,
            ]);
        }

        if ((bool) ($config['auto_pause_on_slippage_spike'] ?? true)) {
            $slippageLimit = max(0.0, (float) ($riskRules['slippage_bps'] ?? 0)) / 100;
            if ($slippageLimit > 0) {
                $slippageSpike = Order::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('asset_code', strtoupper($symbol))
                    ->whereNotNull('slippage_pct')
                    ->where('created_at', '>=', now()->subHours(6))
                    ->orderByDesc('created_at')
                    ->value('slippage_pct');

                if ($slippageSpike !== null && (float) $slippageSpike > $slippageLimit) {
                    $anomalies[] = $this->anomaly('SLIPPAGE_SPIKE', 'high', 'Observed slippage exceeds the configured workspace threshold.', [
                        'observed_pct' => (float) $slippageSpike,
                        'limit_pct' => round($slippageLimit, 4),
                    ]);
                }
            }
        }

        if ((bool) ($config['auto_pause_on_reconciliation_mismatch'] ?? true)) {
            $mismatch = RiskEvent::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', ['order_reconciled_failed', 'reconciliation_mismatch', 'stale_pending_order', 'execution_uncertain'])
                ->where('triggered_at', '>=', now()->subHours(6))
                ->latest('triggered_at')
                ->first();

            if ($mismatch) {
                $anomalies[] = $this->anomaly('RECONCILIATION_MISMATCH', 'high', 'Reconciliation mismatch or stale pending order was detected recently.', [
                    'risk_event_id' => $mismatch->id,
                    'risk_event_type' => $mismatch->type,
                ]);
            }
        }

        $recentProviderFailures = AiDecisionCycle::query()
            ->where('workspace_id', $workspaceId)
            ->where('symbol', strtoupper($symbol))
            ->where('status', AiDecisionCycle::STATUS_FAILED)
            ->where('started_at', '>=', now()->subMinutes(30))
            ->count();
        if ($recentProviderFailures >= 3) {
            $anomalies[] = $this->anomaly('LOW_CONFIDENCE_ENVIRONMENT', 'medium', 'Recent AI/provider failures suggest a low-confidence environment.', [
                'recent_failed_cycles' => $recentProviderFailures,
            ]);
        }

        $recentConflicts = AiDecision::query()
            ->where('workspace_id', $workspaceId)
            ->where('symbol', strtoupper($symbol))
            ->whereIn('action', ['BUY', 'SELL'])
            ->where('created_at', '>=', now()->subMinutes(20))
            ->orderByDesc('created_at')
            ->limit(4)
            ->pluck('action')
            ->all();
        if (count(array_unique($recentConflicts)) > 1 && count($recentConflicts) >= 4) {
            $anomalies[] = $this->anomaly('CONFLICTING_DECISIONS', 'medium', 'Recent AI trade decisions are oscillating between opposing directions.', [
                'recent_actions' => $recentConflicts,
            ]);
        }

        return [
            'anomalies' => $anomalies,
            'recommended_action' => $this->recommendedAction($anomalies),
            'severity' => $this->highestSeverity($anomalies),
        ];
    }

    private function anomaly(string $code, string $severity, string $message, array $meta = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'meta' => $meta,
        ];
    }

    private function highestSeverity(array $anomalies): string
    {
        $order = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $highest = 'low';

        foreach ($anomalies as $anomaly) {
            $severity = strtolower((string) ($anomaly['severity'] ?? 'low'));
            if (($order[$severity] ?? 0) > ($order[$highest] ?? 0)) {
                $highest = $severity;
            }
        }

        return $anomalies === [] ? 'none' : $highest;
    }

    private function recommendedAction(array $anomalies): string
    {
        foreach ($anomalies as $anomaly) {
            if (strtolower((string) ($anomaly['severity'] ?? '')) === 'critical') {
                return 'pause';
            }
        }

        foreach ($anomalies as $anomaly) {
            if (strtolower((string) ($anomaly['severity'] ?? '')) === 'high') {
                return 'operator_review';
            }
        }

        return $anomalies === [] ? 'continue' : 'caution';
    }
}
