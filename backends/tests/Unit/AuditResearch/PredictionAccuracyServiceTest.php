<?php

namespace Tests\Unit\AuditResearch;

use App\Domain\AuditResearch\PredictionAccuracyService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Must return real numbers sourced from an existing seeded
 * RatioBotForecastBacktest row — never fabricated, never recomputed here.
 */
class PredictionAccuracyServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_shapes_the_existing_backtest_row_without_recomputing_it(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => (string) \Illuminate\Support\Str::uuid(),
            'symbol' => 'BTCUSDT',
            'execution_mode' => 'sandbox',
        ]);

        RatioBotForecastBacktest::create([
            'ratio_bot_config_id' => $config->id,
            'horizon_seconds' => 3600,
            'sample_size' => 42,
            'hits' => 30,
            'hit_rate_pct' => 71.43,
            'avg_confidence_pct' => 65.5,
            'computed_at' => now(),
        ]);

        $result = (new PredictionAccuracyService())->forConfig($config);

        $this->assertCount(1, $result);
        $this->assertSame(3600, $result[0]['horizon_seconds']);
        $this->assertSame(71.43, $result[0]['hit_rate_pct']);
        $this->assertSame(42, $result[0]['sample_size']);
        $this->assertSame(65.5, $result[0]['avg_confidence_pct']);
    }

    /** @test */
    public function a_config_with_no_backtest_rows_returns_an_empty_array_not_a_fabricated_one(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => (string) \Illuminate\Support\Str::uuid(),
            'symbol' => 'ETHUSDT',
            'execution_mode' => 'sandbox',
        ]);

        $result = (new PredictionAccuracyService())->forConfig($config);

        $this->assertSame([], $result);
    }
}
