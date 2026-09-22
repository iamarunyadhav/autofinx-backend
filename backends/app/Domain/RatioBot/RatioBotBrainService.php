<?php

namespace App\Domain\RatioBot;

use App\Domain\Market\LiveCandleCacheService;
use App\Domain\Trading\SignalPreviewService;
use App\Domain\Trading\TimeframeRegistry;
use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\RatioBotBrainSnapshot;
use App\Models\RatioBotConfig;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirmation-only "Master Brain" for Ratio Bot — the same Robot Signal
 * (SignalPreviewService) + Near-term Forecast (TradeForecastService) engine
 * already shown on the Quick Trade screen, horizon-matched to whatever
 * timeframe the Ratio Bot itself is configured with (1h, 4h, etc.) instead
 * of a fixed value.
 *
 * Never blocks or vetoes a trade. Fails open on any error: callers get
 * `null` and the existing ratio-threshold logic proceeds unaffected.
 */
class RatioBotBrainService
{
    private const MIN_CANDLES = 30;

    public function __construct(
        private readonly SignalPreviewService $signals,
        private readonly TradeForecastService $forecast,
        private readonly TimeframeRegistry $timeframes,
        private readonly LiveCandleCacheService $liveCandles,
    ) {
    }

    /**
     * Refresh (if the last snapshot is older than one horizon for this
     * bot's timeframe) and return the latest brain snapshot. Inert unless
     * $config->brain_enabled is true.
     */
    public function resolve(RatioBotConfig $config, float $currentPrice): ?RatioBotBrainSnapshot
    {
        if (! (bool) $config->brain_enabled) {
            return null;
        }

        $existing = $this->latest($config);
        $horizonSeconds = $this->horizonSeconds($config);

        if ($existing && $existing->created_at && $existing->created_at->diffInSeconds(now()) < $horizonSeconds) {
            return $existing;
        }

        try {
            return $this->refresh($config, $currentPrice, $horizonSeconds);
        } catch (Throwable $e) {
            Log::warning('[RatioBotBrain] Failed to refresh brain snapshot, failing open', [
                'config_id' => $config->id,
                'symbol' => $config->normalizedSymbol(),
                'error' => $e->getMessage(),
            ]);

            return $existing;
        }
    }

    /**
     * Cheap read of the most recent stored snapshot — no computation, safe
     * to call on every tick (used by RatioBotDecisionService::confirmTrigger()
     * to check for a conflicting brain view).
     */
    public function latest(RatioBotConfig $config): ?RatioBotBrainSnapshot
    {
        return RatioBotBrainSnapshot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->latest('created_at')
            ->first();
    }

    public function horizonSeconds(RatioBotConfig $config): int
    {
        $override = $config->forecastHorizonSeconds();
        if ($override !== null) {
            return $override;
        }

        return max(1, $this->timeframes->seconds($config->timeFrame()));
    }

    private function refresh(RatioBotConfig $config, float $currentPrice, int $horizonSeconds): ?RatioBotBrainSnapshot
    {
        $candles = $this->fetchCandles($config);
        if (count($candles) < self::MIN_CANDLES) {
            return null;
        }

        // `currentPrice` comes from the guarded canonical execution path.
        // Keep the open candle in the analysis window, but make the forecast's
        // reference explicitly current rather than the last persisted close.
        $candles[array_key_last($candles)]['close'] = $currentPrice;

        $signal = $this->signals->preview($candles, $config->timeFrame(), []);
        $decision = $this->forecast->decisionFromSignalPreview($signal);
        $forecast = $this->forecast->forecast($candles, $config->timeFrame(), $horizonSeconds, $decision);

        return RatioBotBrainSnapshot::create([
            'ratio_bot_config_id' => $config->id,
            'symbol' => $config->normalizedSymbol(),
            'timeframe' => $config->timeFrame(),
            'horizon_seconds' => $horizonSeconds,
            'price_at_evaluation' => $currentPrice,
            'direction' => $this->normalizeDirection($signal['direction'] ?? null),
            'confidence' => (int) ($signal['confidence'] ?? 0),
            'bullish_score' => $signal['bullish_score'] ?? 0,
            'neutral_score' => $signal['neutral_score'] ?? 0,
            'bearish_score' => $signal['bearish_score'] ?? 0,
            'reasons' => $signal['reasons'] ?? [],
            'forecast_bias' => $this->normalizeForecastBias($forecast['bias'] ?? null),
            'forecast_confidence' => $forecast['confidence'] ?? null,
            'expected_mid_price' => $forecast['expected_mid_price'] ?? null,
            'expected_low' => $forecast['expected_low'] ?? null,
            'expected_high' => $forecast['expected_high'] ?? null,
            'atr_pct' => $forecast['atr_pct'] ?? null,
            'raw_payload' => ['signal' => $signal, 'forecast' => $forecast],
        ]);
    }

    /**
     * @return array<array{open:float,high:float,low:float,close:float,volume:float}>
     */
    private function fetchCandles(RatioBotConfig $config): array
    {
        $closed = MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->where('timeframe', $config->timeFrame())
            ->orderByDesc('ts')
            ->limit(120)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($candle) => [
                'timestamp' => $candle->ts?->getTimestampMs(),
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) $candle->volume,
            ])
            ->all();

        // Redis only holds current transport state. Closed candles remain
        // historical context from MarketOhlc and must never be rejected as
        // "stale" merely because their close time is old.
        $open = collect($this->liveCandles->candles(
            $config->exchangeKey(),
            $config->normalizedSymbol(),
            $config->timeFrame(),
            1,
        ))->last(static fn (array $candle): bool => ! (bool) ($candle['is_closed'] ?? false));

        if (! is_array($open)) {
            return $closed;
        }

        $openTimestamp = (int) ($open['timestamp'] ?? 0);
        // A cache can contain the just-closed candle briefly. Never duplicate
        // it: replace the same timestamp, otherwise append the one open bar.
        $index = array_key_last($closed);
        if ($index !== null && (int) ($closed[$index]['timestamp'] ?? 0) === $openTimestamp) {
            $closed[$index] = $open;
        } else {
            $closed[] = $open;
        }

        return $closed;
    }

    private function normalizeDirection(mixed $direction): string
    {
        return match (strtolower((string) $direction)) {
            'buy', 'bullish' => 'bullish',
            'sell', 'bearish' => 'bearish',
            default => 'neutral',
        };
    }

    private function normalizeForecastBias(mixed $bias): string
    {
        return match (strtolower((string) $bias)) {
            'buy', 'bullish' => 'bullish',
            'sell', 'bearish' => 'bearish',
            default => 'neutral',
        };
    }
}
