<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Market\Indicators\Indicators;

/**
 * Causal "pattern intelligence" feature groups G1-G6 for schema qt-shadow-features-v4
 * (docs/ml-engine-v1/SPEC.md §102, D-014). Research only: the server keeps training/serving v3.
 *
 * Input: the LONG_LOOKBACK closed 5m candles ending at the anchor (last element). Nothing after the
 * anchor exists here. Two extra causality rules (SPEC §102 L5/L6):
 *  - a swing high/low at bar i is only usable once its SWING_K confirmation bars have closed, so only
 *    bars i <= last - SWING_K can be swings;
 *  - higher-timeframe bars (15m / 1h / 4h) are UTC-aligned buckets that are COMPLETE at the anchor
 *    close; the bucket still forming is never read.
 * Recursive indicators (EMA, RSI, ATR, MACD) always run on fixed-length windows so a value never depends
 * on how much history happened to be loaded.
 */
final class PatternFeatures
{
    public const SWING_K = 3;

    private const FIXED = 200;

    private const HTF = ['15m' => 900_000, '1h' => 3_600_000, '4h' => 14_400_000];

    private const HTF_BARS = 16;

    public const NAMES = [
        // G1 market structure
        'g1_hh_share', 'g1_hl_share', 'g1_dist_swing_high', 'g1_dist_swing_low', 'g1_break_up_age', 'g1_break_down_age',
        'g1_failed_break_up', 'g1_failed_break_down', 'g1_swing_range_pos',
        // G2 candle shape / sequence
        'g2_upper_wick_1', 'g2_lower_wick_1', 'g2_body_1', 'g2_upper_wick_2', 'g2_lower_wick_2', 'g2_body_2',
        'g2_upper_wick_3', 'g2_lower_wick_3', 'g2_body_3', 'g2_close_pos_1', 'g2_streak', 'g2_inside_bar', 'g2_outside_bar',
        'g2_range_expansion',
        // G3 momentum derivatives
        'g3_rsi_slope', 'g3_rsi_accel', 'g3_macd_line', 'g3_macd_hist', 'g3_macd_hist_slope', 'g3_ema20_slope',
        'g3_ema50_slope', 'g3_ema_spread', 'g3_ema_spread_change',
        // G4 volatility / squeeze
        'g4_atr_pct', 'g4_atr_change', 'g4_bb_width', 'g4_bb_position', 'g4_bb_width_ratio', 'g4_vol_pctile',
        // G5 multi-timeframe (closed UTC buckets only)
        'g5_15m_ret3', 'g5_15m_ema_gap', 'g5_15m_structure', 'g5_15m_body', 'g5_15m_age',
        'g5_1h_ret3', 'g5_1h_ema_gap', 'g5_1h_structure', 'g5_1h_body', 'g5_1h_age',
        'g5_4h_ret3', 'g5_4h_ema_gap', 'g5_4h_structure', 'g5_4h_body', 'g5_4h_age', 'g5_alignment',
        // G6 volume / taker
        'g6_volume_z', 'g6_volume_trend', 'g6_taker_shift_12',
    ];

    /**
     * @param  list<array<string, mixed>>  $long  ascending, contiguous, last = anchor candle
     * @return array<string, float>
     */
    public static function compute(array $long, int $intervalMs): array
    {
        $n = count($long);
        $o = array_map(static fn (array $c): float => (float) $c['open'], $long);
        $h = array_map(static fn (array $c): float => (float) $c['high'], $long);
        $l = array_map(static fn (array $c): float => (float) $c['low'], $long);
        $c = array_map(static fn (array $x): float => (float) $x['close'], $long);
        $v = array_map(static fn (array $x): float => (float) $x['volume'], $long);
        $last = $n - 1;
        $close = $c[$last];
        $anchorClose = (int) $long[$last]['open_time_ms'] + $intervalMs;

        return self::structure($h, $l, $c, $last, $close)
            + self::candles($o, $h, $l, $c, $last)
            + self::momentum($c, $last, $close)
            + self::volatility($long, $c, $last, $close)
            + self::multiTimeframe($long, $anchorClose, $intervalMs, $c[$last], Indicators::ema(array_slice($c, -self::FIXED), 12))
            + self::volume($long, $v, $last);
    }

