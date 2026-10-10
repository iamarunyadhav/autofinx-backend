<?php

use App\Domain\QuickTradeManual\ManualTradeMath;

function qtmEstimate(array $over = []): array
{
    return ManualTradeMath::estimate(array_merge([
        'direction' => 'long',
        'stake' => 100.0,
        'leverage' => 10.0,
        'price' => 80000.0,
        'sl_pct' => 1.0,
        'tp_pct' => null,
        'tick_size' => 0.1,
        'step_size' => 0.001,
        'min_qty' => 0.001,
        'min_notional' => 100.0,
        'maint_margin_rate' => 0.004,
        'taker_fee_rate' => 0.0005,
        'slippage_buffer_pct' => 0.04,
        'sl_liq_ratio' => 0.5,
    ], $over));
}

test('stop-loss trigger is price-movement percent: entry 80000 and 1 percent gives 79200 long / 80800 short', function () {
    expect(ManualTradeMath::slTrigger('long', 80000.0, 1.0, 0.1))->toBe(79200.0)
        ->and(ManualTradeMath::slTrigger('short', 80000.0, 1.0, 0.1))->toBe(80800.0);
});

test('trigger prices round to the tick TOWARD entry (never farther than asked)', function () {
    // 80123.45 * 0.99 = 79322.2155 -> long SL rounds UP to the tick (closer to entry)
    expect(ManualTradeMath::slTrigger('long', 80123.45, 1.0, 0.1))->toBe(79322.3)
        // 80123.45 * 1.01 = 80924.6845 -> short SL rounds DOWN (closer to entry)
        ->and(ManualTradeMath::slTrigger('short', 80123.45, 1.0, 0.1))->toBe(80924.6)
        // long TP 2%: 81725.919 -> rounds DOWN toward entry
        ->and(ManualTradeMath::tpTrigger('long', 80123.45, 2.0, 0.1))->toBe(81725.9)
        // short TP 2%: 78520.981 -> rounds UP toward entry
        ->and(ManualTradeMath::tpTrigger('short', 80123.45, 2.0, 0.1))->toBe(78521.0);
});

test('exact tick multiples are not nudged by floating point error', function () {
    expect(ManualTradeMath::floorToStep(0.3, 0.1))->toBe(0.3)
        ->and(ManualTradeMath::ceilToStep(0.3, 0.1))->toBe(0.3)
        ->and(ManualTradeMath::floorToStep(79200.00000000001, 0.1))->toBe(79200.0);
});

test('quantity floors to the step and notional/margin follow stake x leverage', function () {
    $e = qtmEstimate();
    // 100 * 10 / 80000 = 0.0125 -> floor 0.012
    expect($e['quantity'])->toBe(0.012)
        ->and($e['notional'])->toBe(960.0)
        ->and($e['margin_required'])->toBe(96.0)
        ->and($e['sl_trigger_price'])->toBe(79200.0)
        ->and($e['sl_basis'])->toBe('price_movement')
        ->and($e['sl_distance_pct'])->toBe(1.0);
});

test('expected loss at stop includes both fees and is a margin percentage', function () {
    $e = qtmEstimate();
    // gross = 0.012 * 800 = 9.6 ; entry fee 960*0.0005=0.48 ; exit fee 0.012*79200*0.0005=0.4752
    expect($e['gross_loss_at_sl'])->toBe(9.6)
        ->and($e['entry_fee_estimate'])->toBe(0.48)
        ->and($e['exit_fee_estimate_at_sl'])->toBe(0.4752)
        ->and($e['estimated_loss_at_sl'])->toBe(10.5552)
        ->and($e['margin_loss_pct_at_sl'])->toBe(10.5552)   // stake 100 -> 10.5552 %
        ->and($e['ok'])->toBeTrue();
});

test('take-profit is optional and its net profit subtracts both fees', function () {
    expect(qtmEstimate()['take_profit'])->toBeNull();
    $e = qtmEstimate(['tp_pct' => 2.0]);
    // trigger 81600, gross 0.012*1600 = 19.2, entry 0.48, exit 0.012*81600*0.0005=0.4896
    expect($e['take_profit']['trigger_price'])->toBe(81600.0)
        ->and($e['take_profit']['gross_profit'])->toBe(19.2)
        ->and($e['take_profit']['net_profit'])->toBe(round(19.2 - 0.48 - 0.4896, 8));
});

test('a stop that is not safely inside the liquidation distance is refused', function () {
    // 50x: liquidation ~ 1/50 - 0.004 = 1.6 %; 1 % stop > 50 % of 1.6 % -> refused
    $e = qtmEstimate(['leverage' => 50.0, 'stake' => 100.0, 'price' => 80000.0]);
    $codes = array_column($e['errors'], 'code');
    expect($e['ok'])->toBeFalse()->and($codes)->toContain('stop_loss_not_inside_liquidation');
    // 10x: liquidation ~ 9.6 %, 1 % stop is inside
    expect(qtmEstimate()['ok'])->toBeTrue()->and(qtmEstimate()['estimated_liquidation_distance_pct'])->toBe(9.6);
});

test('unknown maintenance margin fails closed', function () {
    $e = qtmEstimate(['maint_margin_rate' => null]);
    expect($e['ok'])->toBeFalse()->and(array_column($e['errors'], 'code'))->toContain('unable_to_verify_liquidation_distance');
});

test('exchange minimums and degenerate stops are blocking errors', function () {
    expect(array_column(qtmEstimate(['stake' => 5.0])['errors'], 'code'))->toContain('notional_below_minimum');
    // 0.1 tick at price 0.5 with a 0.1 % stop rounds onto the entry price
    $e = qtmEstimate(['price' => 0.5, 'tick_size' => 0.1, 'sl_pct' => 0.1, 'min_notional' => 0.0, 'step_size' => 1.0, 'min_qty' => 1.0, 'stake' => 100.0]);
    expect(array_column($e['errors'], 'code'))->toContain('stop_loss_too_tight');
});

test('warnings never promise a maximum loss and mention funding, liquidation estimate and maintenance', function () {
    $w = implode(' ', qtmEstimate()['warnings']);
    expect($w)->toContain('does not guarantee a maximum loss')->and($w)->toContain('Funding')->and($w)->toContain('ESTIMATE')->and($w)->toContain('maintenance');
});

test('decimals follow the tick size', function () {
    expect(ManualTradeMath::decimalsOf(0.1))->toBe(1)->and(ManualTradeMath::decimalsOf(0.01))->toBe(2)->and(ManualTradeMath::decimalsOf(1.0))->toBe(0)
        ->and(ManualTradeMath::formatToStep(79200.0, 0.1))->toBe('79200.0');
});
