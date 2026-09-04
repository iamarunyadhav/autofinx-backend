<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotBrainService;
use App\Models\MarketOhlc;
use App\Models\RatioBotBrainSnapshot;
use App\Models\RatioBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatioBotBrainServiceTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotBrainService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RatioBotBrainService::class);
    }

    /** @test */
    public function horizon_seconds_matches_timeframe_registry(): void
    {
        $hourly = $this->makeConfig('1h');
        $this->assertSame(3600, $this->service->horizonSeconds($hourly));

        $fourHour = $this->makeConfig('4h');
        $this->assertSame(14400, $this->service->horizonSeconds($fourHour));
    }

    /** @test */
    public function resolve_is_inert_when_brain_disabled(): void
    {
        $config = $this->makeConfig('1h', brainEnabled: false);

        $result = $this->service->resolve($config, 100.0);

        $this->assertNull($result);
        $this->assertDatabaseCount('ratio_bot_brain_snapshots', 0);
    }

    /** @test */
    public function resolve_fails_open_and_returns_null_when_not_enough_candles(): void
    {
        $config = $this->makeConfig('1h', brainEnabled: true);
        // No MarketOhlc rows seeded — candle fetch will return an empty set.

        $result = $this->service->resolve($config, 100.0);

        $this->assertNull($result);
        $this->assertDatabaseCount('ratio_bot_brain_snapshots', 0);
    }

    /** @test */
    public function resolve_reuses_fresh_existing_snapshot_without_recomputing(): void
    {
        $config = $this->makeConfig('1h', brainEnabled: true);
        $existing = RatioBotBrainSnapshot::create([
            'ratio_bot_config_id' => $config->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'horizon_seconds' => 3600,
            'direction' => 'bullish',
            'confidence' => 70,
        ]);

        $result = $this->service->resolve($config, 100.0);

        $this->assertNotNull($result);
        $this->assertSame($existing->id, $result->id);
        $this->assertDatabaseCount('ratio_bot_brain_snapshots', 1);
    }

    /** @test */
    public function resolve_computes_a_fresh_snapshot_when_enough_candles_exist(): void
    {
        $config = $this->makeConfig('1h', brainEnabled: true);
        $this->seedCandles($config, 60);

        $result = $this->service->resolve($config, 100.0);

        $this->assertNotNull($result);
        $this->assertSame(3600, $result->horizon_seconds);
        $this->assertContains($result->direction, ['bullish', 'bearish', 'neutral']);
        $this->assertDatabaseCount('ratio_bot_brain_snapshots', 1);
    }

    private function makeConfig(string $timeframe, bool $brainEnabled = false): RatioBotConfig
    {
        return RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'name' => 'Brain test ' . $timeframe . ' ' . uniqid(),
            'symbol' => 'BTCUSDT',
            'enabled' => true,
            'timeframe' => $timeframe,
            'brain_enabled' => $brainEnabled,
            'brain_min_confidence' => 65,
        ]);
    }

    private function seedCandles(RatioBotConfig $config, int $count): void
    {
        $price = 100.0;
        $start = now()->subHours($count + 1);

        for ($i = 0; $i < $count; $i++) {
            $price += ($i % 5 === 0) ? 1.5 : -0.5;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $price,
                'high' => $price + 1,
                'low' => $price - 1,
                'close' => $price,
                'volume' => 10,
                'ts' => $start->copy()->addHours($i),
            ]);
        }
    }
}
