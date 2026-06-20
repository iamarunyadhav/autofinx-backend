<?php

namespace App\Domain\Trading;

use App\Domain\Market\Indicators\Indicators;
use App\Models\RobotSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * KlineBrainService — v11.0 Soft-Filter Signal Engine
 *
 * Pure score-based signal generation. No hard gates except BB squeeze.
 * Designed for Binance native mode: feeds from kline WS stream stored in cache.
 *
 * Cache key per symbol/timeframe: autofinx:kline:{symbol}:{timeframe}:candles
 */
class KlineBrainService
{
    private const CACHE_KEY = 'autofinx:kline:%s:%s:candles';
    private const HTF_CACHE_KEY = 'autofinx:kline:%s:htf_scores';
    private const HTF_TTL_SECONDS = 300; // 5 minutes
    private const MAX_CANDLES = 250;

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Main entry point: compute a trading signal from cached kline candles.
     *
     * @param  RobotSession|null  $session  Optional session for symbol-adaptive params
     * @return array{direction: string, confidence: int, long_score: float, short_score: float, ...}
     */
    public function computeSignal(array $candles, ?RobotSession $session = null): array
    {
        $symbol = $session?->symbol ?? 'BTCUSDT';
        $params = $this->getSymbolParams($symbol);

        $closes  = array_column($candles, 'close');
        $highs   = array_column($candles, 'high');
        $lows    = array_column($candles, 'low');
        $volumes = array_column($candles, 'volume');
        $opens   = array_column($candles, 'open');
        $n       = count($closes);
        $last    = $n > 0 ? (float) end($closes) : 0.0;

        if ($n < 30) {
            return $this->neutralSignal('insufficient_data');
        }

        $longScore  = 0.0;
        $shortScore = 0.0;
        $reasons    = [];
        $blockReasons = [];
        $bbSqueeze = false;

        // ── 1. EMA STACK (26pts) ──────────────────────────────────────────────
        $ema20  = Indicators::ema($closes, 20);
        $ema50  = Indicators::ema($closes, 50);
        $ema200 = Indicators::ema($closes, 200);

        if ($ema20 !== null && $ema50 !== null) {
            if ($ema20 > $ema50) { $longScore += 10; $reasons[] = 'EMA20>50'; }
            else                  { $shortScore += 10; }
            if ($last > $ema50) { $longScore += 8; }
            else                 { $shortScore += 8; }
        }
        if ($ema200 !== null) {
            if ($last > $ema200) { $longScore += 8; }
            else                  { $shortScore += 8; }
        }

        // ── 2. RSI (17pts) ────────────────────────────────────────────────────
        $rsi = Indicators::rsi($closes, 14);
        if ($rsi !== null) {
            if ($rsi >= 32 && $rsi <= 82) { $longScore  += 8; }
            if ($rsi >= 18 && $rsi <= 68) { $shortScore += 8; }
            if ($rsi < 30) { $longScore  += 5; $reasons[] = 'RSI oversold'; }
            if ($rsi > 70) { $shortScore += 5; $reasons[] = 'RSI overbought'; }
            if ($rsi > 75) { $longScore  -= 4; }
            if ($rsi < 25) { $shortScore -= 4; }
            // RSI bull divergence
            if ($n >= 5) {
                $prevLow  = min(array_slice($closes, -5, 4));
                $rsiPrev  = Indicators::rsi(array_slice($closes, 0, -1), 14);
                if ($last < $prevLow && $rsi > ($rsiPrev ?? $rsi)) {
                    $longScore += 4;
                    $reasons[] = 'RSI bull divergence';
                }
            }
        }

        // ── 3. MACD (14pts) ───────────────────────────────────────────────────
        $macd = Indicators::macd($closes, 12, 26, 9);
        if ($macd) {
            if ($macd['line'] > $macd['signal'] && $macd['histogram'] > 0) { $longScore  += 6; $reasons[] = 'MACD bull'; }
            if ($macd['line'] < $macd['signal'] && $macd['histogram'] < 0) { $shortScore += 6; $reasons[] = 'MACD bear'; }
            // Crossover bonus
            $macdPrev = Indicators::macd(array_slice($closes, 0, -1), 12, 26, 9);
            if ($macdPrev) {
                if ($macdPrev['line'] < $macdPrev['signal'] && $macd['line'] > $macd['signal']) { $longScore += 8; $reasons[] = 'MACD cross up'; }
                if ($macdPrev['line'] > $macdPrev['signal'] && $macd['line'] < $macd['signal']) { $shortScore += 8; $reasons[] = 'MACD cross down'; }
            }
            if ($macd['line'] > $macd['signal']) { $longScore  += 2; }
            if ($macd['line'] < $macd['signal']) { $shortScore += 2; }
        }

        // ── 4. ADX (15pts) ────────────────────────────────────────────────────
        [$adxVal, $diPlus, $diMinus] = $this->computeAdx($highs, $lows, $closes, 14);
        $adxMin = (float) ($params['adxMin'] ?? 18);
        if ($adxVal !== null) {
            if ($adxVal >= $adxMin && $diPlus > $diMinus) {
                $longScore  += ($adxVal >= 25 ? 15 : 12);
                $reasons[]   = sprintf('ADX %.0f bull', $adxVal);
            }
            if ($adxVal >= $adxMin && $diMinus > $diPlus) {
                $shortScore += ($adxVal >= 25 ? 15 : 12);
                $reasons[]   = sprintf('ADX %.0f bear', $adxVal);
            }
            if ($adxVal >= $adxMin) { $longScore += 4; $shortScore += 4; }
            if ($adxVal < $adxMin)  { $longScore *= 0.85; $shortScore *= 0.85; } // soft penalty
        }

        // ── 5. SUPERTREND (8pts) ─────────────────────────────────────────────
        $st = $this->computeSupertrend($highs, $lows, $closes, 7, 3.0);
        if ($st === 'bull') { $longScore  += 8; }
        if ($st === 'bear') { $shortScore += 8; }

        // ── 6. BOLLINGER BANDS (12pts) ───────────────────────────────────────
        $bb = Indicators::bollinger($closes, 20, 2.0);
        $bbSqueezeThr = (float) ($params['bbSqueezeThreshold'] ?? 1.0);
        if ($bb) {
            $bbWidth = ($bb['upper'] - $bb['lower']) / max(1, $bb['middle']) * 100;
            $bbSqueeze = $bbWidth < $bbSqueezeThr;

            if (! $bbSqueeze) {
                if ($last <= $bb['lower']) { $longScore  += 5; $reasons[] = 'At BB lower'; }
                if ($last >= $bb['upper']) { $shortScore += 5; $reasons[] = 'At BB upper'; }
                // BB breakout
                if ($n >= 2 && (float) $closes[$n - 2] < $bb['upper'] && $last > $bb['upper']) { $shortScore += 6; $reasons[] = 'BB upper break'; }
                if ($n >= 2 && (float) $closes[$n - 2] > $bb['lower'] && $last < $bb['lower']) { $longScore  += 6; $reasons[] = 'BB lower break'; }
                if ($last > $bb['middle']) { $longScore  += 3; }
                if ($last < $bb['middle']) { $shortScore += 3; }
            }
        }

        // BB squeeze is the ONLY remaining hard block
        if ($bbSqueeze) {
            $blockReasons[] = 'BB squeeze — market consolidating';
        }

        // ── 7. STOCHASTIC (8pts) ─────────────────────────────────────────────
        $stoch = $this->computeStochastic($highs, $lows, $closes, 14, 3);
        if ($stoch) {
            if ($stoch['k'] < 25 && $stoch['k'] > $stoch['d']) { $longScore  += 6; $reasons[] = 'Stoch oversold cross'; }
            if ($stoch['k'] > 75 && $stoch['k'] < $stoch['d']) { $shortScore += 6; $reasons[] = 'Stoch overbought cross'; }
            if ($stoch['k'] < 30) { $longScore  += 3; }
            if ($stoch['k'] > 70) { $shortScore += 3; }
        }

        // ── 8. VOLUME (7pts) ─────────────────────────────────────────────────
        $volAvg = $n >= 20 ? array_sum(array_slice($volumes, -20)) / 20 : null;
        if ($volAvg && $volAvg > 0) {
            $relVol = (float) end($volumes) / $volAvg;
            if ($relVol >= 1.5)       { $longScore += 7; $shortScore += 7; }
            elseif ($relVol >= 0.75)  { $longScore += 5; $shortScore += 5; }
            elseif ($relVol >= 0.5)   { $longScore += 2; $shortScore += 2; }
            if ($relVol < 0.5)        { $longScore -= 3; $shortScore -= 3; }
        }

        // ── 9. VWAP (7pts) ───────────────────────────────────────────────────
        if ($n >= 20 && $volAvg && $volAvg > 0) {
            $slice   = array_slice($candles, -20);
            $vwapNum = array_sum(array_map(fn ($c) => (((float) $c['high'] + (float) $c['low'] + (float) $c['close']) / 3) * (float) $c['volume'], $slice));
            $vwapDen = array_sum(array_map(fn ($c) => (float) $c['volume'], $slice));
            $vwap    = $vwapDen > 0 ? $vwapNum / $vwapDen : $last;
            if ($last > $vwap) { $longScore  += 7; }
            if ($last < $vwap) { $shortScore += 7; }
        }

        // ── 10. CANDLE PATTERNS (up to 15pts) ────────────────────────────────
        if ($n >= 3) {
            $bodySize    = abs((float) $closes[$n - 1] - (float) $opens[$n - 1]);
            $candleRange = (float) $highs[$n - 1] - (float) $lows[$n - 1];
            $bodyRatio   = $candleRange > 0 ? $bodySize / $candleRange : 0;
            $upperWick   = $candleRange > 0 ? ((float) $highs[$n - 1] - max((float) $closes[$n - 1], (float) $opens[$n - 1])) / $candleRange : 0;
            $lowerWick   = $candleRange > 0 ? (min((float) $closes[$n - 1], (float) $opens[$n - 1]) - (float) $lows[$n - 1]) / $candleRange : 0;

            if ((float) $closes[$n - 1] > (float) $opens[$n - 1] && $bodyRatio >= 0.35) { $longScore  += 5; }
            if ((float) $closes[$n - 1] < (float) $opens[$n - 1] && $bodyRatio >= 0.35) { $shortScore += 5; }
            if ($lowerWick >= 0.55 && $bodyRatio <= 0.30 && $upperWick <= 0.15) { $longScore  += 7; $reasons[] = 'Hammer'; }
            if ($upperWick >= 0.55 && $bodyRatio <= 0.30 && $lowerWick <= 0.15) { $shortScore += 7; $reasons[] = 'Shooting star'; }
            // Engulfing
            if ((float) $closes[$n - 1] > (float) $opens[$n - 1] && (float) $closes[$n - 1] > (float) $highs[$n - 2] && (float) $opens[$n - 1] < min((float) $closes[$n - 2], (float) $opens[$n - 2])) { $longScore += 10; $reasons[] = 'Bull engulf'; }
            if ((float) $closes[$n - 1] < (float) $opens[$n - 1] && (float) $closes[$n - 1] < (float) $lows[$n - 2]  && (float) $opens[$n - 1] > max((float) $closes[$n - 2], (float) $opens[$n - 2])) { $shortScore += 10; $reasons[] = 'Bear engulf'; }
            // 3 consecutive
            if ((float) $closes[$n - 1] > (float) $opens[$n - 1] && (float) $closes[$n - 2] > (float) $opens[$n - 2] && (float) $closes[$n - 3] > (float) $opens[$n - 3]) { $longScore  += 5; $reasons[] = '3 bull candles'; }
            if ((float) $closes[$n - 1] < (float) $opens[$n - 1] && (float) $closes[$n - 2] < (float) $opens[$n - 2] && (float) $closes[$n - 3] < (float) $opens[$n - 3]) { $shortScore += 5; $reasons[] = '3 bear candles'; }
            // Doji soft penalty
            if ($bodyRatio < 0.10) { $longScore -= 5; $shortScore -= 5; }
        }

        // ── 11. HTF CACHE (25pts) ────────────────────────────────────────────
        $workspaceId = $session?->workspace_id ?? null;
        $htf = $this->getHtfScores($symbol, $workspaceId);
        $longScore  += $htf['long'];
        $shortScore += $htf['bear'];

        // ── 12. DONCHIAN RIBBON (14pts) ──────────────────────────────────────
        if ($this->computeDonchian($closes, $highs, $lows, 14, 'bull')) { $longScore  += 14; $reasons[] = 'Donchian bull ribbon'; }
        if ($this->computeDonchian($closes, $highs, $lows, 14, 'bear')) { $shortScore += 14; $reasons[] = 'Donchian bear ribbon'; }

        // ── 13. ATR EXPANSION BONUS (4pts) ───────────────────────────────────
        $trs = [];
        for ($i = max(1, $n - 15); $i < $n; $i++) {
            $trs[] = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }
        if (count($trs) >= 14) {
            $atrNow = (float) end($trs);
            $atrAvg = array_sum($trs) / count($trs);
            if ($atrNow > $atrAvg * 1.05) { $longScore += 4; $shortScore += 4; }
        }

        // ── TIME PENALTY (soft) ───────────────────────────────────────────────
        $timePenaltyReason = $this->applyTimeScorePenalty($longScore, $shortScore);

        // ── FINAL SCORING ─────────────────────────────────────────────────────
        $longScore  = max(0.0, $longScore);
        $shortScore = max(0.0, $shortScore);
        $maxScore   = 180.0;
        $spread     = abs($longScore - $shortScore);

        $direction  = 'neutral';
        $confidence = 50;

        $confBoost       = (int) ($params['confBoost'] ?? 0);
        $effectiveThresh = ($session !== null ? (int) $session->confidence_threshold : 55) + $confBoost;

        if (empty($blockReasons) && $spread >= 8) {
            if ($longScore > $shortScore) {
                $direction  = 'buy';
                $confidence = (int) min(95, 40 + round($longScore / $maxScore * 60));
            } else {
                $direction  = 'sell';
                $confidence = (int) min(95, 40 + round($shortScore / $maxScore * 60));
            }

            // Re-neutralise if below effective threshold
            if ($confidence < $effectiveThresh) {
                $direction  = 'neutral';
                $confidence = 50;
            }
        }

        return [
            'direction'      => $direction,
            'confidence'     => $confidence,
            'long_score'     => round($longScore, 1),
            'short_score'    => round($shortScore, 1),
            'spread'         => round($spread, 1),
            'rsi'            => $rsi !== null ? round($rsi, 1) : null,
            'adx'            => $adxVal !== null ? round($adxVal, 1) : null,
            'supertrend'     => $st,
            'bb_squeeze'     => $bbSqueeze,
            'stoch_k'        => $stoch !== null ? round($stoch['k'], 1) : null,
            'reasons'        => array_slice($reasons, 0, 8),
            'block_reasons'  => $blockReasons,
            'time_penalty'   => $timePenaltyReason ?: null,
            'engine'         => 'kline_brain_v11_soft',
        ];
    }

