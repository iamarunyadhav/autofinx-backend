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
        $first = $this->alignedOpen(300);
        $this->seedCandles('BTCUSDT', $first, 200);
        $service = app(FeatureLabelService::class);
        $service->build('BTCUSDT');
        $anchor = $first + 100 * self::FIVE_MIN; // anchor candle index 99 closes here
        $before = MlFeatureRow::query()->where('anchor_time_ms', $anchor)->firstOrFail()->features;

        // Rewrite every candle that closes AFTER the anchor with wild values,
        // then rebuild from scratch: the anchor's features must not move.
        MlCandle::query()->where('open_time_ms', '>=', $anchor)->update(['open' => 1, 'high' => 9999, 'low' => 0.5, 'close' => 5000, 'volume' => 1e9]);
        MlFeatureRow::query()->delete();
        MlLabel::query()->delete();
        $service->build('BTCUSDT', full: true);

        $after = MlFeatureRow::query()->where('anchor_time_ms', $anchor)->firstOrFail()->features;
        $this->assertSame(ShadowFeatureBuilder::FEATURES, array_keys($after));
        foreach (ShadowFeatureBuilder::FEATURES as $name) {
            $this->assertSame($before[$name], $after[$name], "feature {$name} leaked future data");
        }
    }

    public function test_builder_refuses_a_window_that_is_not_exactly_lookback_contiguous_candles(): void
    {
        $window = array_map(fn (int $i) => ['open_time_ms' => $i * self::FIVE_MIN, 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0, 'volume' => 1.0], range(0, ShadowFeatureBuilder::LOOKBACK - 1));
        $gapped = $window;
        $gapped[10]['open_time_ms'] += 1;

        $this->assertCount(count(ShadowFeatureBuilder::FEATURES), (new ShadowFeatureBuilder)->build($window, self::FIVE_MIN));
        $this->expectException(InvalidArgumentException::class);
        (new ShadowFeatureBuilder)->build($gapped, self::FIVE_MIN);
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
        $first = $this->alignedOpen(150);
        $this->seedCandles('BTCUSDT', $first, 120, fn (int $i) => 100 + $i); // close at index i = 100 + i
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
        $label = MlLabel::query()->where('symbol', 'ETHUSDT')->where('horizon_minutes', 15)->where('anchor_time_ms', $last)->firstOrFail();
        $this->assertSame('PENDING', $label->status);

        $this->seedCandles('ETHUSDT', $first + 70 * self::FIVE_MIN, 3, fn (int $i) => 200.0 + $i);
        $this->assertGreaterThanOrEqual(1, $service->mature('ETHUSDT')[15]);
        $this->assertSame('MATURE', $label->refresh()->status);
        $this->assertEqualsWithDelta(202.0, $label->target_close, 1e-9);
    }

    public function test_no_cross_symbol_or_cross_horizon_mixing(): void
    {
        $first = $this->alignedOpen(200);
        $this->seedCandles('BTCUSDT', $first, 150, fn (int $i) => 60000 + $i);
        $this->seedCandles('ETHUSDT', $first, 150, fn (int $i) => 3000 - $i * 0.5);
        $service = app(FeatureLabelService::class);
        $service->build('BTCUSDT');
        $service->build('ETHUSDT');

        foreach (['BTCUSDT' => fn (int $i) => 60000 + $i, 'ETHUSDT' => fn (int $i) => 3000 - $i * 0.5] as $symbol => $price) {
            foreach ([15 => 3, 60 => 12, 240 => 48] as $horizon => $steps) {
                $labels = MlLabel::query()->where('symbol', $symbol)->where('horizon_minutes', $horizon)->where('status', 'MATURE')->get();
                $this->assertNotEmpty($labels->all(), "{$symbol} h{$horizon}");
                foreach ($labels as $label) {
                    $index = intdiv((int) $label->anchor_time_ms - $first, self::FIVE_MIN) - 1;
                    $this->assertEqualsWithDelta($price($index), $label->anchor_close, 1e-6);
                    $this->assertEqualsWithDelta($price($index + $steps), $label->target_close, 1e-6);
                }
            }
        }
        $this->assertSame(0, DB::table('ml_labels')->whereNotIn('horizon_minutes', [15, 60, 240])->count());
    }
}
