<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Market\Indicators\Indicators;
use InvalidArgumentException;

/**
 * Causal feature vector for anchor time T.
 *
 * T is the CLOSE time of the anchor candle (open + interval). The builder
 * receives exactly LOOKBACK consecutive closed candles whose last element is
 * the anchor candle, so by construction it cannot read anything after T:
 * there is no "series + index" API that could be off by one into the future.
 *
 * The same function produces training rows and live prediction inputs, so
 * the model is always served the distribution it was trained on.
 */
final class ShadowFeatureBuilder
{
    public const SCHEMA_VERSION = 'qt-shadow-features-v1';

    public const LOOKBACK = 64;

    public const FEATURES = [
        'ret_1', 'ret_3', 'ret_6', 'ret_12', 'ret_24', 'ret_48',
        'vol_12', 'vol_48',
        'range_1', 'range_mean_12', 'body_1',
        'dist_high_48', 'dist_low_48',
        'volume_ratio_12', 'volume_ratio_48',
        'rsi_14', 'ema_gap_12', 'ema_gap_48',
        'hour_sin', 'hour_cos', 'dow_sin', 'dow_cos',
    ];

    /**
     * @param  list<array{open_time_ms:int, open:float, high:float, low:float, close:float, volume:float}>  $window  Ascending, contiguous, last = anchor candle.
     * @return array<string, float>
     */
    public function build(array $window, int $intervalMs): array
    {
        if (count($window) !== self::LOOKBACK) {
            throw new InvalidArgumentException('Feature window must contain exactly '.self::LOOKBACK.' candles.');
        }
        if (! self::isContiguous($window, $intervalMs)) {
            throw new InvalidArgumentException('Feature window is not contiguous.');
        }

        $closes = array_map(static fn (array $c): float => (float) $c['close'], $window);
        $last = self::LOOKBACK - 1;
        $anchor = $window[$last];
        $close = $closes[$last];

        $logReturns = [];
        for ($i = 1; $i <= $last; $i++) {
            $logReturns[] = log($closes[$i] / $closes[$i - 1]);
        }

        $highs48 = array_map(static fn (array $c): float => (float) $c['high'], array_slice($window, -48));
        $lows48 = array_map(static fn (array $c): float => (float) $c['low'], array_slice($window, -48));
        $ranges12 = array_map(static fn (array $c): float => ((float) $c['high'] - (float) $c['low']) / (float) $c['close'], array_slice($window, -12));
        $volumes = array_map(static fn (array $c): float => (float) $c['volume'], $window);
        $prior12 = array_slice($volumes, -13, 12);
        $prior48 = array_slice($volumes, -49, 48);

        $anchorTimeSeconds = intdiv((int) $anchor['open_time_ms'] + $intervalMs, 1000);
        $hour = ((int) gmdate('G', $anchorTimeSeconds)) + ((int) gmdate('i', $anchorTimeSeconds)) / 60;
        $dow = (int) gmdate('w', $anchorTimeSeconds);

        $ema12 = Indicators::ema($closes, 12);
        $ema48 = Indicators::ema($closes, 48);
        $rsi = Indicators::rsi($closes, 14);

        $features = [
            'ret_1' => log($close / $closes[$last - 1]),
            'ret_3' => log($close / $closes[$last - 3]),
            'ret_6' => log($close / $closes[$last - 6]),
            'ret_12' => log($close / $closes[$last - 12]),
            'ret_24' => log($close / $closes[$last - 24]),
            'ret_48' => log($close / $closes[$last - 48]),
            'vol_12' => self::stdev(array_slice($logReturns, -12)),
            'vol_48' => self::stdev(array_slice($logReturns, -48)),
            'range_1' => ((float) $anchor['high'] - (float) $anchor['low']) / $close,
            'range_mean_12' => array_sum($ranges12) / count($ranges12),
            'body_1' => ($close - (float) $anchor['open']) / (float) $anchor['open'],
            'dist_high_48' => log(max($highs48) / $close),
            'dist_low_48' => log($close / min($lows48)),
            'volume_ratio_12' => self::ratio($volumes[$last], $prior12),
            'volume_ratio_48' => self::ratio($volumes[$last], $prior48),
            'rsi_14' => $rsi === null ? 0.5 : $rsi / 100,
            'ema_gap_12' => $ema12 ? $close / $ema12 - 1 : 0.0,
            'ema_gap_48' => $ema48 ? $close / $ema48 - 1 : 0.0,
            'hour_sin' => sin(2 * M_PI * $hour / 24),
            'hour_cos' => cos(2 * M_PI * $hour / 24),
            'dow_sin' => sin(2 * M_PI * $dow / 7),
            'dow_cos' => cos(2 * M_PI * $dow / 7),
        ];

        foreach ($features as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("Feature {$name} is not finite.");
            }
            $features[$name] = round($value, 12);
        }

        return $features;
    }

    /** @param list<array{open_time_ms:int}> $window */
    public static function isContiguous(array $window, int $intervalMs): bool
    {
        for ($i = 1, $n = count($window); $i < $n; $i++) {
            if ((int) $window[$i]['open_time_ms'] - (int) $window[$i - 1]['open_time_ms'] !== $intervalMs) {
                return false;
            }
        }

        return true;
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

    /** @param list<float> $prior */
    private static function ratio(float $value, array $prior): float
    {
        $mean = $prior === [] ? 0.0 : array_sum($prior) / count($prior);

        return $mean > 0 ? $value / $mean : 0.0;
    }
}
