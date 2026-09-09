<?php

namespace Tests\Unit\MarketIntelligence;

use App\Domain\MarketIntelligence\MarketAnalysisEngine;
use App\Domain\RatioBot\MultiWindowTrendScoringService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * MarketAnalysisEngine is a thin facade over the already-tested
 * MultiWindowTrendScoringService -- this proves the wrap is faithful (same
 * fields as a direct score() call for the same config/candles) and that the
 * facade carries no additional dependency beyond that one service, so it
 * stays reusable by other bot types without pulling in wallet/order/
 * exchange-client machinery.
 */
class MarketAnalysisEngineTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_analyze_matches_a_direct_score_call_for_the_same_config(): void
    {
        $config = $this->makeBot();
        $this->seedTrend($config, 2000.0, 5.0, 48);

        $expected = app(MultiWindowTrendScoringService::class)->score($config);
        $snapshot = app(MarketAnalysisEngine::class)->analyze($config);

        $this->assertSame($expected['direction'], $snapshot->trendDirection);
        $this->assertSame((float) $expected['composite_score'], $snapshot->trendCompositeScore);
        $this->assertSame($expected['windows'], $snapshot->windowBreakdown);
        $this->assertSame((float) $expected['min_composite'], $snapshot->minComposite);
        $this->assertSame($expected, $snapshot->raw);
    }

    public function test_constructor_depends_only_on_trend_scoring_service(): void
    {
        $reflection = new ReflectionClass(MarketAnalysisEngine::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(MultiWindowTrendScoringService::class, $params[0]->getType()?->getName());
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
