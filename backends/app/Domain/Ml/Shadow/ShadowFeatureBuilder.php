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
 *
 * Schemas are append-only: v2 = every v1 feature (identical values, same
 * order) + taker-flow features; v3 = v2 + long-context (12h-3d) features for
 * the 1h/4h horizons. An older champion therefore keeps receiving exactly its
 * training inputs while newer candidates are trained alongside.
 */
final class ShadowFeatureBuilder
{
    public const SCHEMA_V1 = 'qt-shadow-features-v1';

    public const SCHEMA_V2 = 'qt-shadow-features-v2';

    public const SCHEMA_V3 = 'qt-shadow-features-v3';

    /** Schema new models are trained on. */
    public const SCHEMA_VERSION = self::SCHEMA_V3;

    /** Candles the v1/v2 (short-context) features read. */
    public const LOOKBACK = 64;

    /** Candles v3 reads: 3 days of 5m bars + 1 for the 72h return. */
    public const LONG_LOOKBACK = 865;

    public const FEATURES_V1 = [
        'ret_1', 'ret_3', 'ret_6', 'ret_12', 'ret_24', 'ret_48',
        'vol_12', 'vol_48',
        'range_1', 'range_mean_12', 'body_1',
        'dist_high_48', 'dist_low_48',
        'volume_ratio_12', 'volume_ratio_48',
        'rsi_14', 'ema_gap_12', 'ema_gap_48',
        'hour_sin', 'hour_cos', 'dow_sin', 'dow_cos',
    ];

    // Taker BUY share of volume: who is crossing the spread. 0.5 = balanced.
    public const TAKER_FEATURES = ['taker_ratio_1', 'taker_ratio_12', 'taker_ratio_48', 'taker_ratio_shift'];

    // Candles (ending at the anchor) whose taker volume a v2 row needs.
    public const TAKER_WINDOW = 48;

    public const FEATURES_V2 = [...self::FEATURES_V1, ...self::TAKER_FEATURES];

    // Long context for 1h/4h horizons (5m bars: 144 = 12h, 288 = 24h, 864 = 3d).
    public const LONG_FEATURES = [
        'ret_12h', 'ret_24h', 'ret_72h',
        'vol_24h', 'vol_regime',
        'dist_high_24h', 'dist_low_24h', 'range_pos_72h',
        'vwap_gap_24h', 'taker_ratio_24h', 'volume_ratio_24h',
    ];

    public const FEATURES_V3 = [...self::FEATURES_V2, ...self::LONG_FEATURES];

    /** Features of the training schema. */
    public const FEATURES = self::FEATURES_V3;

    public const SCHEMAS = [
        self::SCHEMA_V1 => self::FEATURES_V1,
        self::SCHEMA_V2 => self::FEATURES_V2,
        self::SCHEMA_V3 => self::FEATURES_V3,
    ];

    /** Per schema: candles read (ending at the anchor) and how many need taker volume. */
    private const REQUIREMENTS = [
        self::SCHEMA_V1 => ['lookback' => self::LOOKBACK, 'taker' => 0],
        self::SCHEMA_V2 => ['lookback' => self::LOOKBACK, 'taker' => self::TAKER_WINDOW],
        self::SCHEMA_V3 => ['lookback' => self::LONG_LOOKBACK, 'taker' => 288],
    ];

    /** @return list<string> */
    public static function features(string $schema): array
    {
        return self::SCHEMAS[$schema] ?? throw new InvalidArgumentException("Unknown feature schema: {$schema}");
    }

    public static function lookback(string $schema): int
    {
        self::features($schema);

        return self::REQUIREMENTS[$schema]['lookback'];
    }

    public static function maxLookback(): int
    {
        return max(array_column(self::REQUIREMENTS, 'lookback'));
    }

    /** Whether $window (ending at the anchor) is enough for $schema. */
    public static function satisfies(array $window, string $schema): bool
    {
        return count($window) >= self::lookback($schema)
            && self::hasTakerData($window, self::REQUIREMENTS[$schema]['taker']);
    }

