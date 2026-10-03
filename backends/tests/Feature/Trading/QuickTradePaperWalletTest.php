<?php

namespace Tests\Feature\Trading;

use App\Domain\Trading\PaperWalletService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QuickTradePaperWalletTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);
    }

    private function integration(): Integration
    {
        return Integration::create([
            'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'type' => 'exchange', 'provider_key' => 'binance',
            'display_name' => 'Binance Live', 'status' => 'active', 'config_json' => ['environment' => 'live'],
            'secrets_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'k', 'api_secret' => 's'])),
        ]);
    }

    public function test_wallet_list_never_overwrites_a_paper_wallet_with_exchange_balances(): void
    {
        $integration = $this->integration();
        // Even a paper wallet that somehow carries an integration tag must not be refreshed.
        $paper = WalletAccount::create([
            'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'name' => 'Paper Wallet', 'currency' => 'USD',
            'type' => 'cash', 'wallet_type' => 'paper', 'status' => 'active', 'balance' => 9500, 'available' => 9500, 'locked_balance' => 0,
            'metadata' => ['mode' => 'paper', 'integration_id' => $integration->id],
        ]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response([['asset' => 'USD', 'balance' => '0', 'availableBalance' => '0']]);
        });

        $body = $this->getJson("/api/v1/workspaces/{$this->workspace->id}/wallet/accounts")->assertOk()->json();

        $this->assertSame(0, $calls);
        $this->assertEqualsWithDelta(9500.0, (float) collect($body)->firstWhere('id', $paper->id)['balance'], 1e-9);
        $this->assertEqualsWithDelta(9500.0, (float) $paper->refresh()->balance, 1e-9);
        $this->assertArrayNotHasKey('last_provider_sync_at', (array) $paper->metadata);
    }

    public function test_reset_creates_or_restores_the_paper_wallet_with_a_ledger_entry(): void
    {
        // No paper wallet yet -> created at the starting balance.
        $created = $this->postJson("/api/v1/workspaces/{$this->workspace->id}/quick-trade/paper-wallet/reset")->assertOk();
        $this->assertSame('paper', $created->json('wallet.wallet_type'));
        $this->assertEqualsWithDelta(10000.0, $created->json('wallet.balance'), 1e-9);

        // Lose some, then reset back to 10,000 through the ledger.
        $wallets = app(PaperWalletService::class);
        $wallets->debitStake($this->workspace->id, null, 2500, ['source' => 'test']);
        $this->assertEqualsWithDelta(7500.0, (float) WalletAccount::where('wallet_type', 'paper')->value('balance'), 1e-9);

        $this->postJson("/api/v1/workspaces/{$this->workspace->id}/quick-trade/paper-wallet/reset")
            ->assertOk()->assertJsonPath('wallet.balance', 10000);
        $entry = WalletLedgerEntry::where('type', 'paper_wallet_reset')->firstOrFail();
        $this->assertEqualsWithDelta(2500.0, (float) $entry->amount, 1e-9);
        $this->assertSame(1, WalletAccount::where('workspace_id', $this->workspace->id)->where('wallet_type', 'paper')->count());
    }

    public function test_reset_is_refused_while_a_paper_trade_is_open(): void
    {
        QuickTrade::create(['workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'side' => 'buy', 'stake_amount' => 10,
            'asset_class' => 'CRYPTO', 'mode' => 'paper', 'source' => 'manual_quick_trade', 'timeframe' => '5m', 'expiry_seconds' => 300,
            'payout_ratio' => 0.8, 'entry_price' => 100, 'status' => 'open', 'entry_at' => now(), 'expires_at' => now()->addMinutes(5)]);

        $this->postJson("/api/v1/workspaces/{$this->workspace->id}/quick-trade/paper-wallet/reset")
            ->assertStatus(422)->assertJsonPath('message', 'Close or wait for all open paper trades to settle before resetting the paper wallet.');
    }

    public function test_reset_requires_workspace_membership_and_never_touches_live_wallets(): void
    {
        $other = Workspace::factory()->create();
        $this->postJson("/api/v1/workspaces/{$other->id}/quick-trade/paper-wallet/reset")->assertForbidden();

        $live = WalletAccount::create([
            'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'name' => 'Exchange Wallet', 'currency' => 'USDT',
            'type' => 'exchange', 'wallet_type' => 'provider', 'status' => 'active', 'balance' => 582.83, 'available' => 582.83, 'locked_balance' => 0,
        ]);
        $this->postJson("/api/v1/workspaces/{$this->workspace->id}/quick-trade/paper-wallet/reset")->assertOk();
        $this->assertEqualsWithDelta(582.83, (float) $live->refresh()->balance, 1e-9);
    }
}
