<?php

namespace Tests\Support;

use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGatewayFactory;
use App\Domain\QuickTradeManual\ManualFeatureGate;
use App\Domain\QuickTradeManual\ManualPositionGuard;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Builds a workspace wired to a FakeManualExchange with both Manual SL/TP flags ON (tests only). */
final class ManualTradeScenario
{
    public function __construct(
        public readonly User $user,
        public readonly Workspace $workspace,
        public readonly Integration $integration,
        public readonly FakeManualExchange $fake,
    ) {
    }

    public static function make(string $environment = 'testnet', bool $enableFlags = true, string $role = 'owner'): self
    {
        config()->set('quick_trade_manual.fill_query_sleep_ms', 0);
        config()->set('quick_trade_manual.enabled', $enableFlags);
        config()->set('quick_trade_manual.allow_mainnet', false);
        config()->set('quick_trade.block_live_against_decision', false);
        ManualPositionGuard::forgetTableCheck();

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

        $integration = Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance '.$environment,
            'status' => 'active',
            'config_json' => ['environment' => $environment],
            'secrets_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'test-key', 'api_secret' => 'test-secret'])),
        ]);

        foreach ([
            'trading_mode' => 'live',
            'live_enabled' => true,
            'preferred_provider' => 'binance',
            'preferred_integration_id' => $integration->id,
            'venue_environment' => 'live',
        ] as $key => $value) {
            Setting::updateOrCreate(
                ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
                ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false],
            );
        }

        if ($enableFlags) {
            app(ManualFeatureGate::class)->setWorkspaceEnabled($workspace->id, true);
        }

        $fake = new FakeManualExchange();
        $fake->env = $environment;
        app()->instance(ManualExchangeGatewayFactory::class, new class($fake) extends ManualExchangeGatewayFactory {
            public function __construct(private readonly FakeManualExchange $fake)
            {
            }

            public function make(\App\Models\Integration $integration): ManualExchangeGateway
            {
                return $this->fake;
            }
        });

        // LiveWalletService mirrors the exchange balance through the existing Binance provider.
        Http::fake(['*/fapi/v2/balance*' => Http::response([['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00']], 200)]);

        return new self($user, $workspace, $integration, $fake);
    }

    public function payload(array $over = []): array
    {
        return array_merge([
            'symbol' => 'BTCUSDT',
            'direction' => 'long',
            'stake_amount' => 100,
            'leverage' => 10,
            'sl_pct' => 1,
            'tp_pct' => null,
            'idempotency_key' => 'idem-'.Str::random(12),
            'confirm' => true,
        ], $over);
    }

    public function url(string $suffix = ''): string
    {
        return '/api/v1/workspaces/'.$this->workspace->id.'/quick-trade-manual'.$suffix;
    }
}
