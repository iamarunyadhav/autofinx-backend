<?php

namespace Tests\Unit\Trading;

use App\Domain\Trading\MultiWindowForecastComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiWindowForecastComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
        Cache::forgetDriver('redis');
        Bus::fake();
    }

    public function test_windows_reflects_config_short_medium_long(): void
    {
        $windows = app(MultiWindowForecastComparisonService::class)->windows();

        $this->assertCount(3, $windows);
        $this->assertSame(['short', 'medium', 'long'], array_column($windows, 'label'));
        $this->assertSame(['15m', '1h', '4h'], array_column($windows, 'timeframe'));
        $this->assertSame([900, 3600, 14400], array_column($windows, 'expiry_seconds'));
    }

    public function test_an_invalid_correlation_id_is_replaced_with_a_fresh_uuid_never_trusting_client_input(): void
    {
        $service = app(MultiWindowForecastComparisonService::class);
        $base = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'stake_amount' => 10, 'mode' => 'paper'];

        $result = $service->compare('actor-a', $base, ['short' => [], 'medium' => [], 'long' => []], 'not-a-uuid');

        $this->assertTrue(Str::isUuid($result['correlation_id']));
        $this->assertNotSame('not-a-uuid', $result['correlation_id']);
    }

    public function test_a_valid_client_supplied_correlation_id_is_reused_for_polling(): void
    {
        $service = app(MultiWindowForecastComparisonService::class);
        $base = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'stake_amount' => 10, 'mode' => 'paper'];
        $uuid = (string) Str::uuid();

        $result = $service->compare('actor-a', $base, ['short' => [], 'medium' => [], 'long' => []], $uuid);

        $this->assertSame($uuid, $result['correlation_id']);
    }

    public function test_each_window_stays_independently_labelled_never_averaged(): void
    {
        $service = app(MultiWindowForecastComparisonService::class);
        $base = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'stake_amount' => 10, 'mode' => 'paper'];

        $result = $service->compare('actor-a', $base, ['short' => [], 'medium' => [], 'long' => []]);

        $this->assertCount(3, $result['windows']);
        $labels = array_column($result['windows'], 'window_label');
        $this->assertEqualsCanonicalizing(['short', 'medium', 'long'], $labels);
        // No fused/averaged top-level field exists on the response at all.
        $this->assertArrayNotHasKey('fused_direction', $result);
        $this->assertArrayNotHasKey('average_confidence', $result);
    }
}
