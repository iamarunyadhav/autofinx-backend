<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Http\Controllers\Controller;
use App\Models\ExchangeConnection;
use App\Support\ExchangeSecretVault;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProviderController extends Controller
{
    public function connect(Request $request, string $provider, ExchangeSecretVault $vault)
    {
        $provider = $this->normalizeProvider($provider);
        $this->assertSupported($provider);

        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'name' => 'nullable|string|max:120',
            'api_key' => 'nullable|string|max:512',
            'api_secret' => 'nullable|string|max:512',
            'passphrase' => 'nullable|string|max:512',
            'is_testnet' => 'boolean',
        ]);

        $connection = ExchangeConnection::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'user_id' => $request->user()?->id,
            'name' => $data['name'] ?? strtoupper($provider) . ' Connection',
            'provider' => $provider,
            'exchange' => $provider,
            'api_key_encrypted' => $vault->encrypt($data['api_key'] ?? null),
            'api_secret_encrypted' => $vault->encrypt($data['api_secret'] ?? null),
            'passphrase_encrypted' => $vault->encrypt($data['passphrase'] ?? null),
            'permissions' => ['trade' => true, 'read' => true, 'withdraw' => false],
            'is_testnet' => (bool) ($data['is_testnet'] ?? false),
            'is_enabled' => true,
            'is_active' => true,
        ]);

        return response()->json([
            'id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'provider' => $connection->provider,
            'name' => $connection->name,
            'is_active' => (bool) $connection->is_active,
            'is_testnet' => (bool) $connection->is_testnet,
        ], 201);
    }

    public function status(
        Request $request,
        string $provider,
        ExchangeClientFactory $exchangeClients,
        TradingVenueClientFactory $venues,
    ) {
        $provider = $this->normalizeProvider($provider);
        $this->assertSupported($provider);

        $workspaceId = (string) $request->query('workspace_id');
        if ($workspaceId === '') {
            return response()->json(['message' => 'workspace_id is required'], 422);
        }

        $connection = $this->findConnection($workspaceId, $provider);

        try {
            if ($provider === 'binance') {
                if (! $connection) {
                    return response()->json([
                        'provider' => $provider,
                        'connected' => false,
                        'message' => 'No active binance connection',
                    ]);
                }

                $symbol = strtoupper((string) $request->query('asset_code', 'BTCUSDT'));
                $price = $exchangeClients->makeFromConnection($connection)->getLatestPrice($symbol);

                return response()->json([
                    'provider' => $provider,
                    'connected' => true,
                    'connection_id' => $connection->id,
                    'probe' => [
                        'asset_code' => $symbol,
                        'price' => (float) $price,
                    ],
                ]);
            }

            $assetCode = strtoupper((string) $request->query('asset_code', 'XAUUSD'));
            $quote = $venues->forProvider('metal_broker', $connection)->getLatestPrice($assetCode);

            return response()->json([
                'provider' => $provider,
                'connected' => true,
                'connection_id' => $connection?->id,
                'probe' => [
                    'asset_code' => $assetCode,
                    'mid' => (float) ($quote['mid'] ?? 0),
                    'spread_pct' => (float) ($quote['spread_pct'] ?? 0),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'provider' => $provider,
                'connected' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function balances(
        Request $request,
        string $provider,
        ExchangeClientFactory $exchangeClients,
        TradingVenueClientFactory $venues,
    ) {
        $provider = $this->normalizeProvider($provider);
        $this->assertSupported($provider);

        $workspaceId = (string) $request->query('workspace_id');
        if ($workspaceId === '') {
            return response()->json(['message' => 'workspace_id is required'], 422);
        }

        $connection = $this->findConnection($workspaceId, $provider);

        try {
            if ($provider === 'binance') {
                if (! $connection) {
                    return response()->json(['message' => 'No active binance connection'], 404);
                }

                $balances = $exchangeClients->makeFromConnection($connection)->fetchBalances();

                return response()->json([
                    'provider' => $provider,
                    'connection_id' => $connection->id,
                    'balances' => $balances,
                ]);
            }

            $balances = $venues->forProvider('metal_broker', $connection)->fetchBalances();

            return response()->json([
                'provider' => $provider,
                'connection_id' => $connection?->id,
                'balances' => $balances,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function findConnection(string $workspaceId, string $provider): ?ExchangeConnection
    {
        return ExchangeConnection::where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();
    }

    private function assertSupported(string $provider): void
    {
        if (! in_array($provider, ['binance', 'metal_broker'], true)) {
            abort(422, "Unsupported provider [{$provider}]");
        }
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'metals') {
            return 'metal_broker';
        }

        return $provider;
    }
}
