<?php

namespace Tests\Feature\MarketIntelligence;

use App\Domain\MarketIntelligence\ForecastShadowReportService;
use App\Models\AuditEvent;
use App\Models\MarketOhlc;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ForecastShadowReportService -- honest, read-only comparison of the old
 * TradeForecastService mechanism vs UnifiedForecastEngine's shadow output,
 * resolved against REAL MarketOhlc candles (same walk-forward contract as
 * ForecastHorizonBacktestService, reused not duplicated). Never fabricates
 * an outcome for an event whose horizon hasn't elapsed.
 */
class ForecastShadowReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedCandles(string $exchange, string $symbol, string $timeframe, CarbonImmutable $start, array $closes): void
    {
        foreach ($closes as $i => $close) {
            $ts = $start->addMinutes($i);
            MarketOhlc::create([
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open' => $close,
                'high' => $close + 1,
                'low' => $close - 1,
                'close' => $close,
                'volume' => 10,
                'ts' => $ts,
            ]);
        }
    }

    private function shadowEvent(array $overrides = []): AuditEvent
    {
        $defaults = [
            'audit_session_id' => null,
            'correlation_id' => null,
            'bot_type' => 'quick_trade',
            'bot_instance_id' => 'binance:ETHUSDT:1m',
            'environment' => null,
            'symbol' => 'ETHUSDT',
            'event_type' => 'unified_forecast_shadow_comparison',
            'event_category' => AuditEvent::CATEGORY_DECISION,
            'severity' => AuditEvent::SEVERITY_INFO,
            'occurred_at' => CarbonImmutable::now()->subMinutes(20),
            'received_at' => CarbonImmutable::now()->subMinutes(20),
            'market_price' => null,
            'latency_ms' => null,
            'metadata' => [
                'old' => [
                    'available' => true,
                    'horizon_seconds' => 300,
                    'bias' => 'BUY',
                    'confidence' => 62.0,
                ],
                'new' => [
                    'direction' => 'BUY',
                    'confidence' => 70.0,
                    'calibrated' => false,
                    'status' => 'clear',
                ],
            ],
            'created_at' => CarbonImmutable::now(),
        ];

        return AuditEvent::create(array_merge($defaults, $overrides));
    }

    public function test_a_determinable_event_resolves_real_direction_and_marks_old_and_new_correctness_independently(): void
    {
        // Prediction point at t=0 (close 2000); horizon 300s = 5 candles (1m
        // timeframe) later at t=5 (close 2050) -- real upward move.
        $start = CarbonImmutable::now()->subMinutes(30);
        $this->seedCandles('binance', 'ETHUSDT', '1m', $start, [2000, 2010, 2020, 2030, 2040, 2050, 2060]);

        $event = $this->shadowEvent([
            'occurred_at' => $start, // matches candle index 0 exactly
            'metadata' => [
                'old' => ['available' => true, 'horizon_seconds' => 300, 'bias' => 'SELL', 'confidence' => 55.0],
                'new' => ['direction' => 'BUY', 'confidence' => 70.0, 'calibrated' => true, 'status' => 'clear'],
            ],
        ]);

        $report = app(ForecastShadowReportService::class)->report(7, 'ETHUSDT');
        $row = collect($report['rows'])->firstWhere('id', $event->id);

        $this->assertNotNull($row);
        $this->assertSame('determined', $row['outcome_status']);
        $this->assertSame('up', $row['outcome_direction']);
        $this->assertSame('SELL', $row['old_direction']);
        $this->assertFalse($row['old_correct']); // predicted SELL, price went up
        $this->assertSame('BUY', $row['new_direction']);
        $this->assertTrue($row['new_correct']); // predicted BUY, price went up
        $this->assertTrue($row['new_calibrated']);
    }

    public function test_a_pending_event_whose_horizon_has_not_elapsed_is_never_given_a_fabricated_outcome(): void
    {
        $event = $this->shadowEvent([
            'occurred_at' => CarbonImmutable::now()->subSeconds(30), // horizon 300s, only 30s elapsed
            'metadata' => [
                'old' => ['available' => true, 'horizon_seconds' => 300, 'bias' => 'BUY', 'confidence' => 60.0],
                'new' => ['direction' => 'BUY', 'confidence' => 65.0, 'calibrated' => false, 'status' => 'clear'],
            ],
        ]);

        $report = app(ForecastShadowReportService::class)->report(7, 'ETHUSDT');
        $row = collect($report['rows'])->firstWhere('id', $event->id);

        $this->assertNotNull($row);
        $this->assertSame('pending', $row['outcome_status']);
        $this->assertNull($row['outcome_direction']);
        $this->assertNull($row['old_correct']);
        $this->assertNull($row['new_correct']);
    }

    public function test_below_minimum_sample_size_summary_reports_insufficient_data_not_a_misleading_percentage(): void
    {
        $start = CarbonImmutable::now()->subMinutes(30);
        $this->seedCandles('binance', 'ETHUSDT', '1m', $start, [2000, 2010, 2020, 2030, 2040, 2050, 2060]);

        // Only a handful of determinable, non-neutral events -- well under
        // ForecastShadowReportService::MIN_SAMPLE_SIZE (30).
        for ($i = 0; $i < 5; $i++) {
            $this->shadowEvent([
                'occurred_at' => $start,
                'metadata' => [
                    'old' => ['available' => true, 'horizon_seconds' => 300, 'bias' => 'BUY', 'confidence' => 60.0],
                    'new' => ['direction' => 'BUY', 'confidence' => 65.0, 'calibrated' => false, 'status' => 'clear'],
                ],
            ]);
        }

        $report = app(ForecastShadowReportService::class)->report(7, 'ETHUSDT');

        $this->assertSame(5, $report['summary']['old']['evaluable_samples']);
        $this->assertTrue($report['summary']['old']['insufficient_data']);
        $this->assertNull($report['summary']['old']['accuracy_pct']);
        $this->assertTrue($report['summary']['new']['insufficient_data']);
        $this->assertNull($report['summary']['new']['accuracy_pct']);
    }

    public function test_at_or_above_minimum_sample_size_summary_reports_a_real_percentage(): void
    {
        $start = CarbonImmutable::now()->subMinutes(30);
        $this->seedCandles('binance', 'ETHUSDT', '1m', $start, [2000, 2010, 2020, 2030, 2040, 2050, 2060]);

        for ($i = 0; $i < 30; $i++) {
            $this->shadowEvent([
                'occurred_at' => $start,
                'metadata' => [
                    'old' => ['available' => true, 'horizon_seconds' => 300, 'bias' => 'BUY', 'confidence' => 60.0],
                    'new' => ['direction' => 'BUY', 'confidence' => 65.0, 'calibrated' => false, 'status' => 'clear'],
                ],
            ]);
        }

        $report = app(ForecastShadowReportService::class)->report(7, 'ETHUSDT');

        $this->assertSame(30, $report['summary']['old']['evaluable_samples']);
        $this->assertFalse($report['summary']['old']['insufficient_data']);
        $this->assertSame(100.0, $report['summary']['old']['accuracy_pct']); // predicted BUY, real move was up
        $this->assertFalse($report['summary']['new']['insufficient_data']);
        $this->assertSame(100.0, $report['summary']['new']['accuracy_pct']);
    }
}
