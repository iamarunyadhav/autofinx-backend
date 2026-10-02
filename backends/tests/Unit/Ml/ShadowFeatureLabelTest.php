<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Models\MlCandle;
use App\Models\MlFeatureRow;
use App\Models\MlLabel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class ShadowFeatureLabelTest extends TestCase
{
    use MlShadowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
    }

    public function test_future_candles_cannot_enter_the_feature_vector(): void
    {
        $first = $this->alignedOpen(1100);
        $this->seedCandles('BTCUSDT', $first, 1000);
        $service = app(FeatureLabelService::class);
        $service->build('BTCUSDT');
        $anchor = $first + 900 * self::FIVE_MIN; // anchor candle index 899 closes here (v3 needs 865 bars)
        $schemas = $service->schemasToBuild();
        $this->assertContains(ShadowFeatureBuilder::SCHEMA_VERSION, $schemas);
        $row = fn (string $schema) => MlFeatureRow::query()->where('anchor_time_ms', $anchor)->where('feature_schema_version', $schema)->firstOrFail()->features;
        $before = array_map($row, array_combine($schemas, $schemas));

        // Rewrite every candle that closes AFTER the anchor with wild values
        // (taker volume included), then rebuild from scratch: no schema's
        // features for the anchor may move.
        MlCandle::query()->where('open_time_ms', '>=', $anchor)->update(['open' => 1, 'high' => 9999, 'low' => 0.5, 'close' => 5000, 'volume' => 1e9, 'taker_buy_volume' => 1e9]);
        MlFeatureRow::query()->delete();
        MlLabel::query()->delete();
        $service->build('BTCUSDT', full: true);

        foreach ($schemas as $schema) {
            $names = ShadowFeatureBuilder::features($schema);
            $after = $row($schema);
            $this->assertEqualsCanonicalizing($names, array_keys($after), $schema);
            foreach ($names as $name) {
                $this->assertSame($before[$schema][$name], $after[$name], "{$schema} feature {$name} leaked future data");
            }
        }
    }

    public function test_schemas_are_append_only_and_v3_needs_history_and_taker_volume(): void
    {
        $this->assertSame(ShadowFeatureBuilder::FEATURES_V1, array_slice(ShadowFeatureBuilder::FEATURES_V2, 0, count(ShadowFeatureBuilder::FEATURES_V1)));
        $this->assertSame(ShadowFeatureBuilder::FEATURES_V2, array_slice(ShadowFeatureBuilder::FEATURES_V3, 0, count(ShadowFeatureBuilder::FEATURES_V2)));

        $first = $this->alignedOpen(1100);
        $this->seedCandles('BTCUSDT', $first, 1000, withTaker: false); // Phase 1-style rows
        app(FeatureLabelService::class)->build('BTCUSDT');

        $this->assertGreaterThan(0, MlFeatureRow::query()->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_V1)->count());
        $this->assertSame(0, MlFeatureRow::query()->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_V3)->count());
        // Labels exist regardless of schema; training (v3) just has no rows yet.
        $this->assertGreaterThan(0, MlLabel::query()->where('status', 'MATURE')->count());

        // Once taker volume is filled in, a full rebuild adds v3 rows — only
        // from the 865th candle on — whose v1 part is identical to v1 rows.
        MlCandle::query()->update(['taker_buy_volume' => DB::raw('volume * 0.5')]);
        app(FeatureLabelService::class)->build('BTCUSDT', full: true);
        $firstV3 = (int) MlFeatureRow::query()->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_V3)->min('anchor_time_ms');
        $this->assertSame($first + ShadowFeatureBuilder::LONG_LOOKBACK * self::FIVE_MIN, $firstV3);
        $v3 = MlFeatureRow::query()->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_V3)->where('anchor_time_ms', $firstV3)->firstOrFail();
        $v1 = MlFeatureRow::query()->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_V1)->where('anchor_time_ms', $firstV3)->firstOrFail();
        foreach (ShadowFeatureBuilder::FEATURES_V1 as $name) {
            $this->assertSame($v1->features[$name], $v3->features[$name], $name);
        }
        $this->assertEqualsWithDelta(0.5, $v3->features['taker_ratio_12'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $v3->features['taker_ratio_24h'], 1e-9);
        $this->assertGreaterThanOrEqual(0.0, $v3->features['range_pos_72h']);
        $this->assertLessThanOrEqual(1.0, $v3->features['range_pos_72h']);
    }

    public function test_v3_long_context_values_are_correct(): void
    {
        // Price rises 0.1% per bar; constant volume/taker share.
        $window = array_map(fn (int $i) => ['open_time_ms' => $i * self::FIVE_MIN, 'open' => 100 * 1.001 ** max(0, $i - 1), 'high' => 100 * 1.001 ** $i * 1.0005,
            'low' => 100 * 1.001 ** max(0, $i - 1) * 0.9995, 'close' => 100 * 1.001 ** $i, 'volume' => 2.0, 'taker_buy_volume' => 1.5],
            range(0, ShadowFeatureBuilder::LONG_LOOKBACK - 1));
        $features = (new ShadowFeatureBuilder)->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V3);

        $this->assertSame(ShadowFeatureBuilder::FEATURES_V3, array_keys($features));
        $this->assertEqualsWithDelta(144 * log(1.001), $features['ret_12h'], 1e-9);
        $this->assertEqualsWithDelta(288 * log(1.001), $features['ret_24h'], 1e-9);
        $this->assertEqualsWithDelta(864 * log(1.001), $features['ret_72h'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $features['taker_ratio_24h'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $features['volume_ratio_24h'], 1e-9);
        $this->assertGreaterThan(0.99, $features['range_pos_72h']); // at the top of a steady uptrend
        $this->assertGreaterThan(0, $features['vwap_gap_24h']);     // above the day's VWAP
    }

    public function test_builder_refuses_a_window_that_is_not_exactly_lookback_contiguous_candles(): void
    {
        $window = array_map(fn (int $i) => ['open_time_ms' => $i * self::FIVE_MIN, 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0, 'volume' => 1.0, 'taker_buy_volume' => 0.6], range(0, ShadowFeatureBuilder::LOOKBACK - 1));
        $gapped = $window;
        $gapped[10]['open_time_ms'] += 1;
        $noTaker = $window;
        $noTaker[ShadowFeatureBuilder::LOOKBACK - 1]['taker_buy_volume'] = null;
        $builder = new ShadowFeatureBuilder;

        $this->assertCount(count(ShadowFeatureBuilder::FEATURES_V2), $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V2));
        try {
            $builder->build($window, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V3); // 64 bars < 865
            $this->fail('v3 must refuse a window shorter than its lookback');
        } catch (InvalidArgumentException) {
        }
        $this->assertCount(count(ShadowFeatureBuilder::FEATURES_V1), $builder->build($noTaker, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V1));
        try {
            $builder->build($noTaker, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V2);
            $this->fail('v2 must refuse a window without taker volume');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        $builder->build($gapped, self::FIVE_MIN, ShadowFeatureBuilder::SCHEMA_V1);
    }

    public function test_no_feature_row_is_built_across_a_gap(): void
    {
        $first = $this->alignedOpen(400);
        $this->seedCandles('BTCUSDT', $first, 100);
        $this->seedCandles('BTCUSDT', $first + 101 * self::FIVE_MIN, 100); // one candle missing at index 100
        app(FeatureLabelService::class)->build('BTCUSDT');

        $gapClose = $first + 101 * self::FIVE_MIN;
        $firstAfterGap = $first + (101 + ShadowFeatureBuilder::LOOKBACK) * self::FIVE_MIN; // needs a full new window
        $this->assertFalse(MlFeatureRow::query()->whereBetween('anchor_time_ms', [$gapClose, $firstAfterGap - 1])->exists());
        $this->assertTrue(MlFeatureRow::query()->where('anchor_time_ms', $firstAfterGap)->exists());
    }

    public function test_labels_use_the_exact_target_close_per_horizon_and_stay_pending_without_it(): void
    {
        // 1440m needs 288 bars after the anchor: index 70 + 288 = 358 < 400.
        $first = $this->alignedOpen(420);
        $this->seedCandles('BTCUSDT', $first, 400, fn (int $i) => 100 + $i); // close at index i = 100 + i
        app(FeatureLabelService::class)->build('BTCUSDT');

        $anchorIndex = 70;
        $anchor = $first + ($anchorIndex + 1) * self::FIVE_MIN;
        foreach ([60 => 12, 120 => 24, 240 => 48, 1440 => 288] as $horizon => $steps) {
            $label = MlLabel::query()->where('symbol', 'BTCUSDT')->where('horizon_minutes', $horizon)->where('anchor_time_ms', $anchor)->firstOrFail();
            $this->assertSame($anchor + $horizon * 60_000, (int) $label->target_time_ms);
            $this->assertSame('MATURE', $label->status, "h{$horizon}");
            $this->assertEqualsWithDelta(100 + $anchorIndex + $steps, $label->target_close, 1e-9);
            $this->assertEqualsWithDelta((100 + $anchorIndex + $steps) / (100 + $anchorIndex) - 1, $label->future_return, 1e-12);
        }
        // Near the end the 1440m target candle does not exist yet: PENDING, never false/zero.
        $late = $first + (380 + 1) * self::FIVE_MIN;
        $pending = MlLabel::query()->where('horizon_minutes', 1440)->where('anchor_time_ms', $late)->firstOrFail();
        $this->assertSame('PENDING', $pending->status);
        $this->assertNull($pending->future_return);
        $this->assertSame(0, MlLabel::query()->where('horizon_minutes', 15)->count()); // 15m dropped
    }

    public function test_legacy_label_shape_for_a_short_horizon(): void
    {
        config(['ml.horizons_minutes' => [15, 60, 240]]);
        $first = $this->alignedOpen(150);
        $this->seedCandles('BTCUSDT', $first, 120, fn (int $i) => 100 + $i);
        app(FeatureLabelService::class)->build('BTCUSDT');

        $anchorIndex = 70;
        $anchor = $first + ($anchorIndex + 1) * self::FIVE_MIN;
        foreach ([15 => 3, 60 => 12, 240 => 48] as $horizon => $steps) {
            $label = MlLabel::query()->where('symbol', 'BTCUSDT')->where('horizon_minutes', $horizon)->where('anchor_time_ms', $anchor)->firstOrFail();
            $this->assertSame($anchor + $horizon * 60_000, (int) $label->target_time_ms);
            if ($anchorIndex + $steps < 120) {
                $this->assertSame('MATURE', $label->status);
                $this->assertEqualsWithDelta(100 + $anchorIndex + $steps, $label->target_close, 1e-9);
                $this->assertEqualsWithDelta((100 + $anchorIndex + $steps) / (100 + $anchorIndex) - 1, $label->future_return, 1e-12);
            } else {
                // 240m target candle does not exist yet: PENDING, never false/zero.
                $this->assertSame('PENDING', $label->status);
                $this->assertNull($label->future_return);
            }
        }
    }

    public function test_pending_labels_mature_when_the_target_candle_arrives(): void
    {
        $first = $this->alignedOpen(150);
        $this->seedCandles('ETHUSDT', $first, 70);
        $service = app(FeatureLabelService::class);
        $service->build('ETHUSDT');
        $last = MlFeatureRow::query()->where('symbol', 'ETHUSDT')->max('anchor_time_ms');
        $label = MlLabel::query()->where('symbol', 'ETHUSDT')->where('horizon_minutes', 60)->where('anchor_time_ms', $last)->firstOrFail();
        $this->assertSame('PENDING', $label->status);

        // h60 target = anchor + 60m -> candle index 69 + 12 = 81.
        $this->seedCandles('ETHUSDT', $first + 70 * self::FIVE_MIN, 12, fn (int $i) => 200.0 + $i);
        $this->assertGreaterThanOrEqual(1, $service->mature('ETHUSDT')[60]);
        $this->assertSame('MATURE', $label->refresh()->status);
        $this->assertEqualsWithDelta(211.0, $label->target_close, 1e-9);
    }

    public function test_no_cross_symbol_or_cross_horizon_mixing(): void
    {
        $first = $this->alignedOpen(480);
        $this->seedCandles('BTCUSDT', $first, 450, fn (int $i) => 60000 + $i);
        $this->seedCandles('ETHUSDT', $first, 450, fn (int $i) => 3000 - $i * 0.5);
        $service = app(FeatureLabelService::class);
        $service->build('BTCUSDT');
        $service->build('ETHUSDT');

        foreach (['BTCUSDT' => fn (int $i) => 60000 + $i, 'ETHUSDT' => fn (int $i) => 3000 - $i * 0.5] as $symbol => $price) {
            foreach ([60 => 12, 120 => 24, 240 => 48, 1440 => 288] as $horizon => $steps) {
                $labels = MlLabel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->where('status', 'MATURE')->get();
                $this->assertNotEmpty($labels->all(), "{$symbol} h{$horizon}");
                foreach ($labels as $label) {
                    $index = intdiv((int) $label->anchor_time_ms - $first, self::FIVE_MIN) - 1;
                    $this->assertEqualsWithDelta($price($index), $label->anchor_close, 1e-6);
                    $this->assertEqualsWithDelta($price($index + $steps), $label->target_close, 1e-6);
                }
            }
        }
        $this->assertSame(0, DB::table('ml_labels')->whereNotIn('horizon_minutes', [60, 120, 240, 1440])->count());
    }
}
