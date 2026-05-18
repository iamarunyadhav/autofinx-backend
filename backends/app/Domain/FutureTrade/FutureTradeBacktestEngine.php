<?php

namespace App\Domain\FutureTrade;

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBacktestRun;
use App\Models\FutureTradeBacktestTrade;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FutureTradeBacktestEngine
{
    private const BINANCE_BASE       = 'https://fapi.binance.com';
    private const KLINE_LIMIT        = 1000;
    private const SEQUENCE_LOOKBACK  = 240; // 240 x 5m = 20 h, enough for Prime v6.1.7 EMA200 warm-up
    private const STRUCTURE_LOOKBACK = 100; // candles for structure detection
    private const FEE_RATE           = 0.0004; // 0.04 % per side (taker)

    public function __construct(
        private readonly FutureTradeBotService $botService,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function run(
        FutureTradeBotConfig $config,
        Carbon $from,
        Carbon $to,
        float $initialCapital = 1000.0,
        array $settingsOverride = []
    ): FutureTradeBacktestRun {
        $settingsSnapshot = array_merge($config->mergedSettings(), $settingsOverride);
        $run = FutureTradeBacktestRun::create([
            'workspace_id'              => $config->workspace_id,
            'future_trade_bot_config_id' => $config->id,
            'status'                    => 'running',
            'from_date'                 => $from->toDateString(),
            'to_date'                   => $to->toDateString(),
            'initial_capital_usdt'      => $initialCapital,
            'settings_snapshot'         => $settingsSnapshot,
            'started_at'                => now(),
        ]);

        try {
            $settings = $this->botService->normalizeSettingsForBacktest($settingsSnapshot);
            $symbols  = array_values((array) ($settings['symbols'] ?? ['BTCUSDT']));

            Log::info('[FT BACKTEST] Fetching historical klines', [
                'run_id'  => $run->id,
                'symbols' => $symbols,
                'from'    => $from->toDateString(),
                'to'      => $to->toDateString(),
            ]);

            $klines5m = $this->fetchAllKlines($symbols, '5m', $from, $to);

            // HTF needs a warm-up window before $from so EMAs are valid at the start
            $htfFrom = $from->copy()->subHours(220);
            $klines1h = $this->fetchAllKlines($symbols, '1h', $htfFrom, $to);
            $klines4h = $this->fetchAllKlines($symbols, '4h', $htfFrom, $to);

            $htfIndex = $this->buildHtfIndex($symbols, $klines1h, $klines4h);

            Log::info('[FT BACKTEST] Starting simulation', ['run_id' => $run->id]);

            $trades = $this->simulate($settings, $symbols, $klines5m, $htfIndex, $initialCapital);

            $stats = $this->computeStats($trades, $initialCapital);

            $run->update(array_merge($stats, [
                'status'     => 'completed',
                'symbols'    => $symbols,
                'completed_at' => now(),
            ]));

            foreach (array_chunk($trades, 200) as $chunk) {
                $rows = array_map(fn ($t) => array_merge(
                    ['future_trade_backtest_run_id' => $run->id],
                    $t,
                    ['created_at' => now(), 'updated_at' => now()]
                ), $chunk);
                FutureTradeBacktestTrade::insert($rows);
            }

            Log::info('[FT BACKTEST] Completed', [
                'run_id'       => $run->id,
                'total_trades' => count($trades),
                'win_rate'     => $stats['win_rate'],
                'total_pnl'    => $stats['total_pnl_usdt'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[FT BACKTEST] Failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $run->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }

        return $run->fresh();
    }

    // -------------------------------------------------------------------------
    // Simulation
    // -------------------------------------------------------------------------

    private function simulate(
        array $settings,
        array $symbols,
        array $klines5m,
        array $htfIndex,
        float $initialCapital
    ): array {
        $leverage    = max(1, (float) ($settings['leverage'] ?? 3));
        $tradeUsdt   = (float) ($settings['tradeAmountUsdt'] ?? 25);
        $minConf     = (float) ($settings['minConfidence'] ?? 0.58);
        $tpPct       = (float) ($settings['takeProfitPercent'] ?? 1.2) / 100;
        $slPct       = (float) ($settings['stopLossPercent'] ?? 0.4) / 100;
        $rollbackPct = (float) ($settings['rollbackLossPercent'] ?? 0.25) / 100;
        $trailPct    = (float) ($settings['trailingTakeProfitPercent'] ?? 0.30) / 100;
        $maxDailyLoss = (float) ($settings['maxDailyLossPercent'] ?? 2) / 100;
        $maxTrades   = (int) ($settings['maxTradesPerDay'] ?? 25);
        $cooldownSec = (int) ($settings['cooldownSeconds'] ?? 15);

        // Build unified 5m timeline (all symbols, sorted by open time)
        $timeline = $this->buildTimeline($symbols, $klines5m);

        // Position state (in-memory, no DB)
        $state = [
            'inPosition'    => false,
            'symbol'        => null,
            'side'          => null,
            'entryPrice'    => 0.0,
            'quantity'      => 0.0,
            'stopLoss'      => 0.0,
            'takeProfit'    => 0.0,
            'trailPct'      => 0.0,
            'peakPrice'     => 0.0,
            'entryTime'     => null,
            'cooldownUntil' => null,
            'tradeCountToday' => 0,
            'dailyLoss'     => 0.0,
            'dailyProfit'   => 0.0,
            'tradingDay'    => null,
            'capital'       => $initialCapital,
        ];

        $trades = [];
        $openTrade = null;

        foreach ($timeline as $ts => $symbolCandles) {
            $now = Carbon::createFromTimestampMs($ts);

            // Daily reset
            $today = $now->toDateString();
            if ($state['tradingDay'] !== $today) {
                $state['tradingDay']     = $today;
                $state['tradeCountToday'] = 0;
                $state['dailyLoss']       = 0.0;
                $state['dailyProfit']     = 0.0;
            }

            // --- Manage open position first (exit checks) ---
            if ($state['inPosition'] && isset($symbolCandles[$state['symbol']])) {
                $candle   = $symbolCandles[$state['symbol']];
                $exitInfo = $this->checkExits($state, $candle, $tpPct, $slPct, $rollbackPct, $trailPct);

                if ($exitInfo !== null) {
                    $exitPrice = $exitInfo['price'];
                    $exitReason = $exitInfo['reason'];
                    $dir    = $state['side'] === 'LONG' ? 1 : -1;
                    $qty    = $state['quantity'];
                    $entry  = $state['entryPrice'];
                    $gross  = ($exitPrice - $entry) * $qty * $dir;
                    $fees   = ($entry + $exitPrice) * $qty * self::FEE_RATE;
                    $net    = $gross - $fees;
                    $notional = $entry * $qty;
                    $netPct = $notional > 0 ? ($net / $notional) * 100 : 0;
                    $durMin = $state['entryTime'] ? (int) round($state['entryTime']->diffInSeconds($now) / 60) : 0;

                    if ($net < 0) {
                        $state['dailyLoss'] += abs($net);
                    } else {
                        $state['dailyProfit'] += $net;
                    }

                    $state['capital']       += $net;
                    $state['cooldownUntil']  = $now->copy()->addSeconds($cooldownSec);
                    $state['inPosition']     = false;

                    $openTrade['exit_price']       = $exitPrice;
                    $openTrade['exit_time']        = $now->toDateTimeString();
                    $openTrade['exit_reason']      = $exitReason;
                    $openTrade['gross_pnl']        = round($gross, 4);
                    $openTrade['fees']             = round($fees, 4);
                    $openTrade['net_pnl']          = round($net, 4);
                    $openTrade['duration_minutes'] = $durMin;
                    $openTrade['meta']['exit_pnl_pct'] = round($netPct, 4);

                    $trades[] = $openTrade;
                    $openTrade = null;
                    continue;
                }

                // Still in position — update peak price for trailing
                $candle = $symbolCandles[$state['symbol']];
                if ($state['side'] === 'LONG') {
                    $state['peakPrice'] = max($state['peakPrice'], (float) $candle[2]); // high
                } else {
                    $state['peakPrice'] = min($state['peakPrice'], (float) $candle[3]); // low
                }
                continue;
            }

            // --- Entry evaluation (only when not in position) ---
            if ($state['inPosition']) {
                continue;
            }

            // Guard: daily loss limit
            $dailyLossPct = $initialCapital > 0 ? $state['dailyLoss'] / $initialCapital : 0;
            if ($dailyLossPct >= $maxDailyLoss) {
                continue;
            }

            // Guard: max trades per day
            if ($state['tradeCountToday'] >= $maxTrades) {
                continue;
            }

            // Guard: cooldown
            if ($state['cooldownUntil'] && $now->lt($state['cooldownUntil'])) {
                continue;
            }

            // Score all symbols at this tick
            $signals = [];
            foreach ($symbolCandles as $symbol => $candle) {
                $ticker = $this->buildTicker($symbol, $candle, $klines5m[$symbol] ?? [], $ts, $htfIndex[$symbol] ?? [], $settings);
                $scored = $this->botService->scoreTickerForBacktest($ticker, $settings);
                if (! empty($scored['symbol'])) {
                    $signals[] = $scored;
                }
            }

            if (empty($signals)) {
                continue;
            }

            usort($signals, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
            $best = $signals[0];

            if (! in_array($best['signal'] ?? '', ['BUY', 'SELL'], true)) {
                continue;
            }
            if (($best['confidence'] ?? 0) < $minConf) {
                continue;
            }

            $symbol    = $best['symbol'];
            $signal    = $best['signal'];
            $side      = $signal === 'BUY' ? 'LONG' : 'SHORT';
            $entryPrice = (float) ($best['price'] ?? 0);
            if ($entryPrice <= 0) {
                continue;
            }

            $entrySlPct = $slPct;
            $entryTpPct = $tpPct;
            $entryTrailPct = $trailPct;
            if (($settings['signalProfile'] ?? null) === FutureTradeBotService::PROFILE_PRIME_V617_CRYPTO) {
                $risk = (array) ($best['debug']['risk'] ?? []);
                $riskPercent = (float) ($risk['riskPercent'] ?? 0);
                if ($riskPercent > 0) {
                    $entrySlPct = $riskPercent / 100;
                    $entryTpPct = $entrySlPct * (float) ($settings['tp1RR'] ?? 1.0);
                    $trailAtr = (float) ($risk['atr14'] ?? 0) * (float) ($settings['trailAtrMultiplier'] ?? 1.0);
                    $entryTrailPct = $entryPrice > 0 ? max(0.0001, $trailAtr / $entryPrice) : $trailPct;
                }
            }

            $qty = ($tradeUsdt * $leverage) / $entryPrice;
            $sl  = $side === 'LONG' ? $entryPrice * (1 - $entrySlPct) : $entryPrice * (1 + $entrySlPct);
            $tp  = $side === 'LONG' ? $entryPrice * (1 + $entryTpPct) : $entryPrice * (1 - $entryTpPct);

            $state['inPosition']    = true;
            $state['symbol']        = $symbol;
            $state['side']          = $side;
            $state['entryPrice']    = $entryPrice;
            $state['quantity']      = $qty;
            $state['stopLoss']      = $sl;
            $state['takeProfit']    = $tp;
            $state['trailPct']      = $entryTrailPct;
            $state['peakPrice']     = $entryPrice;
            $state['entryTime']     = $now;
            $state['tradeCountToday']++;

            $openTrade = [
                'symbol'           => $symbol,
                'side'             => $side,
                'entry_price'      => $entryPrice,
                'exit_price'       => null,
                'quantity'         => round($qty, 8),
                'leverage'         => $leverage,
                'gross_pnl'        => null,
                'fees'             => 0,
                'net_pnl'          => null,
                'entry_signal'     => $signal,
                'entry_confidence' => round((float) ($best['confidence'] ?? 0), 4),
                'exit_reason'      => null,
                'entry_time'       => $now->toDateTimeString(),
                'exit_time'        => null,
                'duration_minutes' => null,
                'meta'             => [
                    'stop_loss'   => round($sl, 8),
                    'take_profit' => round($tp, 8),
                    'trail_percent' => round($entryTrailPct * 100, 4),
                    'risk_model' => ($settings['signalProfile'] ?? null) === FutureTradeBotService::PROFILE_PRIME_V617_CRYPTO ? 'atr_dynamic_prime_v6_1_7' : 'percent',
                ],
            ];
        }

        // Close any position still open at end of backtest period
        if ($state['inPosition'] && $openTrade !== null) {
            $openTrade['exit_reason'] = 'BACKTEST_END';
            $trades[] = $openTrade;
        }

        return $trades;
    }

    // -------------------------------------------------------------------------
    // Exit condition check (intra-candle: SL checked before TP)
    // -------------------------------------------------------------------------

    private function checkExits(
        array $state,
        array $candle,
        float $tpPct,
        float $slPct,
        float $rollbackPct,
        float $trailPct
    ): ?array {
        $entry  = $state['entryPrice'];
        $qty    = $state['quantity'];
        $side   = $state['side'];
        $peak   = $state['peakPrice'];
        $dir    = $side === 'LONG' ? 1 : -1;

        $high  = (float) $candle[2];
        $low   = (float) $candle[3];
        $close = (float) $candle[4];

        $sl = $state['stopLoss'];
        $tp = $state['takeProfit'];

        // Stop loss (checked first — conservative / pessimistic)
        if ($side === 'LONG' && $low <= $sl) {
            return ['price' => $sl, 'reason' => 'STOP_LOSS_HIT'];
        }
        if ($side === 'SHORT' && $high >= $sl) {
            return ['price' => $sl, 'reason' => 'STOP_LOSS_HIT'];
        }

        // Take profit
        if ($side === 'LONG' && $high >= $tp) {
            return ['price' => $tp, 'reason' => 'TAKE_PROFIT_HIT'];
        }
        if ($side === 'SHORT' && $low <= $tp) {
            return ['price' => $tp, 'reason' => 'TAKE_PROFIT_HIT'];
        }

        // Rollback loss (evaluated on close price)
        $grossClose  = ($close - $entry) * $qty * $dir;
        $feesClose   = ($entry + $close) * $qty * self::FEE_RATE;
        $netClose    = $grossClose - $feesClose;
        $notional    = $entry * $qty;
        $pctClose    = $notional > 0 ? ($netClose / $notional) * 100 : 0;

        if ($pctClose <= -($rollbackPct * 100)) {
            return ['price' => $close, 'reason' => 'LOSS_ROLLBACK_TRIGGERED'];
        }

        // Trailing profit (activate when pct >= 0.10%)
        $peakNow = $side === 'LONG' ? max($peak, $high) : min($peak, $low);
        $activeTrailPct = (float) ($state['trailPct'] ?? $trailPct);
        $trailTrigger = $side === 'LONG'
            ? $peakNow * (1 - $activeTrailPct)
            : $peakNow * (1 + $activeTrailPct);

        if ($pctClose >= 0.10 && $side === 'LONG' && $close <= $trailTrigger && $peakNow > $entry) {
            return ['price' => $close, 'reason' => 'TRAILING_PROFIT_HIT'];
        }
        if ($pctClose >= 0.10 && $side === 'SHORT' && $close >= $trailTrigger && $peakNow < $entry) {
            return ['price' => $close, 'reason' => 'TRAILING_PROFIT_HIT'];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Ticker builder for a given 5m candle and lookback window
    // -------------------------------------------------------------------------

    private function buildTicker(
        string $symbol,
        array $candle,
        array $allCandles,
        int $currentTs,
        array $htfData,
        array $settings
    ): array {
        $close  = (float) ($candle[4] ?? 0);
        $open   = (float) ($candle[1] ?? 0);
        $high   = (float) ($candle[2] ?? 0);
        $low    = (float) ($candle[3] ?? 0);
        $volume = (float) ($candle[5] ?? 0);

        // Get the lookback window ending at current candle
        $lookback = $this->sliceKlinesUpTo($allCandles, $currentTs, self::SEQUENCE_LOOKBACK);
        $structureCandles = $this->sliceKlinesUpTo($allCandles, $currentTs, self::STRUCTURE_LOOKBACK);

        $sequence  = $this->botService->buildSequenceSnapshotForBacktest($lookback);
        $structure = $this->botService->buildStructureSnapshotForBacktest($structureCandles);
        $htf       = $this->resolveHtf($htfData, $currentTs);

        // Approximate 24h stats from available candles (last 288 × 5m = 24h)
        $window24h = $this->sliceKlinesUpTo($allCandles, $currentTs, 288);
        $open24h   = count($window24h) ? (float) ($window24h[0][1] ?? $open) : $open;
        $high24h   = count($window24h) ? max(array_column($window24h, 2)) : $high;
        $low24h    = count($window24h) ? min(array_column($window24h, 3)) : $low;
        $vol24h    = count($window24h) ? array_sum(array_column($window24h, 7)) : $volume; // quoteVolume at index 7

        $changePct = $open24h > 0 ? (($close - $open24h) / $open24h) * 100 : 0;
        $vwap      = $open24h > 0 ? ($open24h + $close) / 2 : $close;

        return [
            'symbol'             => $symbol,
            'lastPrice'          => $close,
            'price'              => $close,
            'priceChangePercent' => round($changePct, 4),
            'highPrice'          => $high24h,
            'lowPrice'           => $low24h,
            'openPrice'          => $open24h,
            'weightedAvgPrice'   => $vwap,
            'quoteVolume'        => $vol24h,
            'sequence'           => $sequence,
            'structure'          => $structure,
            'htf'                => $htf,
        ];
    }

    // -------------------------------------------------------------------------
    // HTF index: pre-computed EMA values keyed by timestamp
    // -------------------------------------------------------------------------

    private function buildHtfIndex(array $symbols, array $klines1h, array $klines4h): array
    {
        $index = [];
        foreach ($symbols as $symbol) {
            $c1h = $klines1h[$symbol] ?? [];
            $c4h = $klines4h[$symbol] ?? [];

            $closes1h = array_map(fn ($r) => (float) ($r[4] ?? 0), $c1h);
            $times1h  = array_map(fn ($r) => (int) ($r[0] ?? 0), $c1h);
            $closes4h = array_map(fn ($r) => (float) ($r[4] ?? 0), $c4h);
            $times4h  = array_map(fn ($r) => (int) ($r[0] ?? 0), $c4h);

            $ema50_1h  = $this->calcEmaSequence($closes1h, 50);
            $ema200_1h = $this->calcEmaSequence($closes1h, 200);
            $ema50_4h  = $this->calcEmaSequence($closes4h, 50);
            $ema200_4h = $this->calcEmaSequence($closes4h, 200);

            // Build lookup arrays: [timestamp => emaValue]
            $lookup1h_50  = array_combine($times1h, $ema50_1h);
            $lookup1h_200 = array_combine($times1h, $ema200_1h);
            $lookup4h_50  = array_combine($times4h, $ema50_4h);
            $lookup4h_200 = array_combine($times4h, $ema200_4h);

            $index[$symbol] = [
                'times1h'      => $times1h,
                'closes1h'     => $closes1h,
                'ema50_1h'     => $lookup1h_50,
                'ema200_1h'    => $lookup1h_200,
                'times4h'      => $times4h,
                'closes4h'     => $closes4h,
                'ema50_4h'     => $lookup4h_50,
                'ema200_4h'    => $lookup4h_200,
            ];
        }
        return $index;
    }

    private function resolveHtf(array $htfData, int $currentTs): array
    {
        if (empty($htfData)) {
            return ['emaBullish' => null, 'h4EmaBullish' => null, 'ema200Bullish' => null, 'trend1h' => 0, 'trend4h' => 0, 'ema50_1h' => 0.0, 'ema50_4h' => 0.0, 'ema200_1h' => 0.0, 'ema200_4h' => 0.0, 'lastCandleTime_1h' => null, 'lastCandleTime_4h' => null];
        }

        $ema50_1h  = $this->latestBefore($htfData['ema50_1h'],  $htfData['times1h'], $currentTs) ?? 0.0;
        $ema200_1h = $this->latestBefore($htfData['ema200_1h'], $htfData['times1h'], $currentTs) ?? 0.0;
        $ema50_4h  = $this->latestBefore($htfData['ema50_4h'],  $htfData['times4h'], $currentTs) ?? 0.0;
        $ema200_4h = $this->latestBefore($htfData['ema200_4h'] ?? [], $htfData['times4h'], $currentTs) ?? 0.0;
        $last1h    = $this->latestCloseBefore($htfData['closes1h'], $htfData['times1h'], $currentTs) ?? 0.0;
        $last4h    = $this->latestCloseBefore($htfData['closes4h'], $htfData['times4h'], $currentTs) ?? 0.0;
        $trend1h = ($last1h > $ema200_1h && $ema50_1h > $ema200_1h) ? 1 : (($last1h < $ema200_1h && $ema50_1h < $ema200_1h) ? -1 : 0);
        $trend4h = ($last4h > $ema200_4h && $ema50_4h > $ema200_4h) ? 1 : (($last4h < $ema200_4h && $ema50_4h < $ema200_4h) ? -1 : 0);

        return [
            'emaBullish'    => $ema50_1h > 0 ? $last1h > $ema50_1h : null,
            'h4EmaBullish'  => $ema50_4h > 0 ? $last4h > $ema50_4h : null,
            'ema200Bullish' => $ema200_1h > 0 ? $last1h > $ema200_1h : null,
            'trend1h'        => $trend1h,
            'trend4h'        => $trend4h,
            'ema50_1h'      => round($ema50_1h, 4),
            'ema50_4h'      => round($ema50_4h, 4),
            'ema200_1h'     => round($ema200_1h, 4),
            'ema200_4h'     => round($ema200_4h, 4),
            'lastCandleTime_1h' => null,
            'lastCandleTime_4h' => null,
        ];
    }

    private function latestBefore(array $lookup, array $times, int $ts): ?float
    {
        $best = null;
        foreach ($times as $t) {
            if ($t <= $ts) {
                $best = $lookup[$t] ?? null;
            } else {
                break;
            }
        }
        return $best;
    }

    private function latestCloseBefore(array $closes, array $times, int $ts): ?float
    {
        $best = null;
        foreach ($times as $i => $t) {
            if ($t <= $ts) {
                $best = $closes[$i] ?? null;
            } else {
                break;
            }
        }
        return $best;
    }

    // -------------------------------------------------------------------------
    // Timeline builder
    // -------------------------------------------------------------------------

    private function buildTimeline(array $symbols, array $klines5m): array
    {
        $timeline = [];
        foreach ($symbols as $symbol) {
            foreach ($klines5m[$symbol] ?? [] as $candle) {
                $ts = (int) ($candle[0] ?? 0);
                $timeline[$ts][$symbol] = $candle;
            }
        }
        ksort($timeline);
        return $timeline;
    }

    private function sliceKlinesUpTo(array $allCandles, int $endTs, int $limit): array
    {
        $result = [];
        foreach ($allCandles as $candle) {
            $ts = (int) ($candle[0] ?? 0);
            if ($ts <= $endTs) {
                $result[] = $candle;
            } elseif ($ts > $endTs) {
                break;
            }
        }
        return array_slice($result, -$limit);
    }

    // -------------------------------------------------------------------------
    // Kline fetcher
    // -------------------------------------------------------------------------

    private function fetchAllKlines(array $symbols, string $interval, Carbon $from, Carbon $to): array
    {
        $result = [];
        foreach ($symbols as $symbol) {
            $result[$symbol] = $this->fetchKlines($symbol, $interval, $from, $to);
        }
        return $result;
    }

    private function fetchKlines(string $symbol, string $interval, Carbon $from, Carbon $to): array
    {
        $all      = [];
        $startMs  = $from->getTimestampMs();
        $endMs    = $to->copy()->endOfDay()->getTimestampMs();

        while ($startMs < $endMs) {
            try {
                $response = Http::timeout(15)->get(self::BINANCE_BASE . '/fapi/v1/klines', [
                    'symbol'    => $symbol,
                    'interval'  => $interval,
                    'startTime' => $startMs,
                    'endTime'   => $endMs,
                    'limit'     => self::KLINE_LIMIT,
                ]);

                if (! $response->successful()) {
                    break;
                }

                $batch = $response->json();
                if (! is_array($batch) || empty($batch)) {
                    break;
                }

                $all = array_merge($all, $batch);

                $lastTs = (int) ($batch[array_key_last($batch)][0] ?? 0);
                if ($lastTs <= $startMs || count($batch) < self::KLINE_LIMIT) {
                    break;
                }

                $startMs = $lastTs + 1;
                usleep(100_000); // 100 ms between pages to respect rate limits
            } catch (\Throwable $e) {
                Log::warning('[FT BACKTEST] kline fetch error', ['symbol' => $symbol, 'interval' => $interval, 'error' => $e->getMessage()]);
                break;
            }
        }

        return $all;
    }

    // -------------------------------------------------------------------------
    // EMA sequence (returns one EMA value per close)
    // -------------------------------------------------------------------------

    private function calcEmaSequence(array $closes, int $period): array
    {
        if (empty($closes)) {
            return [];
        }
        $k      = 2.0 / ($period + 1);
        $emas   = [];
        $ema    = null;
        $warmUp = 0;

        foreach ($closes as $close) {
            if ($ema === null) {
                $ema = $close;
                $warmUp = 1;
            } else {
                if ($warmUp < $period) {
                    $ema = ($ema * $warmUp + $close) / ($warmUp + 1);
                    $warmUp++;
                } else {
                    $ema = $close * $k + $ema * (1 - $k);
                }
            }
            $emas[] = $ema;
        }

        return $emas;
    }

    // -------------------------------------------------------------------------
    // Statistics
    // -------------------------------------------------------------------------

    private function computeStats(array $trades, float $initialCapital): array
    {
        $closedTrades = array_filter($trades, fn ($t) => $t['exit_reason'] !== null && $t['exit_reason'] !== 'BACKTEST_END');
        $wins         = array_filter($closedTrades, fn ($t) => ($t['net_pnl'] ?? 0) > 0);
        $losses       = array_filter($closedTrades, fn ($t) => ($t['net_pnl'] ?? 0) <= 0);

        $totalPnl  = array_sum(array_column(array_values($closedTrades), 'net_pnl'));
        $grossWins = array_sum(array_column(array_values($wins), 'net_pnl'));
        $grossLoss = abs(array_sum(array_column(array_values($losses), 'net_pnl')));

        $winRate = count($closedTrades) > 0 ? round(count($wins) / count($closedTrades), 4) : 0.0;
        $pf      = $grossLoss > 0 ? round($grossWins / $grossLoss, 4) : ($grossWins > 0 ? 999.0 : 0.0);

        // Max drawdown (equity curve)
        $maxDrawdownUsdt = 0.0;
        $equity = $initialCapital;
        $peak   = $initialCapital;
        foreach ($closedTrades as $t) {
            $equity += (float) ($t['net_pnl'] ?? 0);
            if ($equity > $peak) {
                $peak = $equity;
            }
            $dd = $peak - $equity;
            if ($dd > $maxDrawdownUsdt) {
                $maxDrawdownUsdt = $dd;
            }
        }
        $maxDrawdownPct = $initialCapital > 0 ? round($maxDrawdownUsdt / $initialCapital * 100, 4) : 0.0;

        // Average duration
        $durations = array_filter(array_column(array_values($closedTrades), 'duration_minutes'), fn ($d) => $d !== null);
        $avgDuration = count($durations) > 0 ? (int) round(array_sum($durations) / count($durations)) : 0;

        // Sharpe ratio (simplified: mean pnl / std of pnl)
        $pnlList = array_values(array_column(array_values($closedTrades), 'net_pnl'));
        $sharpe  = $this->calcSharpe($pnlList);

        // By symbol
        $bySymbol = [];
        foreach ($closedTrades as $t) {
            $sym = $t['symbol'];
            $bySymbol[$sym] ??= ['trades' => 0, 'wins' => 0, 'net_pnl' => 0.0];
            $bySymbol[$sym]['trades']++;
            if (($t['net_pnl'] ?? 0) > 0) {
                $bySymbol[$sym]['wins']++;
            }
            $bySymbol[$sym]['net_pnl'] = round($bySymbol[$sym]['net_pnl'] + (float) ($t['net_pnl'] ?? 0), 4);
        }
        foreach ($bySymbol as $sym => &$data) {
            $data['win_rate'] = $data['trades'] > 0 ? round($data['wins'] / $data['trades'], 4) : 0.0;
        }
        unset($data);

        // By exit reason
        $byReason = [];
        foreach ($closedTrades as $t) {
            $reason = $t['exit_reason'] ?? 'UNKNOWN';
            $byReason[$reason] ??= ['trades' => 0, 'net_pnl' => 0.0];
            $byReason[$reason]['trades']++;
            $byReason[$reason]['net_pnl'] = round($byReason[$reason]['net_pnl'] + (float) ($t['net_pnl'] ?? 0), 4);
        }

        return [
            'total_trades'            => count($closedTrades),
            'wins'                    => count($wins),
            'losses'                  => count($losses),
            'win_rate'                => $winRate,
            'total_pnl_usdt'          => round($totalPnl, 4),
            'total_pnl_percent'       => $initialCapital > 0 ? round($totalPnl / $initialCapital * 100, 4) : 0.0,
            'max_drawdown_usdt'       => round($maxDrawdownUsdt, 4),
            'max_drawdown_percent'    => $maxDrawdownPct,
            'profit_factor'           => $pf,
            'sharpe_ratio'            => $sharpe,
            'avg_trade_duration_minutes' => $avgDuration,
            'by_symbol'               => $bySymbol,
            'by_exit_reason'          => $byReason,
        ];
    }

    private function calcSharpe(array $pnlList): float
    {
        $n = count($pnlList);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($pnlList) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $pnlList)) / ($n - 1);
        $std = sqrt($variance);
        return $std > 0 ? round($mean / $std, 4) : 0.0;
    }
}