    /** G1: confirmed fractal swings (k = SWING_K bars each side). */
    private static function structure(array $h, array $l, array $c, int $last, float $close): array
    {
        $k = self::SWING_K;
        $highs = [];
        $lows = [];
        for ($i = $last - $k; $i >= $k && (count($highs) < 4 || count($lows) < 4); $i--) {
            $leftH = max(array_slice($h, $i - $k, $k));
            $rightH = max(array_slice($h, $i + 1, $k));
            if (count($highs) < 4 && $h[$i] > $leftH && $h[$i] >= $rightH) {
                $highs[] = $h[$i];
            }
            $leftL = min(array_slice($l, $i - $k, $k));
            $rightL = min(array_slice($l, $i + 1, $k));
            if (count($lows) < 4 && $l[$i] < $leftL && $l[$i] <= $rightL) {
                $lows[] = $l[$i];
            }
        }
        $highs = array_reverse($highs);   // oldest -> newest
        $lows = array_reverse($lows);
        $share = static function (array $points, bool $up): float {
            $pairs = count($points) - 1;
            if ($pairs < 1) {
                return 0.5;
            }
            $hits = 0;
            for ($i = 1; $i <= $pairs; $i++) {
                $hits += $up ? (int) ($points[$i] > $points[$i - 1]) : (int) ($points[$i] < $points[$i - 1]);
            }

            return $hits / $pairs;
        };
        $swingHigh = $highs === [] ? max($h) : end($highs);
        $swingLow = $lows === [] ? min($l) : end($lows);
        $age = static function (callable $beyond) use ($c, $last): float {
            $count = 0;
            for ($i = $last; $i >= 0 && $count < 48 && $beyond($c[$i]); $i--) {
                $count++;
            }

            return $count / 48;
        };
        $recentHigh = max(array_slice($h, -12));
        $recentLow = min(array_slice($l, -12));
        $span = $swingHigh - $swingLow;

        return [
            'g1_hh_share' => $share($highs, true),
            'g1_hl_share' => $share($lows, true),        // share of rising swing lows (higher lows)
            'g1_dist_swing_high' => log($swingHigh / $close),
            'g1_dist_swing_low' => log($close / $swingLow),
            'g1_break_up_age' => $age(static fn (float $x): bool => $x > $swingHigh),
            'g1_break_down_age' => $age(static fn (float $x): bool => $x < $swingLow),
            'g1_failed_break_up' => (float) ($recentHigh > $swingHigh && $close < $swingHigh),
            'g1_failed_break_down' => (float) ($recentLow < $swingLow && $close > $swingLow),
            'g1_swing_range_pos' => $span > 0 ? max(-1.0, min(2.0, ($close - $swingLow) / $span)) : 0.5,
        ];
    }

    /** G2: shape of the last three candles and the run of same-colour candles. */
    private static function candles(array $o, array $h, array $l, array $c, int $last): array
    {
        $out = [];
        for ($b = 1; $b <= 3; $b++) {
            $i = $last - $b + 1;
            $range = $h[$i] - $l[$i];
            $out["g2_upper_wick_{$b}"] = $range > 0 ? ($h[$i] - max($o[$i], $c[$i])) / $range : 0.0;
            $out["g2_lower_wick_{$b}"] = $range > 0 ? (min($o[$i], $c[$i]) - $l[$i]) / $range : 0.0;
            $out["g2_body_{$b}"] = $range > 0 ? ($c[$i] - $o[$i]) / $range : 0.0;
        }
        $range1 = $h[$last] - $l[$last];
        $streak = 0;
        $direction = $c[$last] <=> $o[$last];
        for ($i = $last; $i >= 0 && $direction !== 0 && abs($streak) < 10 && ($c[$i] <=> $o[$i]) === $direction; $i--) {
            $streak += $direction;
        }
        $prior = [];
        for ($i = $last - 12; $i < $last; $i++) {
            $prior[] = $h[$i] - $l[$i];
        }
        $meanPrior = array_sum($prior) / count($prior);

        return $out + [
            'g2_close_pos_1' => $range1 > 0 ? ($c[$last] - $l[$last]) / $range1 : 0.5,
            'g2_streak' => $streak / 10,
            'g2_inside_bar' => (float) ($h[$last] < $h[$last - 1] && $l[$last] > $l[$last - 1]),
            'g2_outside_bar' => (float) ($h[$last] > $h[$last - 1] && $l[$last] < $l[$last - 1]),
            'g2_range_expansion' => $meanPrior > 0 ? $range1 / $meanPrior : 1.0,
        ];
    }

