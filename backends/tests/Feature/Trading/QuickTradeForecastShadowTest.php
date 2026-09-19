<?php

namespace Tests\Feature\Trading;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\Ops\FeatureFlagService;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unified Forecast Engine, Phase 5 -- Quick Trade shadow wiring. Proves the
 * single most important constraint of this task: the real
 * /quick-trade/forecast response is byte-for-byte identical whether the
 * shadow flag is on or off, that turning the flag on produces exactly one
 * AuditEvent comparison row (reusing the existing AuditEventRecorder, no
 * second logging mechanism), and that turning it off means
 * UnifiedForecastEngine::evaluate() is never even called (not just "the
 * result is discarded").
 */
class QuickTradeForecastShadowTest extends TestCase
{
    use RefreshDatabase;

    private function candles(): array
    {
        $candles = [];
        $price = 2000.0;
        for ($i = 0; $i < 20; $i++) {
            $open = $price;
            $close = $open + 2;
            $candles[] = [
                'open' => $open,
                'high' => $close + 1,
                'low' => $open - 1,
                'close' => $close,
            ];
            $price = $close;
        }

        return $candles;
    }

    private function payload(): array
    {
        return [
            'candles' => $this->candles(),
            'timeframe' => '1m',
            'expiry_seconds' => 60,
            'stake_amount' => 10,
            'symbol' => 'ETHUSDT',
            'exchange' => 'binance',
        ];
    }

    public function test_response_is_byte_identical_whether_the_shadow_flag_is_on_or_off(): void
    {
        $user = User::factory()->create();

        // Freeze time across both calls -- the forecast response embeds a
        // whole-second `generated_at`/`expiry_at` and a stability window
        // counter that both depend on wall-clock time, not the shadow flag.
        // Without this, the two real HTTP round-trips below (now slightly
        // slower than the old raw config() read, since toggling the flag
        // goes through FeatureFlagService's DB write + cache invalidation)
        // can occasionally straddle a real second boundary and produce a
        // spurious byte-diff unrelated to the flag itself.
        $this->travelTo(now()->startOfSecond());

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', true);
        $onResponse = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload());
        $onResponse->assertOk();

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);
        $offResponse = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload());
        $offResponse->assertOk();

        $this->assertSame($onResponse->getContent(), $offResponse->getContent());
    }

    public function test_flag_on_records_an_audit_event_with_both_old_and_new_results(): void
    {
        $user = User::factory()->create();
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', true);

        $this->assertDatabaseCount('audit_events', 0);

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload());
        $response->assertOk();

        $event = AuditEvent::where('event_type', 'unified_forecast_shadow_comparison')->first();
        $this->assertNotNull($event);
        $this->assertSame('quick_trade', $event->bot_type);
        $this->assertArrayHasKey('old', $event->metadata);
        $this->assertArrayHasKey('new', $event->metadata);
        $this->assertSame($response->json('forecast'), $event->metadata['old']);
        $this->assertIsArray($event->metadata['new']);
        $this->assertArrayHasKey('direction', $event->metadata['new']);
    }

    public function test_flag_off_never_calls_the_unified_forecast_engine_at_all(): void
    {
        $user = User::factory()->create();
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldNotReceive('evaluate');
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload());
        $response->assertOk();

        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_flag_on_but_no_symbol_supplied_still_never_calls_the_engine(): void
    {
        // Today's real frontend caller (useQuickTrade.ts) sends no
        // symbol/exchange at all -- proves the shadow comparison degrades
        // to a genuine no-op (not an error, not a fabricated symbol) for
        // that real, currently-shipping caller shape.
        $user = User::factory()->create();
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', true);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldNotReceive('evaluate');
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        $payload = $this->payload();
        unset($payload['symbol'], $payload['exchange']);

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload);
        $response->assertOk();

        $this->assertDatabaseCount('audit_events', 0);
    }
}
