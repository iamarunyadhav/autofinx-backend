<?php

namespace App\Domain\Trading;

/**
 * Multi-candle pattern recognition engine.
 *
 * Identifies Japanese candlestick patterns (single, double, triple) and
 * provides a composite direction signal with pattern-adjusted confidence.
 * All methods are stateless — pass the full candle array ordered oldest→newest.
 */
class CandlePatternAnalyzer
{
    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Full analysis of the latest candle in the context of its history.
     *
     * Returns:
     *   side             – 'BUY' | 'SELL' | 'NEUTRAL'
     *   confidence       – 0.0–1.0 (raw body direction is the floor; patterns/momentum adjust)
     *   pattern          – canonical pattern name (e.g. "Bullish Engulfing")
     *   pattern_type     – 'single' | 'double' | 'triple' | 'trend' | 'none'
     *   pattern_bias     – 'bullish' | 'bearish' | 'neutral'
     *   trend_bias       – 'bullish' | 'bearish' | 'neutral'
     *   momentum_score   – 0–5 candles in last 5 that agree with signal direction
     *   continuation_pct – % of last 20 same-direction candles followed by continuation
     *   reasons          – human-readable string array
     *   score_breakdown  – score components for debugging
     *
     * @param  array<int, array<string, mixed>>  $candles  oldest → newest, ≥ 1
     */
    public function analyze(array $candles): array
    {
        if ($candles === []) {
            return $this->neutral('No candles available.', 'none');
        }

        $c = $this->candle($candles, 0);   // latest (index 0 = last)
        $p = $this->candle($candles, 1);   // prev
        $pp = $this->candle($candles, 2);  // two-back

        // 1. Raw candle body direction (always computed — foundation).
        [$rawSide, $rawConf, $rawReason] = $this->rawBodySignal($c);

        // 2. Single-candle pattern.
        $single = $this->singlePattern($c);

        // 3. Double-candle pattern (if we have a prev candle).
        $double = $p ? $this->doublePattern($c, $p) : null;

        // 4. Triple-candle pattern (if we have two prior candles).
        $triple = ($p && $pp) ? $this->triplePattern($c, $p, $pp) : null;

        // 5. Trend bias (last ≤ 20 candles — widened from 10 for better context).
        $trend = $this->trendBias($candles);

        // 6. Momentum: how many of the last 5 closed candles agree with raw direction.
        $momentum = $this->momentumScore($candles, $rawSide);

        // 7. Continuation rate: of last 20 same-direction candles, how many were
        //    followed by another candle in the same direction? (soft historical check)
        $continuation = $this->continuationRate($candles, $rawSide);

        // 8. Compose final score including momentum + continuation.
        return $this->compose($rawSide, $rawConf, $rawReason, $single, $double, $triple, $trend, $momentum, $continuation, $c);
    }

    /**
     * Thin wrapper: just return the dominant pattern name for UI display.
     */
    public function patternName(array $candles): string
    {
        $r = $this->analyze($candles);
        return $r['pattern'];
    }

    // ─── Raw body signal ──────────────────────────────────────────────────────

    private function rawBodySignal(array $c): array
    {
        $range = $c['range'];
        $bodyRatio = $c['body_ratio'];

        if ($bodyRatio < 0.05) {
            return ['NEUTRAL', 0.5 + $bodyRatio, 'Doji / indecision candle'];
        }

        $side = $c['bullish'] ? 'BUY' : 'SELL';
        $conf = 0.60 + $bodyRatio * 0.39;
        $label = $c['bullish'] ? 'Bullish' : 'Bearish';
        return [$side, $conf, sprintf('%s candle body %.0f%% of range', $label, $bodyRatio * 100)];
    }

    // ─── Single-candle patterns ───────────────────────────────────────────────

