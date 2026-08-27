<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotStartupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspace = Workspace::factory()->create();
        Queue::fake();
    }

    /** @test */
    public function sandbox_bot_checklist_shows_all_six_items_with_correct_shape(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(6, $items);
        $keys = collect($items)->pluck('key')->all();
        $this->assertEqualsCanonicalizing(
            ['exchange_connection', 'wallet_balance', 'market_feed', 'symbol_valid', 'exchange_filters', 'risk_state'],
            $keys,
        );

        // Sandbox mode: no live connection/balance/filters to check, and no
        // starting_entry configured — these should skip cleanly, not fail.
        $this->assertSame('skip', collect($items)->firstWhere('key', 'exchange_connection')['status']);
        $this->assertSame('skip', collect($items)->firstWhere('key', 'wallet_balance')['status']);
        $this->assertSame('skip', collect($items)->firstWhere('key', 'exchange_filters')['status']);
        $this->assertSame('pass', collect($items)->firstWhere('key', 'risk_state')['status']);
        // A brand-new bot has never observed a price yet -> market feed
        // legitimately isn't fresh; this is correct, not a bug.
        $this->assertSame('fail', collect($items)->firstWhere('key', 'market_feed')['status']);
        $this->assertFalse($response->json('overall_ready'));
    }

    /** @test */
    public function checklist_fails_risk_state_when_kill_switch_is_active(): void
    {
        $config = $this->createConfig(['kill_switch' => true]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $riskItem = collect($response->json('items'))->firstWhere('key', 'risk_state');
        $this->assertSame('fail', $riskItem['status']);
        $this->assertFalse($response->json('overall_ready'));
    }

    /** @test */
    public function checklist_fails_exchange_connection_for_live_bot_with_no_connection(): void
    {
        $config = $this->createConfig(['execution_mode' => 'live', 'connection_id' => null]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('key', 'exchange_connection');
        $this->assertSame('fail', $item['status']);
        $this->assertFalse($response->json('overall_ready'));
    }

    /** @test */
    public function wallet_balance_item_falls_back_to_a_real_check_when_no_starting_entry_is_configured(): void
    {
        // Live-mode bot, no starting_entry declared -> startingEntryReadiness()
        // can't say which single asset matters and used to make this item
        // always show "skip" for every such bot. It should now still run a
        // meaningful fallback check (here: workspace trading mode isn't
        // live in this test environment) instead of the old blanket
        // "no starting_entry configured" skip reason.
        $config = $this->createConfig(['execution_mode' => 'live', 'starting_entry' => null]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/startup-checklist");

        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('key', 'wallet_balance');
        $this->assertSame('skip', $item['status']);
        $this->assertStringNotContainsString('no starting_entry configured', $item['detail']);
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

        return $config->refresh()->load('state');
    }
}
