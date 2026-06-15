<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Support\RiskGuardService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return Integration::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))->get();
    }

    public function store(Request $request, IntegrationManager $manager, LiveTradeProviderRegistry $providers, RiskGuardService $riskGuard)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'type' => 'required|string',
            'provider_key' => 'required|string',
            'display_name' => 'required|string',
            'config_json' => 'array|nullable',
            'secrets' => 'array|nullable',
        ]);
        $data['status'] = 'active';
        $data['environment'] = strtolower((string) ($data['config_json']['environment'] ?? 'live'));
        $integration = $manager->upsert($data, (string) Str::uuid());

        // Connecting a live trading account re-baselines the drawdown guard to the
        // real account equity so the first live order isn't blocked by a stale
        // baseline. Best-effort: never fail the connection on a balance hiccup.
        $this->rebaselineLiveAccount($integration, $providers, $riskGuard);

        return response()->json($integration, 201);
    }

    private function rebaselineLiveAccount(Integration $integration, LiveTradeProviderRegistry $providers, RiskGuardService $riskGuard): void
    {
        try {
            $provider = $providers->for((string) $integration->provider_key);
            if (($provider->capabilities()['live_supported'] ?? false) !== true) {
                return;
            }
            $available = (float) ($provider->availableBalance($integration, 'USDT')['available'] ?? 0);
            if ($available > 0) {
                $riskGuard->rebaseline((string) $integration->workspace_id, $available);
            }
        } catch (\Throwable) {
            // Connection still succeeds; baseline will fall back to ledger balance on first guard check.
        }
    }

    public function update(Request $request, Integration $integration, IntegrationManager $manager)
    {
        $data = $request->validate([
            'config_json' => 'array|nullable',
            'status' => 'string|nullable',
            'secrets' => 'array|nullable',
        ]);
        $merged = array_merge($integration->toArray(), $data);
        $integration = $manager->upsert($merged, $integration->id);
        return response()->json($integration);
    }

    public function rotate(Request $request, Integration $integration, IntegrationManager $manager)
    {
        $data = $request->validate([
            'secrets' => 'required|array',
        ]);
        $integration = $manager->upsert(array_merge($integration->toArray(), ['secrets' => $data['secrets']]), $integration->id);
        return response()->json(['ok' => true]);
    }

    public function health(Integration $integration, IntegrationManager $manager)
    {
        $status = $manager->health($integration);
        return response()->json(['status' => $status]);
    }
}
