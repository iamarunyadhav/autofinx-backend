<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\ProviderToCashSweepService;
use App\Http\Controllers\Controller;
use App\Models\WalletSweepRule;
use App\Models\WalletSweepRun;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SweepController extends Controller
{
    public function rules(Request $request, ProviderToCashSweepService $sweeps)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'provider' => 'nullable|string|max:32',
            'currency' => 'nullable|string|max:10',
        ]);

        $query = $sweeps->queryRules((string) $data['workspace_id']);
        if (! empty($data['provider'])) {
            $query->where('provider', strtolower((string) $data['provider']));
        }
        if (! empty($data['currency'])) {
            $query->where('currency', strtoupper((string) $data['currency']));
        }

        return response()->json($query->get());
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'name' => 'nullable|string|max:120',
            'bot_id' => 'nullable|uuid|exists:bots,id',
            'provider' => 'required|string|max:32',
            'asset_class' => 'nullable|string|in:CRYPTO,METALS,crypto,metals',
            'asset_code' => 'nullable|string|max:32',
            'currency' => 'required|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'auto_withdraw_enabled' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'meta_json' => 'nullable|array',
        ]);

        $rule = WalletSweepRule::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'user_id' => $request->user()?->id,
            'bot_id' => $data['bot_id'] ?? null,
            'name' => $data['name'] ?? 'Default Sweep Rule',
            'provider' => strtolower((string) $data['provider']),
            'asset_class' => isset($data['asset_class']) ? strtoupper((string) $data['asset_class']) : null,
            'asset_code' => isset($data['asset_code']) ? strtoupper((string) $data['asset_code']) : null,
            'currency' => strtoupper((string) $data['currency']),
            'min_amount' => (float) ($data['min_amount'] ?? 0),
            'auto_withdraw_enabled' => (bool) ($data['auto_withdraw_enabled'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'meta_json' => $data['meta_json'] ?? null,
        ]);

        return response()->json($rule, 201);
    }

    public function updateRule(Request $request, WalletSweepRule $rule)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'bot_id' => 'nullable|uuid|exists:bots,id',
            'provider' => 'sometimes|string|max:32',
            'asset_class' => 'nullable|string|in:CRYPTO,METALS,crypto,metals',
            'asset_code' => 'nullable|string|max:32',
            'currency' => 'sometimes|string|max:10',
            'min_amount' => 'nullable|numeric|min:0',
            'auto_withdraw_enabled' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'meta_json' => 'nullable|array',
        ]);

        if (array_key_exists('provider', $data)) {
            $data['provider'] = strtolower((string) $data['provider']);
        }
        if (array_key_exists('asset_class', $data)) {
            $data['asset_class'] = $data['asset_class'] ? strtoupper((string) $data['asset_class']) : null;
        }
        if (array_key_exists('asset_code', $data)) {
            $data['asset_code'] = $data['asset_code'] ? strtoupper((string) $data['asset_code']) : null;
        }
        if (array_key_exists('currency', $data)) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        $rule->fill($data);
        $rule->save();

        return response()->json($rule->fresh());
    }

    public function destroyRule(WalletSweepRule $rule)
    {
        $rule->delete();

        return response()->json(['ok' => true]);
    }

    public function run(Request $request, ProviderToCashSweepService $sweeps)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'rule_id' => 'nullable|uuid|exists:wallet_sweep_rules,id',
            'provider' => 'nullable|string|max:32',
            'currency' => 'nullable|string|max:10',
        ]);

        $result = $sweeps->runManual(
            workspaceId: (string) $data['workspace_id'],
            ruleId: $data['rule_id'] ?? null,
            provider: isset($data['provider']) ? strtolower((string) $data['provider']) : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            traceId: (string) Str::uuid(),
        );

        return response()->json($result);
    }

    public function runs(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'rule_id' => 'nullable|uuid',
            'status' => 'nullable|string|max:32',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $query = WalletSweepRun::where('workspace_id', (string) $data['workspace_id'])
            ->orderByDesc('created_at');

        if (! empty($data['rule_id'])) {
            $query->where('rule_id', (string) $data['rule_id']);
        }
        if (! empty($data['status'])) {
            $query->where('status', strtolower((string) $data['status']));
        }

        return response()->json(
            $query->limit((int) ($data['limit'] ?? 100))->get()
        );
    }
}