    /**
     * Get cached kline candles for a symbol/timeframe (written by BinanceKlineStreamCommand).
     *
     * @return array<array{open:float,high:float,low:float,close:float,volume:float,ts:int}>
     */
    public function getCachedCandles(string $symbol, string $timeframe): array
    {
        $key = sprintf(self::CACHE_KEY, strtoupper($symbol), strtolower($timeframe));
        $raw = Cache::get($key, []);
        return is_array($raw) ? array_slice($raw, -self::MAX_CANDLES) : [];
    }

    /**
     * Store a kline candle into the rolling cache.
     * Called by BinanceKlineStreamCommand on each WS message.
     */
    public function pushCandle(string $symbol, string $timeframe, array $candle, bool $isClosed = true): void
    {
        if (! $isClosed) {
            return; // Only store closed candles
        }

        $key      = sprintf(self::CACHE_KEY, strtoupper($symbol), strtolower($timeframe));
        $existing = Cache::get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        $existing[] = $candle;
        // Keep last MAX_CANDLES
        if (count($existing) > self::MAX_CANDLES) {
            $existing = array_slice($existing, -self::MAX_CANDLES);
        }
        Cache::put($key, $existing, 3600 * 24);
    }

    /**
     * Evaluate signal for a session using cached klines.
     */
    public function evaluateForSession(RobotSession $session): array
    {
        $candles = $this->getCachedCandles($session->symbol, $session->timeframe);
        if (count($candles) < 30) {
            return $this->neutralSignal('no_kline_data');
        }

        $signal = $this->computeSignal($candles, $session);

        // Apply time penalty (scores already modified in computeSignal, recompute direction)
        if ($signal['long_score'] > $signal['short_score'] && $signal['long_score'] > 0 && $signal['spread'] >= 8) {
            $signal['direction'] = 'buy';
        } elseif ($signal['short_score'] > $signal['long_score'] && $signal['short_score'] > 0 && $signal['spread'] >= 8) {
            $signal['direction'] = 'sell';
        }

        return $signal;
    }

