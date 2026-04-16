<?php

namespace App\Domain\Strategies;

use App\Models\Bot;
use App\Models\MarketOhlc;
use App\Models\Position;
use App\Models\Trade;
use App\Models\TradeSignal;

class BotSignalGenerator
{
    public function evaluate(Bot $bot, float $price): ?array
    {
        $strategyType = strtolower((string) ($bot->strategy_type ?: 'threshold'));
        $params = $bot->strategy_params ?? [];

        return match ($strategyType) {
            'ma_crossover', 'ma' => $this->evaluateMovingAverage($bot, $price, $params),
            'rebalance_flip', 'flip', 'metals_rebalance' => $this->evaluateRebalanceFlip($bot, $price, $params),
            'mean_reversion', 'mean' => $this->evaluateMeanReversion($bot, $price, $params),
            'sotr', 'scale_out_trail_reentry' => $this->evaluateSotr($bot, $price, $params),
            default => $this->evaluateThreshold($bot, $price, $params),
        };
    }

    private function evaluateThreshold(Bot $bot, float $price, array $params): ?array
    {
        $dropPct = (float) ($params['buy_drop_pct'] ?? 0.7);
        $risePct = (float) ($params['sell_rise_pct'] ?? 0.7);
        $dropValue = abs((float) ($params['buy_drop_value'] ?? 0));
        $riseValue = abs((float) ($params['sell_rise_value'] ?? 0));
        $anchor = (float) ($params['anchor_price'] ?? 0);
        if ($anchor <= 0) {
            $params['anchor_price'] = $price;
            $bot->strategy_params = $params;
            $bot->save();
            return null;
        }

        $buyThreshold = $anchor * (1 - ($dropPct / 100));
        $sellThreshold = $anchor * (1 + ($risePct / 100));
        $buyByValue = $dropValue > 0 ? ($price <= ($anchor - $dropValue)) : false;
        $sellByValue = $riseValue > 0 ? ($price >= ($anchor + $riseValue)) : false;

        if ($price <= $buyThreshold || $buyByValue) {
            $params['anchor_price'] = $price;
            $bot->strategy_params = $params;
            $bot->save();

            return $this->signalPayload($bot, 'BUY', $price, $params);
        }

        if ($price >= $sellThreshold || $sellByValue) {
            $params['anchor_price'] = $price;
            $bot->strategy_params = $params;
            $bot->save();

            return $this->signalPayload($bot, 'SELL', $price, $params);
        }

        return null;
    }

    private function evaluateMovingAverage(Bot $bot, float $price, array $params): ?array
    {
        $shortWindow = max(3, (int) ($params['ma_short'] ?? 9));
        $longWindow = max($shortWindow + 1, (int) ($params['ma_long'] ?? 21));

        $provider = strtolower((string) ($bot->provider ?: $bot->exchange));
        $symbol = strtoupper((string) ($bot->asset_code ?: $bot->symbol));

        $candles = MarketOhlc::where('exchange', $provider)
            ->where('symbol', $symbol)
            ->where('timeframe', '1m')
            ->orderByDesc('ts')
            ->limit($longWindow + 2)
            ->get()
            ->reverse()
            ->values();

        if ($candles->count() < $longWindow + 1) {
            return null;
        }

        $closes = $candles->map(fn ($c) => (float) $c->close)->values()->all();
        $prevShort = $this->avg(array_slice($closes, -$shortWindow - 1, $shortWindow));
        $prevLong = $this->avg(array_slice($closes, -$longWindow - 1, $longWindow));
        $curShort = $this->avg(array_slice($closes, -$shortWindow));
        $curLong = $this->avg(array_slice($closes, -$longWindow));

        if ($prevShort <= $prevLong && $curShort > $curLong) {
            return $this->signalPayload($bot, 'BUY', $price, $params);
        }

        if ($prevShort >= $prevLong && $curShort < $curLong) {
            return $this->signalPayload($bot, 'SELL', $price, $params);
        }

        return null;
    }

