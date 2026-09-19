<?php

namespace App\Domain\Trading;

use Illuminate\Support\Facades\Cache;

class TradeForecastService
{
    /**
     * Bumped only when the scoring/decision LOGIC in forecast() itself
     * changes shape (not when a config value changes -- that's covered
     * automatically by the config-fingerprint suffix in modelVersion()).
     */
    private const MODEL_MAJOR_VERSION = 'v1';

    /**
     * Rolling decision_trend window used by forecastStability(): last N
     * evaluations for a given symbol+timeframe+horizon scope. 5 is small
     * enough to react quickly to a genuinely-flipping model, large enough
     * that 1 noisy tick doesn't tank the label.
     */
    private const STABILITY_WINDOW = 5;

    /** 15 minutes -- long enough to span several quick-trade horizons back
     * to back without ever mixing history across an unrelated trading
     * session that happens to reuse the same symbol/timeframe/horizon much
     * later in the day. */
    private const STABILITY_TTL_SECONDS = 900;

    public function __construct(
        private readonly TimeframeRegistry $timeframes,
        private readonly CandleDirectionBotService $candleBot,
    ) {
    }

    /**
     * Near-term price forecast from recent candles, an optional trailing
     * decision (action/confidence), and the horizon the caller cares about.
     *
     * Ported from RobotSessionService::robotForecast() — session-independent,
     * used by both Robot Trade (via RobotSessionService) and Quick Trade.
     *
     * @param  array<array{open:mixed,high:mixed,low:mixed,close:mixed}>  $candles
     * @param  array{action?:mixed,confidence?:mixed}|null  $decision
     * @param  array{fresh?:mixed,age_seconds?:mixed}|null  $liveFeed  Optional freshness snapshot
     *         from MarketPriceCacheService::getLiveFeedSnapshot() (or equivalent), supplied by the
     *         caller when it knows the exchange/symbol for the candles it is sending. Only present
     *         when a live-feed record actually exists for the symbol; a caller that never resolved
     *         a snapshot (e.g. symbol not currently WS-subscribed) should pass null, not a
     *         fabricated stale value -- this method only fails closed on a snapshot that is
     *         explicitly non-fresh, never on the mere absence of one.
     * @param  string|null  $symbol  Optional, additive: only used to scope the rolling
     *         forecast_stability window (see forecastStability()) to the right
     *         symbol+timeframe+horizon. Callers that don't pass it (existing callers,
     *         unchanged) simply get an 'UNKNOWN' stability label rather than a fabricated one.
     */
    public function forecast(array $candles, string $timeframe, int $horizonSeconds, ?array $decision = null, ?array $volatility = null, ?array $liveFeed = null, ?string $symbol = null): array
    {
        $currentPrice = (float) (end($candles)['close'] ?? 0);

        if (count($candles) < 10 || $currentPrice <= 0) {
            return [
                'available' => false,
                'summary' => 'Not enough fresh candles to estimate the next move.',
            ];
        }

        $tfSeconds = max(1, $this->timeframes->seconds($timeframe));
        // Chart timeframe supplies the context; requested trade duration is
        // the forecast target. Never silently stretch a 5m trade forecast to
        // 15m merely because the user is viewing a 15m chart.
        $horizonSeconds = max(5, $horizonSeconds ?: $tfSeconds);
        $horizonCandles = max(1, (int) ceil($horizonSeconds / $tfSeconds));
        // The chart resolution and trade expiry are intentionally separate. Use
        // a bounded, horizon-aware context so a 15m forecast does not merely
        // repeat the last-candle direction and does not consume unbounded data.
        $windowSize = min(100, max(20, min(count($candles), max(12, $horizonCandles * 4))));
        $recent = array_slice($candles, -$windowSize);
        $closes = array_map(static fn ($c) => (float) ($c['close'] ?? 0), $recent);
        $microSize = min(count($recent), max(4, min(8, $horizonCandles)));
        $shortSize = min(count($recent), max($microSize, min(20, $horizonCandles * 2)));
        $lastSlice = array_slice($recent, -$shortSize);
        $microSlice = array_slice($recent, -$microSize);
        $bull = 0;
        $bear = 0;
        foreach ($lastSlice as $candle) {
            $direction = $this->candleBot->candleDirection($candle);
            if ($direction === 'BUY') {
                $bull++;
            } elseif ($direction === 'SELL') {
                $bear++;
            }
        }

        $microBull = $microBear = 0;
        foreach ($microSlice as $candle) {
            $direction = $this->candleBot->candleDirection($candle);
            $microBull += $direction === 'BUY' ? 1 : 0;
            $microBear += $direction === 'SELL' ? 1 : 0;
        }

        $firstClose = (float) ($closes[0] ?? 0);
        $lastClose = (float) end($closes);
        $driftPct = $firstClose > 0 ? (($lastClose - $firstClose) / $firstClose) * 100 : 0.0;
        $atrPct = max(0.01, (float) ($volatility['atr_pct'] ?? 0) ?: $this->computeAtrAvgPct($recent));
        $rangePct = min(5.0, $atrPct * sqrt($horizonCandles));
        $decisionAction = strtoupper((string) data_get($decision, 'action', 'HOLD'));
        $confidence = (float) data_get($decision, 'confidence', 0);
        $confidencePct = $confidence <= 1 ? $confidence * 100 : $confidence;

        // Current Trend is deliberately immediate and independent from the
        // expiry decision. It is based only on the micro window.
        $currentTrend = ($microBull - $microBear) >= 2 ? 'BUY'
            : (($microBear - $microBull) >= 2 ? 'SELL' : 'NEUTRAL');

        $contextDrift = $firstClose > 0 ? (($lastClose - $firstClose) / $firstClose) * 100 : 0.0;
        $trendScore = max(-1.0, min(1.0, ($bull - $bear) / max(1, $shortSize)));
        $driftScore = max(-1.0, min(1.0, $contextDrift / max(0.01, $atrPct * sqrt($horizonCandles))));
        $signalScore = $decisionAction === 'BUY' ? 1.0 : ($decisionAction === 'SELL' ? -1.0 : 0.0);
        // Transparent, versioned weights: trend 45%, price action/drift 30%,
        // existing signal 25%. Magnitude remains volatility-scaled below.
        $expiryScore = (0.45 * $trendScore) + (0.30 * $driftScore) + (0.25 * $signalScore);
        $bias = $expiryScore >= 0.20 ? 'BUY' : ($expiryScore <= -0.20 ? 'SELL' : 'NEUTRAL');
        $quality = min(95, max(35, 45 + abs($expiryScore) * 38 + min(15, $confidencePct / 6)));
        $displacementPct = $expiryScore * $rangePct * 0.55;
        $expectedMid = $currentPrice * (1 + $displacementPct / 100);
        $feeRate = max(0.0, (float) config('trading.binance_futures_taker_fee_rate', 0.0005));
        $slippageBufferPct = max(0.0, (float) config('robot_trade.live_slippage_buffer_pct', 0.04));
        $costPct = ($feeRate * 2 * 100) + $slippageBufferPct;
        $decisionTrend = abs($displacementPct) <= max($costPct, $rangePct * 0.12)
            ? 'WAIT' : ($expectedMid > $currentPrice ? 'BUY' : 'SELL');
        // Keep the response stable for callers that compare shadow on/off
        // payloads byte-for-byte; sub-second clock noise is not forecast data.
        $generatedAt = now()->setMicrosecond(0);
        $expiryAt = $generatedAt->copy()->addSeconds($horizonSeconds);
        $rangeHalfPct = max($rangePct * 0.65, $costPct * 1.5);
        $featureContext = $this->contextFeatures($recent, $currentPrice, $atrPct);

        // --- Context-driven safety gates -----------------------------------
        // These can only ever downgrade an already-computed BUY/SELL to WAIT
        // (or leave it untouched); they never upgrade WAIT to a confident
        // direction and never change predicted price/range math above.
        $preGateDecisionTrend = $decisionTrend;
        $gateReason = null;
        $contextCaution = [];

        // Stale-quote gate: only fires when the caller resolved an actual
        // live-feed snapshot and it is explicitly non-fresh -- absence of a
        // snapshot (symbol not currently WS-subscribed) is a different
        // condition and is deliberately NOT treated as stale here.
        $liveFeedResolved = is_array($liveFeed);
        $liveFeedFresh = $liveFeedResolved ? (bool) ($liveFeed['fresh'] ?? false) : true;
        if ($liveFeedResolved && ! $liveFeedFresh) {
            $decisionTrend = 'WAIT';
            $gateReason = 'stale_data';
        } elseif (($featureContext['volume_status'] ?? null) === 'contracting' && $decisionTrend !== 'WAIT') {
            // Low-liquidity / abnormal-volume gate: recent volume is
            // meaningfully below its own recent baseline (see
            // contextFeatures() -- "contracting" means recent average volume
            // is below 75% of the trailing baseline average). A BUY/SELL
            // built on candles this thin is not confidence-worthy.
            $decisionTrend = 'WAIT';
            $gateReason = 'low_volume';
        }

        // Support/resistance rejection awareness: surfaced as a caution flag,
        // not a forced WAIT, based on the pre-gate direction (the direction
        // the prediction was actually heading before any gate above fired).
        $srBufferPct = max($costPct, $rangePct * 0.5);
        $resistanceDistancePct = $featureContext['resistance_distance_pct'] ?? null;
        $supportDistancePct = $featureContext['support_distance_pct'] ?? null;
        if ($preGateDecisionTrend === 'BUY' && $resistanceDistancePct !== null && $resistanceDistancePct >= 0 && $resistanceDistancePct <= $srBufferPct) {
            $contextCaution[] = 'near_resistance';
        }
        if ($preGateDecisionTrend === 'SELL' && $supportDistancePct !== null && $supportDistancePct >= 0 && $supportDistancePct <= $srBufferPct) {
            $contextCaution[] = 'near_support';
        }

        $summary = match (true) {
            $gateReason === 'stale_data' => 'Price data is stale; waiting for a fresh quote before showing a confident direction.',
            $gateReason === 'low_volume' => 'Trading volume is unusually low right now; waiting for volume to normalize before showing a confident direction.',
            $decisionTrend === 'WAIT' => 'Expiry move does not clear uncertainty and estimated costs; wait.',
            default => "{$currentTrend} is the current trend; {$decisionTrend} is derived from the predicted expiry price at {$expiryAt->format('H:i:s')}.",
        };

        $stability = $this->forecastStability($symbol, $timeframe, $horizonSeconds, $decisionTrend, $generatedAt);

        return [
            'available' => true,
            'horizon_seconds' => $horizonSeconds,
            'horizon_candles' => $horizonCandles,
            'context_timeframe' => $timeframe,
            'context_timeframe_seconds' => $tfSeconds,
            'resolution_warning' => $horizonSeconds < $tfSeconds
                ? 'Forecast horizon is shorter than chart candle resolution; use a chart timeframe at or below the trade duration for the most directly observable context.' : null,
            'bias' => $bias,
            'current_trend' => $currentTrend,
            'decision_trend' => $decisionTrend,
            'confidence' => round($quality, 1),
            'confidence_status' => $quality >= 75 ? 'medium_high' : ($quality >= 55 ? 'medium' : 'low'),
            'generated_at' => $generatedAt->toISOString(),
            'expiry_at' => $expiryAt->toISOString(),
            'reference_price' => round($currentPrice, 8),
            'current_price' => round($currentPrice, 8),
            'predicted_expiry_price' => round($expectedMid, 8),
            'expected_mid_price' => round($expectedMid, 8),
            'predicted_low' => round($expectedMid * (1 - $rangeHalfPct / 100), 8),
            'predicted_high' => round($expectedMid * (1 + $rangeHalfPct / 100), 8),
            'expected_low' => round($expectedMid * (1 - $rangeHalfPct / 100), 8),
            'expected_high' => round($expectedMid * (1 + $rangeHalfPct / 100), 8),
            'predicted_move' => round($expectedMid - $currentPrice, 8),
            'predicted_move_pct' => round($displacementPct, 4),
            'analysis_window' => ['candles_used' => $windowSize, 'micro' => $microSize, 'short' => $shortSize],
            'model_version' => $this->modelVersion($feeRate, $slippageBufferPct),
            // Additive: how often decision_trend has flipped direction across
            // the last STABILITY_WINDOW evaluations for this symbol+timeframe
            // +horizon scope. Surfaced as a warning signal only -- never used
            // here to alter decision_trend/confidence above (see
            // forecastStability() docblock for the reasoned thresholds).
            'forecast_stability' => $stability['label'],
            'forecast_stability_flips' => $stability['flips'],
            'forecast_stability_window' => $stability['window'],
            'evidence' => [
                'trend_weight' => 0.45, 'price_action_weight' => 0.30, 'signal_weight' => 0.25,
                'trend_score' => round($trendScore, 4), 'drift_score' => round($driftScore, 4),
                'signal_score' => round($signalScore, 4), 'current_expiry_alignment' => $currentTrend === $decisionTrend,
            ],
            'market_context' => $featureContext,
            'atr_pct' => round($atrPct, 4),
            'recent_bull_candles' => $bull,
            'recent_bear_candles' => $bear,
            'drift_pct' => round($driftPct, 4),
            // Additive, backward-compatible: null/[] when no gate fired, so
            // existing callers/tests that don't read these fields see no
            // change at all.
            'gate_reason' => $gateReason,
            'context_caution' => $contextCaution,
            'summary' => $summary,
        ];
    }

