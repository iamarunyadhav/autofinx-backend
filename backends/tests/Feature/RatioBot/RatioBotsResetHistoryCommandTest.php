<?php

namespace Tests\Feature\RatioBot;

use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotForecastBacktest;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatioBotsResetHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private function bot(array $state = []): RatioBotConfig
    {
        $bot = RatioBotConfig::create(['workspace_id' => Workspace::factory()->create()->id, 'symbol' => 'ETHUSDT',
            'name' => 'bot-'.Str::random(5), 'execution_mode' => 'live', 'enabled' => true, 'sell_threshold_ratio' => 0.03,
            'buy_threshold_ratio' => 0.02]);
        RatioBotState::create(array_merge(['ratio_bot_config_id' => $bot->id, 'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 2472, 'realized_pnl' => 12.5, 'consecutive_order_failures' => 2], $state));
        RatioBotDecision::create(['ratio_bot_config_id' => $bot->id, 'decision' => 'HOLD', 'evaluated_price' => 2700, 'decided_at' => now()]);
        RatioBotLot::create(['ratio_bot_config_id' => $bot->id, 'cycle_number' => 1, 'buy_stage_index' => 1, 'lot_type' => 'NORMAL',
            'allocation_pct' => 100, 'buy_price' => 2400, 'original_qty' => 0.1, 'remaining_qty' => 0, 'spent_quote' => 240,
            'status' => 'closed', 'bought_at' => now()]);

        return $bot->fresh('state');
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $bot = $this->bot();

        Artisan::call('ratio-bots:reset-history', ['--bot' => [$bot->id]]);

        $this->assertSame(1, RatioBotDecision::where('ratio_bot_config_id', $bot->id)->count());
        $this->assertStringContainsString('Dry run only', Artisan::output());
    }

    public function test_confirm_wipes_the_bots_history_resets_state_and_keeps_settings_and_shared_data(): void
    {
        $bot = $this->bot();
        $other = $this->bot();
        $symbol = Symbol::firstOrCreate(['symbol' => 'ETHUSDT'], ['id' => (string) Str::uuid(), 'type' => 'crypto']);
        $order = Order::create(['id' => (string) Str::uuid(), 'workspace_id' => $bot->workspace_id, 'symbol_id' => $symbol->id,
            'provider' => 'binance', 'exchange' => 'binance', 'asset_class' => 'CRYPTO', 'asset_code' => 'ETHUSDT', 'quote_currency' => 'USDT',
            'side' => 'sell', 'type' => 'market', 'quantity' => 0.1, 'qty' => 0.1, 'requested_qty' => 0.1, 'price' => 2472,
            'status' => Order::STATUS_FILLED, 'reconciliation_state' => Order::RECONCILIATION_ALIGNED, 'client_order_id' => 'rb-x',
            'idempotency_key' => 'rb-x', 'trace_id' => (string) Str::uuid(), 'meta' => ['ratio_bot_config_id' => (string) $bot->id]]);

        Artisan::call('ratio-bots:reset-history', ['--bot' => [$bot->id], '--confirm' => true]);

        $this->assertSame(0, RatioBotDecision::where('ratio_bot_config_id', $bot->id)->count());
        $this->assertSame(0, RatioBotLot::where('ratio_bot_config_id', $bot->id)->count());
        $this->assertNull(RatioBotState::where('ratio_bot_config_id', $bot->id)->first());
        $this->assertNotNull(RatioBotConfig::find($bot->id));                 // settings kept
        $this->assertFalse((bool) RatioBotConfig::find($bot->id)->enabled);    // left stopped
        $this->assertNotNull(Order::find($order->id));                          // exchange history kept
        $this->assertSame(1, RatioBotDecision::where('ratio_bot_config_id', $other->id)->count());   // other bot untouched
    }

    public function test_a_bot_still_holding_coins_is_refused_without_force(): void
    {
        $bot = $this->bot(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 2400, 'last_buy_quantity' => 0.1]);

        $exit = Artisan::call('ratio-bots:reset-history', ['--bot' => [$bot->id], '--confirm' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame(1, RatioBotDecision::where('ratio_bot_config_id', $bot->id)->count());
        $this->assertStringContainsString('cost basis would be lost', Artisan::output());

        Artisan::call('ratio-bots:reset-history', ['--bot' => [$bot->id], '--confirm' => true, '--force' => true]);
        $this->assertSame(0, RatioBotDecision::where('ratio_bot_config_id', $bot->id)->count());
    }
}
