<?php

namespace App\Domain\Market\Indicators;

class Indicators
{
    public static function sma(array $closes, int $period): ?float
    {
        if ($period <= 0 || count($closes) < $period) {
            return null;
        }

        return array_sum(array_slice($closes, -$period)) / $period;
    }

    public static function ema(array $closes, int $period): ?float
    {
        $series = self::emaSeries($closes, $period);

        if ($series === []) {
            return null;
        }

        return (float) end($series);
    }

    public static function rsi(array $closes, int $period): ?float
    {
        if ($period <= 0 || count($closes) <= $period) {
            return null;
        }

        $gains = [];
        $losses = [];
        for ($i = 1; $i < count($closes); $i++) {
            $delta = (float) $closes[$i] - (float) $closes[$i - 1];
            $gains[] = max($delta, 0);
            $losses[] = max(-1 * $delta, 0);
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    public static function bollinger(array $closes, int $period, float $stddev = 2.0): ?array
    {
        if ($period <= 0 || count($closes) < $period) {
            return null;
        }

        $slice = array_slice($closes, -$period);
        $mean = array_sum($slice) / $period;
        $variance = 0.0;
        foreach ($slice as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $sd = sqrt($variance / $period);

        return [
            'middle' => $mean,
            'upper' => $mean + ($stddev * $sd),
            'lower' => $mean - ($stddev * $sd),
        ];
    }

    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): ?array
    {
        if (count($closes) < max($slow, $fast) + $signal) {
            return null;
        }

        $fastSeries = self::emaSeries($closes, $fast);
        $slowSeries = self::emaSeries($closes, $slow);
        $macdSeries = [];

        foreach ($closes as $index => $close) {
            if (! array_key_exists($index, $fastSeries) || ! array_key_exists($index, $slowSeries)) {
                continue;
            }
            $macdSeries[] = $fastSeries[$index] - $slowSeries[$index];
        }

        $signalSeries = self::emaSeries(array_values($macdSeries), $signal);
        if ($signalSeries === []) {
            return null;
        }

        $line = (float) end($macdSeries);
        $signalLine = (float) end($signalSeries);

        return [
            'line' => $line,
            'signal' => $signalLine,
            'histogram' => $line - $signalLine,
        ];
    }

    public static function atr(array $candles, int $period = 14): ?float
    {
        if ($period <= 0 || count($candles) < ($period + 1)) {
            return null;
        }

        $trueRanges = [];
        for ($i = 1; $i < count($candles); $i++) {
            $high = (float) ($candles[$i]['high'] ?? 0);
            $low = (float) ($candles[$i]['low'] ?? 0);
            $previousClose = (float) ($candles[$i - 1]['close'] ?? 0);

            $trueRanges[] = max(
                $high - $low,
                abs($high - $previousClose),
                abs($low - $previousClose),
            );
        }

        if (count($trueRanges) < $period) {
            return null;
        }

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;
        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
        }

        return $atr;
    }

    public static function adx(array $candles, int $period = 14): ?float
    {
        if ($period <= 0 || count($candles) < ($period * 2)) {
            return null;
        }

        $trs = [];
        $plusDm = [];
        $minusDm = [];

        for ($i = 1; $i < count($candles); $i++) {
            $current = $candles[$i];
            $previous = $candles[$i - 1];

            $high = (float) ($current['high'] ?? 0);
            $low = (float) ($current['low'] ?? 0);
            $prevHigh = (float) ($previous['high'] ?? 0);
            $prevLow = (float) ($previous['low'] ?? 0);
            $prevClose = (float) ($previous['close'] ?? 0);

            $upMove = $high - $prevHigh;
            $downMove = $prevLow - $low;

            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        if (count($trs) < $period) {
            return null;
        }

        $atr = array_sum(array_slice($trs, 0, $period));
        $plusDmSmooth = array_sum(array_slice($plusDm, 0, $period));
        $minusDmSmooth = array_sum(array_slice($minusDm, 0, $period));
        $dxValues = [];

        for ($i = $period; $i < count($trs); $i++) {
            $atr = $atr - ($atr / $period) + $trs[$i];
            $plusDmSmooth = $plusDmSmooth - ($plusDmSmooth / $period) + $plusDm[$i];
            $minusDmSmooth = $minusDmSmooth - ($minusDmSmooth / $period) + $minusDm[$i];

            if ($atr <= 0) {
                continue;
            }

            $plusDi = ($plusDmSmooth / $atr) * 100;
            $minusDi = ($minusDmSmooth / $atr) * 100;
            $denominator = $plusDi + $minusDi;

            if ($denominator <= 0) {
                continue;
            }

            $dxValues[] = (abs($plusDi - $minusDi) / $denominator) * 100;
        }

        if (count($dxValues) < $period) {
            return null;
        }

        $adx = array_sum(array_slice($dxValues, 0, $period)) / $period;
        for ($i = $period; $i < count($dxValues); $i++) {
            $adx = (($adx * ($period - 1)) + $dxValues[$i]) / $period;
        }

        return $adx;
    }

    /**
     * Additive sibling of adx() -- same exact smoothing math (deliberately
     * NOT diverged, per this codebase's discipline), but also surfaces
     * +DI/-DI, which adx() computes internally but never returns. adx()'s
     * own signature/return type is untouched (8 existing consumers rely on
     * its scalar-float return) -- this is a new, independent method.
     *
     * @return array{adx: float, plus_di: float, minus_di: float}|null
     */
    public static function adxWithDi(array $candles, int $period = 14): ?array
    {
        if ($period <= 0 || count($candles) < ($period * 2)) {
            return null;
        }

        $trs = [];
        $plusDm = [];
        $minusDm = [];

        for ($i = 1; $i < count($candles); $i++) {
            $current = $candles[$i];
            $previous = $candles[$i - 1];

            $high = (float) ($current['high'] ?? 0);
            $low = (float) ($current['low'] ?? 0);
            $prevHigh = (float) ($previous['high'] ?? 0);
            $prevLow = (float) ($previous['low'] ?? 0);
            $prevClose = (float) ($previous['close'] ?? 0);

            $upMove = $high - $prevHigh;
            $downMove = $prevLow - $low;

            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        if (count($trs) < $period) {
            return null;
        }

        $atr = array_sum(array_slice($trs, 0, $period));
        $plusDmSmooth = array_sum(array_slice($plusDm, 0, $period));
        $minusDmSmooth = array_sum(array_slice($minusDm, 0, $period));
        $dxValues = [];
        $lastPlusDi = 0.0;
        $lastMinusDi = 0.0;

        for ($i = $period; $i < count($trs); $i++) {
            $atr = $atr - ($atr / $period) + $trs[$i];
            $plusDmSmooth = $plusDmSmooth - ($plusDmSmooth / $period) + $plusDm[$i];
            $minusDmSmooth = $minusDmSmooth - ($minusDmSmooth / $period) + $minusDm[$i];

            if ($atr <= 0) {
                continue;
            }

            $plusDi = ($plusDmSmooth / $atr) * 100;
            $minusDi = ($minusDmSmooth / $atr) * 100;
            $lastPlusDi = $plusDi;
            $lastMinusDi = $minusDi;
            $denominator = $plusDi + $minusDi;

            if ($denominator <= 0) {
                continue;
            }

            $dxValues[] = (abs($plusDi - $minusDi) / $denominator) * 100;
        }

        if (count($dxValues) < $period) {
            return null;
        }

        $adx = array_sum(array_slice($dxValues, 0, $period)) / $period;
        for ($i = $period; $i < count($dxValues); $i++) {
            $adx = (($adx * ($period - 1)) + $dxValues[$i]) / $period;
        }

        return [
            'adx' => $adx,
            'plus_di' => $lastPlusDi,
            'minus_di' => $lastMinusDi,
        ];
    }

    public static function cci(array $candles, int $period = 20): ?float
    {
        if ($period <= 0 || count($candles) < $period) {
            return null;
        }

        $slice = array_slice($candles, -$period);
        $typicalPrices = array_map(
            fn (array $candle) => (((float) $candle['high']) + ((float) $candle['low']) + ((float) $candle['close'])) / 3,
            $slice
        );
        $mean = array_sum($typicalPrices) / count($typicalPrices);
        $meanDeviation = array_sum(array_map(fn (float $price) => abs($price - $mean), $typicalPrices)) / count($typicalPrices);
        if ($meanDeviation == 0.0) {
            return 0.0;
        }

        $latestTypicalPrice = (float) end($typicalPrices);

        return ($latestTypicalPrice - $mean) / (0.015 * $meanDeviation);
    }

    public static function stochastic(array $candles, int $kPeriod = 14, int $dPeriod = 3): ?array
    {
        if ($kPeriod <= 0 || $dPeriod <= 0 || count($candles) < ($kPeriod + $dPeriod - 1)) {
            return null;
        }

        $kSeries = [];
        for ($i = $kPeriod - 1; $i < count($candles); $i++) {
            $window = array_slice($candles, $i - $kPeriod + 1, $kPeriod);
            $high = max(array_map(fn (array $candle) => (float) $candle['high'], $window));
            $low = min(array_map(fn (array $candle) => (float) $candle['low'], $window));
            $close = (float) $candles[$i]['close'];
            $kSeries[] = $high === $low ? 50.0 : (($close - $low) / ($high - $low)) * 100;
        }

        if (count($kSeries) < $dPeriod) {
            return null;
        }

        $dSeries = [];
        for ($i = $dPeriod - 1; $i < count($kSeries); $i++) {
            $window = array_slice($kSeries, $i - $dPeriod + 1, $dPeriod);
            $dSeries[] = array_sum($window) / $dPeriod;
        }

        return [
            'k' => (float) end($kSeries),
            'd' => (float) end($dSeries),
        ];
    }

    public static function heikinAshi(array $candles): array
    {
        if ($candles === []) {
            return [];
        }

        $result = [];
        $previousOpen = (((float) $candles[0]['open']) + ((float) $candles[0]['close'])) / 2;
        $previousClose = (((float) $candles[0]['open']) + ((float) $candles[0]['high']) + ((float) $candles[0]['low']) + ((float) $candles[0]['close'])) / 4;

        foreach ($candles as $index => $candle) {
            $haClose = (((float) $candle['open']) + ((float) $candle['high']) + ((float) $candle['low']) + ((float) $candle['close'])) / 4;
            $haOpen = $index === 0 ? $previousOpen : (($previousOpen + $previousClose) / 2);
            $haHigh = max((float) $candle['high'], $haOpen, $haClose);
            $haLow = min((float) $candle['low'], $haOpen, $haClose);

            $row = $candle;
            $row['open'] = round($haOpen, 8);
            $row['high'] = round($haHigh, 8);
            $row['low'] = round($haLow, 8);
            $row['close'] = round($haClose, 8);
            $result[] = $row;

            $previousOpen = $haOpen;
            $previousClose = $haClose;
        }

        return $result;
    }

    private static function emaSeries(array $closes, int $period): array
    {
        if ($period <= 0 || count($closes) < $period) {
            return [];
        }

        $multiplier = 2 / ($period + 1);
        $seed = array_sum(array_slice($closes, 0, $period)) / $period;
        $series = [$period - 1 => $seed];
        $ema = $seed;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = (((float) $closes[$i] - $ema) * $multiplier) + $ema;
            $series[$i] = $ema;
        }

        return $series;
    }
}
