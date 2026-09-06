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
