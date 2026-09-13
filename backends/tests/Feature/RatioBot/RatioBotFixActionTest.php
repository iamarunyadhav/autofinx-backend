<?php

namespace Tests\Feature\RatioBot;

use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotFixActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
        Queue::fake();
    }

    /** @test */
    public function clear_symbol_stale_breaker_succeeds_when_the_feed_is_actually_fresh(): void
    {
        $config = $this->createConfig();
        app(OperationalBreakerService::class)->activateStaleFeedBreaker(
            (string) $config->workspace_id,
            ['reason' => 'test_setup'],
            'test',
            $config->normalizedSymbol(),
        );
        $this->mockFreshSnapshot(true);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/ratio-bots/{$config->id}/diagnostics/fix",
            ['fix_key' => 'clear_symbol_stale_breaker']
        );

        $response->assertOk();
        $this->assertFalse(app(OperationalBreakerService::class)->isStaleFeedBreakerActive(
            (string) $config->workspace_id,
            $config->normalizedSymbol(),
        ));
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_DIAGNOSTICS_FIX_APPLIED,
        ]);
    }

    /** @test */
    public function clear_symbol_stale_breaker_refuses_when_the_feed_is_not_actually_fresh(): void
    {
        // This is the most important test in this file: it's the guard
        // against the "zombie breaker" bug class recurring — a fix must
        // never blind-clear based on a stale diagnose() read.
        $config = $this->createConfig();
        app(OperationalBreakerService::class)->activateStaleFeedBreaker(
            (string) $config->workspace_id,
            ['reason' => 'test_setup'],
            'test',
            $config->normalizedSymbol(),
        );
        $this->mockFreshSnapshot(false);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/ratio-bots/{$config->id}/diagnostics/fix",
            ['fix_key' => 'clear_symbol_stale_breaker']
        );

        $response->assertStatus(409);
        $this->assertTrue(app(OperationalBreakerService::class)->isStaleFeedBreakerActive(
            (string) $config->workspace_id,
            $config->normalizedSymbol(),
        ));
        $this->assertDatabaseMissing('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_DIAGNOSTICS_FIX_APPLIED,
        ]);
    }

    /** @test */
    public function unknown_fix_key_is_rejected(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/ratio-bots/{$config->id}/diagnostics/fix",
            ['fix_key' => 'something_not_supported']
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_DIAGNOSTICS_FIX_APPLIED,
        ]);
    }

    /** @test */
    public function fix_route_requires_authentication(): void
    {
        $config = $this->createConfig();

        $response = $this->postJson(
            "/api/v1/ratio-bots/{$config->id}/diagnostics/fix",
            ['fix_key' => 'clear_symbol_stale_breaker']
        );

        $response->assertUnauthorized();
    }

    private function mockFreshSnapshot(bool $isFresh): void
    {
        $this->mock(MarketSnapshotService::class, function ($mock) use ($isFresh) {
            $mock->shouldReceive('forRatioBot')->andReturn([
                'is_fresh' => $isFresh,
                'age_ms' => $isFresh ? 500 : 999_999,
            ]);
        });
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
            'enabled' => true,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

        return $config->refresh()->load('state');
    }
}
