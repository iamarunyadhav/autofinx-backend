<?php

namespace App\Domain\Trading;

use App\Domain\Risk\WorkspaceLiveRiskService;
use App\Models\Position;
use App\Models\WorkspaceExitPolicy;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceExitPolicyService
{
    public function __construct(
        private readonly WorkspaceLiveRiskService $workspaceRisk,
    ) {
    }

    public function policyRecord(string $workspaceId): WorkspaceExitPolicy
    {
        return WorkspaceExitPolicy::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                'trailing_stop_enabled' => false,
                'partial_take_profit_enabled' => false,
                'break_even_after_partial' => false,
                'emergency_exit_on_breaker' => false,
                'force_exit_on_pause' => false,
                'force_exit_on_max_daily_loss' => false,
                'native_protection_mode' => 'disabled',
            ]
        );
    }

    public function validatePayload(array $data): array
    {
        $levels = $this->normalizeNumericArray($data['partial_take_profit_levels'] ?? []);
        $sizes = $this->normalizeNumericArray($data['partial_take_profit_sizes'] ?? []);

        if ($levels !== [] || $sizes !== []) {
            if (count($levels) !== count($sizes)) {
                throw new RuntimeException('Partial take-profit levels and sizes must have the same number of entries.');
            }

            $sortedLevels = $levels;
            sort($sortedLevels);
            if ($sortedLevels !== $levels) {
                throw new RuntimeException('Partial take-profit levels must be sorted in ascending percentage order.');
            }

            $sizeTotal = array_sum($sizes);
            if ($sizeTotal > 100.0001) {
                throw new RuntimeException('Partial take-profit sizes cannot exceed 100 percent in total.');
            }
        }

        return [
            'default_stop_loss_pct' => $this->nullableNumeric($data['default_stop_loss_pct'] ?? null),
            'default_take_profit_pct' => $this->nullableNumeric($data['default_take_profit_pct'] ?? null),
            'default_time_stop_minutes' => $this->nullableInteger($data['default_time_stop_minutes'] ?? null),
            'trailing_stop_enabled' => (bool) ($data['trailing_stop_enabled'] ?? false),
            'trailing_stop_pct' => $this->nullableNumeric($data['trailing_stop_pct'] ?? null),
            'partial_take_profit_enabled' => (bool) ($data['partial_take_profit_enabled'] ?? false),
            'partial_take_profit_levels' => $levels,
            'partial_take_profit_sizes' => $sizes,
            'break_even_after_partial' => (bool) ($data['break_even_after_partial'] ?? false),
            'emergency_exit_on_breaker' => (bool) ($data['emergency_exit_on_breaker'] ?? false),
            'force_exit_on_pause' => (bool) ($data['force_exit_on_pause'] ?? false),
            'force_exit_on_max_daily_loss' => (bool) ($data['force_exit_on_max_daily_loss'] ?? false),
            'minimum_position_value_for_partial' => $this->nullableNumeric($data['minimum_position_value_for_partial'] ?? null),
            'native_protection_mode' => in_array((string) ($data['native_protection_mode'] ?? 'disabled'), ['disabled', 'simple_oco'], true)
                ? (string) ($data['native_protection_mode'] ?? 'disabled')
                : 'disabled',
        ];
    }

    public function effectivePolicy(string $workspaceId): array
    {
        $record = $this->policyRecord($workspaceId);
        $riskDefaults = $this->workspaceRisk->resolveRules($workspaceId);
        $trailingPct = $record->trailing_stop_pct !== null
            ? (float) $record->trailing_stop_pct
            : $this->nullableNumeric($riskDefaults['trailing_stop_pct'] ?? null);

        return [
            'workspace_id' => $workspaceId,
            'default_stop_loss_pct' => $record->default_stop_loss_pct !== null
                ? (float) $record->default_stop_loss_pct
                : $this->nullableNumeric($riskDefaults['stop_loss_pct'] ?? null),
            'default_take_profit_pct' => $record->default_take_profit_pct !== null
                ? (float) $record->default_take_profit_pct
                : $this->nullableNumeric($riskDefaults['take_profit_pct'] ?? null),
            'default_time_stop_minutes' => $record->default_time_stop_minutes !== null
                ? (int) $record->default_time_stop_minutes
                : null,
            'trailing_stop_enabled' => $record->trailing_stop_enabled || ($trailingPct !== null && $trailingPct > 0),
            'trailing_stop_pct' => $trailingPct,
            'partial_take_profit_enabled' => (bool) $record->partial_take_profit_enabled,
            'partial_take_profit_levels' => $this->normalizeNumericArray($record->partial_take_profit_levels ?? []),
            'partial_take_profit_sizes' => $this->normalizeNumericArray($record->partial_take_profit_sizes ?? []),
            'break_even_after_partial' => (bool) $record->break_even_after_partial,
            'emergency_exit_on_breaker' => (bool) $record->emergency_exit_on_breaker,
            'force_exit_on_pause' => (bool) $record->force_exit_on_pause,
            'force_exit_on_max_daily_loss' => (bool) $record->force_exit_on_max_daily_loss,
            'minimum_position_value_for_partial' => $record->minimum_position_value_for_partial !== null
                ? (float) $record->minimum_position_value_for_partial
                : null,
            'native_protection_mode' => (string) ($record->native_protection_mode ?: 'disabled'),
            'fallback_risk_profile_id' => $riskDefaults['_risk_profile_id'] ?? null,
            'fallback_risk_profile_name' => $riskDefaults['_risk_profile_name'] ?? null,
        ];
    }

    public function snapshotForPosition(Position $position, ?array $effectivePolicy = null): array
    {
        $effectivePolicy ??= $this->effectivePolicy((string) $position->workspace_id);
        $entryPrice = max(0.0, (float) ($position->avg_entry ?? $position->avg_price ?? 0));
        $currentPrice = max(0.0, (float) ($position->last_mark_price ?? $entryPrice));
        $qty = abs((float) ($position->qty ?? $position->quantity ?? 0));
        $trailingEnabled = (bool) ($effectivePolicy['trailing_stop_enabled'] ?? false);
        $trailingPct = $this->nullableNumeric($effectivePolicy['trailing_stop_pct'] ?? null);
        $highWater = max($entryPrice, $currentPrice);
        $timeStopMinutes = $this->nullableInteger($effectivePolicy['default_time_stop_minutes'] ?? null);

        $snapshot = [
            'workspace_id' => (string) $position->workspace_id,
            'position_id' => (string) $position->id,
            'symbol' => strtoupper((string) ($position->asset_code ?: $position->symbol?->symbol ?: '')),
            'provider' => strtolower((string) ($position->provider ?: 'binance')),
            'market_type' => 'spot',
            'entry_price' => $entryPrice,
            'initial_qty' => $qty,
            'default_stop_loss_pct' => $this->nullableNumeric($effectivePolicy['default_stop_loss_pct'] ?? null),
            'default_take_profit_pct' => $this->nullableNumeric($effectivePolicy['default_take_profit_pct'] ?? null),
            'default_time_stop_minutes' => $timeStopMinutes,
            'trailing_stop_enabled' => $trailingEnabled,
            'trailing_stop_pct' => $trailingPct,
            'partial_take_profit_enabled' => (bool) ($effectivePolicy['partial_take_profit_enabled'] ?? false),
            'partial_take_profit_levels' => $this->normalizeNumericArray($effectivePolicy['partial_take_profit_levels'] ?? []),
            'partial_take_profit_sizes' => $this->normalizeNumericArray($effectivePolicy['partial_take_profit_sizes'] ?? []),
            'break_even_after_partial' => (bool) ($effectivePolicy['break_even_after_partial'] ?? false),
            'emergency_exit_on_breaker' => (bool) ($effectivePolicy['emergency_exit_on_breaker'] ?? false),
            'force_exit_on_pause' => (bool) ($effectivePolicy['force_exit_on_pause'] ?? false),
            'force_exit_on_max_daily_loss' => (bool) ($effectivePolicy['force_exit_on_max_daily_loss'] ?? false),
            'minimum_position_value_for_partial' => $this->nullableNumeric($effectivePolicy['minimum_position_value_for_partial'] ?? null),
            'native_protection_mode' => (string) ($effectivePolicy['native_protection_mode'] ?? 'disabled'),
            'native_eligible' => $this->isNativeEligible($effectivePolicy),
            'armed_at' => now()->toIso8601String(),
        ];

        return $snapshot;
    }

    public function stopLossPrice(array $snapshot): ?float
    {
        $pct = $this->nullableNumeric($snapshot['default_stop_loss_pct'] ?? null);
        $entry = max(0.0, (float) ($snapshot['entry_price'] ?? 0));
        if ($pct === null || $pct <= 0 || $entry <= 0) {
            return null;
        }

        return round($entry * (1 - ($pct / 100)), 8);
    }

    public function takeProfitPrice(array $snapshot): ?float
    {
        $pct = $this->nullableNumeric($snapshot['default_take_profit_pct'] ?? null);
        $entry = max(0.0, (float) ($snapshot['entry_price'] ?? 0));
        if ($pct === null || $pct <= 0 || $entry <= 0) {
            return null;
        }

        return round($entry * (1 + ($pct / 100)), 8);
    }

    public function trailingStopPrice(array $snapshot, float $highWaterMark): ?float
    {
        $enabled = (bool) ($snapshot['trailing_stop_enabled'] ?? false);
        $pct = $this->nullableNumeric($snapshot['trailing_stop_pct'] ?? null);
        if (! $enabled || $pct === null || $pct <= 0 || $highWaterMark <= 0) {
            return null;
        }

        return round($highWaterMark * (1 - ($pct / 100)), 8);
    }

    public function timeStopAt(array $snapshot): ?\Illuminate\Support\Carbon
    {
        $minutes = $this->nullableInteger($snapshot['default_time_stop_minutes'] ?? null);
        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        return now()->addMinutes($minutes);
    }

    public function hasActionableExit(array $snapshot): bool
    {
        return $this->stopLossPrice($snapshot) !== null
            || $this->takeProfitPrice($snapshot) !== null
            || $this->trailingStopPrice($snapshot, (float) ($snapshot['entry_price'] ?? 0)) !== null
            || $this->timeStopAt($snapshot) !== null
            || ! empty($snapshot['emergency_exit_on_breaker'])
            || ! empty($snapshot['force_exit_on_pause'])
            || ! empty($snapshot['force_exit_on_max_daily_loss']);
    }

    private function isNativeEligible(array $policy): bool
    {
        return (string) ($policy['native_protection_mode'] ?? 'disabled') === 'simple_oco'
            && $this->nullableNumeric($policy['default_stop_loss_pct'] ?? null) !== null
            && $this->nullableNumeric($policy['default_take_profit_pct'] ?? null) !== null
            && ! (bool) ($policy['trailing_stop_enabled'] ?? false)
            && ! (bool) ($policy['partial_take_profit_enabled'] ?? false)
            && ! (bool) ($policy['break_even_after_partial'] ?? false)
            && $this->nullableInteger($policy['default_time_stop_minutes'] ?? null) === null;
    }

    private function normalizeNumericArray(array|string|null $values): array
    {
        if (is_string($values)) {
            $values = array_filter(array_map('trim', explode(',', $values)));
        }

        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $numeric = $this->nullableNumeric($value);
            if ($numeric === null || $numeric <= 0) {
                continue;
            }
            $normalized[] = round($numeric, 4);
        }

        return array_values($normalized);
    }

    private function nullableNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (float) $value;

        return $numeric > 0 ? $numeric : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
