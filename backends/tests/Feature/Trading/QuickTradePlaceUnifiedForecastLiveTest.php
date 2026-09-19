<?php

namespace Tests\Feature\Trading;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\QuickTradeService;
use App\Models\Integration;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\QuickTrade;
use App\Models\RatioBotForecastBacktest;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unified Forecast Engine, Phase 6 -- real end-to-end Quick Trade placement
 * with `unified_forecast.quick_trade_live` on and a VALIDATED scope, proving
 * the trade's recorded entry_prediction_snapshot
 * (QuickTradeResearchService::capture(), stored under
 * $trade->signal_payload['research']) correctly shows
 * decision_source === 'unified_forecast_engine' -- and that the trade
 * itself still places successfully (this field is observational only, it
 * never blocks or alters order routing).
 */
class QuickTradePlaceUnifiedForecastLiveTest extends TestCase
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
                'api_key' => 'ufe-e2e-key',
                'api_secret' => 'ufe-e2e-secret',
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
                return Http::response(['orderId' => '9001', 'avgPrice' => '2000.00', 'executedQty' => '0.010'], 200);
            }

            return Http::response([], 200);
        });
    }

    private function seedUptrend(string $symbol, int $count = 60): void
    {
        $time = now()->subMinutes($count + 1);
        $price = 2000.0;
        for ($i = 0; $i < $count; $i++) {
            $open = $price;
            $close = $open + 5.0;
            MarketOhlc::create([
                'exchange' => 'binance', 'symbol' => $symbol, 'timeframe' => '1m',
                'open' => $open, 'high' => $close + 1, 'low' => $open - 1,
                'close' => $close, 'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }
    }

    private function seedValidatedScope(string $botInstanceId, int $horizonSeconds): void
    {
        foreach (['training', 'holdout'] as $period) {
            RatioBotForecastBacktest::create([
                'bot_type' => 'quick_trade', 'bot_instance_id' => $botInstanceId, 'horizon_seconds' => $horizonSeconds,
                'confidence_bucket' => '80-100', 'evaluation_period' => $period,
                'sample_size' => 50, 'hits' => 40, 'hit_rate_pct' => 80.0, 'avg_confidence_pct' => 85.0,
                'computed_at' => now(),
            ]);
        }
    }

    private function seedWinningLiveTrades(string $symbol, string $timeframe, int $horizonSeconds, int $count = 30): void
    {
        for ($i = 0; $i < $count; $i++) {
            QuickTrade::create([
                'workspace_id' => Workspace::factory()->create()->id,
                'symbol' => $symbol, 'side' => 'buy', 'stake_amount' => 100, 'quantity' => 1,
                'asset_class' => 'CRYPTO', 'mode' => 'live', 'source' => 'manual_quick_trade',
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

    public function test_a_real_quick_trade_placed_live_with_a_validated_scope_records_decision_source_unified_forecast_engine(): void
    {
        $symbol = 'UFEE2EUSDT';
        $this->fakeBinance($symbol);
        $this->seedUptrend($symbol);
        $botInstanceId = "binance:{$symbol}:1m";
        $this->seedValidatedScope($botInstanceId, 60);
        $this->seedWinningLiveTrades($symbol, '1m', 60);

        $engineResult = app(UnifiedForecastEngine::class)->evaluate('quick_trade', $botInstanceId, 'binance', $symbol, '1m', 60);
        $this->assertSame('clear', $engineResult['status']);
        $this->assertSame('BUY', $engineResult['direction']);

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $integration = $this->integration($workspace->id);
        $this->liveSettings($workspace->id, $integration->id);
        $this->feedStatus($workspace->id, $symbol, 2000.00);
        $this->liveFeed($symbol, 2000.00);

        $trade = app(QuickTradeService::class)->place([
            'workspace_id' => $workspace->id,
            'symbol' => $symbol,
            'side' => 'buy',
            'stake_amount' => 25,
            'timeframe' => '1m',
            'expiry_seconds' => 60,
            'mode' => 'live',
            'provider' => 'binance',
            'integration_id' => $integration->id,
            'source' => 'manual_quick_trade',
        ], $user->id);

        $this->assertSame('open', $trade->status);
        $research = (array) data_get($trade->signal_payload, 'research', []);
        $this->assertSame('unified_forecast_engine', $research['decision_source'] ?? null);
        $this->assertSame('BUY', $research['primary']['decision_trend'] ?? null);
        $this->assertIsArray($research['unified_forecast_decision'] ?? null);
    }

    public function test_a_real_quick_trade_placed_live_with_flag_off_records_decision_source_internal(): void
    {
        $symbol = 'UFEE2EOFFUSDT';
        $this->fakeBinance($symbol);

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', false);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $integration = $this->integration($workspace->id);
        $this->liveSettings($workspace->id, $integration->id);
        $this->feedStatus($workspace->id, $symbol, 2000.00);
        $this->liveFeed($symbol, 2000.00);

        $trade = app(QuickTradeService::class)->place([
            'workspace_id' => $workspace->id,
            'symbol' => $symbol,
            'side' => 'buy',
            'stake_amount' => 25,
            'timeframe' => '1m',
            'expiry_seconds' => 60,
            'mode' => 'live',
            'provider' => 'binance',
            'integration_id' => $integration->id,
            'source' => 'manual_quick_trade',
        ], $user->id);

        $this->assertSame('open', $trade->status);
        $research = (array) data_get($trade->signal_payload, 'research', []);
        $this->assertSame('internal', $research['decision_source'] ?? null);
    }
}
