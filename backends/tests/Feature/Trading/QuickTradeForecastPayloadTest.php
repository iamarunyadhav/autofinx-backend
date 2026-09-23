<?php

namespace Tests\Feature\Trading;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end proof that /quick-trade/forecast actually returns the Part A/B/C
 * payload the UI reads — not just that the services can produce it.
 */
class QuickTradeForecastPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
        config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
        config()->set('robot_trade.live_min_edge_cost_multiplier', 2.0);
        config()->set('quick_trade.fee_model', 'taker');
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(int $count = 40, int $timeframeSeconds = 300): array
    {
        $now = CarbonImmutable::now();
        $rows = [];
        $price = 2000.0;
        for ($i = $count; $i >= 1; $i--) {
            $open = $price;
            $price += 3.0;
            $rows[] = [
                'timestamp' => $now->subSeconds($i * $timeframeSeconds)->getTimestampMs(),
                'open' => $open, 'high' => $price + 2, 'low' => $open - 2,
                'close' => $price, 'volume' => 900.0,
            ];
        }

        return $rows;
    }

    public function test_the_endpoint_returns_the_direction_and_trade_decision_split(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/quick-trade/forecast', [
                'candles' => $this->candles(),
                'timeframe' => '5m',
                'expiry_seconds' => 3600,
                'stake_amount' => 50,
                'symbol' => 'BTCUSDT',
                'exchange' => 'binance_futures',
            ])->assertOk();

        $forecast = $response->json('forecast');

        $this->assertFalse($forecast['direction']['fee_aware']);
        $this->assertTrue($forecast['trade_decision']['fee_aware']);
        $this->assertEqualsWithDelta(0.28, $forecast['trade_decision']['required_move_pct'], 1e-6);
        $this->assertEqualsWithDelta(0.14, $forecast['trade_decision']['cost_pct'], 1e-6);
        $this->assertArrayHasKey('max_achievable_move_pct', $forecast['trade_decision']);

        // Part B: the forming bar is excluded and the close time is real.
        $this->assertFalse($forecast['input_candles_include_forming_bar']);
        $this->assertNotNull($forecast['decision_candle_close_at']);
        $this->assertTrue($forecast['candle_timing']['timestamps_available']);

        // Part C: the cost model is published so the UI never hardcodes it.
        $this->assertSame('taker', $forecast['cost_model']['fee_model']);
        $this->assertSame('config', $forecast['cost_model']['fee_rate_source']);
    }

    public function test_the_endpoint_returns_per_duration_availability_when_asked(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/quick-trade/forecast', [
                'candles' => $this->candles(),
                'timeframe' => '5m',
                'expiry_seconds' => 3600,
                'stake_amount' => 50,
                'symbol' => 'BTCUSDT',
                'exchange' => 'binance_futures',
                'mode' => 'live',
                'durations' => [60, 300, 1800, 3600],
            ])->assertOk();

        $forecast = $response->json('forecast');
        $availability = collect($forecast['duration_availability'])->keyBy('expiry_seconds');

        $this->assertCount(4, $availability);
        // Short live expiries are hidden by default (allow_short_live_expiry=false).
        $this->assertFalse($availability[60]['available']);
        $this->assertNotNull($availability[60]['reason']);
        // Each row carries the real numbers the UI renders.
        foreach ($availability as $row) {
            $this->assertArrayHasKey('max_achievable_move_pct', $row);
            $this->assertArrayHasKey('required_move_pct', $row);
            $this->assertArrayHasKey('horizon_candles', $row);
        }
        $this->assertArrayHasKey('shortest_viable_expiry_seconds', $forecast);
    }

    public function test_net_edge_carries_the_binding_constraint_hint(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/quick-trade/forecast', [
                'candles' => $this->candles(40, 60),
                'timeframe' => '1m',
                'expiry_seconds' => 60,
                'stake_amount' => 20,
                'symbol' => 'BTCUSDT',
                'exchange' => 'binance_futures',
            ])->assertOk();

        $netEdge = $response->json('net_edge');

        $this->assertContains($netEdge['binding_constraint'], ['duration', 'signal', 'stake', 'none']);
        $this->assertNotEmpty($netEdge['binding_constraint_hint']);
        $this->assertArrayHasKey('max_achievable_move_pct', $netEdge);
        if ($netEdge['binding_constraint'] === 'duration') {
            // The misleading "bigger stake" advice must never appear for a
            // percentage-gate failure (finding F12).
            $this->assertStringContainsString('bigger stake cannot help', $netEdge['binding_constraint_hint']);
        }
    }

    public function test_the_decision_is_cached_within_a_candle_and_reported_as_reused(): void
    {
        $user = User::factory()->create();
        $payload = [
            'candles' => $this->candles(),
            'timeframe' => '5m',
            'expiry_seconds' => 3600,
            'stake_amount' => 50,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance_futures',
        ];

        $first = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload)->assertOk();
        $this->assertSame('computed', $first->json('forecast.decision_cache.status'));

        $second = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload)->assertOk();
        $this->assertSame('reused', $second->json('forecast.decision_cache.status'));

        // The decision itself is stable across the two calls within the candle.
        $this->assertSame($first->json('forecast.decision_trend'), $second->json('forecast.decision_trend'));
        $this->assertSame($first->json('forecast.direction.value'), $second->json('forecast.direction.value'));
    }

    public function test_a_new_candle_close_recomputes_the_decision_instead_of_reusing_it(): void
    {
        $user = User::factory()->create();
        $payload = [
            'candles' => $this->candles(),
            'timeframe' => '5m',
            'expiry_seconds' => 3600,
            'stake_amount' => 50,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance_futures',
        ];

        $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload)->assertOk();

        // A fresh candle has since closed -> a different anchor -> recomputed.
        $this->travel(6)->minutes();
        $next = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', array_merge($payload, [
            'candles' => $this->candles(),
        ]))->assertOk();

        $this->assertSame('computed', $next->json('forecast.decision_cache.status'));
    }
}
