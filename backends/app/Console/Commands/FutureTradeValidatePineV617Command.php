<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBacktestEngine;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FutureTradeValidatePineV617Command extends Command
{
    protected $signature = 'future-trade:validate-pine-v617
        {--workspace= : Workspace UUID. Uses the first primary Future Trade config when omitted}
        {--symbols=ETHUSDT.P,BTCUSDT.P,SOLUSDT.P,BNBUSDT.P : Comma-separated TradingView/Binance symbols}
        {--timeframe=15m : Entry timeframe, default 15m}
        {--days=90 : Lookback days when --from is omitted}
        {--from= : Start date YYYY-MM-DD}
        {--to= : End date YYYY-MM-DD}
        {--capital=1000 : Initial capital in USDT}
        {--tv-csv= : Optional TradingView exported candle decision CSV for mismatch comparison}';

    protected $description = 'Validate AutoFinX Prime v6.1.7 Pine mirror decisions, backtest stats, and optional TradingView mismatches.';

    public function handle(FutureTradeBacktestEngine $engine): int
    {
        $symbols = $this->normalizeSymbols((string) $this->option('symbols'));
        if (empty($symbols)) {
            $this->error('No valid crypto futures symbols supplied.');
            return self::FAILURE;
        }

        $timeframe = $this->normalizeTimeframe((string) $this->option('timeframe'));
        if ($timeframe !== '15m') {
            $this->warn("Prime v6.1.7 production default is 15m. Validation will still run requested timeframe: {$timeframe}");
        }

        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->endOfDay()
            : now()->endOfDay();
        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : $to->copy()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $capital = max(10, (float) $this->option('capital'));

        $config = $this->resolveConfig();
        if (! $config) {
            $this->error('Unable to create or find a Future Trade config for validation.');
            return self::FAILURE;
        }

        if (! Schema::hasTable('future_trade_backtest_runs') || ! Schema::hasTable('future_trade_backtest_trades')) {
            $this->error('Backtest tables are missing. Run: php artisan migrate --force');
            return self::FAILURE;
        }

        $exportDir = storage_path('app/future-trade/validation/' . now()->format('Ymd_His') . '_prime_v617');
        File::ensureDirectoryExists($exportDir);

        $settingsOverride = $this->primeSettings($symbols, $timeframe);

        $this->info('Pine mirror config');
        $this->table(['Key', 'Value'], [
            ['active_profile', FutureTradeBotService::PROFILE_PRIME_V617_CRYPTO],
            ['profile_name', 'AutoFinX Prime v6.1.7 Crypto Stable Default'],
            ['mode', 'pine_mirror_or_webhook_executor'],
            ['timeframe', $timeframe],
            ['symbols', implode(', ', $symbols)],
            ['secondary_ai_weighting', 'disabled'],
            ['legacy_modules', 'macd/rsi weighted, symbol penalty, bayesian, ut_bot, atr_expansion, liquidity_v7 bypassed for Prime'],
            ['period', $from->toDateString() . ' to ' . $to->toDateString()],
        ]);

        $summaryRows = [];
        $tradeRows = [];

        foreach ($symbols as $symbol) {
            $this->line("Backtesting {$symbol} {$timeframe}...");
            $run = $engine->run($config, $from->copy(), $to->copy(), $capital, array_merge($settingsOverride, [
                'symbols' => [$symbol],
            ]));

            if ($run->status === 'failed') {
                $summaryRows[] = [
                    'symbol' => $symbol,
                    'default_status' => $this->defaultStatus($symbol, null),
                    'total_trades' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'win_rate' => 0,
                    'profit_factor' => 0,
                    'max_drawdown_percent' => 0,
                    'total_pnl_usdt' => 0,
                    'decision_mismatch_count' => '',
                    'notes' => 'backtest_failed: ' . $run->error_message,
                ];
                continue;
            }

            $summaryRows[] = [
                'symbol' => $symbol,
                'default_status' => $this->defaultStatus($symbol, (float) $run->profit_factor),
                'total_trades' => (int) $run->total_trades,
                'wins' => (int) $run->wins,
                'losses' => (int) $run->losses,
                'win_rate' => round((float) $run->win_rate * 100, 2),
                'profit_factor' => (float) $run->profit_factor,
                'max_drawdown_percent' => (float) $run->max_drawdown_percent,
                'total_pnl_usdt' => (float) $run->total_pnl_usdt,
                'decision_mismatch_count' => '',
                'notes' => $this->validationNote($symbol, (float) $run->profit_factor, (float) $run->max_drawdown_percent),
            ];

            foreach ($run->trades()->orderBy('entry_time')->get() as $trade) {
                $tradeRows[] = [
                    'run_id' => $run->id,
                    'symbol' => $trade->symbol,
                    'side' => $trade->side,
                    'entry_time' => optional($trade->entry_time)->toIso8601String(),
                    'entry_price' => $trade->entry_price,
                    'exit_time' => optional($trade->exit_time)->toIso8601String(),
                    'exit_price' => $trade->exit_price,
                    'net_pnl' => $trade->net_pnl,
                    'entry_confidence' => $trade->entry_confidence,
                    'exit_reason' => $trade->exit_reason,
                    'risk_model' => data_get($trade->meta, 'risk_model'),
                ];
            }
        }

        $this->line('Exporting candle-by-candle Prime decision rows...');
        $decisionRows = $engine->decisionRows($config, $from->copy(), $to->copy(), $settingsOverride);
        $signalCounts = $this->signalCounts($decisionRows);

        $decisionCsv = $exportDir . DIRECTORY_SEPARATOR . 'prime_v617_backend_decisions.csv';
        $summaryCsv = $exportDir . DIRECTORY_SEPARATOR . 'prime_v617_summary.csv';
        $tradesCsv = $exportDir . DIRECTORY_SEPARATOR . 'prime_v617_trades.csv';
        $mismatchCsv = $exportDir . DIRECTORY_SEPARATOR . 'prime_v617_tv_mismatches.csv';

        $this->writeCsv($decisionCsv, $decisionRows);
        $this->writeCsv($summaryCsv, $summaryRows);
        $this->writeCsv($tradesCsv, $tradeRows);

        $comparison = $this->compareTradingViewCsv((string) $this->option('tv-csv'), $decisionRows, $mismatchCsv);
        foreach ($summaryRows as &$row) {
            $row['decision_mismatch_count'] = $comparison['compared']
                ? (int) ($comparison['bySymbol'][$row['symbol']] ?? 0)
                : 'not_compared';
        }
        unset($row);
        $this->writeCsv($summaryCsv, $summaryRows);

        $this->newLine();
        $this->info('=== Prime v6.1.7 Validation Report ===');
        $this->table(
            ['Symbol', 'Default', 'Trades', 'Win%', 'PF', 'Max DD%', 'P&L', 'Mismatches', 'Notes'],
            array_map(fn (array $row) => [
                $row['symbol'],
                $row['default_status'],
                $row['total_trades'],
                $row['win_rate'],
                $row['profit_factor'],
                $row['max_drawdown_percent'],
                $row['total_pnl_usdt'],
                $row['decision_mismatch_count'],
                $row['notes'],
            ], $summaryRows)
        );

        $this->newLine();
        $this->info('Signal counts');
        $this->table(['Symbol', 'BUY', 'SELL', 'WAIT', 'WATCH'], array_map(
            fn (string $symbol) => [
                $symbol,
                (int) ($signalCounts[$symbol]['BUY'] ?? 0),
                (int) ($signalCounts[$symbol]['SELL'] ?? 0),
                (int) ($signalCounts[$symbol]['WAIT'] ?? 0),
                (int) ($signalCounts[$symbol]['WATCH'] ?? 0),
            ],
            $symbols
        ));

        $this->newLine();
        $this->info('CSV exports');
        $this->line("Decision CSV: {$decisionCsv}");
        $this->line("Summary CSV:  {$summaryCsv}");
        $this->line("Trades CSV:   {$tradesCsv}");
        $this->line("Mismatch CSV: {$mismatchCsv}");
        $this->line('Mismatch report: ' . $comparison['message']);
        $this->line('Acceptance: decision mismatch < 2%, score delta <= 2 points, HTF mismatch ideally 0.');

        return self::SUCCESS;
    }

    private function resolveConfig(): ?FutureTradeBotConfig
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $hasSlotIndex = Schema::hasColumn('future_trade_bot_configs', 'slot_index');

        if ($workspaceId !== '') {
            $query = FutureTradeBotConfig::where('workspace_id', $workspaceId);
            if ($hasSlotIndex) {
                $query->where('slot_index', 0);
            }
            $config = $query->first();

            if ($config) {
                return $config;
            }

            $this->warn("No primary Future Trade config found for {$workspaceId}; creating validation-only config.");
            $attributes = [
                'workspace_id' => $workspaceId,
                'paper_trading' => true,
                'enable_live_trading' => false,
                'enabled' => false,
                'settings' => $this->primeSettings(['ETHUSDT', 'BTCUSDT', 'SOLUSDT'], '15m'),
            ];
            if ($hasSlotIndex) {
                $attributes['slot_index'] = 0;
            }

            return FutureTradeBotConfig::create($attributes);
        }

        $query = FutureTradeBotConfig::query();
        if ($hasSlotIndex) {
            $query->where('slot_index', 0);
        }
        $config = $query->first();
        if ($config) {
            return $config;
        }

        $attributes = [
            'workspace_id' => (string) Str::uuid(),
            'paper_trading' => true,
            'enable_live_trading' => false,
            'enabled' => false,
            'settings' => $this->primeSettings(['ETHUSDT', 'BTCUSDT', 'SOLUSDT'], '15m'),
        ];
        if ($hasSlotIndex) {
            $attributes['slot_index'] = 0;
        }

        return FutureTradeBotConfig::create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function primeSettings(array $symbols, string $timeframe): array
    {
        return [
            'signalProfile' => FutureTradeBotService::PROFILE_PRIME_V617_CRYPTO,
            'signalProfileName' => 'AutoFinX Prime v6.1.7 Crypto Stable Default',
            'marketProfile' => 'Crypto',
            'symbols' => array_values($symbols),
            'timeframe' => $timeframe,
            'minConfidence' => 0.72,
            'tradeScoreThreshold' => 72,
            'watchScoreThreshold' => 58,
            'tradeDirection' => 'Both',
            'htf1' => '60',
            'htf2' => '240',
            'emaFast' => 9,
            'emaSlow' => 21,
            'emaTrend' => 50,
            'emaMajor' => 200,
            'donchianLength' => 20,
            'confidenceMode' => 'fixed',
            'dynamicSuggestionEnabled' => false,
            'classicSimpleMode' => false,
            'avoidChoppyMarket' => false,
            'strictEntryGuards' => false,
            'useVWAP' => true,
            'useDonchianScore' => true,
            'useVolume' => true,
            'useVolatilityFilter' => true,
            'useChopFilter' => true,
            'chopLength' => 14,
            'cryptoChopMax' => 61.8,
            'useMomentumBreakoutFilter' => false,
            'rsiLength' => 14,
            'longRsiMin' => 48,
            'longRsiMax' => 72,
            'shortRsiMin' => 28,
            'shortRsiMax' => 52,
            'adxLength' => 14,
            'adxSmoothing' => 14,
            'adxMin' => 20,
            'volumeLength' => 20,
            'cryptoRelVolMin' => 1.0,
            'atrLength' => 14,
            'atrRegimeLength' => 50,
            'cryptoMinAtrPct' => 0.06,
            'cryptoMaxAtrPct' => 3.0,
            'slAtrMultiplier' => 1.4,
            'tp1RR' => 1.0,
            'tp2RR' => 2.0,
            'useAtrTrailingSecondHalf' => true,
            'trailAtrMultiplier' => 1.0,
            'cooldownBars' => 3,
            'exitOnStrongOppositeScore' => true,
            'oppositeExitScore' => 78,
            'bayesianFilter' => false,
            'useBayesianFilter' => false,
            'utBotFilter' => false,
            'useUtBotFilter' => false,
            'atrExpansionFilter' => false,
            'useAtrExpansionFilter' => false,
            'liquidityV7Engine' => false,
            'useLiquidityV7Engine' => false,
            'fvgEngine' => false,
            'useFvgEngine' => false,
            'scoreGapFilter' => false,
            'heavyAntiChopFilter' => false,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSymbols(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $symbol) => strtoupper(trim($symbol)))
            ->map(fn (string $symbol) => preg_replace('/^[A-Z0-9_]+:/', '', $symbol) ?? $symbol)
            ->map(fn (string $symbol) => str_replace(['.P', 'PERP'], '', $symbol))
            ->map(fn (string $symbol) => preg_replace('/[^A-Z0-9]/', '', $symbol) ?? '')
            ->filter(fn (string $symbol) => $symbol !== '' && str_ends_with($symbol, 'USDT'))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTimeframe(string $timeframe): string
    {
        $timeframe = strtolower(trim($timeframe));

        return match ($timeframe) {
            '15', '15m' => '15m',
            '5', '5m' => '5m',
            '30', '30m' => '30m',
            '60', '1h' => '1h',
            '240', '4h' => '4h',
            default => '15m',
        };
    }

    private function defaultStatus(string $symbol, ?float $profitFactor): string
    {
        if (in_array($symbol, ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'], true)) {
            return 'primary_crypto_default';
        }

        if ($symbol === 'BNBUSDT') {
            return $profitFactor !== null && $profitFactor >= 1.0 ? 'validation_optional' : 'not_default';
        }

        return 'validation_only';
    }

    private function validationNote(string $symbol, float $profitFactor, float $drawdown): string
    {
        if (in_array($symbol, ['ETHUSDT', 'SOLUSDT'], true)) {
            return 'expected strong; confirm PF/DD before live sizing';
        }

        if ($symbol === 'BTCUSDT') {
            return $profitFactor > 1.15 ? 'acceptable if drawdown remains low' : 'BTC below preferred PF 1.15 threshold';
        }

        if ($symbol === 'BNBUSDT') {
            return $profitFactor >= 1.0 ? 'optional only after validation' : 'not default while PF below 1.0';
        }

        return 'not a Prime default symbol';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, int>>
     */
    private function signalCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $symbol = (string) ($row['symbol'] ?? '');
            $decision = (string) ($row['finalDecision'] ?? 'WAIT');
            $bucket = str_starts_with($decision, 'WATCH') ? 'WATCH' : $decision;
            $counts[$symbol][$bucket] = (int) ($counts[$symbol][$bucket] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to write CSV: {$path}");
        }

        $headers = [];
        foreach ($rows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }

        if (empty($headers)) {
            $headers = ['empty'];
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $header) => is_bool($row[$header] ?? null)
                    ? (($row[$header] ?? false) ? 'true' : 'false')
                    : ($row[$header] ?? ''),
                $headers
            ));
        }

        fclose($handle);
    }

    /**
     * @param array<int, array<string, mixed>> $backendRows
     * @return array{compared: bool, message: string, bySymbol: array<string, int>}
     */
    private function compareTradingViewCsv(string $tvCsv, array $backendRows, string $mismatchCsv): array
    {
        if ($tvCsv === '') {
            $this->writeCsv($mismatchCsv, []);
            return [
                'compared' => false,
                'message' => 'not compared; pass --tv-csv=path/to/tradingview_export.csv',
                'bySymbol' => [],
            ];
        }

        if (! is_file($tvCsv)) {
            $this->writeCsv($mismatchCsv, [[
                'error' => "TradingView CSV not found: {$tvCsv}",
            ]]);
            return [
                'compared' => false,
                'message' => "TradingView CSV not found: {$tvCsv}",
                'bySymbol' => [],
            ];
        }

        $backendIndex = [];
        foreach ($backendRows as $row) {
            $key = $this->decisionKey((string) $row['symbol'], (string) $row['timeframe'], (string) $row['candle_close_time']);
            $backendIndex[$key] = $row;
        }

        $mismatches = [];
        $bySymbol = [];
        $comparedRows = 0;
        $decisionMismatches = 0;
        $scoreMismatches = 0;
        $htfMismatches = 0;
        $handle = fopen($tvCsv, 'rb');
        if ($handle === false) {
            $this->writeCsv($mismatchCsv, [['error' => "Unable to read TradingView CSV: {$tvCsv}"]]);
            return ['compared' => false, 'message' => "Unable to read TradingView CSV: {$tvCsv}", 'bySymbol' => []];
        }

        $headers = fgetcsv($handle) ?: [];
        while (($values = fgetcsv($handle)) !== false) {
            $tv = array_combine($headers, $values);
            if (! is_array($tv)) {
                continue;
            }

            $symbol = $this->normalizeSymbols((string) ($tv['symbol'] ?? $tv['ticker'] ?? ''))[0] ?? '';
            $timeframe = $this->normalizeTimeframe((string) ($tv['timeframe'] ?? $tv['interval'] ?? '15m'));
            $time = (string) ($tv['candle_close_time'] ?? $tv['time_close'] ?? $tv['close_time'] ?? $tv['time'] ?? '');
            $tvDecision = $this->normalizeDecision((string) ($tv['signal'] ?? $tv['decision'] ?? $tv['action'] ?? 'WAIT'));
            $key = $this->decisionKey($symbol, $timeframe, $time);
            $backend = $backendIndex[$key] ?? null;
            $backendDecision = $backend ? $this->normalizeDecision((string) ($backend['finalDecision'] ?? 'WAIT')) : 'MISSING';
            $comparedRows++;

            $tvLongScore = $this->csvFloat($tv, ['longScore', 'long_score', 'long_score_v617']);
            $tvShortScore = $this->csvFloat($tv, ['shortScore', 'short_score', 'short_score_v617']);
            $tvHtf1 = $this->csvNullableInt($tv, ['htfTrend1', 'htf_trend_1', 'htf1']);
            $tvHtf2 = $this->csvNullableInt($tv, ['htfTrend2', 'htf_trend_2', 'htf2']);
            $scoreMismatch = false;
            $htfMismatch = false;

            if ($backend && $tvLongScore !== null && abs(((float) ($backend['longScore'] ?? 0)) - $tvLongScore) > 2.0) {
                $scoreMismatch = true;
            }
            if ($backend && $tvShortScore !== null && abs(((float) ($backend['shortScore'] ?? 0)) - $tvShortScore) > 2.0) {
                $scoreMismatch = true;
            }
            if ($backend && $tvHtf1 !== null && (int) ($backend['htfTrend1'] ?? 0) !== $tvHtf1) {
                $htfMismatch = true;
            }
            if ($backend && $tvHtf2 !== null && (int) ($backend['htfTrend2'] ?? 0) !== $tvHtf2) {
                $htfMismatch = true;
            }

            if (! $backend || $backendDecision !== $tvDecision || $scoreMismatch || $htfMismatch) {
                if (! $backend || $backendDecision !== $tvDecision) {
                    $decisionMismatches++;
                }
                if ($scoreMismatch) {
                    $scoreMismatches++;
                }
                if ($htfMismatch) {
                    $htfMismatches++;
                }

                $mismatches[] = [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'candle_close_time' => $time,
                    'tradingview_decision' => $tvDecision,
                    'backend_decision' => $backendDecision,
                    'tradingview_longScore' => $tvLongScore ?? '',
                    'tradingview_shortScore' => $tvShortScore ?? '',
                    'backend_longScore' => $backend['longScore'] ?? '',
                    'backend_shortScore' => $backend['shortScore'] ?? '',
                    'tradingview_htfTrend1' => $tvHtf1 ?? '',
                    'tradingview_htfTrend2' => $tvHtf2 ?? '',
                    'backend_htfTrend1' => $backend['htfTrend1'] ?? '',
                    'backend_htfTrend2' => $backend['htfTrend2'] ?? '',
                    'score_mismatch' => $scoreMismatch ? 'yes' : 'no',
                    'htf_mismatch' => $htfMismatch ? 'yes' : 'no',
                    'backend_blockedReason' => $backend['blockedReason'] ?? 'missing_backend_row',
                ];
                $bySymbol[$symbol] = (int) ($bySymbol[$symbol] ?? 0) + 1;
            }
        }
        fclose($handle);

        $this->writeCsv($mismatchCsv, $mismatches);

        return [
            'compared' => true,
            'message' => sprintf(
                '%d compared rows; %d decision mismatches, %d score mismatches, %d HTF mismatches written',
                $comparedRows,
                $decisionMismatches,
                $scoreMismatches,
                $htfMismatches
            ),
            'bySymbol' => $bySymbol,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function csvFloat(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function csvNullableInt(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        return null;
    }

    private function decisionKey(string $symbol, string $timeframe, string $time): string
    {
        try {
            $time = Carbon::parse($time)->toIso8601String();
        } catch (\Throwable) {
            $time = trim($time);
        }

        return strtoupper($symbol) . '|' . $this->normalizeTimeframe($timeframe) . '|' . $time;
    }

    private function normalizeDecision(string $decision): string
    {
        $decision = strtoupper(trim($decision));

        return match ($decision) {
            'LONG', 'BUY', 'STRATEGY.LONG' => 'BUY',
            'SHORT', 'SELL', 'STRATEGY.SHORT' => 'SELL',
            default => str_starts_with($decision, 'WATCH') ? 'WAIT' : 'WAIT',
        };
    }
}
