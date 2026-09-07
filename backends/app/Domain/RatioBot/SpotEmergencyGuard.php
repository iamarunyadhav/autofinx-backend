<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;

/**
 * Flash-crash protection for SPOT bots -- there is no liquidation deadline
 * forcing a close the way futures has, so this never force-sells (that
 * would violate the never-sell-at-a-loss principle for no external reason).
 * Its only actions (wired in RatioBotRealtimeEngine/RatioBotRiskService) are
 * to accelerate re-evaluation and block new buy entries while active.
 *
 * Reuses TradeForecastService::computeAtrAvgPct() -- verified before writing
 * this to be the only real, already-proven ATR calc in this codebase. The
 * futures stop mechanism (RatioBotExchangeStopOrderService) is
 * liquidation-buffer-based, not ATR-based, so there was no existing
 * ATR-based SL/TP to reuse from that side despite the source spec's claim.
 */
class SpotEmergencyGuard
{
    private const LOOKBACK_CANDLES = 12;
    private const HISTORY_CANDLES = 30;

    public function __construct(
        private readonly TradeForecastService $forecast,
    ) {
    }

    public function assess(RatioBotConfig $config, float $currentPrice): array
    {
        $candles = MarketOhlc::query()
            ->where('exchange', $config->exchangeKey())
            ->where('symbol', $config->normalizedSymbol())
            ->where('timeframe', $config->timeFrame())
            ->orderByDesc('ts')
            ->limit(self::HISTORY_CANDLES)
            ->get(['open', 'high', 'low', 'close'])
            ->reverse()
            ->values();

        if ($candles->count() < self::LOOKBACK_CANDLES) {
            return $this->result(false, 0.0, 0.0, 0.0, 'insufficient candle history');
        }

        $atrCandles = $candles->map(fn ($candle) => [
            'high' => (float) $candle->high,
            'low' => (float) $candle->low,
            'close' => (float) $candle->close,
        ])->all();
        $atrPct = max(0.01, $this->forecast->computeAtrAvgPct($atrCandles));

        $window = $candles->slice(-self::LOOKBACK_CANDLES)->values();
        $referencePrice = (float) $window->first()->open;
        if ($referencePrice <= 0 || $currentPrice <= 0) {
            return $this->result(false, 0.0, $atrPct, 0.0, 'invalid reference or current price');
        }

        $drawdownPct = (($referencePrice - $currentPrice) / $referencePrice) * 100;
        $atrMultiple = $drawdownPct / $atrPct;
        $threshold = $config->emergencyAtrMultipleThreshold();
        $isEmergency = $atrMultiple > $threshold;

        return $this->result(
            $isEmergency,
            round($atrMultiple, 3),
            round($atrPct, 4),
            round($drawdownPct, 4),
            $isEmergency
                ? sprintf(
                    'drop of %.2f%% over the last %d candles is %.2fx the recent ATR (threshold %.2fx)',
                    $drawdownPct,
                    self::LOOKBACK_CANDLES,
                    $atrMultiple,
                    $threshold,
                )
                : 'within normal volatility',
        );
    }

    private function result(bool $isEmergency, float $atrMultiple, float $atrPct, float $drawdownPct, string $reason): array
    {
        return [
            'is_emergency' => $isEmergency,
            'atr_multiple' => $atrMultiple,
            'atr_pct' => $atrPct,
            'drawdown_pct' => $drawdownPct,
            'reason' => $reason,
        ];
    }
}