    /** Whether the last $candles (default: the v2 window) carry taker volume. */
    public static function hasTakerData(array $window, int $candles = self::TAKER_WINDOW): bool
    {
        if ($candles <= 0) {
            return true;
        }
        if (count($window) < $candles) {
            return false;
        }
        foreach (array_slice($window, -$candles) as $candle) {
            if (! isset($candle['taker_buy_volume']) || $candle['taker_buy_volume'] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{open_time_ms:int, open:float, high:float, low:float, close:float, volume:float, taker_buy_volume?:?float}>  $window  Ascending, contiguous, last = anchor candle. Only the schema's own lookback (the tail) is read.
     * @return array<string, float>
     */
    public function build(array $window, int $intervalMs, string $schema = self::SCHEMA_VERSION): array
    {
        $lookback = self::lookback($schema);
        if (count($window) < $lookback) {
            throw new InvalidArgumentException("Feature window must contain at least {$lookback} candles for {$schema}.");
        }
        $long = count($window) === $lookback ? $window : array_slice($window, -$lookback);
        if (! self::hasTakerData($long, self::REQUIREMENTS[$schema]['taker'])) {
            throw new InvalidArgumentException("Feature window lacks taker-buy volume required by {$schema}.");
        }
        if (! self::isContiguous($long, $intervalMs)) {
            throw new InvalidArgumentException('Feature window is not contiguous.');
        }
        // The short-context features always read exactly the last LOOKBACK
        // candles, so v1/v2 values are identical whichever schema is built.
        $window = $lookback === self::LOOKBACK ? $long : array_slice($long, -self::LOOKBACK);

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

        if ($schema === self::SCHEMA_V2 || $schema === self::SCHEMA_V3) {
            $ratio12 = self::takerRatio(array_slice($window, -12));
            $ratio48 = self::takerRatio(array_slice($window, -48));
            $features += [
                'taker_ratio_1' => self::takerRatio([$anchor]),
                'taker_ratio_12' => $ratio12,
                'taker_ratio_48' => $ratio48,
                'taker_ratio_shift' => $ratio12 - $ratio48,
            ];
        }
        if ($schema === self::SCHEMA_V3) {
            $features += $this->longContext($long, (float) $features['vol_12']);
        }

        foreach ($features as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("Feature {$name} is not finite.");
            }
            $features[$name] = round($value, 12);
        }

        return $features;
    }

    /**
     * 12h-3d context from LONG_LOOKBACK closed candles ending at the anchor.
     *
     * @param  list<array<string, mixed>>  $long
     * @return array<string, float>
     */
    private function longContext(array $long, float $vol12): array
    {
        $n = count($long);
        $last = $n - 1;
        $close = (float) $long[$last]['close'];
        $closeAgo = static fn (int $bars): float => (float) $long[$last - $bars]['close'];

        $day = array_slice($long, -288);
        $returns = [];
        for ($i = $n - 288; $i <= $last; $i++) {
            $returns[] = log((float) $long[$i]['close'] / (float) $long[$i - 1]['close']);
        }
        $vol24 = self::stdev($returns);

        $high24 = max(array_map(static fn (array $c): float => (float) $c['high'], $day));
        $low24 = min(array_map(static fn (array $c): float => (float) $c['low'], $day));
        $high72 = max(array_map(static fn (array $c): float => (float) $c['high'], $long));
        $low72 = min(array_map(static fn (array $c): float => (float) $c['low'], $long));

        $pv = 0.0;
        $volume = 0.0;
        foreach ($day as $candle) {
            $typical = ((float) $candle['high'] + (float) $candle['low'] + (float) $candle['close']) / 3;
            $pv += $typical * (float) $candle['volume'];
            $volume += (float) $candle['volume'];
        }
        $lastHourVolume = array_sum(array_map(static fn (array $c): float => (float) $c['volume'], array_slice($long, -12)));

        return [
            'ret_12h' => log($close / $closeAgo(144)),
            'ret_24h' => log($close / $closeAgo(288)),
            'ret_72h' => log($close / $closeAgo(864)),
            'vol_24h' => $vol24,
            // >1 = the last hour is more volatile than the day: a regime shift.
            'vol_regime' => $vol24 > 0 ? $vol12 / $vol24 : 1.0,
            'dist_high_24h' => log($high24 / $close),
            'dist_low_24h' => log($close / $low24),
            'range_pos_72h' => $high72 > $low72 ? ($close - $low72) / ($high72 - $low72) : 0.5,
            'vwap_gap_24h' => $volume > 0 ? $close / ($pv / $volume) - 1 : 0.0,
            'taker_ratio_24h' => self::takerRatio($day),
            // Last hour's volume vs the day's average hour.
            'volume_ratio_24h' => $volume > 0 ? $lastHourVolume / ($volume / 24) : 0.0,
        ];
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

    /** Taker BUY share of total volume; balanced (0.5) when nothing traded. */
    private static function takerRatio(array $candles): float
    {
        $volume = array_sum(array_map(static fn (array $c): float => (float) $c['volume'], $candles));
        $takerBuy = array_sum(array_map(static fn (array $c): float => (float) $c['taker_buy_volume'], $candles));

        return $volume > 0 ? $takerBuy / $volume : 0.5;
    }

    /** @param list<float> $prior */
    private static function ratio(float $value, array $prior): float
    {
        $mean = $prior === [] ? 0.0 : array_sum($prior) / count($prior);

        return $mean > 0 ? $value / $mean : 0.0;
    }
}
