<?php

namespace App\Domain\Backtest;

use App\Domain\Strategies\StrategyInterface;
use Carbon\CarbonInterface;

class BacktestEngine
{
    public function run(StrategyInterface $strategy, array $candles, array $params, array $options = []): array
    {
        $count = count($candles);
        if ($count < 25) {
            throw new \InvalidArgumentException('At least 25 candles are required for a real backtest.');
        }

        $initialCapital = max(100, (float) ($options['initial_capital'] ?? 10000));
        $positionSizePct = min(100, max(1, (float) ($options['position_size_pct'] ?? 20)));
        $feeBps = max(0, (float) ($options['fee_bps'] ?? 10));
        $slippageBps = max(0, (float) ($options['slippage_bps'] ?? 5));
        $stopLossPct = $this->optionalPercent($options['stop_loss_pct'] ?? null);
        $takeProfitPct = $this->optionalPercent($options['take_profit_pct'] ?? null);
        $timeStopCandles = $this->optionalInteger($options['time_stop_candles'] ?? null);

        $cash = $initialCapital;
        $position = null;
        $closedTrades = [];
        $equityCurve = [];
        $warmup = $this->inferWarmup($params, $count);

        for ($index = 0; $index < $count; $index++) {
            $candle = $candles[$index];

            if ($position) {
                $exit = $this->checkExitRules(
                    position: $position,
                    candle: $candle,
                    candleIndex: $index,
                    stopLossPct: $stopLossPct,
                    takeProfitPct: $takeProfitPct,
                    timeStopCandles: $timeStopCandles,
                    slippageBps: $slippageBps,
                    feeBps: $feeBps,
                );

                if ($exit) {
                    [$position, $trade, $cash] = $this->closePosition($position, $exit, $cash);
                    $closedTrades[] = $trade;
                }
            }

            if ($index < $warmup || $index >= $count - 1) {
                $equityCurve[] = $this->equityPoint($candle, $cash, $position);
                continue;
            }

            $signal = $strategy->evaluate(array_slice($candles, 0, $index + 1), $params);
            $nextCandle = $candles[$index + 1];

            if ($position && $this->isReversalSignal($position['side'], $signal->action)) {
                $exitPrice = $this->applySlippage((float) $nextCandle['open'], $position['side'] === 'long' ? 'SELL' : 'BUY', $slippageBps);
                [$position, $trade, $cash] = $this->closePosition($position, [
                    'price' => $exitPrice,
                    'time' => $this->normalizeTime($nextCandle['opens_at']),
                    'reason' => 'signal_reversal',
                    'candle_index' => $index + 1,
                    'fee' => $this->feeAmount($position['qty'] * $exitPrice, $feeBps),
                ], $cash);
                $closedTrades[] = $trade;
            }

            if (! $position && in_array($signal->action, ['BUY', 'SELL'], true)) {
                $notional = max(1, $cash * ($positionSizePct / 100));
                $entryPrice = $this->applySlippage((float) $nextCandle['open'], $signal->action, $slippageBps);
                if ($entryPrice > 0) {
                    $qty = $notional / $entryPrice;
                    $entryFee = $this->feeAmount($qty * $entryPrice, $feeBps);
                    $cash -= $entryFee;
                    $position = [
                        'side' => $signal->action === 'BUY' ? 'long' : 'short',
                        'entry_price' => $entryPrice,
                        'entry_time' => $this->normalizeTime($nextCandle['opens_at']),
                        'entry_index' => $index + 1,
                        'qty' => $qty,
                        'entry_fee' => $entryFee,
                        'signal_reason' => $signal->reason,
                        'confidence' => $signal->confidence,
                    ];
                }
            }

            $equityCurve[] = $this->equityPoint($candle, $cash, $position);
        }

        if ($position) {
            $last = $candles[$count - 1];
            [$position, $trade, $cash] = $this->closePosition($position, [
                'price' => (float) $last['close'],
                'time' => $this->normalizeTime($last['opens_at']),
                'reason' => 'end_of_data',
                'candle_index' => $count - 1,
                'fee' => $this->feeAmount($position['qty'] * (float) $last['close'], $feeBps),
            ], $cash);
            $closedTrades[] = $trade;
            $equityCurve[] = $this->equityPoint($last, $cash, null);
        }

        return [
            'summary' => $this->buildSummary($initialCapital, $cash, $closedTrades, $equityCurve),
            'trades' => $closedTrades,
            'equity_curve' => $equityCurve,
            'meta' => [
                'candles' => $count,
                'warmup_candles' => $warmup,
                'strategy' => $strategy->key(),
                'options' => [
                    'initial_capital' => $initialCapital,
                    'position_size_pct' => $positionSizePct,
                    'fee_bps' => $feeBps,
                    'slippage_bps' => $slippageBps,
                    'stop_loss_pct' => $stopLossPct,
                    'take_profit_pct' => $takeProfitPct,
                    'time_stop_candles' => $timeStopCandles,
                ],
            ],
        ];
    }

