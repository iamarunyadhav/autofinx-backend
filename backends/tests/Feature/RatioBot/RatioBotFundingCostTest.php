<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotFundingCostService;
use App\Models\Integration;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatioBotFundingCostTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->integration = Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance Futures Test',
            'status' => 'active',
            'config_json' => ['environment' => 'testnet'],
            'secrets_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
            ])),
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function funding_cost_accumulates_once_per_eight_hour_boundary_from_the_parsed_funding_rate(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 08:15:00'));
        $config = $this->createConfig();
        $config->state?->update(['last_position_qty' => 2.0, 'last_position_side' => 'long']);

        // A single Http::fake() reading a mutable reference -- calling
        // Http::fake() again mid-test with a second unconditional closure
        // does NOT replace the first one (Laravel appends stub callbacks
        // and matches in registration order), so varying the response
        // across "calls" must happen through one fake, not repeated fakes.
        $fundingRate = '0.0001';
        Http::fake(function () use (&$fundingRate) {
            return Http::response(['markPrice' => '50000.00', 'lastFundingRate' => $fundingRate], 200);
        });

        $service = app(RatioBotFundingCostService::class);
        $workspaceConfig = ['futures_force_testnet' => true];

        // First call this boundary (00:00-08:00 crossed into at 08:00): applies.
        $service->accumulate($config, $this->integration, $workspaceConfig);
        $config = $config->fresh('state');
        $expectedCost = round(2.0 * 50000.0 * 0.0001, 8);
        $this->assertSame($expectedCost, (float) $config->state->estimated_funding_cost);
        $this->assertSame(0.0001, (float) $config->state->last_funding_rate);
        $this->assertNotNull($config->state->last_funding_applied_at);

        // Second call still within the same 8h boundary: rate refreshes,
        // but cost does not accumulate again.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 09:00:00'));
        $fundingRate = '0.0002';
        $service->accumulate($config, $this->integration, $workspaceConfig);
        Http::assertSentCount(2);
        $config = $config->fresh('state');
        $this->assertSame($expectedCost, (float) $config->state->estimated_funding_cost);
        $this->assertSame(0.0002, (float) $config->state->last_funding_rate);

        // Crosses into the next boundary (16:00): applies again.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 16:05:00'));
        $service->accumulate($config, $this->integration, $workspaceConfig);
        $config = $config->fresh('state');
        $expectedCostAfterSecondBoundary = round($expectedCost + (2.0 * 50000.0 * 0.0002), 8);
        $this->assertSame($expectedCostAfterSecondBoundary, (float) $config->state->estimated_funding_cost);
    }

    /** @test */
    public function nothing_accumulates_when_there_is_no_open_position(): void
    {
        $config = $this->createConfig();

        Http::fake(function () {
            $this->fail('No exchange call should happen when there is no open position.');
        });

        app(RatioBotFundingCostService::class)->accumulate($config, $this->integration, ['futures_force_testnet' => true]);

        $config->state?->refresh();
        $this->assertSame(0.0, (float) $config->state->estimated_funding_cost);
    }

    /** @test */
    public function resetting_for_a_closed_position_clears_the_tracker(): void
    {
        $config = $this->createConfig();
        $config->state?->update([
            'estimated_funding_cost' => 12.5,
            'last_funding_rate' => 0.0003,
            'last_funding_applied_at' => now(),
        ]);

        app(RatioBotFundingCostService::class)->resetForClosedPosition($config);

        $config->state?->refresh();
        $this->assertSame(0.0, (float) $config->state->estimated_funding_cost);
        $this->assertNull($config->state->last_funding_rate);
        $this->assertNull($config->state->last_funding_applied_at);
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'live',
            'enabled' => true,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }
}
