<?php

namespace App\Domain\Trading;

/**
 * Single source of truth for Quick Trade's fee/slippage economics.
 *
 * Every number here already existed inline in TradeForecastService
 * (forecast()'s decision_trend gate and netEdge()); this class extracts them
 * so the forecast payload, the UI duration-availability list, the research
 * export and the bucketed accuracy report all quote the SAME numbers rather
 * than each re-deriving them. It reads exactly the same four config keys,
 * with exactly the same defaults, as the code it replaces:
 *
 *   trading.binance_futures_taker_fee_rate      default 0.0005  (0.05%)
 *   robot_trade.live_slippage_buffer_pct        default 0.04    (0.04%)
 *   robot_trade.live_min_edge_cost_multiplier   default 2.0
 *   robot_trade.live_min_expected_net_usdt      default 0.03
 *
 * Verified 2026-09-23: none of `config/trading.php`, `config/robot_trade.php`
 * exists in this repo, and no .env key overrides them, so the inline defaults
 * above ARE the production values.
 *
 * NOTHING here lowers `live_min_edge_cost_multiplier` or the 0.55
 * displacement factor (spec C7) -- they are read, never redefined.
 */
class QuickTradeCostModel
{
    /**
     * The fraction of the volatility range the forecast model can displace
     * price by at a perfect (|expiryScore| == 1.0) read. This mirrors
     * TradeForecastService::forecast()'s own `$expiryScore * $rangePct * 0.55`
     * and MUST stay in sync with it -- it is the reason the §0.1 "max
     * achievable move" table is reproducible.
     */
    public const DISPLACEMENT_RANGE_FACTOR = 0.55;

    public const FEE_MODEL_TAKER = 'taker';

    public const FEE_MODEL_MAKER_ENTRY = 'maker_entry';

    /**
     * Round-trip cost, as a percentage of notional, for a fee model.
     *
     * taker       : taker fee x 2 legs + the full slippage buffer.
     * maker_entry : maker fee (post-only limit entry) + taker fee (market
     *               exit) + the full slippage buffer. The buffer is kept in
     *               full deliberately: the spec's own worked example
     *               ("maker entry ~= 0.22% required") is only reproducible
     *               with the whole buffer retained, and keeping it is the
     *               conservative choice for a real-money gate. A post-only
     *               entry can also simply not fill, which is a cost this
     *               percentage does not capture at all -- see
     *               `limit_unfilled` in the research snapshot.
     *
     * @return array{fee_model:string, fee_rate_entry:float, fee_rate_exit:float,
     *               slippage_buffer_pct:float, cost_pct:float,
     *               cost_multiplier:float, required_move_pct:float,
     *               min_expected_net_usdt:float, fee_rate_source:string}
     */
    public function costs(?string $feeModel = null, ?float $accountFeeRate = null): array
    {
        $feeModel = $this->normalizeFeeModel($feeModel);
        $configuredTaker = max(0.0, (float) config('trading.binance_futures_taker_fee_rate', 0.0005));
        // C6: prefer the account's real fee tier when the caller could read
        // it from the exchange; fall back to config, and say which was used.
        $takerRate = $accountFeeRate !== null && $accountFeeRate >= 0.0 ? $accountFeeRate : $configuredTaker;
        $feeRateSource = $accountFeeRate !== null && $accountFeeRate >= 0.0 ? 'account' : 'config';
        $makerRate = max(0.0, (float) config('quick_trade.maker_fee_rate', 0.0002));
        $slippageBufferPct = max(0.0, (float) config('robot_trade.live_slippage_buffer_pct', 0.04));

        $entryRate = $feeModel === self::FEE_MODEL_MAKER_ENTRY ? $makerRate : $takerRate;
        $costPct = (($entryRate + $takerRate) * 100) + $slippageBufferPct;
        $multiplier = max(1.0, (float) config('robot_trade.live_min_edge_cost_multiplier', 2.0));

        return [
            'fee_model' => $feeModel,
            'fee_rate_entry' => round($entryRate, 8),
            'fee_rate_exit' => round($takerRate, 8),
            'slippage_buffer_pct' => round($slippageBufferPct, 6),
            'cost_pct' => round($costPct, 6),
            'cost_multiplier' => $multiplier,
            'required_move_pct' => round($costPct * $multiplier, 6),
            'min_expected_net_usdt' => max(0.0, (float) config('robot_trade.live_min_expected_net_usdt', 0.03)),
            'fee_rate_source' => $feeRateSource,
        ];
    }