    private function buildSummary(float $initialCapital, float $endingEquity, array $closedTrades, array $equityCurve): array
    {
        $totalTrades = count($closedTrades);
        $wins = array_values(array_filter($closedTrades, fn (array $trade): bool => (float) $trade['pnl'] > 0));
        $losses = array_values(array_filter($closedTrades, fn (array $trade): bool => (float) $trade['pnl'] < 0));
        $grossProfit = array_sum(array_map(fn (array $trade): float => (float) $trade['pnl'], $wins));
        $grossLoss = abs(array_sum(array_map(fn (array $trade): float => (float) $trade['pnl'], $losses)));
        $tradeReturns = array_map(fn (array $trade): float => (float) ($trade['pnl_pct'] ?? 0) / 100, $closedTrades);

        return [
            'initialCapital' => round($initialCapital, 2),
            'endingEquity' => round($endingEquity, 2),
            'totalTrades' => $totalTrades,
            'winRate' => $totalTrades > 0 ? round((count($wins) / $totalTrades) * 100, 2) : 0.0,
            'totalReturn' => round((($endingEquity - $initialCapital) / $initialCapital) * 100, 2),
            'maxDrawdown' => round($this->maxDrawdownPct($equityCurve), 2),
            'sharpeRatio' => round($this->sharpeRatio($tradeReturns), 2),
            'profitFactor' => round($grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? 999.0 : 0.0), 2),
        ];
    }

    private function closePosition(array $position, array $exit, float $cash): array
    {
        $direction = $position['side'] === 'long' ? 1 : -1;
        $exitPrice = (float) $exit['price'];
        $qty = (float) $position['qty'];
        $grossPnl = ($exitPrice - (float) $position['entry_price']) * $qty * $direction;
        $netPnl = $grossPnl - (float) $position['entry_fee'] - (float) ($exit['fee'] ?? 0);
        $cash += $netPnl;

        $trade = [
            'side' => $position['side'],
            'entry_price' => round((float) $position['entry_price'], 8),
            'exit_price' => round($exitPrice, 8),
            'qty' => round($qty, 8),
            'entry_time' => $position['entry_time'],
            'exit_time' => $exit['time'],
            'entry_fee' => round((float) $position['entry_fee'], 8),
            'exit_fee' => round((float) ($exit['fee'] ?? 0), 8),
            'pnl' => round($netPnl, 8),
            'pnl_pct' => round((((($exitPrice - (float) $position['entry_price']) * $direction) / (float) $position['entry_price']) * 100), 4),
            'hold_candles' => max(1, ((int) ($exit['candle_index'] ?? 0)) - ((int) $position['entry_index'])),
            'exit_reason' => $exit['reason'],
            'signal_reason' => $position['signal_reason'],
            'confidence' => round((float) $position['confidence'], 4),
        ];

        return [null, $trade, $cash];
    }

    private function checkExitRules(
        array $position,
        array $candle,
        int $candleIndex,
        ?float $stopLossPct,
        ?float $takeProfitPct,
        ?int $timeStopCandles,
        float $slippageBps,
        float $feeBps,
    ): ?array {
        $side = $position['side'];
        $entryPrice = (float) $position['entry_price'];
        $high = (float) $candle['high'];
        $low = (float) $candle['low'];
        $close = (float) $candle['close'];

        if ($stopLossPct !== null) {
            $stopPrice = $side === 'long'
                ? $entryPrice * (1 - ($stopLossPct / 100))
                : $entryPrice * (1 + ($stopLossPct / 100));
            $stopHit = $side === 'long' ? $low <= $stopPrice : $high >= $stopPrice;
            if ($stopHit) {
                $fill = $this->applySlippage($stopPrice, $side === 'long' ? 'SELL' : 'BUY', $slippageBps);
                return [
                    'price' => $fill,
                    'time' => $this->normalizeTime($candle['opens_at']),
                    'reason' => 'stop_loss',
                    'candle_index' => $candleIndex,
                    'fee' => $this->feeAmount((float) $position['qty'] * $fill, $feeBps),
                ];
            }
        }

        if ($takeProfitPct !== null) {
            $takeProfitPrice = $side === 'long'
                ? $entryPrice * (1 + ($takeProfitPct / 100))
                : $entryPrice * (1 - ($takeProfitPct / 100));
            $tpHit = $side === 'long' ? $high >= $takeProfitPrice : $low <= $takeProfitPrice;
            if ($tpHit) {
                $fill = $this->applySlippage($takeProfitPrice, $side === 'long' ? 'SELL' : 'BUY', $slippageBps);
                return [
                    'price' => $fill,
                    'time' => $this->normalizeTime($candle['opens_at']),
                    'reason' => 'take_profit',
                    'candle_index' => $candleIndex,
                    'fee' => $this->feeAmount((float) $position['qty'] * $fill, $feeBps),
                ];
            }
        }

        if ($timeStopCandles !== null && ($candleIndex - (int) $position['entry_index']) >= $timeStopCandles) {
            $fill = $this->applySlippage($close, $side === 'long' ? 'SELL' : 'BUY', $slippageBps);
            return [
                'price' => $fill,
                'time' => $this->normalizeTime($candle['opens_at']),
                'reason' => 'time_stop',
                'candle_index' => $candleIndex,
                'fee' => $this->feeAmount((float) $position['qty'] * $fill, $feeBps),
            ];
        }

        return null;
    }

    private function equityPoint(array $candle, float $cash, ?array $position): array
    {
        $price = (float) $candle['close'];
        $equity = $cash;

        if ($position) {
            $direction = $position['side'] === 'long' ? 1 : -1;
            $equity += ($price - (float) $position['entry_price']) * (float) $position['qty'] * $direction;
        }

        return [
            'time' => $this->normalizeTime($candle['opens_at']),
            'equity' => round($equity, 8),
        ];
    }

    private function maxDrawdownPct(array $equityCurve): float
    {
        $peak = null;
        $maxDrawdown = 0.0;

        foreach ($equityCurve as $point) {
            $equity = (float) ($point['equity'] ?? 0);
            $peak = $peak === null ? $equity : max($peak, $equity);
            if ($peak > 0) {
                $drawdown = (($peak - $equity) / $peak) * 100;
                $maxDrawdown = max($maxDrawdown, $drawdown);
            }
        }

        return $maxDrawdown;
    }

    private function sharpeRatio(array $returns): float
    {
        $count = count($returns);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($returns) / $count;
        $variance = 0.0;
        foreach ($returns as $return) {
            $variance += ($return - $mean) ** 2;
        }

        $stdDev = sqrt($variance / ($count - 1));
        if ($stdDev <= 0) {
            return 0.0;
        }

        return sqrt($count) * ($mean / $stdDev);
    }

    private function inferWarmup(array $params, int $count): int
    {
        $periods = [10];
        foreach ($params as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $normalizedKey = strtolower((string) $key);
            if (str_contains($normalizedKey, 'period') || str_contains($normalizedKey, 'slow') || str_contains($normalizedKey, 'fast') || str_contains($normalizedKey, 'level')) {
                $periods[] = max(1, (int) $value);
            }
        }

        return min(max($periods) + 2, max(10, $count - 2));
    }

    private function normalizeTime(CarbonInterface|string $time): string
    {
        return $time instanceof CarbonInterface
            ? $time->toIso8601String()
            : (string) $time;
    }

    private function isReversalSignal(string $side, string $action): bool
    {
        return ($side === 'long' && $action === 'SELL') || ($side === 'short' && $action === 'BUY');
    }

    private function feeAmount(float $notional, float $feeBps): float
    {
        return $notional * ($feeBps / 10000);
    }

    private function applySlippage(float $price, string $action, float $slippageBps): float
    {
        $multiplier = in_array($action, ['BUY', 'COVER'], true)
            ? 1 + ($slippageBps / 10000)
            : 1 - ($slippageBps / 10000);

        return $price * $multiplier;
    }

    private function optionalPercent(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0.01, (float) $value);
    }

    private function optionalInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }
}