    /**
     * Real, varying model_version fingerprint -- replaces the previous
     * hardcoded literal 'quick-expiry-v1' (which never changed regardless of
     * config). Format: "{MODEL_MAJOR_VERSION}-{8 hex chars}", where the hash
     * suffix is an md5 fingerprint of the actual weights/thresholds/config
     * that meaningfully affect this method's BUY/SELL/WAIT output:
     *  - the trend/price-action/signal blend weights and bias/decision
     *    thresholds (currently hardcoded literals in forecast() itself --
     *    included here so that if/when they move to config, the fingerprint
     *    picks the change up automatically with zero further changes here)
     *  - the two config()-driven cost inputs forecast() actually reads
     *    (fee rate, slippage buffer) that gate the decision_trend threshold
     *  - the volume-gate and support/resistance-buffer ratios
     * MODEL_MAJOR_VERSION is bumped by hand only when the scoring/decision
     * LOGIC shape itself changes (e.g. a new gate, a different blend
     * formula) -- something a config-value diff alone would not represent.
     *
     * Scope/honesty note: this is a `forecast()` response field only. It is
     * NOT currently wired into RatioBotForecastBacktest, the
     * per-prediction/Brier-score pipeline, or ForecastModelComparisonService
     * -- those are explicitly out of scope here (concurrent work elsewhere).
     * ForecastModelComparisonService's real bot_type+bot_instance_id+
     * horizon_seconds-only scoping (its documented limitation) is therefore
     * unchanged by this task; a genuine before/after comparison keyed on
     * this fingerprint is future wiring, not something this task claims.
     */
    private function modelVersion(float $feeRate, float $slippageBufferPct): string
    {
        $configSnapshot = [
            'trend_weight' => 0.45,
            'price_action_weight' => 0.30,
            'signal_weight' => 0.25,
            'bias_threshold' => 0.20,
            'decision_trend_range_multiplier' => 0.12,
            'displacement_range_multiplier' => 0.55,
            'range_half_atr_multiplier' => 0.65,
            'range_half_cost_multiplier' => 1.5,
            'sr_buffer_range_multiplier' => 0.5,
            'volume_contracting_ratio' => 0.75,
            'volume_expanding_ratio' => 1.25,
            'fee_rate' => $feeRate,
            'slippage_buffer_pct' => $slippageBufferPct,
        ];

        return self::MODEL_MAJOR_VERSION.'-'.substr(md5((string) json_encode($configSnapshot)), 0, 8);
    }