    // ─── Symbol-Adaptive Parameters ──────────────────────────────────────────

    private function getSymbolParams(string $symbol): array
    {
        $sym = strtoupper($symbol);
        return match(true) {
            str_contains($sym, 'BTC')      => ['adxMin' => 20, 'slMult' => 1.8, 'confBoost' => 0,  'bbSqueezeThreshold' => 0.8],
            str_contains($sym, 'ETH')      => ['adxMin' => 20, 'slMult' => 2.0, 'confBoost' => 0,  'bbSqueezeThreshold' => 1.0],
            str_contains($sym, 'SOL')      => ['adxMin' => 18, 'slMult' => 2.5, 'confBoost' => 2,  'bbSqueezeThreshold' => 1.2],
            str_contains($sym, 'BNB')      => ['adxMin' => 18, 'slMult' => 2.0, 'confBoost' => 0,  'bbSqueezeThreshold' => 1.0],
            str_contains($sym, 'XRP')      => ['adxMin' => 22, 'slMult' => 2.2, 'confBoost' => 5,  'bbSqueezeThreshold' => 1.2],
            str_contains($sym, 'DOGE')     => ['adxMin' => 22, 'slMult' => 2.5, 'confBoost' => 7,  'bbSqueezeThreshold' => 1.5],
            str_contains($sym, '1000PEPE') => ['adxMin' => 25, 'slMult' => 3.0, 'confBoost' => 10, 'bbSqueezeThreshold' => 2.0],
            default                         => ['adxMin' => 20, 'slMult' => 2.0, 'confBoost' => 3,  'bbSqueezeThreshold' => 1.0],
        };
    }

