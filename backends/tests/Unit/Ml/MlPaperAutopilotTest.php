<?php

namespace Tests\Unit\Ml;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ml\Shadow\MlPaperAutopilotService;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Models\MlChampion;
use App\Models\MlModel;
use App\Models\MlPaperAutopilotTrade;
use App\Models\MlShadowPrediction;
use App\Models\QuickTrade;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MlPaperAutopilotTest extends TestCase
{
    use MlShadowTestHelpers;

    private ?float $quote = 2000.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
        config(['ml.autopilot.enabled' => true, 'ml.autopilot.stake_usd' => 100, 'ml.autopilot.cost_pct' => 0.18]);
        Http::preventStrayRequests(); // proves no exchange / order call is ever made
        $this->mock(MarketPriceCacheService::class, function ($mock): void {
            $mock->shouldReceive('getLiveFeedSnapshot')->andReturnUsing(fn () => $this->quote === null ? null : ['price' => $this->quote, 'fresh' => true]);
        });
        $model = MlModel::query()->create(['id' => (string) Str::uuid(), 'model_version' => 'qtml-eth4h', 'symbol' => 'ETHUSDT', 'horizon_minutes' => 240,
            'timeframe' => '5m', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => 'side_classifier', 'status' => 'CHAMPION']);
        MlChampion::query()->create(['symbol' => 'ETHUSDT', 'horizon_minutes' => 240, 'model_id' => $model->id]);
    }

    private function signal(string $decision, int $ageSeconds = 30, float $price = 1999.0, int $horizon = 240): MlShadowPrediction
    {
        $anchor = now('UTC')->getTimestampMs() - $ageSeconds * 1000;
        [$pBuy, $pSell] = ['BUY' => [0.7, 0.1], 'SELL' => [0.1, 0.7]][$decision] ?? [0.4, 0.2];

        return MlShadowPrediction::query()->create([
            'symbol' => 'ETHUSDT', 'horizon_minutes' => $horizon, 'anchor_time_ms' => $anchor, 'target_time_ms' => $anchor + $horizon * 60_000,
            'model_version' => 'qtml-eth4h', 'model_type' => 'side_classifier', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION,
            'current_price' => $price, 'p_buy' => $pBuy, 'p_sell' => $pSell, 'buy_net_pct' => 0.3, 'sell_net_pct' => -0.5,
            'decision' => $decision, 'decision_reason' => 'test', 'cost_snapshot' => ['cost_pct' => 0.12], 'status' => 'PENDING',
        ]);
    }

    /** Two consecutive 5-minute decisions: the stable-signal confirmation. */
    private function confirmed(string $decision, int $ageSeconds = 30, int $horizon = 240): MlShadowPrediction
    {
        $this->signal($decision, $ageSeconds + 300, horizon: $horizon);

        return $this->signal($decision, $ageSeconds, horizon: $horizon);
    }

    private function action(MlPaperAutopilotService $service, int $horizon = 240): string
    {
        return collect($service->summary()['signals'])->where('symbol', 'ETHUSDT')->firstWhere('horizon_minutes', $horizon)['action'];
    }

    public function test_a_single_unconfirmed_signal_is_not_traded(): void
    {
        $this->signal('BUY');
        $service = app(MlPaperAutopilotService::class);

        $this->assertSame(0, $service->openNewTrades());
        $this->assertSame('confirming', $this->action($service));
    }

    public function test_display_only_horizons_are_never_traded(): void
    {
        config(['ml.horizons_minutes' => [15, 240], 'ml.autopilot.trade_horizons_minutes' => [240]]);
        $model = MlModel::query()->create(['id' => (string) Str::uuid(), 'model_version' => 'qtml-eth15', 'symbol' => 'ETHUSDT', 'horizon_minutes' => 15,
            'timeframe' => '5m', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => 'side_classifier', 'status' => 'CHAMPION']);
        MlChampion::query()->create(['symbol' => 'ETHUSDT', 'horizon_minutes' => 15, 'model_id' => $model->id]);
        $this->confirmed('BUY', horizon: 15);
        $service = app(MlPaperAutopilotService::class);

        $this->assertSame(0, $service->openNewTrades());
        $this->assertSame('display_only', $this->action($service, 15));
        $this->assertSame('BUY', collect($service->summary()['signals'])->where('symbol', 'ETHUSDT')->firstWhere('horizon_minutes', 15)['stable']['state']);
    }

    public function test_buy_signal_opens_one_simulated_trade_at_the_live_quote_and_touches_nothing_else(): void
    {
        $this->confirmed('BUY');
        $service = app(MlPaperAutopilotService::class);

        $this->assertSame(1, $service->openNewTrades());
        $this->assertSame(0, $service->openNewTrades()); // same signal is never traded twice

        $trade = MlPaperAutopilotTrade::query()->firstOrFail();
        $this->assertSame('BUY', $trade->side);
        $this->assertEqualsWithDelta(2000.0, $trade->entry_price, 1e-9);
        $this->assertSame('live_quote', $trade->entry_price_source);
        $this->assertEqualsWithDelta(1999.0, $trade->signal_price, 1e-9);
        $this->assertSame((int) $trade->entry_time_ms + 240 * 60_000, (int) $trade->expires_at_ms);
        // Isolation: no Quick Trade, no wallet, no HTTP (preventStrayRequests).
        $this->assertSame(0, QuickTrade::query()->count());
        $this->assertSame(0, WalletAccount::query()->count());
    }

    public function test_one_position_at_a_time_and_wait_or_stale_signals_are_not_traded(): void
    {
        $service = app(MlPaperAutopilotService::class);
        $this->signal('WAIT', ageSeconds: 9000);
        $this->assertSame(0, $service->openNewTrades());
        $this->confirmed('SELL', ageSeconds: 3600); // older than max_signal_age
        $this->assertSame(0, $service->openNewTrades());

        $this->confirmed('BUY', ageSeconds: 20);
        $this->assertSame(1, $service->openNewTrades());
        $this->signal('BUY', ageSeconds: 10);               // new agreeing signal while a position is open
        $this->assertSame(0, $service->openNewTrades());
        $this->assertSame(1, MlPaperAutopilotTrade::query()->count());
        $this->assertSame('skipped_position_open', $this->action($service));
    }

    public function test_settlement_charges_cost_and_funding_and_books_pnl(): void
    {
        $this->confirmed('SELL');
        $service = app(MlPaperAutopilotService::class);
        $service->openNewTrades();                       // entry 2000
        $this->travel(241)->minutes();
        $this->quote = 1980.0;                           // −1% move: good for a SELL

        $this->assertSame(['settled' => 1, 'cancelled' => 0], $service->settleExpired());

        $trade = MlPaperAutopilotTrade::query()->firstOrFail();
        $this->assertSame('CLOSED', $trade->status);
        $this->assertEqualsWithDelta(1.0, $trade->gross_return_pct, 1e-9);
        $this->assertEqualsWithDelta(1.0 - 0.18, $trade->net_return_pct, 1e-9); // no funding rows -> 0
        $this->assertEqualsWithDelta(0.82, $trade->pnl_usd, 1e-9);              // $100 stake
        $this->assertSame('WIN', $trade->outcome);

        $summary = $service->summary();
        $this->assertEqualsWithDelta(10000.82, $summary['summary']['balance_usd'], 1e-9);
        $this->assertSame('insufficient_data', $summary['summary']['status']);
        $this->assertSame(1, $summary['by_side']['SELL']['closed_trades']);
        $this->assertSame(100.0, $summary['summary']['direction_accuracy_pct']);
    }

    public function test_exit_falls_back_to_the_expiry_candle_close_when_the_quote_is_stale(): void
    {
        $this->confirmed('BUY');
        $service = app(MlPaperAutopilotService::class);
        $service->openNewTrades();
        $trade = MlPaperAutopilotTrade::query()->firstOrFail();
        $this->travel(250)->minutes();
        $this->quote = null;                              // no fresh websocket quote
        Http::fake(['*/fapi/v1/ticker/price*' => Http::response([], 503)]); // and no REST ticker
        $this->assertSame(['settled' => 0, 'cancelled' => 0], $service->settleExpired()); // waits for data
        $candleOpen = intdiv((int) $trade->expires_at_ms, self::FIVE_MIN) * self::FIVE_MIN;
        $this->seedCandles('ETHUSDT', $candleOpen, 1, fn () => 1990.0);

        $service->settleExpired();

        $trade->refresh();
        $this->assertSame('expiry_candle_close', $trade->exit_price_source);
        $this->assertSame('LOSS', $trade->outcome);       // −0.5% − 0.18%
        $this->assertEqualsWithDelta(-0.5 - 0.18, $trade->net_return_pct, 1e-9);
    }

    public function test_entry_uses_the_public_rest_ticker_when_the_websocket_cache_is_not_fresh(): void
    {
        $this->quote = null; // symbol not streamed by the live websocket
        Http::fake(['*/fapi/v1/ticker/price*' => Http::response(['symbol' => 'ETHUSDT', 'price' => '2005.5', 'time' => now('UTC')->getTimestampMs() - 1000])]);
        $this->confirmed('BUY');

        app(MlPaperAutopilotService::class)->openNewTrades();

        $trade = MlPaperAutopilotTrade::query()->firstOrFail();
        $this->assertSame('rest_ticker', $trade->entry_price_source);
        $this->assertEqualsWithDelta(2005.5, $trade->entry_price, 1e-9);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/ticker/price') && $request['symbol'] === 'ETHUSDT');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/order') || str_contains($request->url(), '/account'));
    }

    public function test_a_stale_rest_ticker_is_not_used_as_entry_price(): void
    {
        $this->quote = null;
        Http::fake(['*/fapi/v1/ticker/price*' => Http::response(['symbol' => 'ETHUSDT', 'price' => '2005.5', 'time' => now('UTC')->getTimestampMs() - 120_000])]);
        $this->confirmed('BUY');

        app(MlPaperAutopilotService::class)->openNewTrades();

        $this->assertSame('signal_candle_close', MlPaperAutopilotTrade::query()->firstOrFail()->entry_price_source);
    }

    public function test_learning_status_reports_history_target_training_now_and_next_retrain(): void
    {
        config(['ml.history.months_by_symbol' => ['ETHUSDT' => 60], 'ml.training.schedule_utc' => '03:05']);
        MlModel::query()->create(['id' => (string) Str::uuid(), 'symbol' => 'ETHUSDT', 'horizon_minutes' => 60, 'timeframe' => '5m',
            'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => 'side_classifier', 'status' => 'TRAINING']);

        $status = app(\App\Domain\Ml\Shadow\LearningStatusService::class)->status('ETHUSDT');

        $this->assertSame(60, $status['history']['target_months']);
        $this->assertSame(525_960, $status['history']['target_candles']);       // 60 × 30.4375 days of 5m candles
        $this->assertStringEndsWith('03:05:00+00:00', $status['next_retrain_at']);
        $h60 = collect($status['horizons'])->firstWhere('horizon_minutes', 60);
        $this->assertSame('side_classifier', $h60['training_now']['model_type']);
        $this->assertTrue($h60['tradable']);
        $this->assertNull(collect($status['horizons'])->firstWhere('horizon_minutes', 240)['training_now']);
    }

    public function test_dashboard_endpoint_requires_membership(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user);

        $this->getJson('/api/v1/quick-trade/ml-autopilot?workspace_id='.$workspace->id)->assertOk()
            ->assertJsonPath('separate_from_user_trades', true)->assertJsonPath('execution', false)
            ->assertJsonPath('settings.cost_pct', 0.18)->assertJsonPath('summary.balance_usd', 10000);
        $this->getJson('/api/v1/quick-trade/ml-autopilot?workspace_id='.Workspace::factory()->create()->id)->assertForbidden();
    }
}