    private function singlePattern(array $c): array
    {
        $open = $c['open']; $close = $c['close'];
        $high = $c['high']; $low = $c['low'];
        $range = $c['range']; $body = $c['body'];
        $bodyRatio = $c['body_ratio'];
        $upperWick = $c['upper_wick']; $lowerWick = $c['lower_wick'];
        $upperWickRatio = $range > 0 ? $upperWick / $range : 0;
        $lowerWickRatio = $range > 0 ? $lowerWick / $range : 0;

        // Marubozu — almost no wicks, very strong momentum.
        if ($bodyRatio >= 0.90) {
            return $c['bullish']
                ? ['name' => 'Bullish Marubozu', 'bias' => 'bullish', 'conf_delta' => +0.15, 'type' => 'single']
                : ['name' => 'Bearish Marubozu', 'bias' => 'bearish', 'conf_delta' => +0.15, 'type' => 'single'];
        }

        // Strong candle — body dominant.
        if ($bodyRatio >= 0.65) {
            return $c['bullish']
                ? ['name' => 'Strong Bullish Candle', 'bias' => 'bullish', 'conf_delta' => +0.08, 'type' => 'single']
                : ['name' => 'Strong Bearish Candle', 'bias' => 'bearish', 'conf_delta' => +0.08, 'type' => 'single'];
        }

        // Dragonfly Doji — open ≈ close ≈ high, long lower wick → bullish reversal.
        if ($bodyRatio < 0.08 && $lowerWickRatio >= 0.60 && $upperWickRatio < 0.15) {
            return ['name' => 'Dragonfly Doji', 'bias' => 'bullish', 'conf_delta' => +0.14, 'type' => 'single'];
        }

        // Gravestone Doji — open ≈ close ≈ low, long upper wick → bearish reversal.
        if ($bodyRatio < 0.08 && $upperWickRatio >= 0.60 && $lowerWickRatio < 0.15) {
            return ['name' => 'Gravestone Doji', 'bias' => 'bearish', 'conf_delta' => +0.14, 'type' => 'single'];
        }

        // Hammer / Hanging Man — small body near top, lower wick ≥ 2× body.
        $bodyTop = max($open, $close); $bodyBottom = min($open, $close);
        $bodyInUpperThird = $bodyTop >= ($low + $range * 0.60);
        if ($bodyRatio >= 0.05 && $bodyRatio <= 0.38 && $lowerWick >= $body * 2.0 && $bodyInUpperThird) {
            // In isolation treat as bullish reversal signal (confirmation via trend needed for Hanging Man).
            return ['name' => 'Hammer', 'bias' => 'bullish', 'conf_delta' => +0.10, 'type' => 'single'];
        }

        // Shooting Star / Inverted Hammer — small body near bottom, upper wick ≥ 2× body.
        $bodyInLowerThird = $bodyBottom <= ($low + $range * 0.38);
        if ($bodyRatio >= 0.05 && $bodyRatio <= 0.38 && $upperWick >= $body * 2.0 && $bodyInLowerThird) {
            // In isolation treat as bearish (Shooting Star; Inverted Hammer needs confirmation).
            return ['name' => 'Shooting Star', 'bias' => 'bearish', 'conf_delta' => +0.10, 'type' => 'single'];
        }

        // Spinning top / small body.
        if ($bodyRatio >= 0.08 && $bodyRatio < 0.35) {
            return ['name' => 'Spinning Top', 'bias' => 'neutral', 'conf_delta' => -0.05, 'type' => 'single'];
        }

        return ['name' => 'Normal Candle', 'bias' => ($c['bullish'] ? 'bullish' : 'bearish'), 'conf_delta' => 0.0, 'type' => 'single'];
    }

    // ─── Double-candle patterns ───────────────────────────────────────────────

