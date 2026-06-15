<?php

namespace App\Console\Commands;

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Settings\SettingsService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Models\Integration;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Wires a Binance Futures **testnet** integration for a workspace and flips the
 * workspace into live mode so the Candle Direction Bot can place real (testnet)
 * orders. Credentials are read from config/services.php (env-backed), never
 * passed on the command line.
 *
 *   php artisan trading:connect-binance-testnet {workspace?} {--live} {--dry-run}
 */
class ConnectBinanceTestnetCommand extends Command
{
    protected $signature = 'trading:connect-binance-testnet
        {workspace? : Workspace id (defaults to the first workspace)}
        {--live : Also flip workspace trading settings to live mode}
        {--dry-run : Validate credentials/connectivity without persisting changes}';

    protected $description = 'Connect a Binance Futures testnet integration and (optionally) enable live mode for a workspace.';

    public function handle(
        IntegrationManager $integrations,
        SettingsService $settings,
        TradingModuleSettingsService $tradingSettings,
        \App\Support\RiskGuardService $riskGuard,
    ): int {
        $workspace = $this->resolveWorkspace();
        if (! $workspace) {
            $this->error('No workspace found. Seed the database or pass a workspace id.');

            return self::FAILURE;
        }
        $this->info("Workspace: {$workspace->id} ({$workspace->name})");

        $apiKey = (string) config('services.binance.futures_testnet_api_key', '');
        $apiSecret = (string) config('services.binance.futures_testnet_api_secret', '');
        if ($apiKey === '' || $apiSecret === '') {
            $this->error('Missing testnet credentials. Set BINANCE_FUTURES_TESTNET_API_KEY and BINANCE_FUTURES_TESTNET_API_SECRET in .env.');

            return self::FAILURE;
        }
        $this->line('Testnet credentials loaded from config (key ...' . substr($apiKey, -6) . ').');

        if ($this->option('dry-run')) {
            $this->validateConnectivity($integrations, $workspace, $apiKey, $apiSecret);

            return self::SUCCESS;
        }

        $existing = Integration::where('workspace_id', $workspace->id)
            ->where('provider_key', 'binance')
            ->where('environment', 'testnet')
            ->first();

        $integration = $integrations->upsert([
            'workspace_id' => $workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'environment' => 'testnet',
            'display_name' => 'Binance Futures Testnet',
            'status' => 'active',
            'config_json' => ['environment' => 'testnet'],
            'secrets' => ['api_key' => $apiKey, 'api_secret' => $apiSecret],
        ], $existing?->id ?? (string) Str::uuid());

        $this->info("Integration upserted: {$integration->id}");

        $health = $integrations->health($integration);
        $this->line("Health check: {$health}");
        if ($health !== 'active') {
            $this->warn('Integration health is not active — testnet balance call failed. Check keys / network.');
        }

        if ($this->option('live')) {
            $settings->upsert([
                'trading' => [
                    'trading_mode' => 'live',
                    'live_enabled' => true,
                    'venue_environment' => 'live',
                    'preferred_provider' => 'binance',
                    'preferred_integration_id' => $integration->id,
                    'auto_trading_enabled' => true,
                ],
            ], $workspace->id, null);
            $this->info('Workspace trading settings set to LIVE (Binance testnet).');

            // Re-baseline the drawdown guard against the real live balance so the
            // first live order is not blocked by a stale seeded baseline.
            try {
                $available = (float) (app(\App\Domain\Trading\Providers\LiveTradeProviderRegistry::class)
                    ->for('binance')
                    ->availableBalance($integration, 'USDT')['available'] ?? 0);
                if ($available > 0) {
                    $riskGuard->rebaseline($workspace->id, $available);
                    $this->line("Risk drawdown baseline reset to live balance: {$available} USDT.");
                }
            } catch (\Throwable $e) {
                $this->warn('Could not re-baseline drawdown guard: ' . $e->getMessage());
            }
        } else {
            $this->line('Skipped live-mode flip (pass --live to enable). Integration is ready to select.');
        }

        $state = $tradingSettings->tradingState($workspace->id);
        $this->newLine();
        $this->line('Resulting trading state:');
        $this->table(['key', 'value'], [
            ['mode', $state['mode']],
            ['live_enabled', $state['live_enabled'] ? 'true' : 'false'],
            ['preferred_provider', $state['preferred_provider']],
            ['preferred_integration_id', $state['preferred_integration_id'] ?? '—'],
            ['venue_environment', $state['venue_environment'] ?? '—'],
            ['live_ready', $state['live_ready'] ? 'YES' : 'no'],
            ['live_ready_reason', $state['live_ready_reason'] ?? '—'],
        ]);

        return self::SUCCESS;
    }

    private function resolveWorkspace(): ?Workspace
    {
        $id = $this->argument('workspace');

        return $id
            ? Workspace::find($id)
            : Workspace::query()->orderBy('created_at')->first();
    }

    private function validateConnectivity(IntegrationManager $integrations, Workspace $workspace, string $apiKey, string $apiSecret): void
    {
        $this->line('[dry-run] Building a transient integration to test connectivity...');
        $probe = new Integration([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance Testnet (probe)',
            'status' => 'active',
            'config_json' => ['environment' => 'testnet'],
            'secrets_encrypted' => $integrations->encryptSecrets(['api_key' => $apiKey, 'api_secret' => $apiSecret]),
        ]);

        try {
            $provider = app(\App\Domain\Trading\Providers\LiveTradeProviderRegistry::class)->for('binance');
            $balance = $provider->availableBalance($probe, 'USDT');
            $this->info(sprintf(
                '[dry-run] Testnet reachable. USDT balance: %s, available: %s',
                $balance['balance'] ?? 'n/a',
                $balance['available'] ?? 'n/a'
            ));
        } catch (\Throwable $e) {
            $this->error('[dry-run] Connectivity failed: ' . $e->getMessage());
        }
    }
}
