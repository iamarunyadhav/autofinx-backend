<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\RiskProfile;
use App\Support\ConfigVersioning;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConfigController extends Controller
{
    public function global()
    {
        return response()->json([
            'feature_flags' => ['tax' => false, 'kyc' => false, 'custody' => false],
        ]);
    }

    public function updateGlobal(Request $request)
    {
        $data = $request->validate([
            'feature_flags' => 'array',
        ]);

        ConfigVersioning::record(
            $request->input('workspace_id'),
            'global',
            $data,
            null,
            $request->user()->id
        );

        return response()->json(['updated' => true]);
    }

    public function riskProfiles(string $workspaceId)
    {
        return RiskProfile::where('workspace_id', $workspaceId)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get()
            ->map(fn (RiskProfile $profile) => $this->serializeRiskProfile($profile));
    }

    public function storeRiskProfile(Request $request, string $workspaceId)
    {
        $data = $this->validateRiskProfile($request, false);
        $payload = $this->buildRiskProfilePayload($workspaceId, $data, true);

        $profile = RiskProfile::create($payload + [
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
        ]);

        if ($profile->is_default) {
            $this->clearOtherDefaults($workspaceId, $profile->id);
        }

        return response()->json($this->serializeRiskProfile($profile->fresh()), 201);
    }

    public function updateRiskProfile(Request $request, string $workspaceId, RiskProfile $riskProfile)
    {
        abort_unless($riskProfile->workspace_id === $workspaceId, 404);

        $data = $this->validateRiskProfile($request, true);
        $payload = $this->buildRiskProfilePayload($workspaceId, $data, false, $riskProfile);

        $riskProfile->update($payload);

        if ($riskProfile->is_default) {
            $this->clearOtherDefaults($workspaceId, $riskProfile->id);
        }

        return response()->json($this->serializeRiskProfile($riskProfile->fresh()));
    }

    public function configs(string $workspaceId)
    {
        return ConfigVersion::where('workspace_id', $workspaceId)->latest()->get();
    }

    public function storeConfigVersion(Request $request, string $workspaceId)
    {
        $data = $request->validate([
            'config_key' => 'required|string',
            'new_value' => 'array',
            'previous_value' => 'array',
        ]);
        $config = ConfigVersion::create($data + [
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'actor_id' => $request->user()->id,
        ]);
        return response()->json($config, 201);
    }

    public function rollback(string $versionId)
    {
        $version = ConfigVersion::findOrFail($versionId);
        $newVersion = ConfigVersioning::record(
            $version->workspace_id,
            $version->config_key,
            $version->previous_value ?? [],
            $version->new_value ?? [],
            $version->actor_id
        );

        return response()->json([
            'rolled_back_to' => $versionId,
            'new_version_id' => $newVersion->id,
            'config_key' => $version->config_key,
        ]);
    }

    private function validateRiskProfile(Request $request, bool $partial): array
    {
        $requiredName = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$requiredName}|string",
            'is_default' => 'sometimes|boolean',
            'rules' => 'sometimes|array',
            'max_trade_percent' => 'sometimes|numeric|min:0|max:100',
            'max_trade_pct' => 'sometimes|numeric|min:0|max:100',
            'max_position_percent' => 'sometimes|numeric|min:0|max:100',
            'max_position_pct' => 'sometimes|numeric|min:0|max:100',
            'max_daily_loss_percent' => 'sometimes|numeric|min:0|max:100',
            'daily_loss_pct' => 'sometimes|numeric|min:0|max:100',
            'max_daily_loss_pct' => 'sometimes|numeric|min:0|max:100',
            'max_daily_loss' => 'sometimes|numeric|min:0',
            'max_drawdown_percent' => 'sometimes|numeric|min:0|max:100',
            'drawdown_pct' => 'sometimes|numeric|min:0|max:100',
            'max_drawdown_pct' => 'sometimes|numeric|min:0|max:100',
            'max_leverage' => 'sometimes|numeric|min:0',
            'stop_loss_percent' => 'sometimes|numeric|min:0',
            'stop_loss_pct' => 'sometimes|numeric|min:0',
            'take_profit_percent' => 'sometimes|numeric|min:0',
            'take_profit_pct' => 'sometimes|numeric|min:0',
            'trailing_stop_percent' => 'sometimes|numeric|min:0',
            'trailing_stop_pct' => 'sometimes|numeric|min:0',
            'slippage_tolerance' => 'sometimes|numeric|min:0',
            'slippage_bps' => 'sometimes|numeric|min:0',
            'max_slippage_pct_default' => 'sometimes|numeric|min:0',
            'daily_max_orders' => 'sometimes|integer|min:0',
            'max_open_orders' => 'sometimes|integer|min:0',
            'max_open_positions' => 'sometimes|integer|min:0',
            'max_notional_per_order' => 'sometimes|numeric|min:0',
            'max_account_exposure_pct' => 'sometimes|numeric|min:0|max:100',
            'allowed_symbols' => 'sometimes|array',
            'allowed_symbols.*' => 'string',
            'blocked_symbols' => 'sometimes|array',
            'blocked_symbols.*' => 'string',
            'circuit_breaker_vol_threshold' => 'sometimes|numeric|min:0',
            'cooldown_sec_after_stop' => 'sometimes|integer|min:0',
        ]);
    }

    private function buildRiskProfilePayload(string $workspaceId, array $data, bool $creating, ?RiskProfile $existing = null): array
    {
        $existingRules = (array) ($existing?->rules ?? []);
        $incomingRules = array_merge((array) ($data['rules'] ?? []), $data);
        $rules = array_merge($existingRules, $this->normalizeRules($incomingRules));

        $isDefault = array_key_exists('is_default', $data)
            ? (bool) $data['is_default']
            : ($creating ? ! RiskProfile::where('workspace_id', $workspaceId)->exists() : (bool) $existing?->is_default);

        $payload = [
            'rules' => $rules,
            'is_default' => $isDefault,
        ];

        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }

        if (array_key_exists('max_daily_loss', $data)) {
            $payload['max_daily_loss'] = $data['max_daily_loss'];
        } elseif ($creating && $existing === null) {
            $payload['max_daily_loss'] = null;
        }

        if (array_key_exists('max_open_positions', $data) || array_key_exists('max_open_positions', $rules)) {
            $payload['max_open_positions'] = $rules['max_open_positions'] ?? $data['max_open_positions'] ?? null;
        }

        if (array_key_exists('max_drawdown_pct', $data) || array_key_exists('drawdown_pct', $rules)) {
            $payload['max_drawdown_pct'] = $rules['max_drawdown_pct'] ?? $rules['drawdown_pct'] ?? null;
        }

        if (array_key_exists('max_slippage_pct_default', $data) || array_key_exists('slippage_bps', $rules)) {
            $payload['max_slippage_pct_default'] = array_key_exists('slippage_bps', $rules)
                ? ((float) $rules['slippage_bps']) / 100
                : $data['max_slippage_pct_default'];
        }

        if (array_key_exists('circuit_breaker_vol_threshold', $data)) {
            $payload['circuit_breaker_vol_threshold'] = $data['circuit_breaker_vol_threshold'];
        }

        if (array_key_exists('cooldown_sec_after_stop', $data)) {
            $payload['cooldown_sec_after_stop'] = $data['cooldown_sec_after_stop'];
        }

        return $payload;
    }

    private function normalizeRules(array $source): array
    {
        $rules = [];

        $this->assignNumeric($rules, 'max_trade_pct', $source, ['max_trade_pct', 'max_trade_percent']);
        $this->assignNumeric($rules, 'max_position_pct', $source, ['max_position_pct', 'max_position_percent']);
        $this->assignNumeric($rules, 'daily_loss_pct', $source, ['daily_loss_pct', 'max_daily_loss_pct', 'max_daily_loss_percent']);
        $this->assignNumeric($rules, 'drawdown_pct', $source, ['drawdown_pct', 'max_drawdown_pct', 'max_drawdown_percent']);
        $this->assignNumeric($rules, 'max_drawdown_pct', $source, ['max_drawdown_pct', 'drawdown_pct', 'max_drawdown_percent']);
        $this->assignNumeric($rules, 'max_leverage', $source, ['max_leverage']);
        $this->assignNumeric($rules, 'stop_loss_pct', $source, ['stop_loss_pct', 'stop_loss_percent']);
        $this->assignNumeric($rules, 'take_profit_pct', $source, ['take_profit_pct', 'take_profit_percent']);
        $this->assignNumeric($rules, 'trailing_stop_pct', $source, ['trailing_stop_pct', 'trailing_stop_percent']);
        $this->assignNumeric($rules, 'max_notional_per_order', $source, ['max_notional_per_order']);
        $this->assignNumeric($rules, 'max_account_exposure_pct', $source, ['max_account_exposure_pct', 'max_exposure_pct']);
        $this->assignInteger($rules, 'daily_max_orders', $source, ['daily_max_orders']);
        $this->assignInteger($rules, 'max_open_orders', $source, ['max_open_orders']);
        $this->assignInteger($rules, 'max_open_positions', $source, ['max_open_positions']);

        $slippageBps = $this->slippageBpsFromSource($source);
        if ($slippageBps !== null) {
            $rules['slippage_bps'] = $slippageBps;
        }

        if (array_key_exists('allowed_symbols', $source)) {
            $rules['allowed_symbols'] = $this->normalizeSymbolList((array) $source['allowed_symbols']);
        }

        if (array_key_exists('blocked_symbols', $source)) {
            $rules['blocked_symbols'] = $this->normalizeSymbolList((array) $source['blocked_symbols']);
        }

        if (array_key_exists('circuit_breaker_vol_threshold', $source) && $source['circuit_breaker_vol_threshold'] !== null) {
            $rules['circuit_breaker_vol_threshold'] = (float) $source['circuit_breaker_vol_threshold'];
        }

        if (array_key_exists('cooldown_sec_after_stop', $source) && $source['cooldown_sec_after_stop'] !== null) {
            $rules['cooldown_sec_after_stop'] = (int) $source['cooldown_sec_after_stop'];
        }

        return $rules;
    }

    private function serializeRiskProfile(RiskProfile $profile): array
    {
        $rawRules = (array) ($profile->rules ?? []);
        $rules = array_merge($rawRules, $this->normalizeRules($rawRules));
        $slippageTolerance = null;
        if (array_key_exists('slippage_bps', $rules)) {
            $slippageTolerance = ((float) $rules['slippage_bps']) / 10000;
        } elseif ($profile->max_slippage_pct_default !== null) {
            $slippageTolerance = ((float) $profile->max_slippage_pct_default) / 100;
        }

        return array_merge($profile->toArray(), [
            'rules' => $rules,
            'max_trade_percent' => $rules['max_trade_pct'] ?? null,
            'max_position_percent' => $rules['max_position_pct'] ?? null,
            'max_daily_loss_percent' => $rules['daily_loss_pct'] ?? null,
            'max_drawdown_percent' => $rules['max_drawdown_pct'] ?? $rules['drawdown_pct'] ?? $profile->max_drawdown_pct,
            'max_leverage' => $rules['max_leverage'] ?? null,
            'stop_loss_percent' => $rules['stop_loss_pct'] ?? null,
            'take_profit_percent' => $rules['take_profit_pct'] ?? null,
            'trailing_stop_percent' => $rules['trailing_stop_pct'] ?? null,
            'slippage_tolerance' => $slippageTolerance,
            'daily_max_orders' => $rules['daily_max_orders'] ?? null,
            'max_open_orders' => $rules['max_open_orders'] ?? null,
            'max_open_positions' => $rules['max_open_positions'] ?? $profile->max_open_positions,
            'allowed_symbols' => $rules['allowed_symbols'] ?? [],
            'blocked_symbols' => $rules['blocked_symbols'] ?? [],
        ]);
    }

    private function clearOtherDefaults(string $workspaceId, string $exceptId): void
    {
        RiskProfile::where('workspace_id', $workspaceId)
            ->where('id', '!=', $exceptId)
            ->update(['is_default' => false]);
    }

    private function assignNumeric(array &$rules, string $target, array $source, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                $rules[$target] = (float) $source[$key];
                return;
            }
        }
    }

    private function assignInteger(array &$rules, string $target, array $source, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                $rules[$target] = (int) $source[$key];
                return;
            }
        }
    }

    private function slippageBpsFromSource(array $source): ?float
    {
        if (array_key_exists('slippage_bps', $source) && $source['slippage_bps'] !== null && $source['slippage_bps'] !== '') {
            return (float) $source['slippage_bps'];
        }

        if (array_key_exists('max_slippage_pct_default', $source) && $source['max_slippage_pct_default'] !== null && $source['max_slippage_pct_default'] !== '') {
            return (float) $source['max_slippage_pct_default'] * 100;
        }

        if (array_key_exists('slippage_tolerance', $source) && $source['slippage_tolerance'] !== null && $source['slippage_tolerance'] !== '') {
            $raw = (float) $source['slippage_tolerance'];

            return $raw <= 1 ? $raw * 10000 : $raw * 100;
        }

        return null;
    }

    private function normalizeSymbolList(array $symbols): array
    {
        $normalized = array_map(
            static fn ($symbol) => strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $symbol)) ?: ''),
            $symbols
        );

        return array_values(array_filter(array_unique($normalized)));
    }
}
