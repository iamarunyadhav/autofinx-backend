<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExchangeConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Support\SecurityNotifier;
use App\Support\ExchangeSecretVault;

class ConnectionController extends Controller
{
    public function index(string $workspaceId)
    {
        $vault = app(ExchangeSecretVault::class);
        $connections = ExchangeConnection::where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ExchangeConnection $c) use ($vault) {
                return [
                    'id' => $c->id,
                    'workspace_id' => $c->workspace_id,
                    'user_id' => $c->user_id,
                    'name' => $c->name,
                    'provider' => $c->provider,
                    'exchange' => $c->exchange ?: $c->provider,
                    'is_enabled' => $c->is_enabled,
                    'is_active' => $c->is_active ?? $c->is_enabled,
                    'is_testnet' => $c->is_testnet,
                    'permissions' => $c->permissions,
                    'last_synced_at' => $c->last_synced_at,
                    'created_at' => $c->created_at,
                    'updated_at' => $c->updated_at,
                    'masked_api_key' => $vault->mask($c->api_key_encrypted),
                    'masked_api_secret' => $vault->mask($c->api_secret_encrypted),
                    'masked_passphrase' => $vault->mask($c->passphrase_encrypted),
                    'has_secret' => (bool) $c->api_secret_encrypted,
                ];
            });

        return response()->json($connections);
    }

    public function store(Request $request, string $workspaceId)
    {
        $vault = app(ExchangeSecretVault::class);
        $data = $request->validate([
            'name' => 'required|string',
            'provider' => 'required|string',
            'api_key' => 'nullable|string',
            'api_secret' => 'nullable|string',
            'passphrase' => 'nullable|string',
            'is_testnet' => 'boolean',
        ]);

        $connection = ExchangeConnection::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'user_id' => null,
            'name' => $data['name'],
            'provider' => $data['provider'],
            'exchange' => strtolower((string) $data['provider']),
            'api_key_encrypted' => $vault->encrypt($data['api_key'] ?? null),
            'api_secret_encrypted' => $vault->encrypt($data['api_secret'] ?? null),
            'passphrase_encrypted' => $vault->encrypt($data['passphrase'] ?? null),
            'permissions' => ['trade' => true, 'read' => true, 'withdraw' => false],
            'is_testnet' => $data['is_testnet'] ?? false,
            'is_active' => true,
        ]);

        $this->logAudit($request, 'connection.create', $workspaceId, ['provider' => $connection->provider, 'connection_id' => $connection->id]);
        app(SecurityNotifier::class)->confirmAction($request->user(), $workspaceId, 'connection.create', ['provider' => $connection->provider, 'connection_id' => $connection->id]);

        return response()->json($connection, 201);
    }

    public function update(Request $request, ExchangeConnection $connection)
    {
        $vault = app(ExchangeSecretVault::class);
        $data = $request->validate([
            'name' => 'sometimes|string',
            'is_enabled' => 'boolean',
            'is_active' => 'boolean',
            'is_testnet' => 'boolean',
            'api_key' => 'nullable|string',
            'api_secret' => 'nullable|string',
            'passphrase' => 'nullable|string',
        ]);

        if (array_key_exists('api_key', $data)) {
            $data['api_key_encrypted'] = $vault->encrypt($data['api_key']);
            unset($data['api_key']);
        }
        if (array_key_exists('api_secret', $data)) {
            $data['api_secret_encrypted'] = $vault->encrypt($data['api_secret']);
            unset($data['api_secret']);
        }
        if (array_key_exists('passphrase', $data)) {
            $data['passphrase_encrypted'] = $vault->encrypt($data['passphrase']);
            unset($data['passphrase']);
        }

        if (array_key_exists('is_enabled', $data) && ! array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_enabled'];
        }
        if (array_key_exists('is_active', $data) && ! array_key_exists('is_enabled', $data)) {
            $data['is_enabled'] = (bool) $data['is_active'];
        }

        $connection->update($data);

        $this->logAudit($request, 'connection.update', $connection->workspace_id, ['connection_id' => $connection->id, 'fields' => array_keys($data)]);
        app(SecurityNotifier::class)->confirmAction($request->user(), $connection->workspace_id, 'connection.update', ['connection_id' => $connection->id, 'fields' => array_keys($data)]);

        return response()->json($connection);
    }

    public function destroy(ExchangeConnection $connection)
    {
        $connection->delete();
        $this->logAudit(request(), 'connection.delete', $connection->workspace_id, ['connection_id' => $connection->id]);
        return response()->json(['message' => 'Connection deleted']);
    }

    public function test(
        ExchangeConnection $connection,
        ExchangeClientFactory $clients,
        TradingVenueClientFactory $venues,
    )
    {
        try {
            $provider = strtolower((string) ($connection->exchange ?: $connection->provider));
            if ($provider === 'metals') {
                $provider = 'metal_broker';
            }

            if ($provider === 'binance') {
                $client = $clients->makeFromConnection($connection);
                $price = $client->getLatestPrice('BTCUSDT');
                $balances = $client->fetchBalances();
                $probe = ['symbol' => 'BTCUSDT', 'price' => $price];
            } else {
                $client = $venues->forProvider($provider, $connection);
                $quote = $client->getLatestPrice('XAUUSD');
                $balances = $client->fetchBalances();
                $probe = [
                    'symbol' => 'XAUUSD',
                    'price' => (float) ($quote['mid'] ?? 0),
                    'spread_pct' => (float) ($quote['spread_pct'] ?? 0),
                ];
            }

            $this->logAudit(request(), 'connection.test', $connection->workspace_id, [
                'connection_id' => $connection->id,
                'result' => 'ok',
            ]);

            return response()->json([
                'success' => true,
                'price_probe' => $probe,
                'balances' => array_slice($balances, 0, 25),
            ]);
        } catch (\Throwable $e) {
            $this->logAudit(request(), 'connection.test', $connection->workspace_id, [
                'connection_id' => $connection->id,
                'result' => 'failed',
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function toggle(ExchangeConnection $connection, Request $request)
    {
        $connection->update(['is_enabled' => $request->boolean('enabled', true)]);
        $connection->is_active = $connection->is_enabled;
        $connection->save();
        $this->logAudit($request, 'connection.toggle', $connection->workspace_id, ['connection_id' => $connection->id, 'enabled' => $connection->is_enabled]);
        return response()->json($connection);
    }

    public function rotate(ExchangeConnection $connection, Request $request)
    {
        $vault = app(ExchangeSecretVault::class);

        $connection->api_key_encrypted = $vault->rotate($connection->api_key_encrypted);
        $connection->api_secret_encrypted = $vault->rotate($connection->api_secret_encrypted);
        $connection->passphrase_encrypted = $vault->rotate($connection->passphrase_encrypted);
        $connection->save();

        $this->logAudit($request, 'connection.rotate', $connection->workspace_id, ['connection_id' => $connection->id]);

        return response()->json([
            'id' => $connection->id,
            'masked_api_key' => $vault->mask($connection->api_key_encrypted),
            'masked_api_secret' => $vault->mask($connection->api_secret_encrypted),
            'masked_passphrase' => $vault->mask($connection->passphrase_encrypted),
        ]);
    }

    private function logAudit(Request $request, string $action, string $workspaceId, array $meta = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $request->user()->id ?? null,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
