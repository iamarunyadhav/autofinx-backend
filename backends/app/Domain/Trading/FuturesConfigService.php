<?php

namespace App\Domain\Trading;

use App\Models\FuturesTradingConfig;
use Illuminate\Support\Str;
use RuntimeException;

class FuturesConfigService
{
    public const MODES = ['disabled', 'testnet_only', 'review_only', 'restricted_testnet_auto'];
    public const MARGIN_MODES = ['isolated', 'cross'];
    public const POSITION_MODES = ['one_way', 'hedge'];

    public function record(string $workspaceId): FuturesTradingConfig
    {
        return FuturesTradingConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['id' => (string) Str::uuid()] + $this->defaults()
        );
    }

    public function effective(string $workspaceId): array
    {
        $record = $this->record($workspaceId);

        return array_merge($this->defaults(), $record->toArray());
    }

    public function validatePayload(array $payload): array
    {
        $mode = strtolower((string) ($payload['futures_mode'] ?? 'disabled'));
        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException('Invalid futures_mode.');
        }

        $maxLeverage = $this->nullableFloat($payload['max_leverage'] ?? null);
        if ($maxLeverage !== null && ($maxLeverage < 1 || $maxLeverage > 125)) {
            throw new RuntimeException('max_leverage must be between 1 and 125.');
        }

        $marginModes = $this->normalizeModes($payload['allowed_margin_modes'] ?? []);
        if (array_diff($marginModes, self::MARGIN_MODES) !== []) {
            throw new RuntimeException('allowed_margin_modes contains unsupported values.');
        }

        $positionModes = $this->normalizeModes($payload['allowed_position_modes'] ?? []);
        if (array_diff($positionModes, self::POSITION_MODES) !== []) {
            throw new RuntimeException('allowed_position_modes contains unsupported values.');
        }

        $confidence = $this->nullableFloat($payload['futures_confidence_threshold'] ?? null);
        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            throw new RuntimeException('futures_confidence_threshold must be between 0 and 1.');
        }

        foreach ([
            'max_futures_trade_size_pct' => 100,
            'max_futures_daily_loss_pct' => 100,
            'max_futures_drawdown_pct' => 100,
            'min_liquidation_buffer_pct' => 100,
        ] as $key => $upperBound) {
            $value = $this->nullableFloat($payload[$key] ?? null);
            if ($value !== null && ($value < 0 || $value > $upperBound)) {
                throw new RuntimeException("{$key} must be between 0 and {$upperBound}.");
            }
        }

        foreach (['max_futures_notional'] as $key) {
            $value = $this->nullableFloat($payload[$key] ?? null);
            if ($value !== null && $value < 0) {
                throw new RuntimeException("{$key} must be zero or positive.");
            }
        }

        foreach (['max_open_futures_positions'] as $key) {
            $value = $this->nullableInt($payload[$key] ?? null);
            if ($value !== null && $value < 0) {
                throw new RuntimeException("{$key} must be zero or positive.");
            }
        }

        $allowedSymbols = $this->normalizeSymbols($payload['allowed_futures_symbols'] ?? []);
        $blockedSymbols = $this->normalizeSymbols($payload['blocked_futures_symbols'] ?? []);
        if (array_intersect($allowedSymbols, $blockedSymbols) !== []) {
            throw new RuntimeException('The same symbol cannot exist in both allowed_futures_symbols and blocked_futures_symbols.');
        }

        $enabled = (bool) ($payload['futures_enabled'] ?? false);
        $forceTestnet = array_key_exists('futures_force_testnet', $payload)
            ? (bool) $payload['futures_force_testnet']
            : true;

        if ($enabled && $mode === 'disabled') {
            throw new RuntimeException('Enabled futures config cannot use disabled futures_mode.');
        }

        if ($enabled && ! $forceTestnet) {
            throw new RuntimeException('Live futures is not supported. futures_force_testnet must remain enabled.');
        }

        return [
            'futures_enabled' => $enabled,
            'futures_mode' => $mode,
            'allowed_futures_symbols' => $allowedSymbols,
            'blocked_futures_symbols' => $blockedSymbols,
            'max_futures_trade_size_pct' => $this->nullableFloat($payload['max_futures_trade_size_pct'] ?? null),
            'max_futures_notional' => $this->nullableFloat($payload['max_futures_notional'] ?? null),
            'max_leverage' => $maxLeverage,
            'allowed_margin_modes' => $marginModes === [] ? ['isolated'] : $marginModes,
            'allowed_position_modes' => $positionModes === [] ? ['one_way'] : $positionModes,
            'max_open_futures_positions' => $this->nullableInt($payload['max_open_futures_positions'] ?? null),
            'max_futures_daily_loss_pct' => $this->nullableFloat($payload['max_futures_daily_loss_pct'] ?? null),
            'max_futures_drawdown_pct' => $this->nullableFloat($payload['max_futures_drawdown_pct'] ?? null),
            'min_liquidation_buffer_pct' => $this->nullableFloat($payload['min_liquidation_buffer_pct'] ?? null),
            'require_reduce_only_for_protective_exit' => (bool) ($payload['require_reduce_only_for_protective_exit'] ?? true),
            'require_manual_approval_for_futures' => (bool) ($payload['require_manual_approval_for_futures'] ?? true),
            'futures_confidence_threshold' => $confidence,
            'futures_force_testnet' => $forceTestnet,
            'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
        ];
    }

    public function defaults(): array
    {
        return [
            'futures_enabled' => false,
            'futures_mode' => 'disabled',
            'allowed_futures_symbols' => [],
            'blocked_futures_symbols' => [],
            'max_futures_trade_size_pct' => null,
            'max_futures_notional' => null,
            'max_leverage' => 3.0,
            'allowed_margin_modes' => ['isolated'],
            'allowed_position_modes' => ['one_way'],
            'max_open_futures_positions' => 1,
            'max_futures_daily_loss_pct' => null,
            'max_futures_drawdown_pct' => null,
            'min_liquidation_buffer_pct' => 15.0,
            'require_reduce_only_for_protective_exit' => true,
            'require_manual_approval_for_futures' => true,
            'futures_confidence_threshold' => 0.65,
            'futures_force_testnet' => true,
            'notes' => null,
        ];
    }

    private function normalizeSymbols(array|string|null $symbols): array
    {
        $values = is_array($symbols) ? $symbols : explode(',', (string) $symbols);

        return array_values(array_filter(array_unique(array_map(
            static fn ($symbol) => strtoupper(trim((string) $symbol)),
            $values
        ))));
    }

    private function normalizeModes(array|string|null $values): array
    {
        $list = is_array($values) ? $values : explode(',', (string) $values);

        return array_values(array_filter(array_unique(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $list
        ))));
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