    /** G3: slopes / accelerations of RSI, MACD and EMAs on fixed 200-bar windows. */
    private static function momentum(array $c, int $last, float $close): array
    {
        $win = static fn (int $back): array => array_slice($c, $last - $back - self::FIXED + 1, self::FIXED);
        $rsi = static fn (int $back): float => (Indicators::rsi($win($back), 14) ?? 50.0) / 100;
        [$r0, $r3, $r6] = [$rsi(0), $rsi(3), $rsi(6)];
        $macd0 = Indicators::macd($win(0)) ?? ['line' => 0.0, 'histogram' => 0.0];
        $macd3 = Indicators::macd($win(3)) ?? ['line' => 0.0, 'histogram' => 0.0];
        $ema = static fn (int $back, int $period): float => (float) (Indicators::ema($win($back), $period) ?? $close);
        [$e20, $e20b, $e50, $e50b] = [$ema(0, 20), $ema(3, 20), $ema(0, 50), $ema(3, 50)];
        $spread0 = $e50 > 0 ? $e20 / $e50 - 1 : 0.0;
        $spread3 = $e50b > 0 ? $e20b / $e50b - 1 : 0.0;

        return [
            'g3_rsi_slope' => $r0 - $r3,
            'g3_rsi_accel' => ($r0 - $r3) - ($r3 - $r6),
            'g3_macd_line' => $macd0['line'] / $close,
            'g3_macd_hist' => $macd0['histogram'] / $close,
            'g3_macd_hist_slope' => ($macd0['histogram'] - $macd3['histogram']) / $close,
            'g3_ema20_slope' => $e20b > 0 ? $e20 / $e20b - 1 : 0.0,
            'g3_ema50_slope' => $e50b > 0 ? $e50 / $e50b - 1 : 0.0,
            'g3_ema_spread' => $spread0,
            'g3_ema_spread_change' => $spread0 - $spread3,
        ];
    }

    /** G4: ATR, Bollinger squeeze and where current volatility sits in the last day. */
    private static function volatility(array $long, array $c, int $last, float $close): array
    {
        $candleWin = static fn (int $back): array => array_slice($long, $last - $back - self::FIXED + 1, self::FIXED);
        $atr0 = (float) (Indicators::atr($candleWin(0), 14) ?? 0.0);
        $atr12 = (float) (Indicators::atr($candleWin(12), 14) ?? 0.0);
        $bbAt = static function (int $back) use ($c, $last): array {
            $bb = Indicators::bollinger(array_slice($c, $last - $back - 19, 20), 20, 2.0);

            return $bb ?? ['middle' => 0.0, 'upper' => 0.0, 'lower' => 0.0];
        };
        $width = static fn (array $bb): float => $bb['middle'] > 0 ? ($bb['upper'] - $bb['lower']) / $bb['middle'] : 0.0;
        $bb0 = $bbAt(0);
        $widths = [];
        $vols = [];
        for ($back = 0; $back < 288; $back += 12) {
            $widths[] = $width($bbAt($back));
            $returns = [];
            for ($i = $last - $back - 11; $i <= $last - $back; $i++) {
                $returns[] = log($c[$i] / $c[$i - 1]);
            }
            $vols[] = self::stdev($returns);
        }
        $meanWidth = array_sum($widths) / count($widths);
        $below = count(array_filter($vols, static fn (float $x): bool => $x <= $vols[0]));
        $band = $bb0['upper'] - $bb0['lower'];

        return [
            'g4_atr_pct' => $atr0 / $close,
            'g4_atr_change' => $atr12 > 0 ? $atr0 / $atr12 - 1 : 0.0,
            'g4_bb_width' => $width($bb0),
            'g4_bb_position' => $band > 0 ? ($close - $bb0['lower']) / $band : 0.5,
            'g4_bb_width_ratio' => $meanWidth > 0 ? $width($bb0) / $meanWidth : 1.0,
            'g4_vol_pctile' => $below / count($vols),
        ];
    }

