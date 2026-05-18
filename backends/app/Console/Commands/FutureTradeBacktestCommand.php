<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBacktestEngine;
use App\Models\FutureTradeBotConfig;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FutureTradeBacktestCommand extends Command
{
    protected $signature = 'future-trade:backtest
        {--workspace= : Workspace UUID (required)}
        {--from=      : Start date YYYY-MM-DD (default: 30 days ago)}
        {--to=        : End date   YYYY-MM-DD (default: yesterday)}
        {--capital=1000 : Initial capital in USDT}
        {--prime-validation : Run AutoFinX Prime v6.1.7 validation symbols: ETH, BTC, SOL, BNB}';

    protected $description = 'Run a historical backtest for a Future Trade bot config using Binance kline data.';

    public function handle(FutureTradeBacktestEngine $engine): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        if ($workspaceId === '') {
            $this->error('--workspace is required.');
            return self::FAILURE;
        }

        $config = FutureTradeBotConfig::where('workspace_id', $workspaceId)
            ->where('slot_index', 0)
            ->first();

        if (! $config) {
            $this->error("No FutureTradeBotConfig found for workspace: {$workspaceId}");
            return self::FAILURE;
        }

        $from    = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        $to      = $this->option('to')
            ? Carbon::parse($this->option('to'))->endOfDay()
            : now()->subDay()->endOfDay();

        $capital = max(10, (float) $this->option('capital'));
        $settingsOverride = [];
        if ((bool) $this->option('prime-validation')) {
            $settingsOverride = [
                'signalProfile' => 'autofinx_prime_v6_1_7_crypto',
                'signalProfileName' => 'AutoFinX Prime v6.1.7 Crypto Stable Default',
                'marketProfile' => 'Crypto',
                'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT'],
            ];
            $this->warn('Prime validation mode: ETHUSDT, BTCUSDT, SOLUSDT, BNBUSDT. BNB is validation-only, not a default symbol.');
        }

        $this->info("Backtest: {$from->toDateString()} → {$to->toDateString()} | capital USDT {$capital}");
        $this->info('Fetching klines from Binance... (may take 30-60s)');

        $run = $engine->run($config, $from, $to, $capital, $settingsOverride);

        if ($run->status === 'failed') {
            $this->error('Backtest failed: ' . $run->error_message);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== BACKTEST RESULTS ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Period',           $run->from_date->toDateString() . ' → ' . $run->to_date->toDateString()],
                ['Total Trades',     $run->total_trades],
                ['Wins / Losses',    "{$run->wins} / {$run->losses}"],
                ['Win Rate',         round($run->win_rate * 100, 2) . '%'],
                ['Total P&L',        '$' . number_format($run->total_pnl_usdt, 2) . ' (' . round($run->total_pnl_percent, 2) . '%)'],
                ['Max Drawdown',     '$' . number_format($run->max_drawdown_usdt, 2) . ' (' . round($run->max_drawdown_percent, 2) . '%)'],
                ['Profit Factor',    $run->profit_factor],
                ['Sharpe Ratio',     $run->sharpe_ratio],
                ['Avg Duration',     $run->avg_trade_duration_minutes . ' min'],
            ]
        );

        if (! empty($run->by_symbol)) {
            $this->newLine();
            $this->info('--- By Symbol ---');
            $rows = [];
            foreach ($run->by_symbol as $sym => $data) {
                $rows[] = [$sym, $data['trades'], round($data['win_rate'] * 100, 1) . '%', '$' . round($data['net_pnl'], 2)];
            }
            $this->table(['Symbol', 'Trades', 'Win%', 'Net P&L'], $rows);
        }

        if ((bool) $this->option('prime-validation')) {
            $this->newLine();
            $this->info('--- Prime v6.1.7 Validation Notes ---');
            $this->line('Expected: ETH and SOL should be strongest.');
            $this->line('BTC is acceptable only if profit factor stays above about 1.15 with low drawdown.');
            $this->line('BNB remains validation-only and should not become default if PF is below 1.0.');
            $this->line('Forex and XAUUSD/Gold require separate profiles.');
        }

        if (! empty($run->by_exit_reason)) {
            $this->newLine();
            $this->info('--- By Exit Reason ---');
            $rows = [];
            foreach ($run->by_exit_reason as $reason => $data) {
                $rows[] = [$reason, $data['trades'], '$' . round($data['net_pnl'], 2)];
            }
            $this->table(['Exit Reason', 'Trades', 'Net P&L'], $rows);
        }

        $this->newLine();
        $this->info("Run ID: {$run->id}");
        return self::SUCCESS;
    }
}
