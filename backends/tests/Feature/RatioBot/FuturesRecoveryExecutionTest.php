<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\FuturesTradingConfig;
use App\Models\Integration;
use App\Models\MarketOhlc;
use App\Models\Position;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FuturesRecoveryExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        $this->integration = Integration::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'type' => 'exchange',
            'provider_key' => 'binance',
            'display_name' => 'Binance Futures Test',
            'status' => 'active',
            'config_json' => ['environment' => 'testnet'],
            'secrets_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'test-key',
                'api_secret' => 'test-secret',
            ])),
        ]);
        FuturesTradingConfig::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'futures_enabled' => true,
            'futures_mode' => 'restricted_testnet_auto',
            'futures_force_testnet' => true,
            'require_manual_approval_for_futures' => false,
            'futures_confidence_threshold' => null,
            'max_leverage' => 10,
            'allowed_margin_modes' => ['isolated'],
            'allowed_position_modes' => ['one_way'],
        ]);
        Queue::fake();
    }

    /** @test */
    public function a_top_up_once_outcome_updates_the_weighted_average_entry_marks_used_and_replaces_the_exchange_stop(): void
    {
        $config = $this->createFuturesConfig();
        $this->createPosition($config, liquidationPrice: 25000.0);
        app(RatioBotStateService::class)->syncPositionSnapshot($config, 'long', 1.0, 50000.0, 0);
        $config->state()->first()->update(['futures_position_opened_at' => now()->subHours(2)]);
        $this->seedTrendCandles($config, bullish: true);

        $placeCount = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$placeCount) {
            $url = $request->url();
            if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
                $placeCount++;
                $qty = (float) ($request->data()['quantity'] ?? 0);

                return Http::response([
                    'orderId' => 5000 + $placeCount,
                    'status' => 'FILLED',
                    'avgPrice' => '48000.00',
                    'executedQty' => (string) $qty,
                ], 200);
            }
            if (str_contains($url, '/fapi/v1/order') && $request->method() === 'DELETE') {
                return Http::response(['orderId' => 1], 200);
            }
            if (str_contains($url, '/fapi/v1/exchangeInfo')) {
                return Http::response(['symbols' => [['filters' => [
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '5.00'],
                ]]]], 200);
            }
            if (str_contains($url, '/fapi/v1/premiumIndex') || str_contains($url, '/fapi/v1/ticker')) {
                return Http::response(['markPrice' => '48000.00', 'lastFundingRate' => '0.0001'], 200);
            }

            return Http::response([], 200);
        });

        $result = app(RatioBotRealtimeEngine::class)->evaluate($config->fresh('state'), 48000.0);

        $this->assertSame('HOLD', $result['decision']);
        $this->assertGreaterThan(0, $placeCount, 'the recovery top-up must have submitted a real order');

        $config->refresh()->load('state');
        $this->assertTrue((bool) $config->state->futures_recovery_used);
        $this->assertNotEquals(50000.0, (float) $config->state->entry_price, 'entry price must have moved toward the volume-weighted average');
        $this->assertGreaterThan(1.0, (float) $config->state->last_position_qty, 'position qty must have grown by the top-up amount');

        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_FUTURES_RECOVERY_TOPUP_EXECUTED,
        ]);
        // Part B §5.3: the exchange-side stop is a required replace, not optional.
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_PLACED,
        ]);
    }

    /** @test */
    public function futures_recovery_used_resets_to_false_only_when_the_position_returns_to_flat(): void
    {
        $config = $this->createFuturesConfig();
        app(RatioBotStateService::class)->syncPositionSnapshot($config, 'long', 1.0, 50000.0, 0);
        $config->state()->first()->update(['futures_recovery_used' => true]);

        // A further top-up-adjacent snapshot update (still open) must not reset it.
        app(RatioBotStateService::class)->syncPositionSnapshot($config->fresh('state'), 'long', 1.5, 51000.0, 0);
        $this->assertTrue((bool) $config->fresh('state')->state->futures_recovery_used);

        // Only returning to flat resets it.
        app(RatioBotStateService::class)->syncPositionSnapshot($config->fresh('state'), 'flat', 0, null, 0);
        $this->assertFalse((bool) $config->fresh('state')->state->futures_recovery_used);
    }

    private function createFuturesConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'algorithm' => 'ma_crossover',
            'timeframe' => '5m',
            'mode' => 'restricted_auto',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'live',
            'enabled' => true,
            'leverage' => 2,
            'liquidation_guard_enabled' => true,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
            'futures_recovery_enabled' => true,
            'futures_recovery_timeout_minutes' => 60,
            'futures_recovery_topup_pct' => 10.0,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }

    private function createPosition(RatioBotConfig $config, float $liquidationPrice): Position
    {
        $symbol = Symbol::firstOrCreate(['symbol' => $config->normalizedSymbol()], [
            'id' => (string) Str::uuid(),
            'type' => 'crypto',
            'exchange' => 'binance_futures',
        ]);

        return Position::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $config->workspace_id,
            'symbol_id' => $symbol->id,
            'market_type' => 'futures',
            'qty' => 1.0,
            'quantity' => 1.0,
            'avg_price' => 50000.0,
            'avg_entry' => 50000.0,
            'last_mark_price' => 48000.0,
            'liquidation_price' => $liquidationPrice,
            'liquidation_buffer_pct' => 40.0,
            'mark_price_synced_at' => now(),
        ]);
    }

    private function seedTrendCandles(RatioBotConfig $config, bool $bullish): void
    {
        $price = 50000.0;
        for ($i = 0; $i < 60; $i++) {
            $step = $bullish ? 40.0 : -40.0;
            $open = $price;
            $close = $price + $step;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'ts' => now()->subMinutes((60 - $i) * 5),
                'open' => $open,
                'high' => max($open, $close) * 1.001,
                'low' => min($open, $close) * 0.999,
                'close' => $close,
                'volume' => 500,
            ]);
            $price = $close;
        }
    }
}