    /**
     * G5: 15m / 1h / 4h bars built from COMPLETE UTC buckets only (bucket end <= anchor close).
     *
     * @param  list<array<string, mixed>>  $long
     */
    private static function multiTimeframe(array $long, int $anchorClose, int $intervalMs, float $close, ?float $ema12): array
    {
        $out = [];
        $signs = [($ema12 !== null && $ema12 > 0) ? ($close <=> $ema12) : 0];
        foreach (self::HTF as $name => $tf) {
            $per = intdiv($tf, $intervalMs);
            $bars = [];
            $bucket = null;
            foreach ($long as $candle) {
                $start = (int) $candle['open_time_ms'] - ((int) $candle['open_time_ms'] % $tf);
                if ($start + $tf > $anchorClose) {
                    break;                                   // the bucket still forming at T0: never read
                }
                if ($bucket === null || $bucket['start'] !== $start) {
                    if ($bucket !== null && $bucket['count'] === $per) {
                        $bars[] = $bucket;
                    }
                    $bucket = ['start' => $start, 'open' => (float) $candle['open'], 'high' => (float) $candle['high'],
                        'low' => (float) $candle['low'], 'close' => (float) $candle['close'], 'count' => 1];
                } else {
                    $bucket['high'] = max($bucket['high'], (float) $candle['high']);
                    $bucket['low'] = min($bucket['low'], (float) $candle['low']);
                    $bucket['close'] = (float) $candle['close'];
                    $bucket['count']++;
                }
            }
            if ($bucket !== null && $bucket['count'] === $per) {
                $bars[] = $bucket;
            }
            $bars = array_slice($bars, -self::HTF_BARS);
            $m = count($bars);
            if ($m < 5) {
                $out += ["g5_{$name}_ret3" => 0.0, "g5_{$name}_ema_gap" => 0.0, "g5_{$name}_structure" => 0.0, "g5_{$name}_body" => 0.0, "g5_{$name}_age" => 1.0];
                $signs[] = 0;

                continue;
            }
            $closes = array_map(static fn (array $b): float => $b['close'], $bars);
            $ema8 = Indicators::ema($closes, 8);
            $lastBar = $bars[$m - 1];
            $prevBar = $bars[$m - 2];
            $gap = $ema8 ? $lastBar['close'] / $ema8 - 1 : 0.0;
            $structure = ($lastBar['high'] > $prevBar['high'] && $lastBar['low'] > $prevBar['low']) ? 1.0
                : (($lastBar['high'] < $prevBar['high'] && $lastBar['low'] < $prevBar['low']) ? -1.0 : 0.0);
            $out += [
                "g5_{$name}_ret3" => log($lastBar['close'] / $bars[$m - 4]['close']),
                "g5_{$name}_ema_gap" => $gap,
                "g5_{$name}_structure" => $structure,
                "g5_{$name}_body" => ($lastBar['close'] - $lastBar['open']) / $lastBar['open'],
                // 0 = the bucket closed exactly at T0; close to 1 = it closed almost a full bucket ago.
                "g5_{$name}_age" => ($anchorClose - ($lastBar['start'] + $tf)) / $tf,
            ];
            $signs[] = $gap <=> 0;
        }

        return $out + ['g5_alignment' => array_sum($signs) / count($signs)];
    }

    /** G6: volume surprise, volume trend and the change in taker-buy share. */
    private static function volume(array $long, array $v, int $last): array
    {
        $logs = array_map(static fn (float $x): float => log(max($x, 1e-12)), array_slice($v, $last - 48, 48));
        $mean = array_sum($logs) / count($logs);
        $sd = self::stdev($logs);
        $recent = array_sum(array_slice($v, -12)) / 12;
        $before = array_sum(array_slice($v, -48, 36)) / 36;
        $taker = static function (array $candles): float {
            $vol = array_sum(array_map(static fn (array $c): float => (float) $c['volume'], $candles));
            $buy = array_sum(array_map(static fn (array $c): float => (float) ($c['taker_buy_volume'] ?? 0.0), $candles));

            return $vol > 0 ? $buy / $vol : 0.5;
        };

        return [
            'g6_volume_z' => $sd > 0 ? (log(max($v[$last], 1e-12)) - $mean) / $sd : 0.0,
            'g6_volume_trend' => $before > 0 ? $recent / $before : 1.0,
            'g6_taker_shift_12' => $taker(array_slice($long, -12)) - $taker(array_slice($long, -24, 12)),
        ];
    }

    /** @param list<float> $values */
    private static function stdev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }
}