    // ─── Indicator Helpers ────────────────────────────────────────────────────

    /**
     * @return array{float|null, float|null, float|null}  [adx, di+, di-]
     */
    private function computeAdx(array $highs, array $lows, array $closes, int $period): array
    {
        $n = count($closes);
        if ($n < $period * 2) {
            return [null, null, null];
        }

        $trs = $plusDm = $minusDm = [];
        for ($i = 1; $i < $n; $i++) {
            $upMove   = (float) $highs[$i] - (float) $highs[$i - 1];
            $downMove = (float) $lows[$i - 1] - (float) $lows[$i];
            $plusDm[]  = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $trs[]     = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }

        if (count($trs) < $period) {
            return [null, null, null];
        }

        $atr         = array_sum(array_slice($trs, 0, $period));
        $plusSmooth  = array_sum(array_slice($plusDm, 0, $period));
        $minusSmooth = array_sum(array_slice($minusDm, 0, $period));
        $dxValues    = [];

        for ($i = $period; $i < count($trs); $i++) {
            $atr         = $atr - ($atr / $period) + $trs[$i];
            $plusSmooth  = $plusSmooth - ($plusSmooth / $period) + $plusDm[$i];
            $minusSmooth = $minusSmooth - ($minusSmooth / $period) + $minusDm[$i];
            if ($atr <= 0) { continue; }
            $pdi = ($plusSmooth / $atr) * 100;
            $mdi = ($minusSmooth / $atr) * 100;
            $den = $pdi + $mdi;
            if ($den <= 0) { continue; }
            $dxValues[] = (abs($pdi - $mdi) / $den) * 100;
        }

        if (count($dxValues) < $period) {
            return [null, null, null];
        }

        $adxVal = array_sum(array_slice($dxValues, 0, $period)) / $period;
        for ($i = $period; $i < count($dxValues); $i++) {
            $adxVal = (($adxVal * ($period - 1)) + $dxValues[$i]) / $period;
        }

        // Final DI+ / DI- for direction
        $lastAtr  = $atr;
        $diPlus   = $lastAtr > 0 ? ($plusSmooth / $lastAtr) * 100 : 0;
        $diMinus  = $lastAtr > 0 ? ($minusSmooth / $lastAtr) * 100 : 0;

        return [round($adxVal, 2), round($diPlus, 2), round($diMinus, 2)];
    }