    private function doublePattern(array $c, array $p): array
    {
        $cOpen = $c['open']; $cClose = $c['close'];
        $pOpen = $p['open']; $pClose = $p['close'];
        $cBull = $c['bullish']; $pBull = $p['bullish'];
        $cBody = $c['body']; $pBody = $p['body'];
        $cBodyTop = max($cOpen, $cClose); $cBodyBot = min($cOpen, $cClose);
        $pBodyTop = max($pOpen, $pClose); $pBodyBot = min($pOpen, $pClose);

        // Bullish Engulfing — current bullish candle's body completely engulfs the prior bearish body.
        if ($cBull && !$pBull && $cOpen <= $pClose && $cClose >= $pOpen && $cBody > $pBody) {
            return ['name' => 'Bullish Engulfing', 'bias' => 'bullish', 'conf_delta' => +0.18, 'type' => 'double'];
        }

        // Bearish Engulfing — current bearish candle engulfs prior bullish body.
        if (!$cBull && $pBull && $cOpen >= $pClose && $cClose <= $pOpen && $cBody > $pBody) {
            return ['name' => 'Bearish Engulfing', 'bias' => 'bearish', 'conf_delta' => +0.18, 'type' => 'double'];
        }

        // Piercing Line — prev bearish, current bullish opens below prev low, closes above midpoint.
        $pMid = ($pOpen + $pClose) / 2;
        if ($cBull && !$pBull && $cOpen < $p['low'] && $cClose > $pMid && $cClose < $pOpen) {
            return ['name' => 'Piercing Line', 'bias' => 'bullish', 'conf_delta' => +0.13, 'type' => 'double'];
        }

        // Dark Cloud Cover — prev bullish, current bearish opens above prev high, closes below midpoint.
        $pMidBull = ($pOpen + $pClose) / 2;
        if (!$cBull && $pBull && $cOpen > $p['high'] && $cClose < $pMidBull && $cClose > $pOpen) {
            return ['name' => 'Dark Cloud Cover', 'bias' => 'bearish', 'conf_delta' => +0.13, 'type' => 'double'];
        }

        // Bullish Harami — small current candle inside larger bearish prev.
        if ($cBull && !$pBull && $cBodyTop <= $pBodyTop && $cBodyBot >= $pBodyBot && $cBody < $pBody * 0.5) {
            return ['name' => 'Bullish Harami', 'bias' => 'bullish', 'conf_delta' => +0.08, 'type' => 'double'];
        }

        // Bearish Harami — small current candle inside larger bullish prev.
        if (!$cBull && $pBull && $cBodyTop <= $pBodyTop && $cBodyBot >= $pBodyBot && $cBody < $pBody * 0.5) {
            return ['name' => 'Bearish Harami', 'bias' => 'bearish', 'conf_delta' => +0.08, 'type' => 'double'];
        }

        // Tweezer Bottom — same (near) lows, direction reverses upward.
        if ($cBull && !$pBull && abs($c['low'] - $p['low']) / max($c['range'], 1e-9) < 0.05) {
            return ['name' => 'Tweezer Bottom', 'bias' => 'bullish', 'conf_delta' => +0.07, 'type' => 'double'];
        }

        // Tweezer Top — same (near) highs, direction reverses downward.
        if (!$cBull && $pBull && abs($c['high'] - $p['high']) / max($c['range'], 1e-9) < 0.05) {
            return ['name' => 'Tweezer Top', 'bias' => 'bearish', 'conf_delta' => +0.07, 'type' => 'double'];
        }

        return ['name' => 'none', 'bias' => 'neutral', 'conf_delta' => 0.0, 'type' => 'none'];
    }

    // ─── Triple-candle patterns ───────────────────────────────────────────────

    private function triplePattern(array $c, array $p, array $pp): array
    {
        $cBull = $c['bullish']; $pBull = $p['bullish']; $ppBull = $pp['bullish'];
        $pBodySmall = $p['body_ratio'] < 0.30;

        // Morning Star — bearish, small/doji, bullish → strong bullish reversal.
        if (!$ppBull && $pBodySmall && $cBull && $c['close'] > ($pp['open'] + $pp['close']) / 2) {
            return ['name' => 'Morning Star', 'bias' => 'bullish', 'conf_delta' => +0.22, 'type' => 'triple'];
        }

        // Evening Star — bullish, small/doji, bearish → strong bearish reversal.
        if ($ppBull && $pBodySmall && !$cBull && $c['close'] < ($pp['open'] + $pp['close']) / 2) {
            return ['name' => 'Evening Star', 'bias' => 'bearish', 'conf_delta' => +0.22, 'type' => 'triple'];
        }

        // Three White Soldiers — three consecutive bullish candles, each closing higher.
        if ($cBull && $pBull && $ppBull
            && $c['close'] > $p['close']
            && $p['close'] > $pp['close']
            && $c['body_ratio'] > 0.55
            && $p['body_ratio'] > 0.55
        ) {
            return ['name' => 'Three White Soldiers', 'bias' => 'bullish', 'conf_delta' => +0.20, 'type' => 'triple'];
        }

        // Three Black Crows — three consecutive bearish candles, each closing lower.
        if (!$cBull && !$pBull && !$ppBull
            && $c['close'] < $p['close']
            && $p['close'] < $pp['close']
            && $c['body_ratio'] > 0.55
            && $p['body_ratio'] > 0.55
        ) {
            return ['name' => 'Three Black Crows', 'bias' => 'bearish', 'conf_delta' => +0.20, 'type' => 'triple'];
        }

        return ['name' => 'none', 'bias' => 'neutral', 'conf_delta' => 0.0, 'type' => 'none'];
    }

