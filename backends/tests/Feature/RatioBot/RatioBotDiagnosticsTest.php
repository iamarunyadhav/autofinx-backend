<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotDiagnosticsTest extends TestCase
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
    public function diagnostics_endpoint_reports_pass_when_feed_is_fresh_and_no_breaker_is_active(): void
    {
        $config = $this->createConfig();
        $config->state->update(['last_price_timestamp' => now()->subSeconds(5), 'last_price_source' => 'polling']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/diagnostics");

        // Assert on the two checks this test is actually about, not the
        // blanket `overall` field — a bare sandbox fixture with no real
        // exchange connection can legitimately `warn` on unrelated checks
        // (e.g. startup_checklist's exchange-connection sub-item), and that
        // must not be conflated with "feed/breaker are unhealthy".
        $response->assertOk();
        $checks = collect($response->json('checks'))->keyBy('key');
        $this->assertSame('pass', $checks['market_feed']['status']);
        $this->assertSame('pass', $checks['symbol_breaker']['status']);
        $this->assertFalse($checks['symbol_breaker']['fixable']);
    }

    /** @test */
    public function diagnostics_endpoint_reports_fail_when_feed_is_stale(): void
    {
        $config = $this->createConfig();
        $config->state->update(['last_price_timestamp' => now()->subDay()]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/diagnostics");

        $response->assertOk()->assertJsonPath('overall', 'fail');
        $checks = collect($response->json('checks'))->keyBy('key');
        $this->assertSame('fail', $checks['market_feed']['status']);
        $this->assertFalse($checks['market_feed']['fixable']);
    }

    /** @test */
    public function diagnostics_endpoint_flags_a_zombie_breaker_as_fixable_when_the_feed_is_actually_fresh(): void
    {
        $config = $this->createConfig();
        $config->state->update(['last_price_timestamp' => now()->subSeconds(5), 'last_price_source' => 'polling']);
        app(OperationalBreakerService::class)->activateStaleFeedBreaker(
            (string) $config->workspace_id,
            ['reason' => 'test_setup'],
            'test',
            $config->normalizedSymbol(),
        );

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/diagnostics");

        $response->assertOk()->assertJsonPath('overall', 'fail');
        $checks = collect($response->json('checks'))->keyBy('key');
        $this->assertSame('fail', $checks['symbol_breaker']['status']);
        $this->assertTrue($checks['symbol_breaker']['fixable']);
        $this->assertSame('clear_symbol_stale_breaker', $checks['symbol_breaker']['fix_key']);
    }

    /** @test */
    public function diagnostics_endpoint_does_not_offer_a_fix_when_the_breaker_is_active_and_the_feed_is_genuinely_stale(): void
    {
        $config = $this->createConfig();
        $config->state->update(['last_price_timestamp' => now()->subDay()]);
        app(OperationalBreakerService::class)->activateStaleFeedBreaker(
            (string) $config->workspace_id,
            ['reason' => 'test_setup'],
            'test',
            $config->normalizedSymbol(),
        );

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/diagnostics");

        $checks = collect($response->json('checks'))->keyBy('key');
        $this->assertSame('fail', $checks['symbol_breaker']['status']);
        $this->assertFalse($checks['symbol_breaker']['fixable']);
        $this->assertNull($checks['symbol_breaker']['fix_key']);
    }

    /** @test */
    public function diagnostics_endpoint_surfaces_recent_events_and_sibling_bots_on_the_same_symbol(): void
    {
        $config = $this->createConfig(['name' => 'Bot A']);
        $config->state->update(['last_price_timestamp' => now()->subSeconds(5)]);
        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_STARTED,
            'context' => ['reason' => 'operator_started'],
            'occurred_at' => now(),
        ]);
        $this->createConfig(['name' => 'Bot B', 'symbol' => $config->symbol]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/diagnostics");

        $checks = collect($response->json('checks'))->keyBy('key');
        $this->assertStringContainsString('started', $checks['recent_events']['detail']);
        $this->assertStringContainsString('Bot B', $checks['sibling_bots_same_symbol']['detail']);
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
