<?php

namespace Tests\Feature\RatioBot;

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\MarketOhlc;
use App\Models\RatioBotForecastBacktest;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the `future-trade:forecast-backtest` periodic command (tracking-only
 * wiring of Future Trade into ForecastHorizonBacktestService::runForBot(),
 * mirroring QuickTradeForecastBacktestCommand) writes calibration rows with a
 * correct, non-null bot_type/bot_instance_id, is driven ONLY by real
 * future_trade_logical_trades rows (never fabricated symbols), and never
 * touches any live trading row -- it is a read-only analysis command, never
 * called from FutureTradeBotService::scan() or any other live path.
 */
class FutureTradeForecastBacktestCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedTrend(string $exchange, string $symbol, string $timeframe, float $start, float $step, int $count): void
    {
        $time = now()->subMinutes($count + 1);
        for ($i = 0; $i < $count; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            MarketOhlc::create([
                'exchange' => $exchange,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open' => $open,
                'high' => max($open, $close) + 1,
                'low' => min($open, $close) - 1,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }

    private function createLogicalTrade(string $configId, string $symbol, string $timeframe): FutureTradeLogicalTrade
    {
        return FutureTradeLogicalTrade::create([
            'id' => (string) Str::uuid(),
            'future_trade_bot_config_id' => $configId,
            'symbol' => $symbol,
            'side' => 'LONG',
            'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
            'mode' => 'PAPER',
            'timeframe' => $timeframe,
            'entry_price' => 100.0,
            'exit_price' => 101.0,
            'quantity' => 1,
            'net_pnl' => 1.0,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);
    }

    public function test_command_writes_calibration_rows_with_correct_bot_type_and_non_null_bot_instance_id(): void
    {
        $symbol = 'FTMUSDT';
        $timeframe = '1m';
        $this->seedTrend('binance_futures', $symbol, $timeframe, 100.0, 1.0, 400);

        $config = FutureTradeBotConfig::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) Str::uuid(),
            'bot_name' => 'Future Trade Bot',
            'paper_trading' => true,
            'enable_live_trading' => false,
            'enabled' => true,
        ]);
        $this->createLogicalTrade($config->id, $symbol, $timeframe);

        $this->artisan('future-trade:forecast-backtest')->assertExitCode(0);

        $rows = RatioBotForecastBacktest::query()->where('bot_type', 'future_trade')->get();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $this->assertNotNull($row->bot_instance_id);
            $this->assertNotSame('', $row->bot_instance_id);
            $this->assertSame("binance_futures:{$symbol}:{$timeframe}", $row->bot_instance_id);
        }
    }

    public function test_command_never_touches_the_real_logical_trade_row_it_read_the_symbol_from(): void
    {
        $symbol = 'GALAUSDT';
        $timeframe = '1m';
        $this->seedTrend('binance_futures', $symbol, $timeframe, 50.0, 0.2, 400);

        $config = FutureTradeBotConfig::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) Str::uuid(),
            'bot_name' => 'Future Trade Bot',
            'paper_trading' => true,
            'enable_live_trading' => false,
            'enabled' => true,
        ]);
        $trade = $this->createLogicalTrade($config->id, $symbol, $timeframe);
        $before = $trade->fresh()->getAttributes();

        $this->artisan('future-trade:forecast-backtest')->assertExitCode(0);

        $after = $trade->fresh()->getAttributes();
        $this->assertSame($before, $after, 'the periodic tracking command must never mutate a real Future Trade logical trade row');
    }

    public function test_symbols_with_no_recent_logical_trades_are_never_backtested(): void
    {
        // No FutureTradeLogicalTrade rows exist at all -- the command must
        // process zero combinations, never fabricate a symbol to test.
        $this->artisan('future-trade:forecast-backtest')->assertExitCode(0);

        $this->assertDatabaseCount('ratio_bot_forecast_backtests', 0);
    }
}
