<?php

namespace Tests\Unit\FutureTrade;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\Ops\OperationalHealthService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Domain\Trading\FuturesOrderSizingService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FutureTradeDynamicAlgorithmTest extends TestCase
{
    public function test_fixed_mode_keeps_manual_min_confidence_as_effective_threshold(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'fixed',
            'minConfidence' => 0.72,
        ], $this->state([
            'chart_points' => $this->trendPoints(100, 104, 20),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.91, 104, 5.2, 3.8),
        ]);

        $this->assertSame('fixed', $decision['mode']);
        $this->assertSame(0.72, $decision['effectiveMinConfidence']);
        $this->assertStringContainsString('Fixed mode', $decision['public']['suggestion']);
    }

    public function test_semi_dynamic_raises_threshold_and_takes_faster_profit_in_choppy_market(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.72,
            'takeProfitPercent' => 1.0,
            'trailingTakeProfitPercent' => 0.2,
        ], $this->state([
            'chart_points' => $this->choppyPoints(100, 0.10, 24),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.78, 100.05, 0.2, 20.0),
        ]);

        $this->assertTrue($decision['public']['analysis']['choppy']);
        $this->assertGreaterThan(0.72, $decision['effectiveMinConfidence']);
        $this->assertLessThan(1.0, $decision['public']['recommended']['takeProfitPercent']);
        $this->assertStringContainsString('Choppy market', implode(' ', $decision['public']['adjustments']));
    }

    public function test_semi_dynamic_strong_aligned_trend_lowers_entry_threshold_within_caps(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.72,
            'dynamicMinConfidenceFloor' => 0.68,
            'takeProfitPercent' => 1.0,
        ], $this->state([
            'chart_points' => $this->trendPoints(100, 102.4, 24),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.92, 102.4, 2.4, 3.2),
            $this->signal('BTCUSDT', 'BUY', 0.70, 204, 2.0, 3.0),
        ]);

        $this->assertFalse($decision['public']['analysis']['choppy']);
        $this->assertGreaterThanOrEqual(0.68, $decision['effectiveMinConfidence']);
        $this->assertLessThan(0.72, $decision['effectiveMinConfidence']);
        $this->assertGreaterThan(1.0, $decision['public']['recommended']['takeProfitPercent']);
    }

    public function test_take_profit_reentry_buffer_requires_stronger_signal_after_recent_profit(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.72,
            'dynamicReentryBuffer' => 0.03,
        ], $this->state([
            'wins' => 1,
            'current_signal' => 'TAKE_PROFIT_HIT',
            'chart_points' => $this->trendPoints(100, 100.3, 20),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.82, 101.2, 1.2, 2.2),
            $this->signal('BTCUSDT', 'BUY', 0.81, 201, 0.8, 2.0),
        ]);

        $this->assertGreaterThanOrEqual(0.75, $decision['effectiveMinConfidence']);
        $this->assertStringContainsString('re-entry buffer', implode(' ', $decision['public']['adjustments']));
    }

    public function test_fully_dynamic_after_recent_loss_raises_threshold_extends_cooldown_and_reduces_size(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.72,
            'tradeAmountUsdt' => 100,
            'cooldownSeconds' => 60,
            'stopLossPercent' => 0.28,
        ], $this->state([
            'wins' => 1,
            'losses' => 3,
            'current_signal' => 'STOP_LOSS_HIT',
            'chart_points' => $this->trendPoints(100, 101.8, 24),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.90, 101.8, 1.8, 2.8),
        ]);

        $this->assertGreaterThan(0.72, $decision['effectiveMinConfidence']);
        $this->assertGreaterThanOrEqual(120, $decision['public']['recommended']['cooldownSeconds']);
        $this->assertLessThan(100.0, $decision['public']['recommended']['tradeAmountUsdt']);
        $this->assertGreaterThanOrEqual(25.0, $decision['public']['recommended']['tradeAmountUsdt']);
        $this->assertGreaterThan(0.28, $decision['public']['recommended']['stopLossPercent']);
        $this->assertStringContainsString('Recent loss', implode(' ', $decision['public']['adjustments']));
    }

    public function test_fully_dynamic_obeys_ceiling_when_many_risk_adjustments_stack(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.86,
            'dynamicMinConfidenceCeiling' => 0.88,
        ], $this->state([
            'wins' => 0,
            'losses' => 4,
            'current_signal' => 'LOSS_ROLLBACK_TRIGGERED',
            'chart_points' => $this->choppyPoints(100, 0.22, 24),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.77, 100.05, 0.2, 20.0),
            $this->signal('BTCUSDT', 'BUY', 0.765, 200, 0.1, 18.0),
        ]);

        $this->assertLessThanOrEqual(0.88, $decision['effectiveMinConfidence']);
        $this->assertGreaterThan(0.86, $decision['effectiveMinConfidence']);
        $this->assertGreaterThan($decision['public']['analysis']['bestConfidence'], $decision['effectiveMinConfidence']);
    }

    public function test_fully_dynamic_trend_regime_uses_bounded_volatility_tuned_targets(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.74,
            'takeProfitPercent' => 0.8,
            'stopLossPercent' => 0.28,
            'trailingTakeProfitPercent' => 0.16,
            'cooldownSeconds' => 120,
            'tradeAmountUsdt' => 30,
        ], $this->state([
            'wins' => 4,
            'losses' => 1,
            'chart_points' => $this->trendPoints(100, 103.2, 30),
        ]), [
            $this->signal('BTCUSDT', 'BUY', 0.93, 103.2, 3.2, 4.0),
            $this->signal('ETHUSDT', 'BUY', 0.81, 52, 2.7, 3.6),
        ]);

        $this->assertSame('trend', $decision['public']['analysis']['regime']);
        $this->assertGreaterThanOrEqual(0.28, $decision['public']['recommended']['stopLossPercent']);
        $this->assertLessThanOrEqual(0.448, $decision['public']['recommended']['stopLossPercent']);
        $this->assertGreaterThan(0.8, $decision['public']['recommended']['takeProfitPercent']);
        $this->assertLessThanOrEqual(30.0, $decision['public']['recommended']['tradeAmountUsdt']);
    }

    public function test_fully_dynamic_does_not_overtrust_tiny_win_rate_samples(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.78,
            'tradeAmountUsdt' => 25,
        ], $this->state([
            'wins' => 1,
            'losses' => 0,
            'chart_points' => $this->trendPoints(100, 103, 24),
        ]), [
            $this->signal('BTCUSDT', 'BUY', 0.94, 103, 3.0, 3.6),
        ]);

        $this->assertSame(0.0, $decision['public']['analysis']['winRateSampleWeight']);
        $this->assertGreaterThanOrEqual(0.73, $decision['effectiveMinConfidence']);
        $this->assertLessThan(0.78, $decision['effectiveMinConfidence']);
        $this->assertStringContainsString('Limited trade sample', implode(' ', $decision['public']['adjustments']));
    }

    public function test_fully_dynamic_uses_larger_trade_samples_to_reduce_size_after_poor_win_rate(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'fully_dynamic',
            'minConfidence' => 0.72,
            'tradeAmountUsdt' => 100,
            'rollbackLossPercent' => 0.18,
        ], $this->state([
            'wins' => 4,
            'losses' => 8,
            'chart_points' => $this->trendPoints(100, 101.5, 24),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.9, 101.5, 1.5, 2.8),
        ]);

        $this->assertGreaterThan(0.5, $decision['public']['analysis']['winRateSampleWeight']);
        $this->assertGreaterThanOrEqual(0.78, $decision['effectiveMinConfidence']);
        $this->assertLessThan(90.0, $decision['public']['recommended']['tradeAmountUsdt']);
        $this->assertGreaterThan(0.18, $decision['settings']['rollbackLossPercent']);
        $this->assertStringContainsString('Weak win rate', implode(' ', $decision['public']['adjustments']));
    }

    public function test_dynamic_decision_payload_contains_ui_suggestion_and_auditable_recommended_values(): void
    {
        $decision = $this->decision([
            'confidenceMode' => 'semi_dynamic',
            'minConfidence' => 0.68,
            'takeProfitPercent' => 1.2,
            'stopLossPercent' => 0.4,
            'trailingTakeProfitPercent' => 0.3,
        ], $this->state([
            'chart_points' => $this->trendPoints(100, 103, 24),
        ]), [
            $this->signal('ETHUSDT', 'BUY', 0.93, 103, 3.0, 3.8),
        ]);

        $this->assertSame('semi_dynamic', $decision['public']['mode']);
        $this->assertArrayHasKey('recommended', $decision['public']);
        $this->assertArrayHasKey('analysis', $decision['public']);
        $this->assertNotSame('', $decision['public']['suggestion']);
    }

    public function test_score_ticker_requires_full_bullish_confluence_for_buy_signal(): void
    {
        $score = $this->scoreTicker([
            'symbol' => 'BTCUSDT',
            'lastPrice' => 101.2,
            'priceChangePercent' => 0.9,
            'quoteVolume' => 2000000,
            'highPrice' => 101.5,
            'lowPrice' => 99.2,
            'weightedAvgPrice' => 100.2,
            'openPrice' => 99.8,
            'sequence' => [
                'netChangePercent' => 0.34,
                'consistency' => 0.72,
                'volatilityPercent' => 0.14,
                'impulse' => 'BUY',
                'rsi14' => 61.2,
                'emaBullish' => true,
                'emaStackBullish' => true,
                'emaStackBearish' => false,
                'macdBullish' => true,
                'macdHistogramGrowing' => true,
                'adx14' => 24.8,
                'sessionVwap' => 100.3,
                'volumeOk' => true,
                'extendedFromEma' => false,
                'extendedFromVwap' => false,
                'utSignal' => 'BUY',
                'utBias' => 'BUY',
            ],
            'structure' => [
                'signal' => 'BUY',
                'bullishMsb' => true,
                'bearishMsb' => false,
                'bullishObValid' => true,
                'bearishObValid' => false,
            ],
            'htf' => [
                'emaBullish' => true,
                'h4EmaBullish' => true,
                'ema200Bullish' => true,
            ],
            'symbolPerfAdjust' => 0.0,
        ], ['timeframe' => '5m']);

        $this->assertSame('BUY', $score['signal']);
        $this->assertFalse($score['conflicted']);
        $this->assertTrue($score['strongSequence']);
        $this->assertGreaterThan(0.80, $score['confidence']);
    }

    public function test_score_ticker_waits_when_ut_trigger_or_structure_confirmation_is_missing(): void
    {
        $score = $this->scoreTicker([
            'symbol' => 'ETHUSDT',
            'lastPrice' => 99.8,
            'priceChangePercent' => 0.4,
            'quoteVolume' => 1200000,
            'highPrice' => 100.1,
            'lowPrice' => 98.9,
            'weightedAvgPrice' => 99.4,
            'openPrice' => 99.2,
            'sequence' => [
                'netChangePercent' => 0.18,
                'consistency' => 0.60,
                'volatilityPercent' => 0.18,
                'impulse' => 'BUY',
                'rsi14' => 59.0,
                'emaBullish' => true,
                'emaStackBullish' => true,
                'emaStackBearish' => false,
                'macdBullish' => true,
                'macdHistogramGrowing' => true,
                'adx14' => 21.0,
                'sessionVwap' => 99.3,
                'volumeOk' => true,
                'extendedFromEma' => false,
                'extendedFromVwap' => false,
                'utSignal' => 'WAIT',
                'utBias' => 'NEUTRAL',
            ],
            'structure' => [
                'signal' => 'WAIT',
                'bullishMsb' => false,
                'bearishMsb' => false,
                'bullishObValid' => false,
                'bearishObValid' => false,
            ],
            'htf' => [
                'emaBullish' => true,
                'h4EmaBullish' => true,
                'ema200Bullish' => true,
            ],
            'symbolPerfAdjust' => 0.0,
        ], ['timeframe' => '5m']);

        $this->assertSame('WAIT', $score['signal']);
        $this->assertStringContainsString('UT Bot', $score['reason']);
        $this->assertStringContainsString('market structure breakout', $score['reason']);
    }

    private function decision(array $settings, FutureTradeBotState $state, array $signals): array
    {
        $settings = array_merge([
            'confidenceMode' => 'fixed',
            'minConfidence' => 0.72,
            'dynamicLookbackMinutes' => 10,
            'dynamicMinConfidenceFloor' => 0.68,
            'dynamicMinConfidenceCeiling' => 0.88,
            'dynamicReentryBuffer' => 0.02,
            'takeProfitPercent' => 1.0,
            'stopLossPercent' => 0.4,
            'trailingTakeProfitPercent' => 0.2,
            'cooldownSeconds' => 60,
            'tradeAmountUsdt' => 25,
            'scanIntervalSeconds' => 3,
        ], $settings);
        $config = new FutureTradeBotConfig([
            'workspace_id' => '00000000-0000-0000-0000-000000000099',
            'settings' => $settings,
        ]);
        $best = $signals[0] ?? null;
        $service = new FutureTradeBotService(
            new LivePortfolioGuardService(),
            $this->createMock(OperationalHealthService::class),
            new FuturesOrderSizingService(),
        );
        $method = new ReflectionMethod($service, 'dynamicDecision');
        $method->setAccessible(true);

        return $method->invoke($service, $config, $state, $signals, $best, $settings);
    }

    private function scoreTicker(array $ticker, array $settings): array
    {
        $service = new FutureTradeBotService(
            new LivePortfolioGuardService(),
            $this->createMock(OperationalHealthService::class),
            new FuturesOrderSizingService(),
        );
        $method = new ReflectionMethod($service, 'scoreTicker');
        $method->setAccessible(true);

        return $method->invoke($service, $ticker, $settings);
    }

    private function state(array $attributes): FutureTradeBotState
    {
        return new FutureTradeBotState(array_merge([
            'wins' => 0,
            'losses' => 0,
            'current_signal' => 'WAITING_FOR_SIGNAL',
            'chart_points' => [],
        ], $attributes));
    }

    private function signal(string $symbol, string $side, float $confidence, float $price, float $change, float $range): array
    {
        return [
            'symbol' => $symbol,
            'signal' => $side,
            'price' => $price,
            'changePercent' => $change,
            'rangePercent' => $range,
            'confidence' => $confidence,
            'timeframe' => '5m',
            'reason' => 'test signal',
        ];
    }

    private function trendPoints(float $start, float $end, int $count): array
    {
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $ratio = $count > 1 ? $i / ($count - 1) : 1;
            $points[] = [
                'time' => date('H:i:s', time() - (($count - $i) * 3)),
                'symbol' => 'ETHUSDT',
                'price' => round($start + (($end - $start) * $ratio), 6),
                'pnl' => 0,
            ];
        }

        return $points;
    }

    private function choppyPoints(float $base, float $swing, int $count): array
    {
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $direction = $i % 2 === 0 ? 1 : -1;
            $points[] = [
                'time' => date('H:i:s', time() - (($count - $i) * 3)),
                'symbol' => 'ETHUSDT',
                'price' => round($base + ($direction * $swing), 6),
                'pnl' => 0,
            ];
        }

        return $points;
    }
}
