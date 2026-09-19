<?php

namespace Tests\Feature\Trading;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\QuickTradeResearchService;
use App\Models\QuickTrade;
use App\Models\QuickTradeResearchSample;
use App\Models\RatioBotForecastBacktest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickTradeResearchTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);
    }

    private function trade(array $overrides = []): QuickTrade
    {
        return QuickTrade::create(array_merge(['workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT',
            'side' => 'buy', 'stake_amount' => 100, 'quantity' => 1, 'asset_class' => 'CRYPTO',
            'mode' => 'live', 'source' => 'manual_quick_trade', 'provider_key' => 'binance', 'quote_currency' => 'USDT',
            'timeframe' => '1m', 'expiry_seconds' => 60, 'payout_ratio' => 0, 'entry_price' => 100,
            'exit_price' => 100.3, 'profit_loss' => -0.1, 'gross_pnl' => 0.3, 'fees_total' => 0.4,
            'fee_source' => 'real', 'status' => 'lost', 'entry_at' => now()->subMinutes(2),
            'expires_at' => now()->subMinute(), 'settled_at' => now()->subMinute(),
            'signal_payload' => ['direction' => 'buy', 'research' => ['captured_at' => now()->subMinutes(2)->toIso8601String(),
                'execution_environment' => 'live', 'collect_until_ms' => now()->addMinute()->getTimestampMs(),
                'primary' => ['available' => true, 'bias' => 'BUY', 'horizon_seconds' => 60, 'current_price' => 100,
                    'expected_mid_price' => 101, 'expected_low' => 99, 'expected_high' => 102],
                'shadow' => ['status' => 'clear', 'direction' => 'SELL', 'horizon_seconds' => 60, 'current_price' => 100]]]], $overrides));
    }

    private function sample(QuickTrade $trade, int $time, float $price = 101): void
    {
        QuickTradeResearchSample::create(['quick_trade_id' => $trade->id, 'quote_time_ms' => $time,
            'price' => $price, 'source' => 'test_futures_quote', 'recorded_at' => now()]);
    }

    public function test_prediction_outcomes_and_net_loss_are_exported_without_double_slippage(): void
    {
        $trade = $this->trade();
        $this->sample($trade, now()->subMinute()->getTimestampMs());
        $this->getJson('/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research')->assertOk()
            ->assertJsonPath('rows.0.primary_comparison.direction_correct', true)
            ->assertJsonPath('rows.0.shadow_comparison.direction_correct', false)
            ->assertJsonPath('rows.0.primary_comparison.price_in_predicted_range', true)
            ->assertJsonPath('rows.0.net_pnl_booked', -0.1)
            ->assertJsonPath('rows.0.data_quality.training_eligible', true)
            ->assertJsonPath('summary.primary.accuracy_pct', null)
            ->assertJsonPath('shadow_only', true);
    }

    public function test_other_workspace_and_selected_foreign_trade_are_not_exposed(): void
    {
        $other = Workspace::factory()->create();
        $foreign = $this->trade(['workspace_id' => $other->id]);
        $this->getJson('/api/v1/workspaces/'.$other->id.'/quick-trades/research')->assertForbidden();
        $this->postJson('/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research/export',
            ['trade_ids' => [$foreign->id]])->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_old_trades_do_not_get_retroactively_invented_predictions(): void
    {
        $trade = $this->trade(['signal_payload' => ['direction' => 'buy']]);
        $row = app(QuickTradeResearchService::class)->report($trade);
        $this->assertNull($row['research_snapshot']);
        $this->assertNull($row['primary_comparison']['direction_correct']);
        $this->assertFalse($row['data_quality']['training_eligible']);
        $this->assertCount(2, $row['timeline']);
    }

    public function test_early_exit_is_not_substituted_for_prediction_horizon(): void
    {
        $trade = $this->trade(['settled_at' => now()->subSeconds(90), 'exit_price' => 99]);
        $service = app(QuickTradeResearchService::class);
        $before = $service->report($trade);
        $this->assertSame('missing_horizon_sample', $before['primary_comparison']['status']);
        $this->assertFalse($before['entry_signal_correct_at_trade_exit']);
        $this->sample($trade, now()->subMinute()->getTimestampMs(), 101);
        $after = $service->report($trade);
        $this->assertTrue($after['primary_comparison']['direction_correct']);
        $quote = collect($after['timeline'])->firstWhere('kind', 'sampled_quote');
        $this->assertFalse($quote['position_open_at_quote']);
        $this->assertNull($quote['gross_pnl_at_quote']);
    }

    public function test_too_late_sample_and_abstaining_shadow_are_unknown_not_false(): void
    {
        $trade = $this->trade();
        $this->sample($trade, now()->subSeconds(40)->getTimestampMs());
        $service = app(QuickTradeResearchService::class);
        $this->assertNull($service->report($trade)['primary_comparison']['direction_correct']);
        $result = $service->comparison(['status' => 'insufficient_clarity', 'direction' => 'NEUTRAL',
            'horizon_seconds' => 60, 'current_price' => 100], now()->subMinutes(2)->toIso8601String(), []);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_export_paginates_all_selected_trades_without_duplicates(): void
    {
        $this->trade(); $this->trade(); $this->trade();
        $url = '/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research/export';
        $first = $this->postJson($url, ['limit' => 2])->assertOk()->assertJsonCount(2, 'rows');
        $second = $this->postJson($url, ['limit' => 2, 'cursor' => $first->json('next_cursor'), 'as_of' => $first->json('as_of')])
            ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('has_more', false);
        $this->assertNotContains($second->json('rows.0.trade_id'), array_column($first->json('rows'), 'trade_id'));
    }

    public function test_paper_data_never_contributes_to_live_accuracy(): void
    {
        $trade = $this->trade(['mode' => 'paper']);
        $this->sample($trade, now()->subMinute()->getTimestampMs());
        $this->getJson('/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research')->assertOk()
            ->assertJsonPath('summary.primary.evaluable', 0)->assertJsonPath('rows.0.data_quality.training_eligible', false);
    }

    public function test_sampler_deduplicates_and_rejects_stale_spot_and_future_quotes(): void
    {
        $trade = $this->trade(['status' => 'open', 'settled_at' => null]);
        $base = ['fresh' => true, 'market_type' => 'futures', 'price' => 100, 'source_key' => 'futures',
            'event_timestamp_ms' => now()->getTimestampMs()];
        $mock = $this->mock(MarketPriceCacheService::class);
        $mock->shouldReceive('getLiveFeedSnapshot')->andReturn($base, $base,
            array_merge($base, ['fresh' => false]), array_merge($base, ['market_type' => 'spot']),
            array_merge($base, ['event_timestamp_ms' => now()->addSecond()->getTimestampMs()]));
        for ($i = 0; $i < 5; $i++) $this->artisan('quick-trades:sample-research')->assertSuccessful();
        $this->assertSame(1, QuickTradeResearchSample::where('quick_trade_id', $trade->id)->count());
        $this->assertSame('open', $trade->fresh()->status);
    }

    private function backtestRow(array $overrides = []): RatioBotForecastBacktest
    {
        return RatioBotForecastBacktest::create(array_merge([
            'id' => (string) Str::uuid(), 'bot_type' => 'quick_trade',
            'bot_instance_id' => 'binance_futures:BTCUSDT:1m', 'horizon_seconds' => 60,
            'confidence_bucket' => '80-90', 'evaluation_period' => 'holdout',
            'sample_size' => 40, 'hits' => 30, 'hit_rate_pct' => 75.0,
            'avg_confidence_pct' => 85.0, 'computed_at' => now(),
        ], $overrides));
    }

    public function test_calibration_endpoint_returns_real_reliability_curve_and_is_workspace_scoped(): void
    {
        $this->backtestRow();
        $url = '/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research/calibration';
        $response = $this->getJson($url.'?bot_instance_id=binance_futures:BTCUSDT:1m&horizon_seconds=60')
            ->assertOk()
            ->assertJsonPath('bot_type', 'quick_trade')
            ->assertJsonPath('bot_instance_id', 'binance_futures:BTCUSDT:1m');
        $bucket = collect($response->json('reliability_curve'))->firstWhere('confidence_bucket', '80-90');
        $this->assertSame(40, $bucket['sample_size']);
        $this->assertTrue($bucket['calibrated']);
        $this->assertEquals(75.0, $bucket['actual_hit_rate_pct']);

        $other = Workspace::factory()->create();
        $this->getJson('/api/v1/workspaces/'.$other->id.'/quick-trades/research/calibration?bot_instance_id=binance_futures:BTCUSDT:1m&horizon_seconds=60')
            ->assertForbidden();
    }

    public function test_readiness_endpoint_reports_known_limitations_from_real_data_and_is_workspace_scoped(): void
    {
        $this->backtestRow(['evaluation_period' => 'training']);
        $this->backtestRow(['evaluation_period' => 'holdout']);
        $url = '/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research/readiness';
        $this->getJson($url.'?bot_instance_id=binance_futures:BTCUSDT:1m&horizon_seconds=60&symbol=BTCUSDT&timeframe=1m')
            ->assertOk()
            ->assertJsonPath('bot_type', 'quick_trade')
            ->assertJsonStructure(['data_source', 'performance', 'calibration', 'promotion_gate', 'known_limitations'])
            ->assertJsonPath('promotion_gate.status', 'NOT_VALIDATED');

        $other = Workspace::factory()->create();
        $this->getJson('/api/v1/workspaces/'.$other->id.'/quick-trades/research/readiness?bot_instance_id=binance_futures:BTCUSDT:1m&horizon_seconds=60')
            ->assertForbidden();
    }

    public function test_compare_endpoint_returns_two_scopes_with_a_recommendation_and_is_workspace_scoped(): void
    {
        $this->backtestRow(['bot_instance_id' => 'binance_futures:BTCUSDT:1m']);
        $this->backtestRow(['bot_instance_id' => 'binance_futures:ETHUSDT:1m']);
        $url = '/api/v1/workspaces/'.$this->workspace->id.'/quick-trades/research/compare';
        $params = 'a[bot_instance_id]=binance_futures:BTCUSDT:1m&a[horizon_seconds]=60'
            .'&b[bot_instance_id]=binance_futures:ETHUSDT:1m&b[horizon_seconds]=60';
        $this->getJson($url.'?'.$params)->assertOk()
            ->assertJsonPath('model_a.bot_instance_id', 'binance_futures:BTCUSDT:1m')
            ->assertJsonPath('model_b.bot_instance_id', 'binance_futures:ETHUSDT:1m')
            ->assertJsonStructure(['deltas', 'recommendation']);

        $other = Workspace::factory()->create();
        $this->getJson('/api/v1/workspaces/'.$other->id.'/quick-trades/research/compare?'.$params)->assertForbidden();
    }

    public function test_capture_whitelists_config_and_preserves_pre_entry_inputs(): void
    {
        config(['unified_forecast.quick_trade_shadow_enabled' => false]);
        $candles = array_fill(0, 12, ['open' => 100, 'high' => 102, 'low' => 99, 'close' => 101, 'volume' => 1]);
        $snapshot = app(QuickTradeResearchService::class)->capture($candles, ['direction' => 'buy', 'confidence' => 75],
            ['mode' => 'live', 'provider' => 'binance', 'symbol' => 'BTCUSDT', 'timeframe' => '1m',
                'expiry_seconds' => 60, 'api_secret' => 'must-not-export']);
        $this->assertSame('server_pre_entry', $snapshot['origin']);
        $this->assertArrayNotHasKey('api_secret', $snapshot['config']);
        $this->assertCount(12, $snapshot['input_candles']);
        $this->assertTrue($snapshot['primary']['available']);
    }
}
