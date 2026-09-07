<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-Window Forecast Engine Part C -- a genuine walk-forward backtest
 * (verified before writing: no existing service in this codebase measures
 * whether a past TradeForecastService::forecast() call would have been
 * directionally correct). Each simulated call only sees candles up to that
 * point in history; the outcome is read from the real candle horizonCandles
 * later, never from data the simulated forecast() call itself could see.
 */
class ForecastHorizonBacktestServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_a_consistent_trend_produces_a_high_hit_rate(): void
    {
        $config = $this->makeBot(['brain_enabled' => true]);
        $this->seedTrend($config, 2000.0, 1.0, 200);

        $result = app(ForecastHorizonBacktestService::class)->runForConfig($config);

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->sample_size);
        $this->assertGreaterThan(50.0, (float) $result->hit_rate_pct);
    }

    public function test_a_bot_with_brain_disabled_is_skipped(): void
    {
        $config = $this->makeBot(['brain_enabled' => false]);
        $this->seedTrend($config, 2000.0, 1.0, 200);

        $result = app(ForecastHorizonBacktestService::class)->runForConfig($config);

        $this->assertNull($result);
    }

    public function test_insufficient_history_is_skipped_rather_than_producing_a_meaningless_sample(): void
    {
        $config = $this->makeBot(['brain_enabled' => true]);
        $this->seedTrend($config, 2000.0, 1.0, 20);

        $result = app(ForecastHorizonBacktestService::class)->runForConfig($config);

        $this->assertNull($result);
    }

    public function test_result_is_upserted_per_horizon_not_duplicated_on_rerun(): void
    {
        $config = $this->makeBot(['brain_enabled' => true]);
        $this->seedTrend($config, 2000.0, 1.0, 200);

        app(ForecastHorizonBacktestService::class)->runForConfig($config);
        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 1);

        app(ForecastHorizonBacktestService::class)->runForConfig($config);
        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 1);
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        return RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
        ], $overrides));
    }

    private function seedTrend(RatioBotConfig $config, float $start, float $step, int $count): void
    {
        $time = now()->subMinutes($count);
        for ($i = 0; $i < $count; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $open,
                'high' => max($open, $close) + 1,
                'low' => min($open, $close) - 1,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }
}