    private function evaluateRebalanceFlip(Bot $bot, float $price, array $params): ?array
    {
        $buyDropPct = abs((float) ($params['buy_drop_pct'] ?? 1.2));
        $sellRisePct = abs((float) ($params['sell_rise_pct'] ?? 1.2));
        $buyDropValue = abs((float) ($params['buy_drop_value'] ?? 0));
        $sellRiseValue = abs((float) ($params['sell_rise_value'] ?? 0));
        $coolDownSec = max(0, (int) ($params['cool_down_sec'] ?? 45));
        $maxTradesPerDay = max(0, (int) ($params['max_trades_per_day'] ?? 0));

        if ($maxTradesPerDay > 0) {
            $todayTrades = Trade::where('bot_id', $bot->id)
                ->whereDate('ts', now()->toDateString())
                ->count();
            if ($todayTrades >= $maxTradesPerDay) {
                return null;
            }
        }

        if ($coolDownSec > 0) {
            $lastTrade = Trade::where('bot_id', $bot->id)->latest('ts')->first();
            if ($lastTrade && $lastTrade->ts && now()->diffInSeconds($lastTrade->ts) < $coolDownSec) {
                return null;
            }
        }

        $reference = (float) ($params['reference_price'] ?? 0);
        if ($reference <= 0) {
            $params['reference_price'] = $price;
            $bot->strategy_params = $params;
            $bot->save();
            return null;
        }

        $position = Position::where('bot_id', $bot->id)
            ->whereRaw('COALESCE(qty, quantity, 0) > 0')
            ->latest('updated_at')
            ->first();
        $openQty = (float) ($position?->qty ?? $position?->quantity ?? 0);

        if ($openQty <= 0) {
            if ($price > $reference) {
                // Trail the local high so buy trigger happens on a meaningful pullback.
                $params['reference_price'] = $price;
                $bot->strategy_params = $params;
                $bot->save();
                return null;
            }

            $dropPct = (($price - $reference) / $reference) * 100;
            $dropAbs = $reference - $price;
            $pctTrigger = $buyDropPct > 0 ? ($dropPct <= -$buyDropPct) : false;
            $absTrigger = $buyDropValue > 0 ? ($dropAbs >= $buyDropValue) : false;
            if ($pctTrigger || $absTrigger) {
                $params['reference_price'] = $price;
                $bot->strategy_params = $params;
                $bot->save();

                return $this->signalPayload($bot, 'BUY', $price, $params);
            }

            return null;
        }

        $avgEntry = (float) ($position->avg_entry ?? $position->avg_price ?? $reference);
        if ($avgEntry <= 0) {
            return null;
        }

        $risePct = (($price - $avgEntry) / $avgEntry) * 100;
        $riseAbs = $price - $avgEntry;
        $pctTrigger = $sellRisePct > 0 ? ($risePct >= $sellRisePct) : false;
        $absTrigger = $sellRiseValue > 0 ? ($riseAbs >= $sellRiseValue) : false;
        if ($pctTrigger || $absTrigger) {
            $params['reference_price'] = $price;
            $bot->strategy_params = $params;
            $bot->save();

            return $this->signalPayload($bot, 'SELL', $price, $params);
        }

        return null;
    }

    private function evaluateMeanReversion(Bot $bot, float $price, array $params): ?array
    {
        $window = max(8, (int) ($params['mr_window'] ?? 20));
        $stdMult = max(0.5, (float) ($params['mr_std_mult'] ?? 2.0));
        $history = array_values(array_filter((array) ($params['_recent_prices'] ?? []), static fn ($v) => is_numeric($v)));
        $history[] = $price;
        if (count($history) > ($window * 3)) {
            $history = array_slice($history, -($window * 3));
        }

        $params['_recent_prices'] = $history;
        $bot->strategy_params = $params;
        $bot->save();

        if (count($history) < $window + 1) {
            return null;
        }

        $sample = array_slice($history, -$window);
        $mean = $this->avg($sample);
        $std = $this->stddev($sample, $mean);
        if ($std <= 0) {
            return null;
        }

        $upper = $mean + ($stdMult * $std);
        $lower = $mean - ($stdMult * $std);
        if ($price <= $lower) {
            return $this->signalPayload($bot, 'BUY', $price, $params);
        }
        if ($price >= $upper) {
            return $this->signalPayload($bot, 'SELL', $price, $params);
        }

        return null;
    }

