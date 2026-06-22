<?php

namespace App\Domain\Trading;

use App\Models\RobotSession;

/**
 * Candle Direction Bot — enters on a candle-open direction signal and rides the
 * move using a trailing stop that ratchets off peak favourable excursion.
 *
 * Conventions used throughout this service:
 *  - "side" is 'BUY' | 'SELL'.
 *  - peak excursion / peak_profit is measured in PRICE units away from entry in
 *    the favourable direction (i.e. how far price has moved in our favour at its
 *    best point). This keeps the trailing-stop maths free of position sizing.
 *  - A candle is an associative array with at least: open, high, low, close.
 */
class CandleDirectionBotService
{
    public function __construct(
        private readonly CandleSignalAdapter $signals,
    ) {
    }

    /**
     * Decide what to do at a candle boundary for a given session.
     *
     * @param  array<int, array<string, mixed>>  $candles  ordered oldest -> newest
     * @return array{action: 'BUY'|'SELL'|'HOLD'|'CLOSE', reason: string, confidence: float, pattern?: string, signal?: array<string, mixed>, stop_price?: float}
     */
    public function evaluateCandle(RobotSession $session, array $candles): array
    {
        if ($candles === []) {
            return ['action' => 'HOLD', 'reason' => 'No candle data available.', 'confidence' => 0.0];
        }

        $latest = $candles[array_key_last($candles)];
        $closePrice = (float) ($latest['close'] ?? 0);

        // No open position -> look for an entry.
        if (! $session->active_side) {
            if ($this->isNoisyCandle($latest)) {
                return [
                    'action' => 'HOLD',
                    'reason' => 'noisy_candle_filtered',
                    'confidence' => 0.0,
                    'pattern' => 'noisy_candle',
                    'signal' => [
                        'side' => 'NEUTRAL',
                        'confidence' => 0.0,
                        'pattern' => 'noisy_candle',
                        'noisy_candle' => true,
                        'reasons' => ['Small body with large two-sided wicks.'],
                    ],
                ];
            }

            $signal = $this->computeSignal($candles, (string) $session->timeframe, (array) $session->indicator_set);

            if (! $this->signals->isActionable($signal, (int) $session->confidence_threshold)) {
                return [
                    'action' => 'HOLD',
                    'reason' => sprintf(
                        'Signal %s (%.0f%%) below threshold %d%%. Pattern: %s',
                        $signal['side'],
                        $signal['confidence'] * 100,
                        (int) $session->confidence_threshold,
                        $signal['pattern'] ?? 'n/a'
                    ),
                    'confidence' => $signal['confidence'],
                    'pattern' => $signal['pattern'] ?? null,
                    'signal' => $signal,
                ];
            }

            return [
                'action' => $signal['side'], // BUY or SELL
                'reason' => sprintf('Entry [%s]: %s', $signal['pattern'] ?? 'body', implode(' ', array_slice($signal['reasons'], 0, 2))),
                'confidence' => $signal['confidence'],
                'pattern' => $signal['pattern'] ?? null,
                'signal' => $signal,
            ];
        }

        // Position open -> manage trailing stop / take profit.
        $side = (string) $session->active_side;
        $entry = (float) $session->active_entry_price;

        // Peak favourable excursion = best of stored peak and the current move.
        $currentExcursion = $this->favourableExcursion($side, $entry, $closePrice);
        $peakExcursion = max((float) $session->peak_profit, $currentExcursion);

        $stopPrice = $this->computeTrailingStop($entry, $peakExcursion, (float) $session->stop_loss_pct, $side);

        // Optional fixed take-profit.
        if ($session->take_profit_pct !== null) {
            $tpPrice = $this->takeProfitPrice($side, $entry, (float) $session->take_profit_pct);
            if ($this->priceReached($side, $closePrice, $tpPrice, true)) {
                return [
                    'action' => 'CLOSE',
                    'reason' => sprintf('Take profit hit at %s (target %s).', $closePrice, $tpPrice),
                    'confidence' => 1.0,
                    'stop_price' => $stopPrice,
                ];
            }
        }

        // Fast exit: the moment a candle closes against our position, get out.
        // This is the "quick in / quick out per candle" behaviour — candles are
        // the main factor, not a slow indicator give-back.
        $candleDir = $this->candleDirection($latest);
        if ($candleDir !== 'NEUTRAL' && $candleDir !== $side) {
            return [
                'action' => 'CLOSE',
                'reason' => sprintf('Candle closed %s against %s — exiting.', $candleDir, $side),
                'confidence' => 1.0,
                'stop_price' => $stopPrice,
            ];
        }

        if (! $this->shouldContinueTrade(
            ['side' => $side, 'entry_price' => $entry, 'peak_profit' => $peakExcursion],
            $latest,
            (float) $session->stop_loss_pct
        )) {
            return [
                'action' => 'CLOSE',
                'reason' => sprintf('Trailing stop breached at %s (stop %s, peak excursion %s).', $closePrice, $stopPrice, $peakExcursion),
                'confidence' => 1.0,
                'stop_price' => $stopPrice,
            ];
        }

        return [
            'action' => 'HOLD',
            'reason' => sprintf('In %s, riding move. Stop trailing at %s.', $side, $stopPrice),
            'confidence' => 0.0,
            'stop_price' => $stopPrice,
        ];
    }

