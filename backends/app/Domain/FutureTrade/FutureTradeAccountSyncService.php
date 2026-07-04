<?php

namespace App\Domain\FutureTrade;

use App\Models\FutureTradeBotConfig;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class FutureTradeAccountSyncService
{
    public const STATE_KEY = 'autofinx_ft:account_sync:%s:state';
    private const HEARTBEAT_FILE = 'framework/autofinx-account-sync-heartbeat.json';

    public function __construct(private readonly FutureTradeFuturesCredentialResolver $credentials)
    {
    }

    public function syncWorkspace(string $workspaceId): array
    {
        $configs = FutureTradeBotConfig::query()
            ->where('workspace_id', $workspaceId)
            ->where('enable_live_trading', true)
            ->orderBy('slot_index')
            ->get();

        return $this->syncConfigs($configs->all());
    }

    public function syncAll(): array
    {
        $configs = FutureTradeBotConfig::query()
            ->where('enable_live_trading', true)
            ->orderBy('workspace_id')
            ->orderBy('slot_index')
            ->get();

        return $this->syncConfigs($configs->all());
    }

    public function snapshot(string $workspaceId): array
    {
        $state = (array) Cache::get(sprintf(self::STATE_KEY, $workspaceId), []);
        if ($state !== []) {
            return $state;
        }

        $fileState = $this->readHeartbeatFile($workspaceId);
        if ($fileState !== []) {
            return $fileState;
        }

        return [
            'status' => 'UNKNOWN',
            'last_sync_at' => null,
            'age_seconds' => null,
        ];
    }

    private function syncConfigs(array $configs): array
    {
        $reports = [];
        $seenWorkspaces = [];

        foreach ($configs as $config) {
            if (! $config instanceof FutureTradeBotConfig) {
                continue;
            }
            $workspaceId = (string) $config->workspace_id;
            if (isset($seenWorkspaces[$workspaceId])) {
                continue;
            }
            $seenWorkspaces[$workspaceId] = true;
            $reports[] = $this->syncConfig($config);
        }

        return [
            'ok' => collect($reports)->every(fn (array $row) => ($row['ok'] ?? false) === true),
            'count' => count($reports),
            'reports' => $reports,
        ];
    }

    private function syncConfig(FutureTradeBotConfig $config): array
    {
        $workspaceId = (string) $config->workspace_id;
        $resolved = $this->credentials->resolveForConfig($config);
        if (! ($resolved['configured'] ?? false)) {
            return $this->remember($workspaceId, [
                'ok' => false,
                'status' => 'NOT_CONFIGURED',
                'workspace_id' => $workspaceId,
                'config_id' => (string) $config->id,
                'credential_source' => (string) ($resolved['source'] ?? 'none'),
                'reason' => (string) ($resolved['reason'] ?? 'credentials_missing'),
            ]);
        }

        $client = $this->credentials->clientForConfig($config, 'account-sync');
        if (! $client) {
            return $this->remember($workspaceId, [
                'ok' => false,
                'status' => 'NOT_CONFIGURED',
                'workspace_id' => $workspaceId,
                'config_id' => (string) $config->id,
                'credential_source' => (string) ($resolved['source'] ?? 'none'),
                'reason' => 'client_unavailable',
            ]);
        }

        try {
            $account = $client->fetchAccountInfo();
            $positions = collect((array) $client->fetchPositionRisk())
                ->filter(fn (array $row) => abs((float) ($row['positionAmt'] ?? 0)) > 0.0)
                ->values();
            $asset = collect((array) ($account['assets'] ?? []))
                ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === 'USDT');

            $walletBalance = isset($account['totalWalletBalance'])
                ? (float) $account['totalWalletBalance']
                : (float) ($asset['walletBalance'] ?? 0);
            $availableBalance = isset($account['availableBalance'])
                ? (float) $account['availableBalance']
                : (float) ($asset['availableBalance'] ?? $walletBalance);
            $marginBalance = isset($account['totalMarginBalance'])
                ? (float) $account['totalMarginBalance']
                : (float) ($asset['marginBalance'] ?? $walletBalance);
            $unrealizedPnl = isset($account['totalUnrealizedProfit'])
                ? (float) $account['totalUnrealizedProfit']
                : (float) $positions->sum(fn (array $row) => (float) ($row['unRealizedProfit'] ?? 0));

            $wallet = WalletAccount::updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'currency' => 'USDT',
                    'type' => 'exchange',
                ],
                [
                    'id' => WalletAccount::query()
                        ->where('workspace_id', $workspaceId)
                        ->where('currency', 'USDT')
                        ->where('type', 'exchange')
                        ->value('id') ?: (string) Str::uuid(),
                    'name' => 'BINANCE USDT Futures Wallet',
                    'status' => 'active',
                    'wallet_type' => 'provider',
                    'balance' => $walletBalance,
                    'available' => $availableBalance,
                    'locked_balance' => max(0, $walletBalance - $availableBalance),
                    'metadata' => [
                        'provider' => 'binance',
                        'market_type' => 'futures',
                        'mode' => (string) ($resolved['mode'] ?? 'live'),
                        'credential_source' => (string) ($resolved['source'] ?? 'unknown'),
                        'integration_id' => $resolved['integration_id'] ?? null,
                        'last_provider_sync_at' => now()->toIso8601String(),
                        'margin_balance' => $marginBalance,
                        'unrealized_pnl' => $unrealizedPnl,
                        'open_position_count' => $positions->count(),
                        'open_symbols' => $positions->pluck('symbol')->map(fn ($s) => strtoupper((string) $s))->all(),
                    ],
                ]
            );

            $summary = [
                'ok' => true,
                'status' => 'OK',
                'workspace_id' => $workspaceId,
                'config_id' => (string) $config->id,
                'wallet_account_id' => (string) $wallet->id,
                'credential_source' => (string) ($resolved['source'] ?? 'unknown'),
                'mode' => (string) ($resolved['mode'] ?? 'live'),
                'wallet_balance' => $walletBalance,
                'available_balance' => $availableBalance,
                'margin_balance' => $marginBalance,
                'unrealized_pnl' => $unrealizedPnl,
                'open_position_count' => $positions->count(),
                'open_symbols' => $positions->pluck('symbol')->map(fn ($s) => strtoupper((string) $s))->all(),
            ];

            Cache::put(
                'future_trade_account_summary:'.$workspaceId.':'.($config->executionMode()),
                $account,
                30
            );
            Cache::put(
                'future_trade_account_summary:'.$workspaceId.':'.($config->executionMode()).':meta',
                ['synced_at' => now()->toIso8601String(), 'source' => 'future-trade:account-sync'],
                300
            );

            return $this->remember($workspaceId, $summary);
        } catch (Throwable $e) {
            return $this->remember($workspaceId, [
                'ok' => false,
                'status' => 'ERROR',
                'workspace_id' => $workspaceId,
                'config_id' => (string) $config->id,
                'credential_source' => (string) ($resolved['source'] ?? 'unknown'),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function remember(string $workspaceId, array $state): array
    {
        $state['last_sync_at'] = now()->toIso8601String();
        $state['age_seconds'] = 0;
        Cache::put(sprintf(self::STATE_KEY, $workspaceId), $state, 900);
        $this->writeHeartbeatFile($workspaceId, $state);

        return $state;
    }

    private function writeHeartbeatFile(string $workspaceId, array $state): void
    {
        try {
            @file_put_contents(storage_path(self::HEARTBEAT_FILE), json_encode(array_merge($state, [
                'workspace_id' => $workspaceId,
                'server_now' => now()->toIso8601String(),
                'sync_epoch' => time(),
            ]), JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // Runtime-master fallback only.
        }
    }

    private function readHeartbeatFile(string $workspaceId): array
    {
        try {
            $path = storage_path(self::HEARTBEAT_FILE);
            if (! is_file($path)) {
                return [];
            }
            $payload = json_decode((string) file_get_contents($path), true);
            if (! is_array($payload)) {
                return [];
            }
            if ((string) ($payload['workspace_id'] ?? '') !== $workspaceId) {
                return [];
            }

            return $payload;
        } catch (Throwable) {
            return [];
        }
    }
}
