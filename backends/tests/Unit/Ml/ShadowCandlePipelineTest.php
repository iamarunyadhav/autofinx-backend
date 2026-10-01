<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\KlineBatchValidator;
use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Models\MlBackfillCheckpoint;
use App\Models\MlCandle;
use App\Models\MlCandleRejection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class ShadowCandlePipelineTest extends TestCase
{
    use MlShadowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
        Http::preventStrayRequests();
    }

    public function test_forming_candle_is_excluded_and_never_stored(): void
    {
        $forming = intdiv(now('UTC')->getTimestampMs(), self::FIVE_MIN) * self::FIVE_MIN;
        Http::fake(['*/fapi/v1/klines*' => Http::response([
            $this->kline($forming - 2 * self::FIVE_MIN),
            $this->kline($forming - self::FIVE_MIN),
            $this->kline($forming), // still forming: close_time is in the future
        ])]);

        $result = app(MlCandleCollector::class)->sync('BTCUSDT', '5m', 1);

        $this->assertSame(2, $result['written']);
        $this->assertSame(1, $result['forming_excluded']);
        $this->assertFalse(MlCandle::query()->where('open_time_ms', $forming)->exists());
        $this->assertTrue(MlCandle::query()->get()->every(fn (MlCandle $c) => $c->close_time_ms < now('UTC')->getTimestampMs()));
    }

    public function test_validator_rejects_every_bad_row_with_a_reason(): void
    {
        $base = $this->alignedOpen(50);
        $now = now('UTC')->getTimestampMs();
        $result = (new KlineBatchValidator)->validate([
            $this->kline($base, 100),
            $this->kline($base, 100),                                   // duplicate
            $this->kline($base + self::FIVE_MIN, 100, open: 100, high: 99), // invalid OHLCV (high < open)
            $this->kline($base + 123, 100),                             // misaligned -> mismatch
            $this->kline($base + self::FIVE_MIN, 100, closeMs: $base + 2 * self::FIVE_MIN + 5), // wrong duration -> mismatch
            $this->kline($base + 3 * self::FIVE_MIN, 100),              // gap: base+1, base+2 missing
            $this->kline($base + 2 * self::FIVE_MIN, 100),              // out of order
            $this->kline($base + 4 * self::FIVE_MIN, 300),              // +200% vs previous close -> wrong series
            ['garbage'],
        ], self::FIVE_MIN, $now);

        $reasons = array_count_values(array_column($result['rejections'], 'reason'));
        $this->assertSame(2, count($result['accepted']));
        $this->assertSame(1, $reasons[KlineBatchValidator::REASON_DUPLICATE]);
        $this->assertSame(2, $reasons[KlineBatchValidator::REASON_INVALID_OHLCV]);
        $this->assertSame(3, $reasons[KlineBatchValidator::REASON_MISMATCH]);
        $this->assertSame(2, $reasons[KlineBatchValidator::REASON_MISSING_INTERVAL]);
        $this->assertSame(1, $reasons[KlineBatchValidator::REASON_OUT_OF_ORDER]);
    }

    public function test_rerun_is_idempotent_and_conflicting_refetch_is_rejected_not_applied(): void
    {
        $first = $this->alignedOpen(20);
        Http::fakeSequence('*/fapi/v1/klines*')
            ->push([$this->kline($first, 100), $this->kline($first + self::FIVE_MIN, 101)])
            ->push([$this->kline($first, 100), $this->kline($first + self::FIVE_MIN, 101)])
            ->push([$this->kline($first, 100), $this->kline($first + self::FIVE_MIN, 555.5, open: 101, high: 600, low: 100)]);
        $collector = app(MlCandleCollector::class);
        $from = $first;
        $to = $first + self::FIVE_MIN;

        $collector->backfill('BTCUSDT', '5m', $from, $to);
        MlBackfillCheckpoint::query()->delete();
        $collector->backfill('BTCUSDT', '5m', $from, $to);
        MlBackfillCheckpoint::query()->delete();
        $collector->backfill('BTCUSDT', '5m', $from, $to);

        $this->assertSame(2, MlCandle::query()->count());
        $this->assertEquals(101.0, MlCandle::query()->where('open_time_ms', $first + self::FIVE_MIN)->value('close'));
        $this->assertTrue(MlCandleRejection::query()->where('reason', 'duplicate')->exists());
    }

    public function test_backfill_paginates_resumes_from_checkpoint_and_respects_page_budget(): void
    {
        config(['ml.binance.page_limit' => 3]);
        $from = $this->alignedOpen(30);
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $rows = [];
            for ($open = (int) $query['startTime']; $open <= (int) $query['endTime'] && count($rows) < (int) $query['limit']; $open += self::FIVE_MIN) {
                $rows[] = $this->kline($open, 100 + ($open / self::FIVE_MIN % 10));
            }

            return Http::response($rows);
        });
        $collector = app(MlCandleCollector::class);
        $to = $from + 8 * self::FIVE_MIN; // 9 candles = 3 pages of 3

        $partial = $collector->backfill('BTCUSDT', '5m', $from, $to, maxPages: 2);
        $this->assertSame('RUNNING', $partial->status);
        $this->assertSame(6, MlCandle::query()->count());

        $done = $collector->backfill('BTCUSDT', '5m', $from, $to);
        $this->assertSame('COMPLETED', $done->status);
        $this->assertSame(9, MlCandle::query()->count());
        $this->assertSame(3, $done->pages);
        Http::assertSentCount(3); // resumed: the first two pages were not fetched again
    }

    public function test_rate_limit_stops_backfill_with_a_resumable_checkpoint(): void
    {
        $from = $this->alignedOpen(30);
        Http::fake(['*/fapi/v1/klines*' => Http::response(['code' => -1003, 'msg' => 'Too many requests'], 429)]);

        try {
            app(MlCandleCollector::class)->backfill('BTCUSDT', '5m', $from, $from + 5 * self::FIVE_MIN);
            $this->fail('Expected the rate-limited backfill to stop.');
        } catch (RuntimeException) {
        }

        $checkpoint = MlBackfillCheckpoint::query()->firstOrFail();
        $this->assertSame('FAILED', $checkpoint->status);
        $this->assertSame($from, (int) $checkpoint->cursor_ms);
        $this->assertSame(0, MlCandle::query()->count());
    }

    public function test_missing_interval_is_recorded_then_resolved_when_the_candle_arrives(): void
    {
        $first = $this->alignedOpen(10);
        Http::fakeSequence('*/fapi/v1/klines*')
            ->push([$this->kline($first), $this->kline($first + 2 * self::FIVE_MIN)])
            ->push([])
            ->push([$this->kline($first + self::FIVE_MIN)]);

        $collector = app(MlCandleCollector::class);
        $collector->backfill('BTCUSDT', '5m', $first, $first + 2 * self::FIVE_MIN);
        $gap = MlCandleRejection::query()->where('reason', 'missing_interval')->firstOrFail();
        $this->assertSame($first + self::FIVE_MIN, (int) $gap->open_time_ms);
        $this->assertFalse($gap->resolved);

        $collector->sync('BTCUSDT', '5m', 1); // tail page (empty) then gap retry
        $this->assertTrue($gap->refresh()->resolved);
        $this->assertSame(3, MlCandle::query()->count());
    }
}