    // ─── Trend bias ───────────────────────────────────────────────────────────

    private function trendBias(array $candles): array
    {
        $n = min(20, count($candles));
        if ($n < 3) {
            return ['bias' => 'neutral', 'streak' => 0, 'streak_dir' => 'neutral', 'conf_delta' => 0.0, 'reason' => 'Insufficient history'];
        }

        $slice = array_values(array_slice($candles, -$n));
        $bullCount = 0; $bearCount = 0;

        $firstHigh = (float) ($slice[0]['high'] ?? 0);
        $lastHigh  = (float) ($slice[$n - 1]['high'] ?? 0);
        $firstLow  = (float) ($slice[0]['low'] ?? 0);
        $lastLow   = (float) ($slice[$n - 1]['low'] ?? 0);

        foreach ($slice as $raw) {
            $o = (float) ($raw['open'] ?? 0);
            $cl = (float) ($raw['close'] ?? 0);
            if ($cl >= $o) { $bullCount++; } else { $bearCount++; }
        }

        // Streak on the last 3 candles.
        $streak = 0; $streakDir = 'neutral';
        $last3 = array_slice($slice, -3);
        $all3Bull = array_reduce($last3, fn($carry, $v) => $carry && ((float)($v['close'] ?? 0) >= (float)($v['open'] ?? 0)), true);
        $all3Bear = array_reduce($last3, fn($carry, $v) => $carry && ((float)($v['close'] ?? 0) < (float)($v['open'] ?? 0)), true);
        if ($all3Bull) { $streak = 3; $streakDir = 'bullish'; }
        elseif ($all3Bear) { $streak = 3; $streakDir = 'bearish'; }

        // Higher-high / lower-low swing.
        $hhBull = $lastHigh > $firstHigh;
        $llBear = $lastLow  < $firstLow;

        $bias = 'neutral'; $confDelta = 0.0; $reason = '';

        if ($bullCount >= (int) round($n * 0.70) || ($hhBull && $streakDir === 'bullish')) {
            $bias = 'bullish'; $confDelta = 0.06;
            $reason = sprintf('Bullish trend (%d/%d candles up)', $bullCount, $n);
        } elseif ($bearCount >= (int) round($n * 0.70) || ($llBear && $streakDir === 'bearish')) {
            $bias = 'bearish'; $confDelta = 0.06;
            $reason = sprintf('Bearish trend (%d/%d candles down)', $bearCount, $n);
        } else {
            $reason = sprintf('Mixed trend (%d↑/%d↓)', $bullCount, $bearCount);
        }

        return ['bias' => $bias, 'streak' => $streak, 'streak_dir' => $streakDir, 'conf_delta' => $confDelta, 'reason' => $reason];
    }

    // ─── Score composition ────────────────────────────────────────────────────