    private function evaluateSotr(Bot $bot, float $price, array $params): ?array
    {
        $entryMethod = strtolower((string) ($params['entry_method'] ?? 'threshold'));
        $buyDropPct = abs((float) ($params['buy_drop_pct'] ?? 0.9));
        $sellRisePct = abs((float) ($params['sell_rise_pct'] ?? 0.9));
        $tp1Pct = abs((float) ($params['tp1_pct'] ?? 1.0));
        $tp2Pct = abs((float) ($params['tp2_pct'] ?? 2.0));
        $tp1SellPct = max(0.0, min(100.0, (float) ($params['tp1_sell_pct'] ?? 40.0)));
        $tp2SellPct = max(0.0, min(100.0, (float) ($params['tp2_sell_pct'] ?? 30.0)));
        $trailAtrMult = max(0.1, (float) ($params['runner_trail_atr_mult'] ?? 2.2));
        $trailMinPct = max(0.05, (float) ($params['runner_trail_min_pct'] ?? 0.6));
        $atrPeriod = max(5, (int) ($params['atr_period'] ?? 14));
        $adxPeriod = max(5, (int) ($params['adx_period'] ?? 14));
        $adxThreshold = max(1.0, (float) ($params['adx_threshold'] ?? 25.0));
        $enableBreakoutReentry = filter_var($params['enable_breakout_reentry'] ?? true, FILTER_VALIDATE_BOOL);
        $reentryWindow = max(5, (int) ($params['reentry_breakout_window'] ?? 20));
        $reentryBufferPct = max(0.0, (float) ($params['reentry_buffer_pct'] ?? 0.15));
        $reentrySizePct = max(1.0, min(100.0, (float) ($params['reentry_size_pct'] ?? 25.0)));
        $reentryCooldownSec = max(0, (int) ($params['reentry_cooldown_sec'] ?? 300));
        $baseQty = max(0.0, (float) ($params['qty'] ?? 0));

        $position = Position::where('bot_id', $bot->id)
            ->whereRaw('COALESCE(qty, quantity, 0) > 0')
            ->latest('updated_at')
            ->first();
        $openQty = (float) ($position?->qty ?? $position?->quantity ?? 0);

        $atr = $this->computeAtr($bot, $atrPeriod);
        $adx = $this->computeAdx($bot, $adxPeriod);
        $trendStrong = $adx !== null ? $adx >= $adxThreshold : false;

        if ($openQty <= 0) {
            $anchor = (float) ($params['sotr_anchor_price'] ?? 0);
            if ($anchor <= 0) {
                $params['sotr_anchor_price'] = $price;
                $params['sotr_tp1_done'] = false;
                $params['sotr_tp2_done'] = false;
                $params['sotr_high_water'] = 0;
                $this->saveParams($bot, $params);
                return null;
            }

            if ($price > $anchor) {
                $params['sotr_anchor_price'] = $price;
                $this->saveParams($bot, $params);
                return null;
            }

            if ($entryMethod !== 'breakout_only') {
                $buyThreshold = $anchor * (1 - ($buyDropPct / 100));
                if ($price <= $buyThreshold) {
                    $params['sotr_anchor_price'] = $price;
                    $params['sotr_tp1_done'] = false;
                    $params['sotr_tp2_done'] = false;
                    $params['sotr_high_water'] = $price;
                    $this->saveParams($bot, $params);

                    return $this->signalPayloadWithQty($bot, 'BUY', $price, $params, $baseQty);
                }
            }

            if ($enableBreakoutReentry && $trendStrong) {
                $lastExitTs = (int) ($params['sotr_last_exit_ts'] ?? 0);
                if ($lastExitTs > 0 && (time() - $lastExitTs) < $reentryCooldownSec) {
                    return null;
                }

                $upperBand = $this->donchianUpper($bot, $reentryWindow);
                if ($upperBand > 0 && $price >= ($upperBand * (1 + ($reentryBufferPct / 100)))) {
                    $reentryQty = $baseQty > 0
                        ? $baseQty * ($reentrySizePct / 100)
                        : (float) ($params['reentry_qty'] ?? 0);
                    $params['sotr_anchor_price'] = $price;
                    $params['sotr_tp1_done'] = false;
                    $params['sotr_tp2_done'] = false;
                    $params['sotr_high_water'] = $price;
                    $this->saveParams($bot, $params);

                    return $this->signalPayloadWithQty($bot, 'BUY', $price, $params, $reentryQty);
                }
            }

            return null;
        }

        $avgEntry = (float) ($position?->avg_entry ?? $position?->avg_price ?? 0);
        if ($avgEntry <= 0) {
            return null;
        }

        $highWater = max((float) ($params['sotr_high_water'] ?? $avgEntry), $price);
        $params['sotr_high_water'] = $highWater;

        $tp1Done = filter_var($params['sotr_tp1_done'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $tp1Done && $price >= ($avgEntry * (1 + ($tp1Pct / 100)))) {
            $sellQty = max(0.0, min($openQty, $openQty * ($tp1SellPct / 100)));
            if ($sellQty > 0) {
                $params['sotr_tp1_done'] = true;
                $this->saveParams($bot, $params);
                return $this->signalPayloadWithQty($bot, 'SELL', $price, $params, $sellQty);
            }
        }

        $tp2Done = filter_var($params['sotr_tp2_done'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $tp2Done && $price >= ($avgEntry * (1 + ($tp2Pct / 100)))) {
            $sellQty = max(0.0, min($openQty, $openQty * ($tp2SellPct / 100)));
            if ($sellQty > 0) {
                $params['sotr_tp2_done'] = true;
                $this->saveParams($bot, $params);
                return $this->signalPayloadWithQty($bot, 'SELL', $price, $params, $sellQty);
            }
        }

        $trailStop = $atr && $atr > 0
            ? $highWater - ($atr * $trailAtrMult)
            : $highWater * (1 - ($trailMinPct / 100));

        if ($price <= $trailStop) {
            $params['sotr_last_exit_price'] = $price;
            $params['sotr_last_exit_ts'] = time();
            $params['sotr_anchor_price'] = $price;
            $params['sotr_tp1_done'] = false;
            $params['sotr_tp2_done'] = false;
            $params['sotr_high_water'] = 0;
            $this->saveParams($bot, $params);

            return $this->signalPayloadWithQty($bot, 'SELL', $price, $params, $openQty);
        }

        // Optional safety partial if move is in profit but TP ladder not hit yet.
        if (! $tp1Done && $sellRisePct > 0 && $price >= ($avgEntry * (1 + ($sellRisePct / 100)))) {
            $safetyQty = min($openQty, max(0.0, $openQty * 0.25));
            if ($safetyQty > 0) {
                $params['sotr_tp1_done'] = true;
                $this->saveParams($bot, $params);
                return $this->signalPayloadWithQty($bot, 'SELL', $price, $params, $safetyQty);
            }
        }

        $this->saveParams($bot, $params);
        return null;
    }

    private function signalPayload(Bot $bot, string $side, float $decisionPrice, array $params): ?array
    {
        $last = TradeSignal::where('bot_id', $bot->id)->latest('decision_ts')->first();
        $cooldownMs = (int) ($params['signal_cooldown_ms'] ?? 4000);
        if ($last && now()->diffInMilliseconds($last->decision_ts) < $cooldownMs && strtoupper($last->side) === $side) {
            return null;
        }

        return [
            'side' => $side,
            'decision_price' => $decisionPrice,
            'max_slippage_pct' => (float) ($params['max_slippage_pct'] ?? $bot->riskProfile?->max_slippage_pct_default ?? 0.5),
            'ttl_ms' => (int) ($params['ttl_ms'] ?? 8000),
            'fill_timeout_ms' => (int) ($params['fill_timeout_ms'] ?? 3000),
            'exec_policy' => strtoupper((string) ($params['exec_policy'] ?? 'SAFE')),
            'qty' => (float) ($params['qty'] ?? 0),
        ];
    }

    private function signalPayloadWithQty(Bot $bot, string $side, float $decisionPrice, array $params, float $qty): ?array
    {
        $payload = $this->signalPayload($bot, $side, $decisionPrice, $params);
        if (! $payload) {
            return null;
        }

        $payload['qty'] = max(0.0, $qty);

        return $payload;
    }

    private function avg(array $values): float
    {
        if (count($values) === 0) {
            return 0;
        }

        return array_sum($values) / count($values);
    }

    private function stddev(array $values, float $mean): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / (count($values) - 1));
    }

