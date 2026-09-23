<?php

use App\Domain\Trading\TradeForecastService;

/**
 * Covers the decision_trend/netEdge() cost-gate alignment fix: the
 * BUY/SELL badge (decision_trend) used to require only a 1x round-trip
 * cost clearance while netEdge()'s "tradeable" gate already required 2x
 * (`robot_trade.live_min_edge_cost_multiplier`, default 2.0) -- the same
 * forecast could show BUY while the Profit Calculator on that exact
 * forecast said "below live safety threshold". decision_trend now reuses
 * the identical config key/multiplier netEdge() already reads, so a move
 * between 1x and 2x cost is WAIT on both, and a move clearing 2x cost is
 * still a confident BUY/SELL exactly as before.
 *
 * Fixture math (all with `atr_pct` pinned via the `$volatility` param so
 * the numbers are deterministic, horizon_seconds=60 on a 1m timeframe ->
 * horizonCandles=1 -> rangePct == atr_pct):
 *   fee_rate default 0.0005, slippage_buffer_pct default 0.04
 *     -> costPct = (0.0005*2*100) + 0.04 = 0.14%
 *     -> 1x cost = 0.14%, 2x cost (default multiplier) = 0.28%
 *   A strongly-aligned bullish signal (all-bullish candles + a matching
 *   BUY decision at high confidence) drives trend_score/drift_score/
 *   signal_score all to 1.0, i.e. expiryScore = 1.0, so
 *   displacementPct = 1.0 * atr_pct * 0.55:
 *     atr_pct=0.4  -> displacementPct=0.22% (clears 1x=0.14%, NOT 2x=0.28%)
 *     atr_pct=1.0  -> displacementPct=0.55% (clears both 1x and 2x)
 *   In both cases rangePct*0.12 (0.048 / 0.12) is well below the cost
 *   floor, so the cost multiplier -- not the volatility floor -- is what's
 *   under test.
 */
function decisionTrendCostGateCandles(int $count = 40, float $start = 100.0, float $volume = 1000.0, float $step = 1.0): array
{
    $candles = [];
    $price = $start;
    for ($i = 0; $i < $count; $i++) {
        $open = $price;
        $close = $open + $step;
        $candles[] = [
            'open' => $open,
            'high' => $close + ($step * 0.2),
            'low' => $open - ($step * 0.2),
            'close' => $close,
            'volume' => $volume,
        ];
        $price = $close;
    }

    return $candles;
}

it('still returns a confident BUY when the expected move clears the full 2x safety margin', function () {
    $result = app(TradeForecastService::class)->forecast(
        decisionTrendCostGateCandles(), '1m', 60,
        ['action' => 'BUY', 'confidence' => 90], ['atr_pct' => 1.0]
    );

    expect($result['predicted_move_pct'])->toBe(0.55);
    expect($result['decision_trend'])->toBe('BUY');
});

it('returns WAIT, not BUY, for a move that clears only 1x cost but not netEdge()\'s 2x safety margin', function () {
    $result = app(TradeForecastService::class)->forecast(
        decisionTrendCostGateCandles(), '1m', 60,
        ['action' => 'BUY', 'confidence' => 90], ['atr_pct' => 0.4]
    );

    // 0.22% clears the raw 1x round-trip cost (0.14%) -- the OLD gate would
    // have shown BUY here -- but does not clear the 2x safety margin
    // (0.28%) netEdge()'s tradeable gate already required for this exact
    // move. The two must now agree: WAIT.
    expect($result['predicted_move_pct'])->toBe(0.22);
    expect($result['decision_trend'])->toBe('WAIT');
});

it('reads the same robot_trade.live_min_edge_cost_multiplier config key netEdge() uses, not a hardcoded 2.0', function () {
    config(['robot_trade.live_min_edge_cost_multiplier' => 1.0]);

    $result = app(TradeForecastService::class)->forecast(
        decisionTrendCostGateCandles(), '1m', 60,
        ['action' => 'BUY', 'confidence' => 90], ['atr_pct' => 0.4]
    );

    // Same 0.22% move as above, but with the multiplier explicitly lowered
    // back to 1x -- decision_trend must track the config value dynamically,
    // exactly like netEdge()'s required_move_pct does.
    expect($result['predicted_move_pct'])->toBe(0.22);
    expect($result['decision_trend'])->toBe('BUY');
});

it('agrees with netEdge()\'s own tradeable verdict on the same borderline-between-1x-and-2x move', function () {
    // Same candle shape as above but a smaller step (0.3 instead of 1.0),
    // scaled so the CANDLES' OWN real ATR (no pinned volatility override --
    // both forecast() and netEdge() derive their volatility from these
    // exact same candles here) lands in the same 1x-2x cost band.
    $candles = decisionTrendCostGateCandles(40, 100.0, 1000.0, 0.3);
    $service = app(TradeForecastService::class);

    $forecast = $service->forecast($candles, '1m', 60, ['action' => 'BUY', 'confidence' => 90]);
    $netEdge = $service->netEdge($candles, 100.0);

    // Both must reject this exact move -- no more contradictory
    // BUY-badge-but-Profit-Calculator-says-no.
    expect($forecast['predicted_move_pct'])->toBe(0.2063);
    expect($forecast['decision_trend'])->toBe('WAIT');
    expect($netEdge['required_move_pct'])->toBe(0.28);
    expect($netEdge['expected_move_pct'])->toBe(0.1313);
    expect($netEdge['tradeable'])->toBeFalse();
});
