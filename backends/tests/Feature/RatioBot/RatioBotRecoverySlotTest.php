<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRecoverySlotService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotRecoverySlotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $this->workspace = Workspace::factory()->create();
    }

    /** @test */
    public function skim_happens_only_when_realized_profit_is_positive(): void
    {
        $config = $this->makeConfig([
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => 10,
        ]);
        $service = app(RatioBotRecoverySlotService::class);

        $service->skim($config, -5.0, 'quote');
        $slot = $service->slotFor($config);
        $this->assertEqualsWithDelta(0.0, (float) $slot->quote_balance, 0.00000001);

        $service->skim($config, 100.0, 'quote');
        $slot = $slot->fresh();
        $this->assertEqualsWithDelta(10.0, (float) $slot->quote_balance, 0.00000001);
    }

    /** @test */
    public function skim_uses_bot_override_or_global_default_allocation(): void
    {
        config()->set('autofinx.recovery_slot.default_allocation_pct', 12.0);
        $service = app(RatioBotRecoverySlotService::class);

        $defaultBot = $this->makeConfig([
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => null,
        ]);
        $overrideBot = $this->makeConfig([
            'symbol' => 'ETHUSDT',
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => 20,
        ]);

        $service->skim($defaultBot, 100.0, 'quote');
        $service->skim($overrideBot, 100.0, 'quote');

        $this->assertEqualsWithDelta(12.0, (float) $service->slotFor($defaultBot)->quote_balance, 0.00000001);
        $this->assertEqualsWithDelta(20.0, (float) $service->slotFor($overrideBot)->quote_balance, 0.00000001);
    }

    /** @test */
    public function skim_stops_at_cap_and_resumes_after_balance_drops_below_cap(): void
    {
        $config = $this->makeConfig([
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => 50,
            'recovery_slot_target_pct' => 10,
            'base_quote_amount' => 100,
        ]);
        $service = app(RatioBotRecoverySlotService::class);

        $service->skim($config, 50.0, 'quote');
        $firstBalance = (float) $service->slotFor($config)->quote_balance;
        $this->assertTrue($firstBalance > 10.0);

        $service->skim($config, 50.0, 'quote');
        $this->assertEqualsWithDelta($firstBalance, (float) $service->slotFor($config)->quote_balance, 0.00000001);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_RECOVERY_SLOT_CAP_REACHED,
        ]);

        $slot = $service->slotFor($config);
        $slot->update(['quote_balance' => 0]);
        $service->skim($config, 10.0, 'quote');

        $this->assertEqualsWithDelta(5.0, (float) $service->slotFor($config)->quote_balance, 0.00000001);
    }

    /** @test */
    public function two_bots_on_same_symbol_do_not_share_slot_balances(): void
    {
        $botA = $this->makeConfig([
            'name' => 'A',
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => 10,
        ]);
        $botB = $this->makeConfig([
            'name' => 'B',
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => 25,
        ]);
        $service = app(RatioBotRecoverySlotService::class);

        $service->skim($botA, 100.0, 'quote');

        $this->assertEqualsWithDelta(10.0, (float) $service->slotFor($botA)->quote_balance, 0.00000001);
        $this->assertEqualsWithDelta(0.0, (float) $service->slotFor($botB)->quote_balance, 0.00000001);
    }

    /** @test */
    public function recovery_slot_endpoint_returns_bot_scoped_payload(): void
    {
        $config = $this->makeConfig([
            'recovery_slot_enabled' => true,
            'recovery_slot_allocation_pct' => 10,
            'recovery_slot_target_pct' => 25,
        ]);

        app(RatioBotRecoverySlotService::class)->skim($config, 100.0, 'quote');

        $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$config->id}/recovery-slot")
            ->assertOk()
            ->assertJsonPath('bot_id', $config->id)
            ->assertJsonPath('symbol', 'BTCUSDT')
            ->assertJsonPath('allocation_pct', 10)
            ->assertJsonPath('target_pct', 25);
    }

    private function makeConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'name' => 'Recovery Bot',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'base_quote_amount' => 100.0,
            'sell_threshold_ratio' => 0.5,
            'buy_threshold_ratio' => 0.25,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE, 'last_seen_price' => 50000]
        );

        return $config->fresh('state');
    }
}