    private function compose(
        string $rawSide, float $rawConf, string $rawReason,
        array $single, ?array $double, ?array $triple,
        array $trend, array $momentum, array $continuation, array $c
    ): array {
        // Pick strongest multi-candle pattern (triple > double > single).
        $dominant = ['name' => 'none', 'bias' => 'neutral', 'conf_delta' => 0.0, 'type' => 'none'];
        foreach (array_filter([$triple, $double]) as $p) {
            if ($p && $p['name'] !== 'none' && abs($p['conf_delta']) > abs($dominant['conf_delta'])) {
                $dominant = $p;
            }
        }
        // Fall back to single-candle if no multi-candle.
        if ($dominant['name'] === 'none' && $single['name'] !== 'Normal Candle' && $single['name'] !== 'none') {
            $dominant = $single;
        }

        $patternName = $dominant['name'] !== 'none' ? $dominant['name'] : $single['name'];
        $patternType = $dominant['type'] !== 'none' ? $dominant['type'] : 'single';
        $patternBias = $dominant['bias'] !== 'neutral' ? $dominant['bias'] : $single['bias'];

        // Resolve final side: strong pattern can OVERRIDE raw body direction.
        $side = $rawSide;
        $reasons = [$rawReason];
        $override = false;

        if ($dominant['conf_delta'] >= 0.13 && $dominant['bias'] !== 'neutral') {
            $patternSide = $dominant['bias'] === 'bullish' ? 'BUY' : 'SELL';
            if ($patternSide !== $rawSide) {
                // Strong pattern disagrees with body — pattern wins (it signals reversal).
                $side = $patternSide;
                $override = true;
                $reasons[] = sprintf('%s overrides body direction (reversal).', $dominant['name']);
            } else {
                $reasons[] = sprintf('%s confirms direction.', $dominant['name']);
            }
        } elseif ($dominant['name'] !== 'none' && $dominant['name'] !== 'Normal Candle') {
            $reasons[] = sprintf('Pattern: %s.', $dominant['name']);
        }

        // Start with raw confidence (or boosted if override).
        $conf = $override ? 0.62 : $rawConf;

        // Apply pattern delta (single-candle delta is the fallback if no dominant multi).
        $patternDelta = $dominant['conf_delta'];
        if ($dominant['type'] === 'none') {
            $patternDelta = $single['conf_delta'];
        }

        // If pattern CONFIRMS direction, add delta.
        // If it was an override, delta is already baked into 0.62 — add half to avoid double-bump.
        // If pattern DISAGREES with direction (but didn't override), penalise confidence.
        $patternBiasSide = $patternBias === 'bullish' ? 'BUY' : ($patternBias === 'bearish' ? 'SELL' : 'NEUTRAL');
        $patternAgreesWithSide = $patternBiasSide === $side || $patternBiasSide === 'NEUTRAL';

        if ($override) {
            $conf += $patternDelta * 0.5;
        } elseif ($patternAgreesWithSide) {
            $conf += $patternDelta;
        } else {
            // Pattern signal contradicts direction — subtract half its strength.
            $conf -= $patternDelta * 0.5;
        }

        // Trend alignment / divergence.
        $trendSide = $trend['bias'] === 'bullish' ? 'BUY' : ($trend['bias'] === 'bearish' ? 'SELL' : 'NEUTRAL');
        $trendDelta = 0.0;
        if ($trendSide === $side && $side !== 'NEUTRAL') {
            $trendDelta = $trend['conf_delta'];
            $conf += $trendDelta;
            $reasons[] = 'Trend: ' . $trend['reason'];
        } elseif ($trendSide !== 'NEUTRAL' && $trendSide !== $side) {
            $trendDelta = -$trend['conf_delta'] * 0.5;
            $conf += $trendDelta;
            $reasons[] = 'Counter-trend: ' . $trend['reason'];
        }

        // Momentum: how many of last 5 candles agree with signal direction.
        // 5/5 → +0.04, 4/5 → +0.02, 3/5 → 0, 2/5 → -0.02, 1/5 → -0.03
        $mScore = $momentum['score'];  // 0-5
        $momDelta = match(true) {
            $mScore >= 5 => 0.04,
            $mScore === 4 => 0.02,
            $mScore === 3 => 0.0,
            $mScore === 2 => -0.02,
            default => -0.03,
        };
        $conf += $momDelta;
        if ($mScore >= 4) {
            $reasons[] = sprintf('Strong momentum (%d/5 recent candles agree).', $mScore);
        } elseif ($mScore <= 1) {
            $reasons[] = sprintf('Weak momentum (%d/5 recent candles agree).', $mScore);
        }

        // Continuation rate: historical % of same-direction candles that continued.
        // Uses last 20 candles. ≥70% → +0.03, ≤40% → -0.03.
        $contPct = $continuation['pct'];
        $contDelta = 0.0;
        if ($contPct !== null) {
            if ($contPct >= 70) {
                $contDelta = 0.03;
                $conf += $contDelta;
                $reasons[] = sprintf('High continuation rate (%.0f%% of past setups followed through).', $contPct);
            } elseif ($contPct <= 40) {
                $contDelta = -0.03;
                $conf += $contDelta;
                $reasons[] = sprintf('Low continuation rate (%.0f%% — choppy history).', $contPct);
            }
        }

        // Clamp.
        $conf = round(max(0.0, min(0.99, $conf)), 4);

        // Final NEUTRAL guard: if body was doji and no strong pattern, keep NEUTRAL.
        if ($rawSide === 'NEUTRAL' && $dominant['conf_delta'] < 0.10) {
            $side = 'NEUTRAL';
        }

        return [
            'side' => $side,
            'confidence' => $conf,
            'pattern' => $patternName,
            'pattern_type' => $patternType,
            'pattern_bias' => $patternBias,
            'trend_bias' => $trend['bias'],
            'trend_streak' => $trend['streak'] ?? 0,
            'momentum_score' => $mScore,
            'continuation_pct' => $contPct,
            'reasons' => $reasons,
            'score_breakdown' => [
                'raw_conf' => round($rawConf, 4),
                'pattern_delta' => round($patternDelta, 4),
                'trend_delta' => round($trendDelta, 4),
                'momentum_delta' => round($momDelta, 4),
                'continuation_delta' => round($contDelta, 4),
                'override' => $override,
            ],
        ];
    }