    /**
     * Simplified Supertrend (ATR multiplier method).
     */
    private function computeSupertrend(array $highs, array $lows, array $closes, int $period, float $mult): string
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return 'neutral';
        }

        // Simple ATR
        $trs = [];
        for ($i = max(1, $n - $period - 5); $i < $n; $i++) {
            $trs[] = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }
        if ($trs === []) {
            return 'neutral';
        }
        $atr  = array_sum($trs) / count($trs);
        $last = (float) end($closes);
        $hl2  = ((float) end($highs) + (float) end($lows)) / 2;
        $ub   = $hl2 + $mult * $atr;
        $lb   = $hl2 - $mult * $atr;

        if ($last > $lb) {
            return 'bull';
        }
        if ($last < $ub) {
            return 'bear';
        }

        return 'neutral';
    }

    private function computeStochastic(array $highs, array $lows, array $closes, int $kPeriod, int $dPeriod): ?array
    {
        $n = count($closes);
        if ($n < $kPeriod) {
            return null;
        }

        $kValues = [];
        for ($i = $kPeriod - 1; $i < $n; $i++) {
            $sliceH = array_slice($highs, $i - $kPeriod + 1, $kPeriod);
            $sliceL = array_slice($lows,  $i - $kPeriod + 1, $kPeriod);
            $hh      = max($sliceH);
            $ll      = min($sliceL);
            $kValues[] = $hh > $ll ? (((float) $closes[$i] - $ll) / ($hh - $ll)) * 100 : 50.0;
        }

        if (count($kValues) < $dPeriod) {
            return null;
        }
        $d = array_sum(array_slice($kValues, -$dPeriod)) / $dPeriod;

        return ['k' => (float) end($kValues), 'd' => $d];
    }

    private function computeDonchian(array $closes, array $highs, array $lows, int $period, string $direction): bool
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return false;
        }

        $prevHighs  = array_slice($highs, -$period - 1, $period);
        $prevLows   = array_slice($lows, -$period - 1, $period);
        $donchianH  = max($prevHighs);
        $donchianL  = min($prevLows);
        $last       = (float) end($closes);

        if ($direction === 'bull') {
            return $last > $donchianH;
        }
        if ($direction === 'bear') {
            return $last < $donchianL;
        }

        return false;
    }

    /**
     * Higher-timeframe scores cached for 5 minutes.
     * Provides 25pts of long/bear bias from daily/4h context.
     */
    private function getHtfScores(string $symbol, ?string $workspaceId): array
    {
        $key = sprintf(self::HTF_CACHE_KEY, strtoupper($symbol));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        // Default: neutral (no HTF data yet)
        // BinanceKlineStreamCommand will populate 1h/4h caches which we could
        // extend here later. For now return balanced scores.
        $scores = ['long' => 12, 'bear' => 12];
        Cache::put($key, $scores, self::HTF_TTL_SECONDS);

        return $scores;
    }

    /**
     * Soft time penalties — bad hours and days reduce scores, never hard-block.
     */
    private function applyTimeScorePenalty(float &$longScore, float &$shortScore): string
    {
        $now    = now('UTC');
        $dow    = (int) $now->format('N'); // 1=Mon, 7=Sun
        $hour   = (int) $now->format('G');
        $penalty = 0;
        $reason  = '';

        // Soft penalty on slow trading days (Mon/Tue lower liquidity)
        if ($dow === 1 || $dow === 2) {
            $penalty += 8;
            $reason   = 'Mon/Tue';
        }

        // Soft penalty on low-volume hours (UTC)
        if (in_array($hour, [7, 10, 14, 20, 23], true)) {
            $penalty += 5;
            $reason  .= ($reason ? '+' : '') . 'bad_hour';
        }

        if ($penalty > 0) {
            $longScore  = max(0, $longScore  - $penalty);
            $shortScore = max(0, $shortScore - $penalty);
        }

        return $reason;
    }

    private function neutralSignal(string $reason = ''): array
    {
        return [
            'direction'     => 'neutral',
            'confidence'    => 50,
            'long_score'    => 0.0,
            'short_score'   => 0.0,
            'spread'        => 0.0,
            'rsi'           => null,
            'adx'           => null,
            'supertrend'    => null,
            'bb_squeeze'    => false,
            'stoch_k'       => null,
            'reasons'       => $reason ? [$reason] : [],
            'block_reasons' => [],
            'time_penalty'  => null,
            'engine'        => 'kline_brain_v11_soft',
        ];
    }
}
