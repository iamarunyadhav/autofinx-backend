<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\PatternFeatures;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Models\MlCandle;
use App\Models\MlFeatureRow;
use App\Models\MlLabel;
use Tests\TestCase;

/** Research schema v4 (docs/ml-engine-v1 SPEC §102, D-014): append-only, finite, causal (K, L5, L6). */
final class PatternFeaturesTest extends TestCase
{
    use MlShadowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
    }

    /** Deterministic noisy random walk ending so the anchor closes at $anchorClose. */
    private function window(int $anchorClose, int $n = ShadowFeatureBuilder::LONG_LOOKBACK): array
    {
        $first = $anchorClose - $n * self::FIVE_MIN;
        $price = 100.0;
        $rows = [];
        mt_srand(42);
        for ($i = 0; $i < $n; $i++) {
            $open = $price;
            $close = $open * (1 + (mt_rand(-1000, 1000) / 1000) * 0.003);
            $rows[] = ['open_time_ms' => $first + $i * self::FIVE_MIN, 'open' => $open, 'close' => $close,
                'high' => max($open, $close) * (1 + mt_rand(0, 1000) / 1000 * 0.002),
                'low' => min($open, $close) * (1 - mt_rand(0, 1000) / 1000 * 0.002),
                'volume' => 10 + mt_rand(0, 100) / 10, 'taker_buy_volume' => 5 + mt_rand(0, 50) / 10];
            $price = $close;
        }

        return $rows;
    }

    public function test_v4_is_v3_plus_pattern_features_with_identical_v3_values(): void
    {
        $this->assertSame([...ShadowFeatureBuilder::FEATURES_V3, ...PatternFeatures::NAMES], ShadowFeatureBuilder::FEATURES_V4);
        $this->assertSame(ShadowFeatureBuilder::SCHEMA_V3, ShadowFeatureBuilder::SCHEMA_VERSION);   // server default unchanged
        $window = $this->window(1_700_000_100_000 - (1_700_000_100_000 % 300_000));
        $builder = new ShadowFeatureBuilder;
        $v3 = $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V3);
        $v4 = $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V4);
        $this->assertSame(ShadowFeatureBuilder::FEATURES_V4, array_keys($v4));
        foreach ($v3 as $name => $value) {
            $this->assertSame($value, $v4[$name], $name);
        }
        foreach ($v4 as $name => $value) {
            $this->assertTrue(is_finite($value), $name);
        }
    }

    public function test_L6_a_swing_is_not_used_before_its_confirmation_bars_close(): void
    {
        $window = $this->window(1_700_000_100_000 - (1_700_000_100_000 % 300_000));
        $n = count($window);
        // The last 9 bars rise steadily, so no confirmed swing exists among them.
        $base = $window[$n - 10]['close'];
        for ($j = 9; $j >= 1; $j--) {
            $i = $n - $j;
            $p = $base * (1 + (10 - $j) * 0.001);
            $window[$i] = ['open_time_ms' => $window[$i]['open_time_ms'], 'open' => $p * 0.9995, 'close' => $p, 'high' => $p * 1.0002,
                'low' => $p * 0.999, 'volume' => 10.0, 'taker_buy_volume' => 5.0];
        }
        $builder = new ShadowFeatureBuilder;
        $before = $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V4);
        // Turn the bar just before the anchor into a clear peak: it is closed, but only 1 confirmation
        // bar has closed after it, so it must NOT count as a swing high (SWING_K = 3).
        $window[$n - 2]['high'] *= 1.05;
        $after = $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V4);
        foreach (['g1_hh_share', 'g1_dist_swing_high', 'g1_swing_range_pos', 'g1_break_up_age'] as $name) {
            $this->assertSame($before[$name], $after[$name], "{$name} used an unconfirmed swing");
        }
    }

    public function test_L5_higher_timeframe_features_never_read_the_bucket_still_forming(): void
    {
        $hour = 3_600_000;
        $anchorClose = 1_700_006_400_000 - (1_700_006_400_000 % $hour) + 35 * 60_000;   // T0 = hh:35
        $window = $this->window($anchorClose);
        $builder = new ShadowFeatureBuilder;
        $before = $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V4);
        // Rewrite every candle inside the forming hour [hh:00, hh:35): 1h (and 4h) features must not move.
        $hourStart = $anchorClose - 35 * 60_000;
        foreach ($window as $i => $candle) {
            if ($candle['open_time_ms'] >= $hourStart) {
                $window[$i]['close'] *= 1.03; $window[$i]['high'] *= 1.04; $window[$i]['low'] *= 0.97;
            }
        }
        $after = $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V4);
        foreach (['g5_1h_ret3', 'g5_1h_ema_gap', 'g5_1h_structure', 'g5_1h_body', 'g5_1h_age', 'g5_4h_ret3', 'g5_4h_ema_gap', 'g5_4h_structure'] as $name) {
            $this->assertSame($before[$name], $after[$name], "{$name} read the forming bucket");
        }
        $this->assertNotSame($before['g5_15m_ret3'], $after['g5_15m_ret3']);   // the closed 15m buckets inside the hour do change
        $this->assertEqualsWithDelta(35 / 60, $after['g5_1h_age'], 1e-9);       // last complete 1h bar closed 35 minutes ago
    }

    public function test_K_future_candles_cannot_enter_v4_features(): void
    {
        config(['ml.extra_feature_schemas' => [ShadowFeatureBuilder::SCHEMA_V4]]);
        $first = $this->alignedOpen(1100);
        $this->seedCandles('BTCUSDT', $first, 1000);
        $service = app(FeatureLabelService::class);
        $this->assertContains(ShadowFeatureBuilder::SCHEMA_V4, $service->schemasToBuild());
        $service->build('BTCUSDT');
        $anchor = $first + 900 * self::FIVE_MIN;
        $row = fn () => MlFeatureRow::query()->where('anchor_time_ms', $anchor)->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_V4)->firstOrFail()->features;
        $before = $row();

        MlCandle::query()->where('open_time_ms', '>=', $anchor)->update(['open' => 1, 'high' => 9999, 'low' => 0.5, 'close' => 5000, 'volume' => 1e9, 'taker_buy_volume' => 1e9]);
        MlFeatureRow::query()->delete();
        MlLabel::query()->delete();
        $service->build('BTCUSDT', full: true);

        $after = $row();
        foreach (ShadowFeatureBuilder::FEATURES_V4 as $name) {
            $this->assertSame($before[$name], $after[$name], "v4 feature {$name} leaked future data");
        }
    }

    public function test_v4_is_not_built_unless_configured(): void
    {
        config(['ml.extra_feature_schemas' => []]);
        $this->assertNotContains(ShadowFeatureBuilder::SCHEMA_V4, app(FeatureLabelService::class)->schemasToBuild());
    }
}