    // ─── Momentum & continuation ──────────────────────────────────────────────

    /**
     * How many of the last 5 closed candles (excluding current) went in the same
     * direction as $side. Returns score 0-5.
     */
    private function momentumScore(array $candles, string $side): array
    {
        if ($side === 'NEUTRAL') {
            return ['score' => 0];
        }
        $lookback = min(5, count($candles) - 1);
        if ($lookback <= 0) {
            return ['score' => 0];
        }
        $score = 0;
        $slice = array_slice($candles, -1 - $lookback, $lookback); // candles before current
        foreach ($slice as $raw) {
            $o = (float) ($raw['open'] ?? 0);
            $cl = (float) ($raw['close'] ?? 0);
            $bull = $cl >= $o;
            if (($side === 'BUY' && $bull) || ($side === 'SELL' && !$bull)) {
                $score++;
            }
        }
        return ['score' => $score];
    }

    /**
     * Of the last 20 closed candles that had the same raw direction as $side,
     * what % were immediately followed by another candle in the same direction?
     * Returns ['pct' => float|null, 'sample' => int].
     */
    private function continuationRate(array $candles, string $side): array
    {
        if ($side === 'NEUTRAL' || count($candles) < 4) {
            return ['pct' => null, 'sample' => 0];
        }
        $lookback = min(20, count($candles) - 1);
        $slice = array_values(array_slice($candles, -1 - $lookback, $lookback)); // exclude current candle
        $n = count($slice);
        $matches = 0; $continued = 0;

        for ($i = 0; $i < $n - 1; $i++) {
            $o = (float) ($slice[$i]['open'] ?? 0);
            $cl = (float) ($slice[$i]['close'] ?? 0);
            $bull = $cl >= $o;
            $isSameDir = ($side === 'BUY' && $bull) || ($side === 'SELL' && !$bull);
            if (!$isSameDir) {
                continue;
            }
            $matches++;
            $no = (float) ($slice[$i + 1]['open'] ?? 0);
            $ncl = (float) ($slice[$i + 1]['close'] ?? 0);
            $nextBull = $ncl >= $no;
            if (($side === 'BUY' && $nextBull) || ($side === 'SELL' && !$nextBull)) {
                $continued++;
            }
        }

        if ($matches < 3) {
            return ['pct' => null, 'sample' => $matches];
        }

        return ['pct' => round($continued / $matches * 100, 1), 'sample' => $matches];
    }

    // ─── Candle normaliser ────────────────────────────────────────────────────

    /**
     * Resolve a candle from the END of an array. index 0 = last, 1 = second-last.
     * Returns null if the index is out of bounds.
     */
    private function candle(array $candles, int $indexFromEnd): ?array
    {
        $pos = count($candles) - 1 - $indexFromEnd;
        if ($pos < 0) {
            return null;
        }
        $raw = $candles[$pos];
        $open = (float) ($raw['open'] ?? 0);
        $close = (float) ($raw['close'] ?? 0);
        $high = (float) ($raw['high'] ?? 0);
        $low = (float) ($raw['low'] ?? 0);
        $range = max($high - $low, 1e-9);
        $body = abs($close - $open);
        $bodyTop = max($open, $close);
        $bodyBot = min($open, $close);

        return [
            'open' => $open, 'close' => $close, 'high' => $high, 'low' => $low,
            'range' => $range,
            'body' => $body,
            'body_ratio' => min(1.0, $body / $range),
            'bullish' => $close >= $open,
            'upper_wick' => $high - $bodyTop,
            'lower_wick' => $bodyBot - $low,
        ];
    }

    private function neutral(string $reason, string $type): array
    {
        return [
            'side' => 'NEUTRAL', 'confidence' => 0.0, 'pattern' => 'none',
            'pattern_type' => $type, 'pattern_bias' => 'neutral',
            'trend_bias' => 'neutral', 'trend_streak' => 0,
            'reasons' => [$reason],
            'score_breakdown' => [],
        ];
    }
}