    /**
     * The largest expiry displacement the forecast model can EVER produce at
     * this volatility, i.e. the value at a perfect |expiryScore| = 1.0.
     *
     * Reproduces the spec's §0.1 table exactly:
     *   rangePct = min(5.0, atrPct * sqrt(horizonCandles))   [same cap as forecast()]
     *   max      = rangePct * 0.55
     */
    public function maxAchievableMovePct(float $atrPct, int $horizonCandles): float
    {
        $atrPct = max(0.0, $atrPct);
        $horizonCandles = max(1, $horizonCandles);
        $rangePct = min(5.0, $atrPct * sqrt($horizonCandles));

        return $rangePct * self::DISPLACEMENT_RANGE_FACTOR;
    }

    /** Chart candles that fit inside the trade expiry -- forecast()'s own formula. */
    public function horizonCandles(int $horizonSeconds, int $timeframeSeconds): int
    {
        return max(1, (int) ceil(max(1, $horizonSeconds) / max(1, $timeframeSeconds)));
    }

    /**
     * The minimum |expiryScore| that could clear the cost gate at this
     * volatility, or null when no score can (max achievable < required).
     */
    public function minimumViableScore(float $atrPct, int $horizonCandles, ?string $feeModel = null): ?float
    {
        $max = $this->maxAchievableMovePct($atrPct, $horizonCandles);
        if ($max <= 0.0) {
            return null;
        }
        $required = $this->costs($feeModel)['required_move_pct'];
        $score = $required / $max;

        return $score > 1.0 ? null : round($score, 4);
    }

    /**
     * C4 -- which constraint is ACTUALLY binding when a trade looks
     * unprofitable. The old UI text ("try a longer duration or a bigger
     * stake") was misleading: fees and PnL both scale with notional, so a
     * bigger stake can only ever clear the ABSOLUTE min-expected-net floor,
     * never the percentage cost gate.
     *
     * @param  float  $maxAchievableMovePct  from maxAchievableMovePct()
     * @param  float|null  $expectedNetUsdt   netEdge()'s expected_net_usdt, when known
     * @param  float|null  $accountFeeRate    optional, additive: see costs()
     * @return array{constraint:string, hint:string}
     */
    public function bindingConstraint(
        float $maxAchievableMovePct,
        float $expectedMovePct,
        ?float $expectedNetUsdt = null,
        ?string $feeModel = null,
        ?float $accountFeeRate = null,
    ): array {
        $costs = $this->costs($feeModel, $accountFeeRate);
        $required = $costs['required_move_pct'];

        if ($maxAchievableMovePct < $required) {
            return [
                'constraint' => 'duration',
                'hint' => sprintf(
                    'A longer duration is needed. At the current volatility this chart/expiry can move at most %.3f%%, but %.3f%% is required to clear fees and slippage. A bigger stake cannot help — fees scale with the stake.',
                    $maxAchievableMovePct,
                    $required,
                ),
            ];
        }

        if ($expectedMovePct < $required) {
            return [
                'constraint' => 'signal',
                'hint' => sprintf(
                    'The current signal is too weak, not the duration. This expiry could clear the %.3f%% requirement, but the model only expects %.3f%%. A bigger stake cannot help — fees scale with the stake.',
                    $required,
                    $expectedMovePct,
                ),
            ];
        }

        if ($expectedNetUsdt !== null && $expectedNetUsdt < $costs['min_expected_net_usdt']) {
            return [
                'constraint' => 'stake',
                'hint' => sprintf(
                    'A larger stake is needed. The percentage edge is fine, but the expected net of %.4f USDT is below the %.4f USDT absolute floor — that floor is the only thing a bigger stake fixes.',
                    $expectedNetUsdt,
                    $costs['min_expected_net_usdt'],
                ),
            ];
        }

        return ['constraint' => 'none', 'hint' => 'This duration and stake clear the fee/slippage requirement at the current signal.'];
    }