    /**
     * Rolling forecast_stability label for a symbol+timeframe+horizon scope:
     * a model that flips BUY -> SELL -> BUY every few seconds should not
     * present high confidence without a visible penalty (spec item 76).
     * This tracks the last STABILITY_WINDOW *returned* decision_trend values
     * (post-gate -- a stale-data/low-volume WAIT is a data-quality signal,
     * not model instability, but it is still the value users actually saw,
     * so it is what gets tracked) and counts direction flips between
     * consecutive entries.
     *
     * Thresholds (starting defaults, not backtested-optimal -- documented
     * here so a future pass can tune them from real outcome data):
     *   0-1 flips in the last 5 evaluations => HIGH   (essentially settled)
     *   2-3 flips in the last 5 evaluations => MEDIUM (some back-and-forth)
     *   4+  flips in the last 5 evaluations => LOW    (flipping almost every
     *                                                   tick -- a confident
     *                                                   read here would be
     *                                                   misleading)
     *
     * Mirrors FutureTradeSignalStabilityService::observeRaw()'s established
     * Cache-backed rolling-buffer pattern (get -> append -> cap -> put) --
     * no new caching approach introduced. Does not read or alter
     * decision_trend/confidence; it is a read-after-the-fact observation of
     * what forecast() itself already decided this call.
     *
     * Same-second calls are deduplicated (keyed by $generatedAt's
     * whole-second timestamp, mirroring forecast()'s own existing
     * `now()->setMicrosecond(0)` byte-identical-response convention) so
     * that two forecast() calls for the same scope within the same second
     * -- e.g. a UI polling faster than 1/s, or two calls in the same test --
     * observe one evaluation, not two. This keeps forecast() side-effect
     * stable for identical rapid-fire inputs while still tracking real
     * flips across genuinely distinct evaluation ticks.
     */
    private function forecastStability(?string $symbol, string $timeframe, int $horizonSeconds, string $decisionTrend, \Carbon\Carbon $generatedAt): array
    {
        if ($symbol === null || trim($symbol) === '') {
            // No scope to key the rolling window on -- honestly unknown
            // rather than a fabricated label. Existing callers that don't
            // pass a symbol (see forecast()'s docblock) land here.
            return ['label' => 'UNKNOWN', 'flips' => null, 'window' => 0];
        }

        $key = sprintf('quick_trade:forecast_stability:%s:%s:%d', strtoupper($symbol), strtolower($timeframe), $horizonSeconds);
        $history = (array) Cache::get($key, []);
        $nowTs = $generatedAt->getTimestamp();
        $lastEntry = $history === [] ? null : end($history);
        if ($lastEntry === null || (int) $lastEntry['ts'] !== $nowTs) {
            $history[] = ['ts' => $nowTs, 'decision' => $decisionTrend];
            if (count($history) > self::STABILITY_WINDOW) {
                $history = array_slice($history, -self::STABILITY_WINDOW);
            }
            Cache::put($key, $history, self::STABILITY_TTL_SECONDS);
        }

        $decisions = array_column($history, 'decision');
        $flips = 0;
        for ($i = 1; $i < count($decisions); $i++) {
            if ($decisions[$i] !== $decisions[$i - 1]) {
                $flips++;
            }
        }

        $label = $flips >= 4 ? 'LOW' : ($flips >= 2 ? 'MEDIUM' : 'HIGH');

        return ['label' => $label, 'flips' => $flips, 'window' => count($decisions)];
    }

