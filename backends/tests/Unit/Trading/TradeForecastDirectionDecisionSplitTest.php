<?php

namespace Tests\Unit\Trading;

use App\Domain\Trading\CandleCloseResolver;
use App\Domain\Trading\TradeForecastService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Part A1 -- the forecast payload must expose DIRECTION (cost-blind) and
 * TRADE DECISION (cost-aware) as two separate, explicitly-flagged answers.
 * Part B3/B4 -- candle-timing fields and the candle_gap gate.
 */
class TradeForecastDirectionDecisionSplitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
        config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
        config()->set('robot_trade.live_min_edge_cost_multiplier', 2.0);
        config()->set('quick_trade.fee_model', 'taker');
    }

    /**
     * Strongly trending closed candles, timestamped so the resolver sees
     * them as genuinely closed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function candles(int $count = 40, float $step = 5.0, int $timeframeSeconds = 60, int $endOffsetSeconds = 0): array
    {
        $now = CarbonImmutable::now();
        $candles = [];
        $price = 2000.0;
        for ($i = $count; $i >= 1; $i--) {
            $openAt = $now->subSeconds($endOffsetSeconds + ($i * $timeframeSeconds));
            $open = $price;
            $price += $step;
            $candles[] = [
                'timestamp' => $openAt->getTimestampMs(),
                'opens_at' => $openAt->toIso8601String(),
                'open' => $open,
                'high' => max($open, $price) + 1,
                'low' => min($open, $price) - 1,
                'close' => $price,
                'volume' => 1000.0,
            ];
        }

        return $candles;
    }

    public function test_payload_contains_direction_and_trade_decision_with_correct_fee_aware_flags(): void
    {
        $service = app(TradeForecastService::class);
        $result = $service->forecast($this->candles(), '1m', 900, null, null, null, 'BTCUSDT');

        $this->assertTrue($result['available']);

        $this->assertArrayHasKey('direction', $result);
        $this->assertArrayHasKey('trade_decision', $result);

        // Direction: cost-blind.
        $this->assertFalse($result['direction']['fee_aware']);
        $this->assertContains($result['direction']['value'], ['BUY', 'SELL', 'NEUTRAL']);
        $this->assertSame('bias (expiryScore >= 0.20 / <= -0.20)', $result['direction']['source']);
        $this->assertIsFloat($result['direction']['expiry_score']);
        $this->assertNotNull($result['direction']['expected_move_pct']);

        // Trade decision: cost-aware.
        $this->assertTrue($result['trade_decision']['fee_aware']);
        $this->assertContains($result['trade_decision']['value'], ['BUY', 'SELL', 'WAIT']);
        $this->assertEqualsWithDelta(0.28, $result['trade_decision']['required_move_pct'], 1e-6);
        $this->assertEqualsWithDelta(0.14, $result['trade_decision']['cost_pct'], 1e-6);
        $this->assertArrayHasKey('max_achievable_move_pct', $result['trade_decision']);
        $this->assertArrayHasKey('gate_reason', $result['trade_decision']);
    }

    public function test_the_two_blocks_never_contradict_the_legacy_fields(): void
    {
        $service = app(TradeForecastService::class);
        $result = $service->forecast($this->candles(), '1m', 900, null, null, null, 'BTCUSDT');

        // There is exactly one evaluation behind both -- never a second engine.
        $this->assertSame($result['bias'], $result['direction']['value']);
        $this->assertSame($result['decision_trend'], $result['trade_decision']['value']);
        $this->assertSame($result['gate_reason'], $result['trade_decision']['gate_reason']);
    }

    public function test_direction_can_be_buy_while_the_trade_decision_is_wait(): void
    {
        // A clear, consistent uptrend on a 1-candle horizon: comfortably past
        // the >= 0.20 bias threshold, but the achievable displacement over a
        // single 1m candle cannot clear 2x round-trip cost. This is exactly
        // F4 -- the "BUY 81%" shown next to "WAIT" that the split exists to
        // explain instead of hiding.
        $service = app(TradeForecastService::class);
        $result = $service->forecast($this->candles(40, 5.0), '1m', 60, null, null, null, 'BTCUSDT');

        $this->assertSame('BUY', $result['direction']['value']);
        $this->assertSame('WAIT', $result['trade_decision']['value']);
        $this->assertLessThan(
            $result['trade_decision']['required_move_pct'],
            abs((float) $result['trade_decision']['expected_move_pct']),
        );
    }

    public function test_max_achievable_move_matches_the_standalone_cost_model(): void
    {
        $service = app(TradeForecastService::class);
        $result = $service->forecast($this->candles(), '5m', 3600, null, null, null, 'BTCUSDT');

        $expected = app(\App\Domain\Trading\QuickTradeCostModel::class)
            ->maxAchievableMovePct((float) $result['atr_pct'], (int) $result['horizon_candles']);

        $this->assertEqualsWithDelta($expected, $result['trade_decision']['max_achievable_move_pct'], 0.0001);
    }

    // ---- Part B ----------------------------------------------------------

    public function test_candle_timing_fields_are_null_for_callers_that_do_not_supply_timing(): void
    {
        // Ratio Bot / Robot Trade / Future Trade call it exactly like this.
        $result = app(TradeForecastService::class)->forecast($this->candles(), '1m', 900);

        $this->assertNull($result['input_candles_include_forming_bar']);
        $this->assertNull($result['decision_candle_close_at']);
        $this->assertNull($result['seconds_since_last_close']);
    }

    public function test_decision_excludes_the_forming_candle_and_reports_the_real_close_time(): void
    {
        $candles = $this->candles(40);
        // Append a forming bar: opened now, therefore not yet closed.
        $now = CarbonImmutable::now();
        $candles[] = [
            'timestamp' => $now->getTimestampMs(),
            'opens_at' => $now->toIso8601String(),
            'open' => 9999.0, 'high' => 99999.0, 'low' => 1.0, 'close' => 9999.0, 'volume' => 1.0,
        ];

        $timing = app(CandleCloseResolver::class)->resolve($candles, '1m');

        $this->assertCount(41, $candles);
        $this->assertCount(40, $timing['candles'], 'The forming bar must be excluded from the decision input.');
        $this->assertTrue($timing['forming_bar_dropped']);
        $this->assertFalse($timing['include_forming_bar']);

        $result = app(TradeForecastService::class)->forecast(
            $timing['candles'], '1m', 900, null, null, null, 'BTCUSDT', null, $timing,
        );

        $this->assertFalse($result['input_candles_include_forming_bar']);
        $this->assertSame($timing['decision_candle_close_at'], $result['decision_candle_close_at']);
        // The last CLOSED bar's close time, not the forming bar's open time.
        $this->assertNotNull($result['decision_candle_close_at']);
        $this->assertLessThanOrEqual(
            CarbonImmutable::now()->getTimestampMs(),
            CarbonImmutable::parse($result['decision_candle_close_at'])->getTimestampMs(),
        );
    }

    public function test_candle_gap_gate_forces_wait_with_its_own_reason(): void
    {
        // Last closed candle is 5 minutes old on a 1m chart -> > 1.5x gap.
        $timing = app(CandleCloseResolver::class)->resolve($this->candles(40, 5.0, 60, 300), '1m');
        $this->assertTrue($timing['candle_gap'], 'Fixture must actually present a gap.');

        $result = app(TradeForecastService::class)->forecast(
            $timing['candles'], '1m', 900, null, null, null, 'BTCUSDT', null, $timing,
        );

        $this->assertSame('WAIT', $result['decision_trend']);
        $this->assertSame('candle_gap', $result['gate_reason']);
        $this->assertSame('candle_gap', $result['trade_decision']['gate_reason']);
        // The cost-blind direction is NOT suppressed -- the user still sees
        // where the model thinks price goes, with an honest reason not to act.
        $this->assertContains($result['direction']['value'], ['BUY', 'SELL', 'NEUTRAL']);
    }

    public function test_no_candle_gap_when_the_feed_is_current(): void
    {
        $timing = app(CandleCloseResolver::class)->resolve($this->candles(40, 5.0, 60, 0), '1m');

        $this->assertFalse($timing['candle_gap']);

        $result = app(TradeForecastService::class)->forecast(
            $timing['candles'], '1m', 900, null, null, null, 'BTCUSDT', null, $timing,
        );
        $this->assertNotSame('candle_gap', $result['gate_reason']);
    }

    public function test_a_stale_feed_keeps_its_more_specific_reason_over_candle_gap(): void
    {
        $timing = app(CandleCloseResolver::class)->resolve($this->candles(40, 5.0, 60, 300), '1m');

        $result = app(TradeForecastService::class)->forecast(
            $timing['candles'], '1m', 900, null, null,
            ['fresh' => false, 'age_seconds' => 90], 'BTCUSDT', null, $timing,
        );

        $this->assertSame('stale_data', $result['gate_reason']);
    }

    public function test_candles_without_usable_timestamps_are_reported_honestly_not_trimmed(): void
    {
        $candles = array_map(
            static fn (array $candle) => ['open' => $candle['open'], 'high' => $candle['high'],
                'low' => $candle['low'], 'close' => $candle['close'], 'volume' => $candle['volume']],
            $this->candles(20),
        );

        $timing = app(CandleCloseResolver::class)->resolve($candles, '1m');

        $this->assertFalse($timing['timestamps_available']);
        $this->assertCount(20, $timing['candles'], 'Never silently drop a bar we cannot classify.');
        $this->assertNull($timing['decision_candle_close_at']);
        $this->assertFalse($timing['candle_gap']);
    }
}