    /**
     * C2/C3 -- per-duration availability at the current volatility, for the
     * live duration picker. Never hardcodes a percentage in the UI: the
     * frontend renders `reason` and the numbers verbatim.
     *
     * @param  array<int>  $durationSeconds
     * @return array<int, array{expiry_seconds:int, horizon_candles:int, max_achievable_move_pct:float,
     *          required_move_pct:float, available:bool, reason:string|null, short_live_expiry:bool}>
     */
    public function durationAvailability(
        array $durationSeconds,
        float $atrPct,
        int $timeframeSeconds,
        string $mode,
        ?string $feeModel = null,
        ?float $accountFeeRate = null,
    ): array {
        $costs = $this->costs($feeModel, $accountFeeRate);
        $required = $costs['required_move_pct'];
        $isLive = strtolower($mode) === 'live';
        $allowShort = (bool) config('quick_trade.allow_short_live_expiry', false);
        $shortThreshold = (int) config('quick_trade.short_live_expiry_threshold_seconds', 900);

        $rows = [];
        foreach ($durationSeconds as $seconds) {
            $seconds = (int) $seconds;
            $horizonCandles = $this->horizonCandles($seconds, $timeframeSeconds);
            $max = $this->maxAchievableMovePct($atrPct, $horizonCandles);
            $isShort = $seconds < $shortThreshold;
            $available = true;
            $reason = null;

            if ($isLive && $isShort && ! $allowShort) {
                $available = false;
                $reason = sprintf('Hidden in live mode: expiries under %dm cannot clear fees at realistic volatility.', (int) ($shortThreshold / 60));
            } elseif ($isLive && $max < $required) {
                $available = false;
                $reason = sprintf('Max possible %.3f%% < required %.3f%% at current volatility', $max, $required);
            } elseif ($isLive && $horizonCandles < (int) config('quick_trade.min_horizon_candles', 3)) {
                $available = false;
                $reason = sprintf(
                    'Only %d chart candle(s) fit inside this expiry; live mode requires at least %d.',
                    $horizonCandles,
                    (int) config('quick_trade.min_horizon_candles', 3),
                );
            }

            $rows[] = [
                'expiry_seconds' => $seconds,
                'horizon_candles' => $horizonCandles,
                'max_achievable_move_pct' => round($max, 4),
                'required_move_pct' => round($required, 4),
                'available' => $available,
                'reason' => $reason,
                'short_live_expiry' => $isShort,
            ];
        }

        return $rows;
    }

    /**
     * C3 -- the shortest expiry in the supplied list that is actually
     * capable of clearing the cost gate at this volatility. Null when none
     * is (an honest "nothing here works right now", never a guess).
     */
    public function shortestViableExpirySeconds(array $durationSeconds, float $atrPct, int $timeframeSeconds, ?string $feeModel = null, ?float $accountFeeRate = null): ?int
    {
        $required = $this->costs($feeModel, $accountFeeRate)['required_move_pct'];
        $viable = [];
        foreach ($durationSeconds as $seconds) {
            $seconds = (int) $seconds;
            $max = $this->maxAchievableMovePct($atrPct, $this->horizonCandles($seconds, $timeframeSeconds));
            if ($max >= $required) {
                $viable[] = $seconds;
            }
        }

        return $viable === [] ? null : min($viable);
    }

    /**
     * C5 -- maker_entry is paper/shadow only. Live mode always falls back to
     * the taker model, explicitly and visibly, rather than silently pricing
     * a live gate off a fill rate nobody has measured yet.
     */
    public function resolveFeeModelForMode(string $mode, ?string $requested = null): string
    {
        $model = $this->normalizeFeeModel($requested);
        if ($model === self::FEE_MODEL_MAKER_ENTRY
            && strtolower($mode) === 'live'
            && ! (bool) config('quick_trade.allow_maker_entry_in_live', false)) {
            return self::FEE_MODEL_TAKER;
        }

        return $model;
    }

    private function normalizeFeeModel(?string $feeModel): string
    {
        $model = strtolower(trim((string) ($feeModel ?? config('quick_trade.fee_model', self::FEE_MODEL_TAKER))));

        return $model === self::FEE_MODEL_MAKER_ENTRY ? self::FEE_MODEL_MAKER_ENTRY : self::FEE_MODEL_TAKER;
    }
}