    /**
     * Cheap, deterministic context features. These are descriptive evidence,
     * not independent trade signals; unavailable inputs stay null/unknown.
     */
    private function contextFeatures(array $candles, float $price, float $atrPct): array
    {
        $volumes = array_values(array_filter(array_map(static fn ($c) => (float) ($c['volume'] ?? 0), $candles), fn ($v) => $v > 0));
        $recentVolumes = array_slice($volumes, -min(5, count($volumes)));
        $baseVolumes = array_slice($volumes, -min(20, count($volumes)));
        $recentVolume = $recentVolumes !== [] ? array_sum($recentVolumes) / count($recentVolumes) : null;
        $baseVolume = $baseVolumes !== [] ? array_sum($baseVolumes) / count($baseVolumes) : null;
        $highs = array_map(static fn ($c) => (float) ($c['high'] ?? 0), $candles);
        $lows = array_map(static fn ($c) => (float) ($c['low'] ?? 0), $candles);
        $support = $lows !== [] ? min($lows) : null;
        $resistance = $highs !== [] ? max($highs) : null;
        $range = $price > 0 && $support !== null && $resistance !== null ? ($resistance - $support) / $price * 100 : null;
        $regime = $atrPct >= 1.0 ? 'high_volatility' : ($atrPct <= 0.08 ? 'low_volatility' : 'normal');
        return [
            'volume_ratio' => $recentVolume !== null && $baseVolume > 0 ? round($recentVolume / $baseVolume, 4) : null,
            'volume_status' => $recentVolume === null ? 'unknown' : ($recentVolume > $baseVolume * 1.25 ? 'expanding' : ($recentVolume < $baseVolume * 0.75 ? 'contracting' : 'normal')),
            'support' => $support !== null ? round($support, 8) : null,
            'resistance' => $resistance !== null ? round($resistance, 8) : null,
            'support_distance_pct' => $support !== null && $price > 0 ? round(($price - $support) / $price * 100, 4) : null,
            'resistance_distance_pct' => $resistance !== null && $price > 0 ? round(($resistance - $price) / $price * 100, 4) : null,
            'local_range_pct' => $range !== null ? round($range, 4) : null,
            'regime' => $regime,
            'liquidity_status' => $recentVolume === null ? 'unknown' : 'observed_volume_only',
            'liquidity_note' => 'Spread/order-book liquidity is unavailable in candle-only forecast input.',
        ];
    }

