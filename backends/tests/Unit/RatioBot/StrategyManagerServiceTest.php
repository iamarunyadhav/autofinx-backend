<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\DynamicThresholdService;
use App\Domain\RatioBot\IndicatorService;
use App\Domain\RatioBot\StrategyManagerService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StrategyManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    private StrategyManagerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StrategyManagerService(new IndicatorService(), new DynamicThresholdService());
    }

    /** @test */
    public function classify_regime_returns_uncertain_on_insufficient_data(): void
    {
        $result = $this->service->classifyRegime(['sufficient_data' => false]);

        $this->assertSame(StrategyManagerService::REGIME_UNCERTAIN, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_high_volatility_on_extreme_volatility_level(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'EXTREME',
            'market_mode' => 'SIDEWAYS',
        ]);

        $this->assertSame(StrategyManagerService::REGIME_HIGH_VOLATILITY, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_low_liquidity_when_thin_and_low_volume(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'LOW',
            'market_mode' => 'SIDEWAYS',
            'liquidity_notional' => 500.0,
            'volume_ratio' => 0.2,
        ]);

        $this->assertSame(StrategyManagerService::REGIME_LOW_LIQUIDITY, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_strong_uptrend_for_trending_up_high_adx(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'MEDIUM',
            'market_mode' => 'TRENDING',
            'ema_9' => 110,
            'ema_21' => 100,
            'adx' => 40,
        ]);

        $this->assertSame(StrategyManagerService::REGIME_STRONG_UPTREND, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_weak_uptrend_for_trending_up_low_adx(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'MEDIUM',
            'market_mode' => 'TRENDING',
            'ema_9' => 110,
            'ema_21' => 100,
            'adx' => 20,
        ]);

        $this->assertSame(StrategyManagerService::REGIME_WEAK_UPTREND, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_strong_downtrend_for_trending_down_high_adx(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'MEDIUM',
            'market_mode' => 'TRENDING',
            'ema_9' => 90,
            'ema_21' => 100,
            'adx' => 40,
        ]);

        $this->assertSame(StrategyManagerService::REGIME_STRONG_DOWNTREND, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_weak_downtrend_for_trending_down_low_adx(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'MEDIUM',
            'market_mode' => 'TRENDING',
            'ema_9' => 90,
            'ema_21' => 100,
            'adx' => 20,
        ]);

        $this->assertSame(StrategyManagerService::REGIME_WEAK_DOWNTREND, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_high_volatility_for_volatile_market_mode(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'MEDIUM',
            'market_mode' => 'VOLATILE',
        ]);

        $this->assertSame(StrategyManagerService::REGIME_HIGH_VOLATILITY, $result['regime']);
    }

    /** @test */
    public function classify_regime_returns_range_for_sideways_market_mode(): void
    {
        $result = $this->service->classifyRegime([
            'sufficient_data' => true,
            'volatility_level' => 'LOW',
            'market_mode' => 'SIDEWAYS',
        ]);

        $this->assertSame(StrategyManagerService::REGIME_RANGE, $result['regime']);
    }

    /** @test */
    public function analyze_is_inert_when_v2_disabled(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            'v2_enabled' => false,
        ]);
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id]);

        $result = $this->service->analyze($config, $state, 100.0);

        $this->assertFalse($result['enabled']);
        $this->assertArrayNotHasKey('regime', $result);
    }

    /** @test */
    public function manual_reserve_mode_returns_static_pct_unchanged(): void
    {
        $config = $this->makeConfigWithCandles('MANUALUSDT', trend: 'up', reserveMode: 'manual');
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id]);

        $result = $this->service->analyze($config, $state, 160.0);

        $this->assertTrue($result['enabled']);
        $this->assertSame('manual', $result['reserve']['mode']);
        // initial_entry_pct=0.6 -> base reserve pct = 0.4
        $this->assertEqualsWithDelta(0.4, $result['reserve']['effective_pct'], 0.0001);
    }

    /** @test */
    public function dynamic_reserve_mode_never_exceeds_configured_bounds(): void
    {
        $config = $this->makeConfigWithCandles('DYNUSDT', trend: 'down', reserveMode: 'dynamic', reserveMin: 0.3, reserveMax: 0.5);
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id]);

        $result = $this->service->analyze($config, $state, 40.0);

        $this->assertTrue($result['enabled']);
        $this->assertSame('dynamic', $result['reserve']['mode']);
        $this->assertGreaterThanOrEqual(0.3, $result['reserve']['effective_pct']);
        $this->assertLessThanOrEqual(0.5, $result['reserve']['effective_pct']);
    }

    private function makeConfigWithCandles(string $symbol, string $trend, string $reserveMode, float $reserveMin = 0.05, float $reserveMax = 0.95): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => $symbol,
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'timeframe' => '1m',
            'base_quote_amount' => 100,
            'initial_entry_pct' => 0.6,
            'v2_enabled' => true,
            'reserve_mode' => $reserveMode,
            'reserve_min_pct' => $reserveMin,
            'reserve_max_pct' => $reserveMax,
        ]);

        $start = $trend === 'up' ? 100.0 : 200.0;
        $step = $trend === 'up' ? 1.0 : -1.0;
        $ts = Carbon::now()->subMinutes(60);

        for ($i = 0; $i < 60; $i++) {
            $close = $start + ($i * $step);
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'ts' => $ts->copy()->addMinutes($i),
                'open' => $close - ($step * 0.5),
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'volume' => 100,
            ]);
        }

        return $config;
    }
}
