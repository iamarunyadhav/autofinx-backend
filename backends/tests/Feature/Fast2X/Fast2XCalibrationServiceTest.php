<?php

namespace Tests\Feature\Fast2X;

use App\Domain\Fast2X\Fast2XCalibrationService;
use App\Models\Fast2XConfig;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fast2X's own NATIVE calibration (Fast2XCalibrationService) -- reads real
 * fast2x_decision_logs (BUY_TRIGGER_DETECTED) + real fast2x_trades
 * (fee/slippage-netted net_pnl), never touches
 * ForecastHorizonBacktestService::runForBot() or any OHLC forecast data.
 *
 * All trigger/trade timestamps below are inserted via raw DB::table()
 * inserts (not Eloquent ::create()) specifically so `created_at`/
 * `occurred_at` are fully hand-controlled, not overwritten by Eloquent's
 * automatic timestamp management -- required for the trigger-to-trade
 * pairing logic under test to be deterministic.
 */
class Fast2XCalibrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_calibration_computes_hand_computable_win_rate_from_real_matched_trades(): void
    {
        $config = $this->config();
        $base = now()->subHour();

        // Cycle 1: trigger -> buy -> sell, net_pnl positive (WIN)
        $this->insertTrigger($config->id, $base->copy()->addSeconds(0));
        $this->insertTrade($config->id, 'BUY', $base->copy()->addSeconds(1), ['entry_price' => 100, 'net_pnl' => null]);
        $this->insertTrade($config->id, 'SELL', $base->copy()->addSeconds(2), ['entry_price' => 100, 'exit_price' => 105, 'gross_pnl' => 5, 'fees' => 0.1, 'slippage' => 0.05, 'net_pnl' => 4.85]);

        // Cycle 2: trigger -> buy -> sell, net_pnl negative (LOSS)
        $this->insertTrigger($config->id, $base->copy()->addSeconds(10));
        $this->insertTrade($config->id, 'BUY', $base->copy()->addSeconds(11), ['entry_price' => 100, 'net_pnl' => null]);
        $this->insertTrade($config->id, 'SELL', $base->copy()->addSeconds(12), ['entry_price' => 100, 'exit_price' => 98, 'gross_pnl' => -2, 'fees' => 0.1, 'slippage' => 0.05, 'net_pnl' => -2.15]);

        // Cycle 3: trigger -> buy -> sell, net_pnl positive (WIN)
        $this->insertTrigger($config->id, $base->copy()->addSeconds(20));
        $this->insertTrade($config->id, 'BUY', $base->copy()->addSeconds(21), ['entry_price' => 100, 'net_pnl' => null]);
        $this->insertTrade($config->id, 'SELL', $base->copy()->addSeconds(22), ['entry_price' => 100, 'exit_price' => 101, 'gross_pnl' => 1, 'fees' => 0.05, 'slippage' => 0.02, 'net_pnl' => 0.93]);

        $result = app(Fast2XCalibrationService::class)->calibrate($config);

        $this->assertNotNull($result);
        $this->assertSame(3, $result['sample_size']);
        $this->assertSame(2, $result['hits']);
        $this->assertEqualsWithDelta(66.67, $result['win_rate_pct'], 0.01);
        $this->assertFalse($result['calibrated']); // below MIN_BUCKET_SAMPLE_SIZE (30)
        $this->assertFalse($result['confidence_buckets_available']);
        $this->assertSame(0, $result['unresolved_triggers']);
    }

    public function test_fee_and_slippage_netting_turns_a_price_direction_win_into_a_calibration_loss(): void
    {
        $config = $this->config();
        $base = now()->subHour();

        $this->insertTrigger($config->id, $base->copy());
        $this->insertTrade($config->id, 'BUY', $base->copy()->addSeconds(1), ['entry_price' => 100, 'net_pnl' => null]);
        // Price moved UP (101 > 100, gross_pnl positive -- a price-direction
        // "win") but fees+slippage exceed the gross profit, so net_pnl < 0.
        $this->insertTrade($config->id, 'SELL', $base->copy()->addSeconds(2), [
            'entry_price' => 100,
            'exit_price' => 101,
            'gross_pnl' => 1,
            'fees' => 0.8,
            'slippage' => 0.5,
            'net_pnl' => -0.3,
        ]);

        $result = app(Fast2XCalibrationService::class)->calibrate($config);

        $this->assertSame(1, $result['sample_size']);
        $this->assertSame(0, $result['hits'], 'A fee/slippage-negative trade must NOT count as a calibration hit even though price moved in the predicted direction.');
        $this->assertSame(0.0, $result['win_rate_pct']);
    }

    public function test_unresolved_trigger_with_no_settled_trade_is_excluded_not_counted_as_loss(): void
    {
        $config = $this->config();
        $base = now()->subHour();

        // Trigger followed by a BUY that never got a settled SELL (position still open).
        $this->insertTrigger($config->id, $base->copy());
        $this->insertTrade($config->id, 'BUY', $base->copy()->addSeconds(1), ['entry_price' => 100, 'net_pnl' => null]);

        $result = app(Fast2XCalibrationService::class)->calibrate($config);

        $this->assertSame(0, $result['sample_size']);
        $this->assertSame(0, $result['hits']);
        $this->assertNull($result['win_rate_pct']);
        $this->assertSame(1, $result['unresolved_triggers']);
    }

    public function test_config_with_no_decision_history_returns_null_and_writes_nothing(): void
    {
        $config = $this->config();

        $result = app(Fast2XCalibrationService::class)->calibrate($config);

        $this->assertNull($result);
        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 0);
    }

    public function test_calibration_is_read_only_and_never_mutates_fast2x_tables(): void
    {
        $config = $this->config();
        $base = now()->subHour();

        $this->insertTrigger($config->id, $base->copy());
        $this->insertTrade($config->id, 'BUY', $base->copy()->addSeconds(1), ['entry_price' => 100, 'net_pnl' => null]);
        $this->insertTrade($config->id, 'SELL', $base->copy()->addSeconds(2), ['entry_price' => 100, 'exit_price' => 105, 'gross_pnl' => 5, 'fees' => 0.1, 'slippage' => 0.05, 'net_pnl' => 4.85]);

        $configsBefore = DB::table('fast2x_configs')->orderBy('id')->get();
        $tradesBefore = DB::table('fast2x_trades')->orderBy('id')->get();
        $logsBefore = DB::table('fast2x_decision_logs')->orderBy('id')->get();

        app(Fast2XCalibrationService::class)->calibrate($config);

        $this->assertEquals($configsBefore, DB::table('fast2x_configs')->orderBy('id')->get());
        $this->assertEquals($tradesBefore, DB::table('fast2x_trades')->orderBy('id')->get());
        $this->assertEquals($logsBefore, DB::table('fast2x_decision_logs')->orderBy('id')->get());

        // The ONLY write this service performs is into the shared, separate
        // ratio_bot_forecast_backtests analytics table.
        $this->assertDatabaseHas('ratio_bot_forecast_backtests', [
            'bot_type' => 'fast2x',
            'bot_instance_id' => (string) $config->id,
        ]);
    }

    public function test_periodic_command_calibrates_all_fast2x_configs(): void
    {
        $configA = $this->config();
        $configB = $this->config(['symbol' => 'ETHUSDT']);
        $base = now()->subHour();

        $this->insertTrigger($configA->id, $base->copy());
        $this->insertTrade($configA->id, 'BUY', $base->copy()->addSeconds(1), ['entry_price' => 100, 'net_pnl' => null]);
        $this->insertTrade($configA->id, 'SELL', $base->copy()->addSeconds(2), ['entry_price' => 100, 'exit_price' => 105, 'gross_pnl' => 5, 'fees' => 0.1, 'slippage' => 0.05, 'net_pnl' => 4.85]);

        $this->insertTrigger($configB->id, $base->copy());
        $this->insertTrade($configB->id, 'BUY', $base->copy()->addSeconds(1), ['entry_price' => 200, 'net_pnl' => null]);
        $this->insertTrade($configB->id, 'SELL', $base->copy()->addSeconds(2), ['entry_price' => 200, 'exit_price' => 199, 'gross_pnl' => -1, 'fees' => 0.1, 'slippage' => 0.05, 'net_pnl' => -1.15]);

        $this->artisan('fast2x:calibration-backtest')->assertExitCode(0);

        $this->assertDatabaseHas('ratio_bot_forecast_backtests', [
            'bot_type' => 'fast2x',
            'bot_instance_id' => (string) $configA->id,
            'sample_size' => 1,
            'hits' => 1,
        ]);
        $this->assertDatabaseHas('ratio_bot_forecast_backtests', [
            'bot_type' => 'fast2x',
            'bot_instance_id' => (string) $configB->id,
            'sample_size' => 1,
            'hits' => 0,
        ]);
    }

    private function config(array $overrides = []): Fast2XConfig
    {
        return Fast2XConfig::create(array_merge([
            'workspace_id' => (string) Str::uuid(),
            'symbol' => 'BTCUSDT',
            'paper_trading' => true,
            'enable_live_trading' => false,
            'settings' => config('fast2x.defaults'),
        ], $overrides));
    }

    private function insertTrigger(string $configId, $occurredAt): void
    {
        DB::table('fast2x_decision_logs')->insert([
            'id' => (string) Str::uuid(),
            'fast2x_config_id' => $configId,
            'event' => 'BUY_TRIGGER_DETECTED',
            'bot_name' => 'Fast2X',
            'symbol' => 'BTCUSDT',
            'entry_price' => null,
            'current_price' => 100,
            'net_pnl_percent' => null,
            'reason' => null,
            'context' => json_encode(['currentPrice' => 100]),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function insertTrade(string $configId, string $side, $createdAt, array $overrides = []): void
    {
        DB::table('fast2x_trades')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'fast2x_config_id' => $configId,
            'bot_name' => 'Fast2X',
            'symbol' => 'BTCUSDT',
            'side' => $side,
            'entry_price' => null,
            'exit_price' => null,
            'quantity' => 0.001,
            'gross_pnl' => null,
            'fees' => 0,
            'slippage' => 0,
            'net_pnl' => null,
            'reason' => $side === 'BUY' ? 'MICRO_ENTRY_SIGNAL' : 'PROFIT_TARGET_HIT',
            'mode' => 'PAPER',
            'status' => 'FILLED',
            'order_id' => 'paper-' . strtolower($side) . '-' . Str::uuid(),
            'meta' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $overrides));
    }
}