    private function computeAtr(Bot $bot, int $period): ?float
    {
        $provider = strtolower((string) ($bot->provider ?: $bot->exchange));
        $symbol = strtoupper((string) ($bot->asset_code ?: $bot->symbol));
        $candles = MarketOhlc::where('exchange', $provider)
            ->where('symbol', $symbol)
            ->where('timeframe', '1m')
            ->orderByDesc('ts')
            ->limit($period + 2)
            ->get()
            ->reverse()
            ->values();

        if ($candles->count() < 2) {
            return null;
        }

        $tr = [];
        for ($i = 1; $i < $candles->count(); $i++) {
            $prevClose = (float) $candles[$i - 1]->close;
            $high = (float) $candles[$i]->high;
            $low = (float) $candles[$i]->low;
            $tr[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        if (count($tr) === 0) {
            return null;
        }

        $window = array_slice($tr, -min($period, count($tr)));
        return $this->avg($window);
    }

    private function computeAdx(Bot $bot, int $period): ?float
    {
        $provider = strtolower((string) ($bot->provider ?: $bot->exchange));
        $symbol = strtoupper((string) ($bot->asset_code ?: $bot->symbol));
        $candles = MarketOhlc::where('exchange', $provider)
            ->where('symbol', $symbol)
            ->where('timeframe', '1m')
            ->orderByDesc('ts')
            ->limit($period + 2)
            ->get()
            ->reverse()
            ->values();

        if ($candles->count() < 3) {
            return null;
        }

        $plusDm = [];
        $minusDm = [];
        $tr = [];

        for ($i = 1; $i < $candles->count(); $i++) {
            $curHigh = (float) $candles[$i]->high;
            $curLow = (float) $candles[$i]->low;
            $prevHigh = (float) $candles[$i - 1]->high;
            $prevLow = (float) $candles[$i - 1]->low;
            $prevClose = (float) $candles[$i - 1]->close;

            $upMove = $curHigh - $prevHigh;
            $downMove = $prevLow - $curLow;

            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $tr[] = max(
                $curHigh - $curLow,
                abs($curHigh - $prevClose),
                abs($curLow - $prevClose)
            );
        }

        $window = min($period, count($tr));
        if ($window <= 0) {
            return null;
        }

        $sumTr = array_sum(array_slice($tr, -$window));
        if ($sumTr <= 0) {
            return null;
        }
        $sumPlus = array_sum(array_slice($plusDm, -$window));
        $sumMinus = array_sum(array_slice($minusDm, -$window));

        $plusDi = 100 * ($sumPlus / $sumTr);
        $minusDi = 100 * ($sumMinus / $sumTr);
        $den = max(0.0000001, $plusDi + $minusDi);
        $dx = 100 * (abs($plusDi - $minusDi) / $den);

        return $dx;
    }

    private function donchianUpper(Bot $bot, int $window): float
    {
        $provider = strtolower((string) ($bot->provider ?: $bot->exchange));
        $symbol = strtoupper((string) ($bot->asset_code ?: $bot->symbol));

        $candles = MarketOhlc::where('exchange', $provider)
            ->where('symbol', $symbol)
            ->where('timeframe', '1m')
            ->orderByDesc('ts')
            ->limit($window)
            ->get();

        if ($candles->isEmpty()) {
            return 0.0;
        }

        return (float) $candles->max('high');
    }

    private function saveParams(Bot $bot, array $params): void
    {
        $bot->strategy_params = $params;
        $bot->save();
    }
}
