<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotExchangeStopOrderService;
use App\Models\Integration;
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

class RatioBotExchangeStopOrderTest extends TestCase
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
        Queue::fake();
    }

    /** @test */
    public function a_stop_order_is_placed_on_the_first_sync_and_replaced_not_duplicated_on_cost_basis_change(): void
    {
        $config = $this->createFuturesConfig();
        $this->createPosition($config, liquidationPrice: 45000.0);
        $this->setPositionState($config, entryPrice: 50000.0, qty: 1.0, side: 'long');

        $placeCount = 0;
        $cancelCount = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$placeCount, &$cancelCount) {
            $url = $request->url();
            if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
                $placeCount++;
                return Http::response(['orderId' => 9000 + $placeCount], 200);
            }
            if (str_contains($url, '/fapi/v1/order') && $request->method() === 'DELETE') {
                $cancelCount++;
                return Http::response(['orderId' => 9000], 200);
            }
            return Http::response([], 200);
        });

        $service = app(RatioBotExchangeStopOrderService::class);
        $workspaceConfig = ['futures_force_testnet' => true];

        // First sync: places a fresh stop, no prior order to cancel.
        $service->syncStopOrder($config, $this->integration, $workspaceConfig);
        $this->assertSame(1, $placeCount);
        $this->assertSame(0, $cancelCount);
        $config->state?->refresh();
        $this->assertNotNull($config->state->futures_stop_order_id);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_PLACED,
        ]);

        // Retried with the SAME cost basis: idempotent no-op, no new call.
        $service->syncStopOrder($config, $this->integration, $workspaceConfig);
        $this->assertSame(1, $placeCount);
        $this->assertSame(0, $cancelCount);

        // Cost basis changes (another stage buy averaged the price up):
        // cancel the stale stop, place a fresh one -- never duplicate.
        $this->setPositionState($config, entryPrice: 51000.0, qty: 1.5, side: 'long');
        $service->syncStopOrder($config->fresh('state'), $this->integration, $workspaceConfig);
        $this->assertSame(2, $placeCount);
        $this->assertSame(1, $cancelCount);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_REPLACED,
        ]);
    }

    /** @test */
    public function cancelling_the_stop_order_clears_the_tracker_and_logs_an_event(): void
    {
        $config = $this->createFuturesConfig();
        $this->createPosition($config, liquidationPrice: 45000.0);
        $this->setPositionState($config, entryPrice: 50000.0, qty: 1.0, side: 'long');

        Http::fake(fn () => Http::response(['orderId' => 12345], 200));

        $service = app(RatioBotExchangeStopOrderService::class);
        $workspaceConfig = ['futures_force_testnet' => true];
        $service->syncStopOrder($config, $this->integration, $workspaceConfig);

        $service->cancelStopOrder($config->fresh('state'), $this->integration, $workspaceConfig, 'liquidation_guard_force_exit');

        $config->state?->refresh();
        $this->assertNull($config->state->futures_stop_order_id);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_CANCELLED,
        ]);
    }

    /** @test */
    public function algo_mode_places_replaces_and_cancels_the_stop_through_the_algo_endpoints_only(): void
    {
        config(['binance.conditional_order_mode' => 'algo']);
        $config = $this->createFuturesConfig();
        $this->createPosition($config, liquidationPrice: 45000.0);
        $this->setPositionState($config, entryPrice: 50000.0, qty: 1.0, side: 'long');

        $calls = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$calls) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            parse_str((string) $request->body(), $form);
            $calls[] = ['key' => $request->method().' '.$path, 'query' => $query, 'form' => $form];
            if ($request->method() === 'POST' && $path === '/fapi/v1/algoOrder') {
                return Http::response(['algoId' => 7000 + count($calls), 'clientAlgoId' => $form['clientAlgoId'], 'orderType' => 'STOP_MARKET',
                    'symbol' => 'BTCUSDT', 'side' => 'SELL', 'algoStatus' => 'NEW', 'triggerPrice' => $form['triggerPrice'], 'closePosition' => true], 200);
            }
            if ($request->method() === 'DELETE' && $path === '/fapi/v1/algoOrder') {
                return Http::response(['clientAlgoId' => $query['clientAlgoId'] ?? '', 'code' => '200', 'msg' => 'success'], 200);
            }

            return Http::response(['code' => -9999, 'msg' => 'unexpected '.$request->method().' '.$path], 500);
        });

        $service = app(RatioBotExchangeStopOrderService::class);
        $workspaceConfig = ['futures_force_testnet' => true];

        $service->syncStopOrder($config, $this->integration, $workspaceConfig);
        $config->state?->refresh();
        $firstClientId = $config->state->futures_stop_client_order_id;
        $this->assertNotNull($config->state->futures_stop_order_id);
        $this->assertSame('POST /fapi/v1/algoOrder', $calls[0]['key']);
        $this->assertSame('SELL', $calls[0]['form']['side']);
        $this->assertSame('true', $calls[0]['form']['closePosition']);
        $this->assertArrayNotHasKey('quantity', $calls[0]['form']);
        $this->assertSame($firstClientId, $calls[0]['form']['clientAlgoId']);
        $this->assertLessThanOrEqual(36, strlen($calls[0]['form']['clientAlgoId']));

        // Same basis: idempotent, no new exchange call at all.
        $before = count($calls);
        $service->syncStopOrder($config->fresh('state'), $this->integration, $workspaceConfig);
        $this->assertCount($before, $calls);

        // Basis changed: cancel by clientAlgoId (query string) then place the new one, never a duplicate.
        $this->setPositionState($config, entryPrice: 51000.0, qty: 1.5, side: 'long');
        $service->syncStopOrder($config->fresh('state'), $this->integration, $workspaceConfig);
        $keys = array_column($calls, 'key');
        $this->assertSame(['POST /fapi/v1/algoOrder', 'DELETE /fapi/v1/algoOrder', 'POST /fapi/v1/algoOrder'], $keys);
        $this->assertSame($firstClientId, $calls[1]['query']['clientAlgoId']);

        // Close: cancel through the algo endpoint and clear the tracker.
        $service->cancelStopOrder($config->fresh('state'), $this->integration, $workspaceConfig, 'test_close');
        $allKeys = array_column($calls, 'key');
        $this->assertSame('DELETE /fapi/v1/algoOrder', end($allKeys));
        $config->state?->refresh();
        $this->assertNull($config->state->futures_stop_order_id);
        $this->assertNotContains('POST /fapi/v1/order', array_column($calls, 'key'));
    }

    /** @test */
    public function a_failed_algo_stop_placement_is_loud_keeps_state_untouched_and_never_falls_back_to_legacy(): void
    {
        config(['binance.conditional_order_mode' => 'algo']);
        \Illuminate\Support\Facades\Log::spy();
        $config = $this->createFuturesConfig();
        $this->createPosition($config, liquidationPrice: 45000.0);
        $this->setPositionState($config, entryPrice: 50000.0, qty: 1.0, side: 'long');

        $legacyHits = 0;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$legacyHits) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/fapi/v1/order') {
                $legacyHits++;
            }
            if ($path === '/fapi/v1/algoOrder' && $request->method() === 'POST') {
                return Http::response(['code' => -2021, 'msg' => 'Order would immediately trigger.'], 400);
            }

            return Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400);
        });

        app(RatioBotExchangeStopOrderService::class)->syncStopOrder($config, $this->integration, ['futures_force_testnet' => true]);

        $config->state?->refresh();
        $this->assertNull($config->state->futures_stop_order_id);
        $this->assertSame(0, $legacyHits);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message, $context = []) => str_contains($message, '[RatioBotStopOrder]') && ($context['binance_code'] ?? null) === -2021)
            ->once();
    }

    /** @test */
    public function no_stop_is_placed_when_there_is_no_open_position(): void
    {
        $config = $this->createFuturesConfig();

        Http::fake(function () {
            $this->fail('No exchange call should happen when there is no open position.');
        });

        app(RatioBotExchangeStopOrderService::class)->syncStopOrder($config, $this->integration, ['futures_force_testnet' => true]);

        $config->state?->refresh();
        $this->assertNull($config->state->futures_stop_order_id);
    }

    private function createFuturesConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'live',
            'enabled' => true,
            'liquidation_guard_enabled' => true,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
            'leverage' => 5,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }

    private function setPositionState(RatioBotConfig $config, float $entryPrice, float $qty, string $side): void
    {
        $config->state?->update([
            'position_state' => RatioBotState::STATE_HOLDING,
            'last_position_side' => $side,
            'last_position_qty' => $qty,
            'entry_price' => $entryPrice,
        ]);
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
            'last_mark_price' => 50000.0,
            'liquidation_price' => $liquidationPrice,
            'liquidation_buffer_pct' => 10.0,
            'mark_price_synced_at' => now(),
        ]);
    }
}
