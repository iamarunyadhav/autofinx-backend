<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotBrainService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-Window Forecast Engine Part A -- forecast_horizon_seconds decouples
 * "how far ahead the brain forecasts" from "which candle size the ladder
 * trades on" (RatioBotBrainService::horizonSeconds()). Null keeps the
 * pre-existing timeframe-derived behavior unchanged.
 */
class RatioBotForecastHorizonTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_horizon_defaults_to_timeframe_derived_seconds_when_no_override_is_set(): void
    {
        $config = $this->makeBot(['timeframe' => '15m']);

        $this->assertNull($config->forecastHorizonSeconds());
        $this->assertSame(900, app(RatioBotBrainService::class)->horizonSeconds($config));
    }

    public function test_explicit_override_decouples_horizon_from_timeframe(): void
    {
        $config = $this->makeBot(['timeframe' => '1m', 'forecast_horizon_seconds' => 3600]);

        $this->assertSame(3600, app(RatioBotBrainService::class)->horizonSeconds($config));
    }

    public function test_override_is_clamped_to_a_sane_range(): void
    {
        $tooSmall = $this->makeBot(['name' => 'clamp-low', 'timeframe' => '1m', 'forecast_horizon_seconds' => 5]);
        $this->assertSame(30, $tooSmall->forecastHorizonSeconds());

        $tooLarge = $this->makeBot(['name' => 'clamp-high', 'timeframe' => '1m', 'forecast_horizon_seconds' => 999999]);
        $this->assertSame(86400, $tooLarge->forecastHorizonSeconds());
    }

    public function test_brain_snapshot_persists_the_overridden_horizon(): void
    {
        $config = $this->makeBot(['timeframe' => '1m', 'forecast_horizon_seconds' => 1800, 'brain_enabled' => true]);
        $this->seedCandles($config, 60);

        $snapshot = app(RatioBotBrainService::class)->resolve($config, 100.0);

        $this->assertNotNull($snapshot);
        $this->assertSame(1800, $snapshot->horizon_seconds);
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        return RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
        ], $overrides));
    }

    private function seedCandles(RatioBotConfig $config, int $count): void
    {
        $time = now()->subMinutes($count);
        for ($i = 0; $i < $count; $i++) {
            $close = 100.0 + $i * 0.1;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $close - 0.1,
                'high' => $close + 0.2,
                'low' => $close - 0.2,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }
}
