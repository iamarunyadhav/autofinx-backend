<?php

namespace Tests\Feature\Trading;

use App\Jobs\CalculateTradeForecast;
use App\Models\MultiWindowForecastAudit;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VNext Dynamic Decision Engine, Phase A item 1 -- end-to-end proof that
 * POST /quick-trade/forecast/multi-window actually computes all 3 windows
 * (short/medium/long) through the EXISTING AsyncTradeForecast
 * caching/locking pattern, keeps each window's own Direction/Trade
 * Decision, and persists the audit rows (item 2).
 */
class MultiWindowForecastComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
        Cache::forgetDriver('redis');
        Bus::fake();
        config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
        config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
        config()->set('quick_trade.fee_model', 'taker');
    }

    /** @return array<int, array<string, mixed>> */
    private function candles(int $count = 40, int $timeframeSeconds = 900): array
    {
        $now = CarbonImmutable::now();
        $rows = [];
        $price = 2000.0;
        for ($i = $count; $i >= 1; $i--) {
            $open = $price;
            $price += 3.0;
            $rows[] = [
                'timestamp' => $now->subSeconds($i * $timeframeSeconds)->getTimestampMs(),
                'open' => $open, 'high' => $price + 2, 'low' => $open - 2,
                'close' => $price, 'volume' => 900.0,
            ];
        }

        return $rows;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance_futures',
            'stake_amount' => 50,
            'windows' => [
                'short' => ['candles' => $this->candles(40, 900)],
                'medium' => ['candles' => $this->candles(40, 3600)],
                'long' => ['candles' => $this->candles(40, 14400)],
            ],
        ], $overrides);
    }

    public function test_first_call_dispatches_one_job_per_window_and_reports_calculating(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/quick-trade/forecast/multi-window', $this->payload())
            ->assertOk();

        $body = $response->json();
        $this->assertSame(1, $body['schema_version']);
        $this->assertTrue(Str::isUuid($body['correlation_id']));
        $this->assertCount(3, $body['windows']);

        $labels = array_column($body['windows'], 'window_label');
        $this->assertEqualsCanonicalizing(['short', 'medium', 'long'], $labels);
        foreach ($body['windows'] as $window) {
            $this->assertSame('calculating', $window['status']);
        }

        Bus::assertDispatchedTimes(CalculateTradeForecast::class, 3);
        Bus::assertDispatched(CalculateTradeForecast::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'predictions');
    }

    public function test_second_call_with_the_same_correlation_id_returns_all_three_windows_ready_and_writes_audit_rows(): void
    {
        $user = User::factory()->create();
        // AsyncTradeForecast's cache key is an exact hash of the whole
        // per-window input, candles included (see its own docblock: fresh
        // candle content is deliberately a NEW scope, "latest wins"). Reuse
        // the SAME payload/candles across both calls -- exactly what a real
        // poll loop does within one comparison's lifetime -- so the second
        // call is a genuine cache hit rather than a second in-flight job.
        $payload = $this->payload();
        $first = $this->actingAs($user)
            ->postJson('/api/v1/quick-trade/forecast/multi-window', $payload)
            ->assertOk();
        $correlationId = $first->json('correlation_id');

        // Simulate the queue worker: run each dispatched job for real
        // (Bus::fake() only intercepts dispatch, .handle() below is the
        // real QuickTradeForecastCalculator/TradeForecastService pipeline).
        foreach (Bus::dispatched(CalculateTradeForecast::class) as $job) {
            $job->handle(app(\App\Domain\Trading\QuickTradeForecastCalculator::class), app(\App\Domain\Trading\TradeForecastService::class));
        }

        $second = $this->actingAs($user)
            ->postJson('/api/v1/quick-trade/forecast/multi-window', array_merge($payload, ['correlation_id' => $correlationId]))
            ->assertOk();

        $windows = collect($second->json('windows'))->keyBy('window_label');
        $this->assertSame('ready', $windows['short']['status']);
        $this->assertSame('ready', $windows['medium']['status']);
        $this->assertSame('ready', $windows['long']['status']);
        $this->assertSame(900, $windows['short']['expiry_seconds']);
        $this->assertSame('15m', $windows['short']['timeframe']);
        $this->assertSame(3600, $windows['medium']['expiry_seconds']);
        $this->assertSame('1h', $windows['medium']['timeframe']);
        $this->assertSame(14400, $windows['long']['expiry_seconds']);
        $this->assertSame('4h', $windows['long']['timeframe']);
        foreach (['short', 'medium', 'long'] as $label) {
            $this->assertArrayHasKey('direction', $windows[$label]['forecast']);
            $this->assertArrayHasKey('trade_decision', $windows[$label]['forecast']);
        }

        // No second dispatch just because the cached result was served.
        Bus::assertDispatchedTimes(CalculateTradeForecast::class, 3);

        $rows = MultiWindowForecastAudit::query()->where('correlation_id', $correlationId)->get();
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(['short', 'medium', 'long'], $rows->pluck('window_label')->all());
        foreach ($rows as $row) {
            $this->assertSame('BTCUSDT', $row->symbol);
            $this->assertNotNull($row->direction);
            $this->assertNotNull($row->trade_decision);
        }
    }

    public function test_a_workspace_the_caller_does_not_belong_to_is_rejected(): void
    {
        $otherWorkspace = Workspace::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/quick-trade/forecast/multi-window', $this->payload(['workspace_id' => $otherWorkspace->id]))
            ->assertForbidden();
    }

    public function test_a_workspace_member_is_allowed(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)
            ->postJson('/api/v1/quick-trade/forecast/multi-window', $this->payload(['workspace_id' => $workspace->id]))
            ->assertOk();
    }

    public function test_missing_a_required_window_is_a_validation_error(): void
    {
        $payload = $this->payload();
        unset($payload['windows']['long']);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/quick-trade/forecast/multi-window', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['windows.long']);
    }

    public function test_accuracy_report_endpoint_never_crashes_when_no_data_exists_yet(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/quick-trade/forecast/multi-window/accuracy-report')
            ->assertOk();

        $windows = $response->json('windows');
        foreach (['short', 'medium', 'long'] as $label) {
            $this->assertSame(0, $windows[$label]['n']);
            $this->assertSame('insufficient', $windows[$label]['status']);
        }
    }
}
