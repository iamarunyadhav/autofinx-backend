<?php

namespace App\Domain\MarketOpportunityBrain\Validation;

/**
 * Phase 5 dataset builder (pure, no I/O, RESEARCH_ONLY). Turns raw Binance public kline rows
 * (ascending, closed bars: [openMs, o, h, l, c, v, closeMs, ...]) into point-in-time windows and
 * then into {@see ExperimentRunner} samples for arm A (non-ML momentum baseline) and arm C
 * (Chronos-2 forecast, fee-aware threshold).
 *
 * Causality: a window decided at bar i uses closes[0..i] only; as_of = open time of bar i+1 (the
 * instant bar i is closed and known); the label is close(bar i+h) vs close(bar i), available at the
 * open of bar i+h+1. Entry is assumed AT the as_of bar close (optimistic; no latency modelled).
 *
 * Sealed holdout: any window whose label would become available after SEAL_TS (2026-01-04 UTC, the
 * owner's global ml-engine-v1 seal) is EXCLUDED here and counted, never emitted as a sample.
 * execution_authorized is never produced or implied.
 */
final class ExperimentDatasetBuilder
{
    /** 2026-01-04T00:00:00Z: first instant of the sealed holdout. */
    public const SEAL_TS = 1767484800;

    /**
     * @param  array<int,array<int,mixed>>  $bars
     * @return array{windows:list<array<string,mixed>>, excluded_sealed:int, excluded_gap:int}
     */
    public static function windows(string $symbol, array $bars, int $intervalMs, int $ctx, int $h, int $step): array
    {
        $n = count($bars);
        $windows = [];
        $sealed = 0;
        $gap = 0;
        for ($i = $ctx - 1; $i + $h < $n; $i += $step) {
            $asOf = intdiv((int) $bars[$i][6] + 1, 1000);
            $labelAt = intdiv((int) $bars[$i + $h][6] + 1, 1000);
            if ($labelAt > self::SEAL_TS) {
                $sealed++;

                continue;
            }
            // Contiguity over context + horizon: spanning a missing bar would corrupt the horizon.
            $span = $bars[$i + $h][0] - $bars[$i - $ctx + 1][0];
            if ($span !== ($ctx - 1 + $h) * $intervalMs) {
                $gap++;

                continue;
            }
            $closes = [];
            for ($k = $i - $ctx + 1; $k <= $i; $k++) {
                $closes[] = (float) $bars[$k][4];
            }
            $windows[] = [
                'key' => $symbol.'|'.$asOf,
                'symbol' => $symbol,
                'as_of' => $asOf,
                'label_available_at' => $labelAt,
                'closes' => $closes,
                'entry' => (float) $bars[$i][4],
                'exit' => (float) $bars[$i + $h][4],
            ];
        }

        return ['windows' => $windows, 'excluded_sealed' => $sealed, 'excluded_gap' => $gap];
    }

    /** Arm A: deterministic non-ML momentum rule (sign of last-h-bar return). */
    public static function armA(array $w, int $h): array
    {
        $c = $w['closes'];
        $ref = $c[count($c) - 1 - $h];
        $ret = $c[count($c) - 1] / $ref - 1.0;
        $side = $ret > 0 ? 'LONG' : ($ret < 0 ? 'SHORT' : 'WAIT');

        return self::sample('A', $w, $side);
    }

    /**
     * Arm C: Chronos-2 p50 forecast vs entry; trade only if the predicted move exceeds the
     * pre-registered fee-aware threshold, else WAIT. Returns null when no usable forecast exists
     * (never treated as WAIT: the sample is unavailable, not a decision).
     *
     * @param  array{p10:?float,p50:?float,p90:?float}|null  $forecast
     */
    public static function armC(array $w, ?array $forecast, float $threshold): ?array
    {
        if ($forecast === null || ! isset($forecast['p50']) || ! is_finite((float) $forecast['p50'])) {
            return null;
        }
        $rel = (float) $forecast['p50'] / $w['entry'] - 1.0;
        $side = $rel > $threshold ? 'LONG' : ($rel < -$threshold ? 'SHORT' : 'WAIT');

        return self::sample('C', $w, $side);
    }

    private static function sample(string $arm, array $w, string $side): array
    {
        $raw = $w['exit'] / $w['entry'] - 1.0;
        $gross = $side === 'LONG' ? $raw : ($side === 'SHORT' ? -$raw : 0.0);

        return [
            'arm' => $arm,
            'symbol' => $w['symbol'],
            'as_of' => $w['as_of'],
            'known_by' => $w['as_of'],
            'label_available_at' => $w['label_available_at'],
            'side' => $side,
            'gross_return' => $gross,
            'holdout' => false,
        ];
    }
}
