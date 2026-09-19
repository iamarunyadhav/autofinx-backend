<?php

namespace Tests\Feature\Trading;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\RobotSessionService;
use App\Models\Integration;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\QuickTrade;
use App\Models\RatioBotForecastBacktest;
use App\Models\RobotSession;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Unified Forecast Engine, Phase 6 -- Robot Trade LIVE decision wiring
 * (`unified_forecast.robot_trade_live`, default OFF). This is the safety-
 * critical scope for this task: unlike Quick Trade (advisory/manual entry),
 * $signal['direction'] here directly becomes the real order `side`
 * (RobotSessionService::evaluateTimedExpirySession() ->
 * $this->quickTrades->place(['side' => $signal['direction'], ...])).
 *
 * The internal signal (from real klines fed through SignalPreviewService)
 * always says BUY in this fixture (mirrors
 * RobotSessionStopLossFeeAndForecastShadowTest's proven klines shape). The
 * Unified Forecast Engine's own (independent, MarketOhlc-seeded) trend is
 * seeded SELL, so a successful override is unambiguous: the real order side
 * actually flips only when the flag is on AND the scope is VALIDATED.
 */
class RobotSessionUnifiedForecastLiveTest extends TestCase
{
    use RefreshDatabase;

    private function integration(string $workspaceId): Integration
    {
        return Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance Live',
            'status' => 'active',
            'config_json' => ['environment' => 'live'],
            'secrets_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'ufe-live-key',
                'api_secret' => 'ufe-live-secret',
            ])),
        ]);
    }

    private function liveSettings(string $workspaceId, string $integrationId): void
    {
        foreach ([
            'trading_mode' => 'live',
            'live_enabled' => true,
            'preferred_provider' => 'binance',
            'preferred_integration_id' => $integrationId,
            'venue_environment' => 'live',
        ] as $key => $value) {
            Setting::updateOrCreate(
                ['workspace_id' => $workspaceId, 'group' => 'trading', 'key' => $key],
                ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
            );
        }
    }

    private function feedStatus(string $workspaceId, string $symbol, float $price): void
    {
        MarketFeedStatus::updateOrCreate(
            ['workspace_id' => $workspaceId, 'symbol' => strtoupper($symbol), 'exchange' => 'binance', 'market_type' => 'futures'],
            [
                'id' => (string) Str::uuid(), 'symbol_id' => (string) Str::uuid(),
                'last_tick_at' => now(), 'last_price' => $price, 'source' => 'websocket',
                'status' => 'healthy', 'meta_json' => ['test' => true],
            ]
        );
    }

    private function liveFeed(string $symbol, float $price): void
    {
        app(\App\Domain\Market\MarketPriceCacheService::class)->setLatestPrice(
            'binance_futures', strtoupper($symbol), $price, (int) floor(microtime(true) * 1000),
            ['stream' => strtolower($symbol).'@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
        );
    }

    /** Rising-close klines -- proven (RobotSessionStopLossFeeAndForecastShadowTest)
     * to make SignalPreviewService's MACD read confirm BUY after 2 ticks. */
    private function fakeBinance(string $symbol): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($symbol) {
            $url = $request->url();

            if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
                return Http::response([], 200);
            }
            if (str_contains($url, '/fapi/v2/balance')) {
                return Http::response([['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00']], 200);
            }
            if (str_contains($url, '/fapi/v1/ticker/price')) {
                return Http::response(['symbol' => $symbol, 'price' => '2000.00'], 200);
            }
            if (str_contains($url, '/fapi/v1/exchangeInfo')) {
                return Http::response(['symbols' => [[
                    'filters' => [
                        ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                        ['filterType' => 'MIN_NOTIONAL', 'notional' => '20.00000000'],
                    ],
                ]]], 200);
            }
            if (str_contains($url, '/fapi/v1/klines')) {
                $rows = [];
                for ($i = 0; $i < 100; $i++) {
                    $open = 1701 + ($i * 3);
                    $close = $open + 2;
                    $rows[] = [1700000000000 + ($i * 60000), (string) $open, (string) ($close + 20), (string) ($open - 20), (string) $close, '100'];
                }

                return Http::response($rows, 200);
            }
            if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
                $isClose = str_contains((string) $request->body(), 'reduceOnly');

                return Http::response([
                    'orderId' => $isClose ? '7772' : '7771',
                    'avgPrice' => $isClose ? '1980.00' : '2000.00',
                    'executedQty' => '0.010',
                ], 200);
            }

            return Http::response([], 200);
        });
    }

    /** Independent, MarketOhlc-based SELL trend for UnifiedForecastEngine. */
    private function seedBearishTrend(string $symbol, int $count = 60): void
    {
        $time = now()->subMinutes($count + 1);
        $price = 5000.0;
        for ($i = 0; $i < $count; $i++) {
            $open = $price;
            $close = $open - 5.0;
            MarketOhlc::create([
                'exchange' => 'binance', 'symbol' => $symbol, 'timeframe' => '1m',
                'open' => $open, 'high' => $open + 1, 'low' => $close - 1,
                'close' => $close, 'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }
    }

    private function seedValidatedScope(string $botInstanceId, int $horizonSeconds): void
    {
        foreach (['training', 'holdout'] as $period) {
            RatioBotForecastBacktest::create([
                'bot_type' => 'robot_trade', 'bot_instance_id' => $botInstanceId, 'horizon_seconds' => $horizonSeconds,
                'confidence_bucket' => '80-100', 'evaluation_period' => $period,
                'sample_size' => 50, 'hits' => 40, 'hit_rate_pct' => 80.0, 'avg_confidence_pct' => 85.0,
                'computed_at' => now(),
            ]);
        }
    }

    private function seedWinningRobotTrades(string $robotSessionId, string $symbol, string $timeframe, int $horizonSeconds, int $count = 30): void
    {
        $workspace = Workspace::factory()->create();
        for ($i = 0; $i < $count; $i++) {
            QuickTrade::create([
                'workspace_id' => $workspace->id, 'robot_session_id' => $robotSessionId,
                'symbol' => $symbol, 'side' => 'buy', 'stake_amount' => 100, 'quantity' => 1,
                'asset_class' => 'CRYPTO', 'mode' => 'live', 'source' => 'robot_trade',
                'provider_key' => 'binance', 'quote_currency' => 'USDT', 'timeframe' => $timeframe,
                'expiry_seconds' => $horizonSeconds, 'payout_ratio' => 0, 'entry_price' => 100,
                'exit_price' => 101, 'profit_loss' => 5.0, 'gross_pnl' => 5.5, 'fees_total' => 0.5,
                'fee_source' => 'real', 'status' => 'won',
                'entry_at' => now()->subMinutes(20 + $i), 'expires_at' => now()->subMinutes(19 + $i),
                'settled_at' => now()->subMinutes(19 + $i),
                'signal_payload' => ['direction' => 'buy', 'research' => ['execution_environment' => 'live']],
            ]);
        }
    }

    /** @return array{0: RobotSession, 1: string} */
    private function createSession(string $symbol): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $integration = $this->integration($workspace->id);
        $this->liveSettings($workspace->id, $integration->id);
        $this->feedStatus($workspace->id, $symbol, 2000.00);
        $this->liveFeed($symbol, 2000.00);

        $session = app(RobotSessionService::class)->create($workspace->id, [
            'name' => "{$symbol} UFE robot", 'symbol' => $symbol, 'timeframe' => '1m',
            'expiry_seconds' => 60, 'indicator_set' => ['MACD'], 'strategy_profile' => 'balanced',
            'base_stake' => 20, 'confidence_threshold' => 50, 'provider' => 'binance',
            'integration_id' => $integration->id,
        ], $user->id);

        return [app(RobotSessionService::class)->start($session), $workspace->id];
    }

    public function test_flag_off_places_the_same_side_as_internal_signal(): void
    {
        $symbol = 'UFEOFFUSDT';
        $this->fakeBinance($symbol);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_live', false);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);

        [$session] = $this->createSession($symbol);
        app(RobotSessionService::class)->evaluateSession($session);
        $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

        $this->assertSame('placed', $result['status']);
        $trade = QuickTrade::where('robot_session_id', $session->id)->where('status', 'open')->firstOrFail();
        $this->assertSame('buy', $trade->side);
        $this->assertSame('internal', $trade->signal_payload['decision_source'] ?? null);
    }

    public function test_flag_off_never_calls_the_unified_forecast_engine(): void
    {
        $symbol = 'UFEOFF2USDT';
        $this->fakeBinance($symbol);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_live', false);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldNotReceive('evaluate');
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        [$session] = $this->createSession($symbol);
        app(RobotSessionService::class)->evaluateSession($session);
        app(RobotSessionService::class)->evaluateSession($session->fresh());

        $trade = QuickTrade::where('robot_session_id', $session->id)->where('status', 'open')->firstOrFail();
        $this->assertSame('buy', $trade->side);
    }

    public function test_flag_on_but_scope_not_validated_falls_back_to_internal_side(): void
    {
        $symbol = 'UFENVUSDT';
        $this->fakeBinance($symbol);
        $this->seedBearishTrend($symbol);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);

        Log::spy();

        [$session] = $this->createSession($symbol);
        app(RobotSessionService::class)->evaluateSession($session);
        $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

        $this->assertSame('placed', $result['status']);
        $trade = QuickTrade::where('robot_session_id', $session->id)->where('status', 'open')->firstOrFail();
        $this->assertSame('buy', $trade->side, 'no real backtest evidence exists yet -- must fall back to the internal signal');
        $this->assertSame('internal', $trade->signal_payload['decision_source'] ?? null);
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'live mode enabled but scope not validated'))
            ->atLeast()->once();
    }

    public function test_flag_on_and_scope_validated_places_the_real_engine_direction_instead_of_the_internal_signal(): void
    {
        $symbol = 'UFEOKUSDT';
        $this->fakeBinance($symbol);
        $this->seedBearishTrend($symbol);

        [$session] = $this->createSession($symbol);
        $this->seedValidatedScope($session->id, 60);
        $this->seedWinningRobotTrades($session->id, $symbol, '1m', 60);

        $engineResult = app(UnifiedForecastEngine::class)->evaluate('robot_trade', $session->id, 'binance', $symbol, '1m', 60);
        $this->assertSame('clear', $engineResult['status']);
        $this->assertSame('SELL', $engineResult['direction'], 'test fixture must actually produce a clear SELL engine direction');

        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);

        app(RobotSessionService::class)->evaluateSession($session);
        $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

        $this->assertSame('placed', $result['status']);
        $trade = QuickTrade::where('robot_session_id', $session->id)->where('status', 'open')->firstOrFail();
        $this->assertSame('sell', $trade->side, 'the real engine result must override the internal buy signal once the scope is validated');
        $this->assertSame('unified_forecast_engine', $trade->signal_payload['decision_source'] ?? null);
        $this->assertIsArray($trade->signal_payload['unified_forecast_decision'] ?? null);
    }

    public function test_engine_exception_falls_back_to_internal_side_without_crashing(): void
    {
        $symbol = 'UFETHROWUSDT';
        $this->fakeBinance($symbol);
        $this->seedBearishTrend($symbol);

        [$session] = $this->createSession($symbol);
        $this->seedValidatedScope($session->id, 60);
        $this->seedWinningRobotTrades($session->id, $symbol, '1m', 60);

        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldReceive('evaluate')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        Log::spy();

        app(RobotSessionService::class)->evaluateSession($session);
        $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

        $this->assertSame('placed', $result['status']);
        $trade = QuickTrade::where('robot_session_id', $session->id)->where('status', 'open')->firstOrFail();
        $this->assertSame('buy', $trade->side);
        $this->assertSame('internal', $trade->signal_payload['decision_source'] ?? null);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'engine evaluate() failed'))
            ->atLeast()->once();
    }
}
