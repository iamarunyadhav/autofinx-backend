<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\MultiWindowTrendScoringService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-Window Forecast Engine Part B -- replaces the single-window boolean
 * anchor-direction check with a 0.5/0.3/0.2 weighted composite across
 * short/medium/long windows, each scaled by its own ATR rather than a
 * static config constant (config('assets.*.default_threshold_pct') does not
 * exist in this codebase -- verified before writing MultiWindowTrendScoringService).
 */
class MultiWindowTrendScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_a_consistent_uptrend_scores_bullish_across_all_three_windows(): void
    {
        $config = $this->makeBot();
        $this->seedTrend($config, 2000.0, 5.0, 48);

        $result = app(MultiWindowTrendScoringService::class)->score($config);

        $this->assertSame('bullish', $result['direction']);
        $this->assertCount(3, $result['windows']);
        $this->assertSame(3, $result['agreement_count']);
        $this->assertGreaterThan(0, $result['composite_score']);
        foreach ($result['windows'] as $window) {
            $this->assertSame('bullish', $window['direction'], "window [{$window['key']}] should agree with the uniform uptrend");
        }
    }

    public function test_a_consistent_downtrend_scores_bearish_across_all_three_windows(): void
    {
        $config = $this->makeBot();
        $this->seedTrend($config, 2100.0, -5.0, 48);

        $result = app(MultiWindowTrendScoringService::class)->score($config);

        $this->assertSame('bearish', $result['direction']);
        $this->assertLessThan(0, $result['composite_score']);
    }

    public function test_a_flat_market_scores_neutral(): void
    {
        $config = $this->makeBot();
        $this->seedTrend($config, 2000.0, 0.0, 48);

        $result = app(MultiWindowTrendScoringService::class)->score($config);

        $this->assertSame('neutral', $result['direction']);
        $this->assertSame(0, $result['agreement_count']);
    }

    public function test_the_composite_weights_short_window_highest(): void
    {
        $config = $this->makeBot();
        $this->seedTrend($config, 2000.0, 1.0, 48);

        $result = app(MultiWindowTrendScoringService::class)->score($config);
        $weights = collect($result['windows'])->pluck('weight', 'key')->all();

        $this->assertEquals(0.5, $weights['short']);
        $this->assertEquals(0.3, $weights['medium']);
        $this->assertEquals(0.2, $weights['long']);
    }

    public function test_a_sharp_reversal_in_the_short_window_disagrees_with_the_older_long_window_trend(): void
    {
        $config = $this->makeBot(['strategy_params' => ['auto_start_observation_candles' => 12]]);
        // 18 candles of bearish drift, then 6 candles of a sharp reversal --
        // the short window (6 candles) sees only the reversal and should
        // read bullish, while the long window (up to 24, capped by the 24
        // candles actually seeded) still carries the earlier bearish drift.
        $this->seedTrend($config, 2100.0, -5.0, 18);
        $this->seedTrend($config, 2010.0, 8.0, 6);

        $result = app(MultiWindowTrendScoringService::class)->score($config);
        $windowsByKey = collect($result['windows'])->keyBy('key');

        $this->assertSame('bullish', $windowsByKey['short']['direction']);
        $this->assertLessThan(3, $result['agreement_count'], 'windows disagreeing should keep full 3-way agreement from happening');
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
        $latest = MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->orderByDesc('ts')
            ->first();
        $time = $latest ? $latest->ts->copy() : now()->subMinutes($count + 1);

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
                'ts' => $time->copy()->addMinutes($i + 1),
            ]);
        }
    }
}