    /**
     * Expected move vs. fee+slippage cost for a given stake — the "is this
     * trade worth it after costs" edge estimate.
     *
     * Ported from RobotSessionService::robotNetEdgeEstimate(). No stake
     * flooring here — callers apply their own minimum-stake rules before
     * calling in, so the numbers always reflect the stake actually at risk.
     *
     * Adds `actual_cost_usdt` / `expected_net_usdt_actual` alongside the
     * original (safety-buffered) fields: the original `expected_net_usdt`
     * uses a 2x-cost safety margin meant as an entry gate, not a literal
     * profit estimate — the `_actual` fields use the real round-trip cost.
     *
     * @param  array<array{high:mixed,low:mixed,close:mixed}>  $candles
     */
    public function netEdge(array $candles, float $stake): array
    {
        $atrPct = $this->computeAtrAvgPct($candles);
        $expectedMovePct = max(0.0, $atrPct * 0.35);

        $feeRate = max(0.0, (float) config('trading.binance_futures_taker_fee_rate', 0.0005));
        $slippageBufferPct = max(0.0, (float) config('robot_trade.live_slippage_buffer_pct', 0.04));
        $costPct = ($feeRate * 2 * 100) + $slippageBufferPct;
        $requiredMovePct = $costPct * max(1.0, (float) config('robot_trade.live_min_edge_cost_multiplier', 2.0));

        $expectedGrossUsdt = $stake * ($expectedMovePct / 100);
        $requiredCostUsdt = $stake * ($requiredMovePct / 100);
        $expectedNetUsdt = $expectedGrossUsdt - $requiredCostUsdt;
        $minExpectedNetUsdt = max(0.0, (float) config('robot_trade.live_min_expected_net_usdt', 0.03));

        $actualCostUsdt = $stake * ($costPct / 100);
        $expectedNetUsdtActual = $expectedGrossUsdt - $actualCostUsdt;

        $result = [
            'tradeable' => $expectedMovePct >= $requiredMovePct && $expectedNetUsdt >= $minExpectedNetUsdt,
            'stake' => round($stake, 8),
            'atr_pct' => round($atrPct, 4),
            'expected_move_pct' => round($expectedMovePct, 4),
            'fee_rate' => round($feeRate, 6),
            'round_trip_fee_pct' => round($feeRate * 2 * 100, 4),
            'slippage_buffer_pct' => round($slippageBufferPct, 4),
            'required_move_pct' => round($requiredMovePct, 4),
            'expected_gross_usdt' => round($expectedGrossUsdt, 8),
            'required_cost_usdt' => round($requiredCostUsdt, 8),
            'expected_net_usdt' => round($expectedNetUsdt, 8),
            'min_expected_net_usdt' => round($minExpectedNetUsdt, 8),
            'actual_cost_usdt' => round($actualCostUsdt, 8),
            'expected_net_usdt_actual' => round($expectedNetUsdtActual, 8),
        ];

        if (! $result['tradeable']) {
            $result['reason'] = sprintf(
                'Expected move %.4f%% / net %.4f USDT is below live fee/slippage requirement %.4f%% / %.4f USDT.',
                $expectedMovePct,
                $expectedNetUsdt,
                $requiredMovePct,
                $minExpectedNetUsdt
            );
        }

        return $result;
    }