    /**
     * Compute a fresh direction signal for the symbol/timeframe.
     *
     * @param  array<int, array<string, mixed>>  $candles
     * @param  array<int, string>  $indicators
     * @return array{side: string, confidence: float, reasons: array<int, string>, raw: array<string, mixed>}
     */
    public function computeSignal(array $candles, string $timeframe, array $indicators): array
    {
        // Candle direction is the primary signal — fast, fires on (almost) every
        // candle. Selected indicators only nudge confidence; they never block.
        return $this->signals->getCandleDirectionSignal($candles, $timeframe, $indicators);
    }

    /** Direction of a single candle (close vs open). */
    public function candleDirection(array $candle): string
    {
        $open = (float) ($candle['open'] ?? 0);
        $close = (float) ($candle['close'] ?? 0);
        $high = (float) ($candle['high'] ?? 0);
        $low = (float) ($candle['low'] ?? 0);
        $range = max($high - $low, 1e-9);

        // Treat near-zero-body candles (doji) as neutral.
        if (abs($close - $open) / $range < 0.05) {
            return 'NEUTRAL';
        }

        return $close > $open ? 'BUY' : 'SELL';
    }

    public function isNoisyCandle(array $candle): bool
    {
        $open = (float) ($candle['open'] ?? 0);
        $close = (float) ($candle['close'] ?? 0);
        $high = (float) ($candle['high'] ?? 0);
        $low = (float) ($candle['low'] ?? 0);
        $volume = (float) ($candle['volume'] ?? 1);
        $range = max($high - $low, 1e-9);
        $bodyRatio = abs($close - $open) / $range;
        $upperWickRatio = max(0, $high - max($open, $close)) / $range;
        $lowerWickRatio = max(0, min($open, $close) - $low) / $range;

        return $bodyRatio < 0.18
            && $upperWickRatio > 0.30
            && $lowerWickRatio > 0.30
            && $volume > 0;
    }

    /**
     * Returns false when the trade should be closed: either the trailing stop has
     * been breached, or price has reversed through the entry while in loss.
     *
     * @param  array{side: string, entry_price: float, peak_profit: float}  $activeTrade
     * @param  array<string, mixed>  $newCandle
     */
    public function shouldContinueTrade(array $activeTrade, array $newCandle, float $stopLossPct): bool
    {
        $side = strtoupper((string) ($activeTrade['side'] ?? ''));
        $entry = (float) ($activeTrade['entry_price'] ?? 0);
        $peak = (float) ($activeTrade['peak_profit'] ?? 0);
        $close = (float) ($newCandle['close'] ?? 0);

        if ($side === '' || $entry <= 0 || $close <= 0) {
            return true; // insufficient data -> do not force a close
        }

        $stopPrice = $this->computeTrailingStop($entry, $peak, $stopLossPct, $side);

        // For BUY the stop sits below price; breach when close <= stop.
        // For SELL the stop sits above price; breach when close >= stop.
        if ($side === 'BUY') {
            return $close > $stopPrice;
        }

        return $close < $stopPrice;
    }

    /**
     * The trailing stop PRICE for the given side. The stop is anchored to the
     * peak favourable excursion and gives back at most {@code stopLossPct} of it.
     *
     *  - BUY:  stop = entry + peakExcursion * (1 - pct/100)
     *  - SELL: stop = entry - peakExcursion * (1 - pct/100)
     *
     * With zero peak excursion the stop sits exactly at entry (break-even floor).
     */
    public function computeTrailingStop(float $entryPrice, float $peakProfit, float $stopLossPct, string $side): float
    {
        $retained = max(0.0, $peakProfit) * (1 - max(0.0, min(100.0, $stopLossPct)) / 100);

        return strtoupper($side) === 'SELL'
            ? $entryPrice - $retained
            : $entryPrice + $retained;
    }

    /**
     * How far price has moved in our favour relative to entry (>= 0 means profit).
     */
    public function favourableExcursion(string $side, float $entryPrice, float $price): float
    {
        return strtoupper($side) === 'SELL'
            ? $entryPrice - $price
            : $price - $entryPrice;
    }

    private function takeProfitPrice(string $side, float $entryPrice, float $takeProfitPct): float
    {
        $delta = $entryPrice * (max(0.0, $takeProfitPct) / 100);

        return strtoupper($side) === 'SELL'
            ? $entryPrice - $delta
            : $entryPrice + $delta;
    }

    /**
     * Has price reached the target in the favourable direction?
     */
    private function priceReached(string $side, float $price, float $target, bool $favourable): bool
    {
        if (strtoupper($side) === 'SELL') {
            return $favourable ? $price <= $target : $price >= $target;
        }

        return $favourable ? $price >= $target : $price <= $target;
    }
}
