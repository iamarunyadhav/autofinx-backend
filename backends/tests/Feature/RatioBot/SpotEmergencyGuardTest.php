<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\SpotEmergencyGuard;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotEmergencyGuardTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_a_sharp_drawdown_exceeding_the_threshold_triggers_emergency(): void
    {
        $config = $this->makeBot(['emergency_atr_multiple_threshold' => 3.0]);
        // Calm history (tight ranges -> small ATR), then a hard 15% drop over
        // the lookback window -- a huge multiple of that small ATR.
        $this->seedCalmHistory($config, count: 18, price: 100.0);

        $result = app(SpotEmergencyGuard::class)->assess($config, currentPrice: 85.0);

        $this->assertTrue($result['is_emergency']);
        $this->assertGreaterThan(3.0, $result['atr_multiple']);
    }

    public function test_normal_volatility_drawdown_does_not_trigger_emergency(): void
    {
        $config = $this->makeBot(['emergency_atr_multiple_threshold' => 3.0]);
        // Same calm history, but only a tiny drift -- well within normal ATR.
        $this->seedCalmHistory($config, count: 18, price: 100.0);

        $result = app(SpotEmergencyGuard::class)->assess($config, currentPrice: 99.9);

        $this->assertFalse($result['is_emergency']);
    }

    public function test_insufficient_history_fails_safe_to_not_an_emergency(): void
    {
        $config = $this->makeBot();
        $this->seedCalmHistory($config, count: 3, price: 100.0);

        $result = app(SpotEmergencyGuard::class)->assess($config, currentPrice: 50.0);

        $this->assertFalse($result['is_emergency']);
        $this->assertStringContainsString('insufficient', $result['reason']);
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

    private function seedCalmHistory(RatioBotConfig $config, int $count, float $price): void
    {
        $time = now()->subMinutes($count + 1);
        for ($i = 0; $i < $count; $i++) {
            // Small, consistent 0.1-unit ranges -- a low, stable ATR.
            $open = $price;
            $close = $price + (($i % 2 === 0) ? 0.05 : -0.05);
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $open,
                'high' => max($open, $close) + 0.05,
                'low' => min($open, $close) - 0.05,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }
}
