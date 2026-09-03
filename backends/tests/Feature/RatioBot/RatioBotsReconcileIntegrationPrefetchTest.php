<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Models\Integration;
use App\Models\RatioBotConfig;
use App\Models\RatioBotReconciliationLog;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RatioBotsReconcileCommand::reconcileFutures() used to look up the active
 * Binance Integration once PER config (up to 500 configs, every 5 minutes).
 * This proves multiple futures configs in the same workspace now share one
 * batched lookup, and that each still resolves to the correct integration.
 */
class RatioBotsReconcileIntegrationPrefetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_futures_configs_in_one_workspace_share_a_single_integration_query(): void
    {
        $workspace = Workspace::factory()->create();
        $integration = Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance',
            'status' => 'active',
        ]);

        $configA = $this->makeFuturesBot($workspace->id, 'BTCUSDT', 'Reconcile Bot A');
        $configB = $this->makeFuturesBot($workspace->id, 'ETHUSDT', 'Reconcile Bot B');
        $configC = $this->makeFuturesBot($workspace->id, 'SOLUSDT', 'Reconcile Bot C');

        $this->mock(FuturesPositionSyncService::class, function ($mock) use ($integration) {
            $mock->shouldReceive('sync')
                ->times(3)
                ->withArgs(fn ($workspaceId, Integration $arg) => $arg->id === $integration->id)
                ->andReturn(['synced' => true]);
        });
        $this->mock(FuturesConfigService::class, function ($mock) {
            $mock->shouldReceive('effective')->andReturn([]);
        });

        DB::enableQueryLog();
        $this->artisan('ratio-bots:reconcile')->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $integrationQueries = collect($queries)->filter(
            fn (array $q) => str_contains($q['query'], '"integrations"') || str_contains($q['query'], 'integrations')
        );

        // One batched lookup for all 3 configs, not one per config.
        $this->assertLessThanOrEqual(1, $integrationQueries->count());

        foreach ([$configA, $configB, $configC] as $config) {
            $log = RatioBotReconciliationLog::where('ratio_bot_config_id', $config->id)->latest('occurred_at')->first();
            $this->assertNotNull($log);
            $this->assertSame('ok', $log->status);
        }
    }

    public function test_a_futures_config_with_no_active_integration_fails_gracefully_without_breaking_others(): void
    {
        $noIntegrationWorkspace = Workspace::factory()->create();
        $configWithout = $this->makeFuturesBot($noIntegrationWorkspace->id, 'BTCUSDT', 'No Integration Bot');

        $withWorkspace = Workspace::factory()->create();
        $integration = Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $withWorkspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance',
            'status' => 'active',
        ]);
        $configWith = $this->makeFuturesBot($withWorkspace->id, 'ETHUSDT', 'Has Integration Bot');

        $this->mock(FuturesPositionSyncService::class, function ($mock) use ($integration) {
            $mock->shouldReceive('sync')
                ->once()
                ->withArgs(fn ($workspaceId, Integration $arg) => $arg->id === $integration->id)
                ->andReturn(['synced' => true]);
        });
        $this->mock(FuturesConfigService::class, function ($mock) {
            $mock->shouldReceive('effective')->andReturn([]);
        });

        $this->artisan('ratio-bots:reconcile')->assertSuccessful();

        $failedLog = RatioBotReconciliationLog::where('ratio_bot_config_id', $configWithout->id)->latest('occurred_at')->first();
        $this->assertSame('failed', $failedLog->status);

        $okLog = RatioBotReconciliationLog::where('ratio_bot_config_id', $configWith->id)->latest('occurred_at')->first();
        $this->assertSame('ok', $okLog->status);
    }

    public function test_disabled_bots_are_skipped_and_do_not_generate_reconciliation_noise(): void
    {
        $workspace = Workspace::factory()->create();
        Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance',
            'status' => 'active',
        ]);

        $enabledConfig = $this->makeFuturesBot($workspace->id, 'BTCUSDT', 'Enabled Bot');
        $disabledConfig = $this->makeFuturesBot($workspace->id, 'ETHUSDT', 'Disabled Bot', enabled: false);

        $this->mock(FuturesPositionSyncService::class, function ($mock) {
            $mock->shouldReceive('sync')->once()->andReturn(['synced' => true]);
        });
        $this->mock(FuturesConfigService::class, function ($mock) {
            $mock->shouldReceive('effective')->andReturn([]);
        });

        $this->artisan('ratio-bots:reconcile')->assertSuccessful();

        $this->assertNotNull(RatioBotReconciliationLog::where('ratio_bot_config_id', $enabledConfig->id)->first());
        $this->assertNull(RatioBotReconciliationLog::where('ratio_bot_config_id', $disabledConfig->id)->first());
    }

    public function test_bot_option_reconciles_a_specific_disabled_bot_for_manual_troubleshooting(): void
    {
        $workspace = Workspace::factory()->create();
        Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance',
            'status' => 'active',
        ]);

        $disabledConfig = $this->makeFuturesBot($workspace->id, 'BTCUSDT', 'Disabled Bot', enabled: false);

        $this->mock(FuturesPositionSyncService::class, function ($mock) {
            $mock->shouldReceive('sync')->once()->andReturn(['synced' => true]);
        });
        $this->mock(FuturesConfigService::class, function ($mock) {
            $mock->shouldReceive('effective')->andReturn([]);
        });

        $this->artisan('ratio-bots:reconcile', ['--bot' => $disabledConfig->id])->assertSuccessful();

        $this->assertNotNull(RatioBotReconciliationLog::where('ratio_bot_config_id', $disabledConfig->id)->first());
    }

    private function makeFuturesBot(string $workspaceId, string $symbol, string $name, bool $enabled = true): RatioBotConfig
    {
        return RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'name' => $name,
            'market_type' => 'futures',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'execution_mode' => 'sandbox',
            'enabled' => $enabled,
        ]);
    }
}