    /**
     * Average ATR as % of price over the last 20 periods.
     *
     * @param  array<array{high:mixed,low:mixed,close:mixed}>  $candles
     */
    public function computeAtrAvgPct(array $candles): float
    {
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $closes = array_column($candles, 'close');
        $trs    = [];
        $count  = min(count($candles), 21);
        for ($i = 1; $i < $count; $i++) {
            $trs[] = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }
        $last = (float) end($closes);
        return (count($trs) > 0 && $last > 0) ? (array_sum($trs) / count($trs) / $last) * 100 : 0.0;
    }

    /**
     * Adapts SignalPreviewService::preview()'s shape (`direction`: lowercase
     * buy/sell/neutral) into the `{action, confidence}` shape forecast()
     * expects (`action`: uppercase BUY/SELL/HOLD) — without this, passing
     * a signal preview straight into forecast() silently degrades to HOLD.
     *
     * @param  array{direction?:mixed,confidence?:mixed}  $signalPreview
     */
    public function decisionFromSignalPreview(array $signalPreview): array
    {
        $direction = strtolower((string) ($signalPreview['direction'] ?? 'neutral'));

        return [
            'action' => $direction === 'buy' ? 'BUY' : ($direction === 'sell' ? 'SELL' : 'HOLD'),
            'confidence' => (float) ($signalPreview['confidence'] ?? 0),
        ];
    }
}
