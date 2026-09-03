<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Support\RiskGuardService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Support\SecurityNotifier;
use App\Support\ExchangeSecretVault;
use Illuminate\Support\Facades\Log;

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

        // Mirror into the trading-engine Integration table so the Robot Trade
        // live/testnet path can actually use these keys (the engine reads
        // Integration, not ExchangeConnection).
        $this->syncTradingIntegration(
            $workspaceId,
            $connection->provider,
            $connection->name,
            (bool) $connection->is_testnet,
            $data['api_key'] ?? null,
            $data['api_secret'] ?? null,
        );

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

        $rawApiKey = $data['api_key'] ?? null;
        $rawApiSecret = $data['api_secret'] ?? null;

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

        $this->syncTradingIntegration(
            $connection->workspace_id,
            $connection->provider,
            $connection->name,
            (bool) $connection->is_testnet,
            $rawApiKey,
            $rawApiSecret,
        );

        $this->logAudit($request, 'connection.update', $connection->workspace_id, ['connection_id' => $connection->id, 'fields' => array_keys($data)]);
        app(SecurityNotifier::class)->confirmAction($request->user(), $connection->workspace_id, 'connection.update', ['connection_id' => $connection->id, 'fields' => array_keys($data)]);

        return response()->json($connection);
    }

    public function destroy(ExchangeConnection $connection)
    {
        $workspaceId = $connection->workspace_id;
        $providerKey = strtolower((string) $connection->provider);
        $environment = $connection->is_testnet ? 'testnet' : 'live';
        $connection->delete();

        // Disable the mirrored trading integration (matching environment) so
        // live_ready turns off for that environment.
        Integration::where('workspace_id', $workspaceId)
            ->where('provider_key', $providerKey)
            ->where('environment', $environment)
            ->update(['status' => 'disabled']);

        $this->logAudit(request(), 'connection.delete', $workspaceId, ['connection_id' => $connection->id]);
        return response()->json(['message' => 'Connection deleted']);
    }

    /**
     * Mirror an exchange connection into the trading-engine Integration table.
     * The Robot Trade / candle-bot live path resolves credentials from Integration
     * (via TradingIntegrationResolver), not from ExchangeConnection, so a connection
     * added in the UI must be reflected here to be usable for live/testnet trading.
     * The Testnet toggle maps to config_json.environment, which routes the Binance
     * provider to the testnet futures endpoint.
     */
    private function syncTradingIntegration(
        string $workspaceId,
        string $provider,
        string $name,
        bool $isTestnet,
        ?string $apiKey,
        ?string $apiSecret,
    ): void {
        // The entire mirror is best-effort: a failure here must NEVER fail the
        // connection save itself. Worst case the integration isn't mirrored and
        // we log it for diagnosis; the ExchangeConnection is already persisted.
        try {
            $providerKey = strtolower($provider);
            $environment = $isTestnet ? 'testnet' : 'live';
            $manager = app(IntegrationManager::class);

            // Key by (workspace, provider, environment) so testnet and live keys
            // for the same exchange are stored as separate integrations. Fall back
            // to any same-provider integration if the environment-aware unique
            // index hasn't been applied yet (avoids a duplicate-key insert).
            $existing = Integration::where('workspace_id', $workspaceId)
                ->where('provider_key', $providerKey)
                ->where('environment', $environment)
                ->first()
                ?? Integration::where('workspace_id', $workspaceId)
                    ->where('provider_key', $providerKey)
                    ->first();

            $attrs = [
                'workspace_id' => $workspaceId,
                'type' => 'exchange',
                'provider_key' => $providerKey,
                'environment' => $environment,
                'display_name' => $name,
                'status' => 'active',
                'config_json' => array_merge(
                    (array) ($existing->config_json ?? []),
                    ['environment' => $environment],
                ),
            ];

            if ($apiKey && $apiSecret) {
                $attrs['secrets_encrypted'] = $manager->encryptSecrets(['api_key' => $apiKey, 'api_secret' => $apiSecret]);
            } elseif ($existing) {
                $attrs['secrets_encrypted'] = $existing->secrets_encrypted; // preserve on partial update
            }

            $integration = Integration::updateOrCreate(
                ['id' => $existing->id ?? (string) Str::uuid()],
                $attrs,
            );

            // Re-baseline the drawdown guard against the real account equity so the
            // first live order isn't blocked by a stale seeded baseline.
            try {
                $providerImpl = app(LiveTradeProviderRegistry::class)->for($providerKey);
                if (($providerImpl->capabilities()['live_supported'] ?? false) === true && $integration->secrets_encrypted) {
                    $available = (float) ($providerImpl->availableBalance($integration, 'USDT')['available'] ?? 0);
                    if ($available > 0) {
                        app(RiskGuardService::class)->rebaseline($workspaceId, $available);
                    }
                }
            } catch (\Throwable) {
                // Balance probe is optional.
            }
        } catch (\Throwable $e) {
            Log::warning('Connection->Integration mirror failed (connection still saved): ' . $e->getMessage(), [
                'workspace_id' => $workspaceId,
                'provider' => $provider,
            ]);
        }
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
                // Test against the SAME endpoint the bot uses — Binance USDⓈ-M
                // futures (testnet.binancefuture.com for testnet). The old spot
                // client (testnet.binance.vision) rejects futures-testnet keys
                // with -2015, even though the key is valid for futures.
                $environment = $connection->is_testnet ? 'testnet' : 'live';
                $integration = Integration::where('workspace_id', $connection->workspace_id)
                    ->where('provider_key', 'binance')
                    ->where('environment', $environment)
                    ->first();

                if (! $integration || ! $integration->secrets_encrypted) {
                    throw new \RuntimeException('No mirrored Binance integration found — re-save this connection, then test again.');
                }

                $futures = app(LiveTradeProviderRegistry::class)->for('binance');
                $balance = $futures->availableBalance($integration, 'USDT'); // signed call validates the key
                $balances = [$balance];
                $probe = [
                    'market' => 'futures',
                    'environment' => $environment,
                    'asset' => $balance['asset'] ?? 'USDT',
                    'available' => $balance['available'] ?? 0,
                ];
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

    /**
     * Pull real balances from the exchange for this connection. Previously
     * the frontend's useSyncConnection() called a hand-written mock in
     * client.ts that always returned fixed fake numbers (0.5 BTC, 2.3 ETH,
     * 10000 USDT) regardless of the connection's actual holdings — on a
     * real-money platform, silently showing fabricated balances as if they
     * were synced from the exchange is dangerous. This is read-only: it
     * reports what the exchange says, it does not credit any internal
     * ledger.
     */
    public function sync(ExchangeConnection $connection, ExchangeClientFactory $clients)
    {
        $provider = strtolower((string) ($connection->exchange ?: $connection->provider));

        if ($provider !== 'binance') {
            return response()->json([
                'success' => false,
                'message' => "Balance sync is not yet supported for {$provider} connections.",
            ], 422);
        }

        try {
            $raw = $clients->makeFromConnection($connection)->fetchBalances();
            $balances = array_map(static function (array $b): array {
                $available = (float) ($b['free'] ?? 0);
                $locked = (float) ($b['locked'] ?? 0);
                return [
                    'currency' => (string) ($b['asset'] ?? ''),
                    'available' => $available,
                    'locked' => $locked,
                    'total' => $available + $locked,
                ];
            }, $raw);

            $connection->update(['last_synced_at' => now()]);

            $this->logAudit(request(), 'connection.sync', $connection->workspace_id, [
                'connection_id' => $connection->id,
                'result' => 'ok',
                'balance_count' => count($balances),
            ]);

            return response()->json([
                'success' => true,
                'balances' => $balances,
                'syncedAt' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $this->logAudit(request(), 'connection.sync', $connection->workspace_id, [
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
