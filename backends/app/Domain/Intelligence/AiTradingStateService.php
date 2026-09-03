<?php

namespace App\Domain\Intelligence;

use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\AiDecisionCycle;
use App\Models\AiTradingState;
use App\Models\AuditLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiTradingStateService
{
    public function __construct(
        private readonly KillSwitchService $killSwitch,
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    public function snapshot(string $workspaceId, ?string $symbol = null, array $config = [], array $context = []): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        $workspaceState = $this->stateRecord($workspaceId, null);
        $symbolState = $symbol ? $this->stateRecord($workspaceId, $symbol) : null;
        $reasons = [];
        $status = AiTradingState::STATUS_ACTIVE;

        if ($this->killSwitch->isActive($workspaceId) || (bool) data_get($context, 'ops.kill_switch.active', false)) {
            $status = AiTradingState::STATUS_BLOCKED_BY_BREAKER;
            $reasons[] = AiTradingState::REASON_KILL_SWITCH;
        }

        if ($status === AiTradingState::STATUS_ACTIVE && ($this->breakers->isProviderOutageActive($workspaceId, 'binance') || (bool) data_get($context, 'ops.provider_breaker.active', false))) {
            $status = AiTradingState::STATUS_BLOCKED_BY_BREAKER;
            $reasons[] = AiTradingState::REASON_EXCHANGE_UNHEALTHY;
        }

        if ($status === AiTradingState::STATUS_ACTIVE && ($this->breakers->isStaleFeedBreakerActive($workspaceId, $symbol) || (bool) data_get($context, 'ops.stale_feed_breaker.active', false))) {
            $status = AiTradingState::STATUS_BLOCKED_BY_BREAKER;
            $reasons[] = AiTradingState::REASON_STALE_FEED;
        }

        $configPauseUntil = $this->timestampOrNull($config['pause_until'] ?? null);
        if ($status === AiTradingState::STATUS_ACTIVE && $configPauseUntil && $configPauseUntil->isFuture()) {
            $status = AiTradingState::STATUS_PAUSED;
            $reasons[] = AiTradingState::REASON_MANUAL_OPERATOR_PAUSE;
        }

        foreach ([$workspaceState, $symbolState] as $record) {
            if (! $record) {
                continue;
            }

            $recordStatus = (string) ($record->status ?? AiTradingState::STATUS_ACTIVE);
            $manualResumeRequired = (bool) ($record->manual_resume_required ?? false);
            $pauseUntil = $record->pause_until;
            $cooldownUntil = $record->cooldown_until;

            if (in_array($recordStatus, [AiTradingState::STATUS_PAUSED, AiTradingState::STATUS_STOPPED, AiTradingState::STATUS_BLOCKED_BY_BREAKER], true)) {
                $stillActive = $manualResumeRequired || ! $pauseUntil || $pauseUntil->isFuture();
                if ($stillActive && $this->priority($recordStatus) >= $this->priority($status)) {
                    $status = $recordStatus;
                    if ($record->reason_code) {
                        $reasons[] = (string) $record->reason_code;
                    }
                }
            }

            if ($status === AiTradingState::STATUS_ACTIVE && $cooldownUntil && $cooldownUntil->isFuture()) {
                $status = AiTradingState::STATUS_COOLING_DOWN;
                $reasons[] = (string) ($record->reason_code ?: AiTradingState::REASON_TRADE_COOLDOWN);
            }
        }

        return [
            'allowed' => $status === AiTradingState::STATUS_ACTIVE,
            'status' => $status,
            'reason_codes' => array_values(array_unique(array_filter($reasons))),
            'workspace_state' => $workspaceState?->toArray(),
            'symbol_state' => $symbolState?->toArray(),
            'config_pause_until' => $configPauseUntil?->toIso8601String(),
        ];
    }

    public function enforceCadence(string $workspaceId, string $symbol, array $config, bool $manualTrigger = false, ?string $ignoreCycleId = null): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        if ($manualTrigger) {
            return ['allowed' => true, 'reason_codes' => []];
        }

        $reasons = [];
        $latestCycleQuery = AiDecisionCycle::query()
            ->where('workspace_id', $workspaceId)
            ->where('symbol', $symbol);
        if ($ignoreCycleId) {
            $latestCycleQuery->where('id', '!=', $ignoreCycleId);
        }
        $latestCycle = $latestCycleQuery->latest('started_at')->first();

        $interval = max(0, (int) ($config['decision_interval_minutes'] ?? 0));
        if ($interval > 0 && $latestCycle?->started_at && $latestCycle->started_at->gt(now()->subMinutes($interval))) {
            $reasons[] = AiTradingState::REASON_DECISION_INTERVAL_ACTIVE;
        }

        $hourlyCap = max(0, (int) ($config['max_decisions_per_hour'] ?? 0));
        if ($hourlyCap > 0) {
            $hourlyQuery = AiDecisionCycle::query()
                ->where('workspace_id', $workspaceId)
                ->where('started_at', '>=', now()->subHour());
            if ($ignoreCycleId) {
                $hourlyQuery->where('id', '!=', $ignoreCycleId);
            }
            $count = $hourlyQuery->count();

            if ($count >= $hourlyCap) {
                $reasons[] = AiTradingState::REASON_DECISION_RATE_LIMIT;
            }
        }

        return [
            'allowed' => $reasons === [],
            'reason_codes' => $reasons,
        ];
    }

    public function pause(string $workspaceId, ?string $symbol, string $reasonCode, ?string $detail = null, ?string $actorId = null, ?\Carbon\CarbonInterface $pauseUntil = null, bool $manualResumeRequired = false, array $meta = []): AiTradingState
    {
        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->fill([
            'status' => AiTradingState::STATUS_PAUSED,
            'reason_code' => $reasonCode,
            'reason_detail' => $detail,
            'pause_until' => $pauseUntil,
            'manual_resume_required' => $manualResumeRequired,
            'meta_json' => array_merge((array) ($record->meta_json ?? []), $meta),
        ])->save();

        $this->audit($workspaceId, 'ai_trading_state.paused', $actorId, $symbol, [
            'reason_code' => $reasonCode,
            'reason_detail' => $detail,
            'pause_until' => $pauseUntil?->toIso8601String(),
            'manual_resume_required' => $manualResumeRequired,
        ]);

        return $record->fresh();
    }

    public function resume(string $workspaceId, ?string $symbol, ?string $actorId = null, string $detail = 'manual_resume'): AiTradingState
    {
        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->fill([
            'status' => AiTradingState::STATUS_ACTIVE,
            'reason_code' => null,
            'reason_detail' => $detail,
            'pause_until' => null,
            'cooldown_until' => null,
            'manual_resume_required' => false,
            'anomaly_snapshot_json' => null,
            'consecutive_order_failures' => 0,
            'last_order_failed_at' => null,
        ])->save();

        $this->audit($workspaceId, 'ai_trading_state.resumed', $actorId, $symbol, [
            'detail' => $detail,
        ]);

        return $record->fresh();
    }

    public function recordCycle(string $workspaceId, string $symbol, string $status, array $meta = []): void
    {
        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->last_cycle_at = now();
        if ($status === AiTradingState::STATUS_COOLING_DOWN) {
            $record->status = AiTradingState::STATUS_COOLING_DOWN;
        } elseif ($record->status === AiTradingState::STATUS_COOLING_DOWN && (! $record->cooldown_until || $record->cooldown_until->isPast())) {
            $record->status = AiTradingState::STATUS_ACTIVE;
        }
        if ($meta !== []) {
            $record->meta_json = array_merge((array) ($record->meta_json ?? []), $meta);
        }
        $record->save();
    }

    public function recordTradeExecuted(string $workspaceId, string $symbol, array $config, array $meta = []): AiTradingState
    {
        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->last_trade_at = now();
        $record->consecutive_order_failures = 0;
        $record->status = AiTradingState::STATUS_ACTIVE;
        $record->reason_code = null;
        $record->reason_detail = null;
        $record->manual_resume_required = false;
        $record->cooldown_until = $this->futureMinutes((int) ($config['cooldown_minutes_after_trade'] ?? 0));
        if ($record->cooldown_until) {
            $record->status = AiTradingState::STATUS_COOLING_DOWN;
            $record->reason_code = AiTradingState::REASON_TRADE_COOLDOWN;
        }
        $record->meta_json = array_merge((array) ($record->meta_json ?? []), $meta);
        $record->save();

        return $record->fresh();
    }

    public function recordLoss(string $workspaceId, string $symbol, array $config, ?string $detail = null): AiTradingState
    {
        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->last_loss_at = now();
        $lossCooldown = $this->futureMinutes((int) ($config['cooldown_minutes_after_loss'] ?? 0));
        if ($lossCooldown && (! $record->cooldown_until || $lossCooldown->gt($record->cooldown_until))) {
            $record->cooldown_until = $lossCooldown;
            $record->status = AiTradingState::STATUS_COOLING_DOWN;
            $record->reason_code = AiTradingState::REASON_LOSS_COOLDOWN;
            $record->reason_detail = $detail;
        }
        $record->save();

        return $record->fresh();
    }

    public function recordOrderFailure(string $workspaceId, string $symbol, array $config, string $reason, ?string $actorId = null): AiTradingState
    {
        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->last_order_failed_at = now();
        $record->consecutive_order_failures = max(0, (int) $record->consecutive_order_failures) + 1;
        $record->reason_detail = $reason;
        $record->save();

        $threshold = max(0, (int) ($config['auto_pause_on_order_failure_streak'] ?? 0));
        if ($threshold > 0 && $record->consecutive_order_failures >= $threshold) {
            return $this->pause(
                $workspaceId,
                $symbol,
                AiTradingState::REASON_ORDER_FAILURE_STREAK,
                $reason,
                $actorId,
                null,
                (bool) ($config['manual_resume_required'] ?? false),
                ['failure_streak' => $record->consecutive_order_failures]
            );
        }

        return $record->fresh();
    }

    public function applyAnomalies(string $workspaceId, string $symbol, array $config, array $anomalyReport, ?string $actorId = null): ?AiTradingState
    {
        $action = (string) ($anomalyReport['recommended_action'] ?? 'continue');
        $anomalies = (array) ($anomalyReport['anomalies'] ?? []);
        $reason = $this->reasonFromAnomalies($anomalies);
        $detail = $anomalies !== [] ? (string) Arr::first($anomalies)['message'] : null;

        if (in_array($action, ['pause', 'stop'], true) && $reason) {
            return $this->pause(
                $workspaceId,
                $symbol,
                $reason,
                $detail,
                $actorId,
                null,
                (bool) ($config['manual_resume_required'] ?? false),
                ['anomalies' => $anomalies]
            );
        }

        $record = $this->stateRecord($workspaceId, $symbol, true);
        $record->anomaly_snapshot_json = $anomalyReport;
        $record->save();

        return null;
    }

    public function stateRowsForWorkspace(string $workspaceId)
    {
        return AiTradingState::query()
            ->where('workspace_id', $workspaceId)
            ->orderByRaw('CASE WHEN symbol IS NULL THEN 0 ELSE 1 END')
            ->orderBy('symbol')
            ->get();
    }

    private function stateRecord(string $workspaceId, ?string $symbol, bool $create = false): ?AiTradingState
    {
        $symbol = $this->normalizeSymbol($symbol);
        $scopeKey = $this->scopeKey($workspaceId, $symbol);
        $record = AiTradingState::query()
            ->where('workspace_id', $workspaceId)
            ->where('scope_key', $scopeKey)
            ->first();

        if ($record || ! $create) {
            return $record;
        }

        return AiTradingState::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'scope_key' => $scopeKey,
            'status' => AiTradingState::STATUS_ACTIVE,
        ]);
    }

    private function scopeKey(string $workspaceId, ?string $symbol): string
    {
        return 'workspace:' . $workspaceId . ':symbol:' . ($symbol ?: '*');
    }

    private function normalizeSymbol(?string $symbol): ?string
    {
        $normalized = strtoupper(trim((string) $symbol));

        return $normalized !== '' ? $normalized : null;
    }

    private function timestampOrNull(mixed $value): ?\Carbon\CarbonInterface
    {
        if (! $value) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse((string) $value);
    }

    private function futureMinutes(int $minutes): ?\Carbon\CarbonInterface
    {
        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    private function priority(string $status): int
    {
        return match ($status) {
            AiTradingState::STATUS_STOPPED => 5,
            AiTradingState::STATUS_BLOCKED_BY_BREAKER => 4,
            AiTradingState::STATUS_PAUSED => 3,
            AiTradingState::STATUS_COOLING_DOWN => 2,
            default => 1,
        };
    }

    private function audit(string $workspaceId, string $action, ?string $actorId, ?string $symbol, array $metadata = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'metadata' => array_merge([
                'symbol' => $symbol,
            ], $metadata),
        ]);
    }

    private function reasonFromAnomalies(array $anomalies): ?string
    {
        foreach ($anomalies as $anomaly) {
            $code = strtoupper((string) ($anomaly['code'] ?? ''));
            if (in_array($code, [
                AiTradingState::REASON_DAILY_LOSS_LIMIT,
                AiTradingState::REASON_DRAWDOWN_LIMIT,
                AiTradingState::REASON_SLIPPAGE_SPIKE,
                AiTradingState::REASON_RECONCILIATION_MISMATCH,
                AiTradingState::REASON_ORDER_FAILURE_STREAK,
                AiTradingState::REASON_STALE_FEED,
                AiTradingState::REASON_EXCHANGE_UNHEALTHY,
                AiTradingState::REASON_KILL_SWITCH,
                AiTradingState::REASON_LOW_CONFIDENCE_ENVIRONMENT,
            ], true)) {
                return $code;
            }
        }

        return null;
    }
}
