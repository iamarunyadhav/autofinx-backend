<?php

use App\Domain\QuickTradeManual\ManualPreviewService;

function qtmNormalize(array $over = [], bool $ack = true): array
{
    return app(ManualPreviewService::class)->normalizeInput(array_merge([
        'symbol' => 'BTCUSDT', 'direction' => 'long', 'stake_amount' => 100, 'leverage' => 5, 'sl_pct' => 1,
    ], $over), $ack);
}

function qtmNormalizeCode(array $over = [], bool $ack = true): ?string
{
    try {
        qtmNormalize($over, $ack);
    } catch (RuntimeException $e) {
        return strtok($e->getMessage(), ':');
    }

    return null;
}

test('stop-loss input is unchanged: required, bounded, returned as before', function () {
    expect(qtmNormalize()['sl_pct'])->toBe(1.0)->and(qtmNormalize()['no_stop_loss'])->toBeFalse()
        ->and(qtmNormalizeCode(['sl_pct' => null]))->toBe('stop_loss_required')
        ->and(qtmNormalizeCode(['sl_pct' => '']))->toBe('stop_loss_required')
        ->and(qtmNormalizeCode(['sl_pct' => 'abc']))->toBe('stop_loss_required')
        ->and(qtmNormalizeCode(['sl_pct' => 99]))->toBe('invalid_stop_loss');
});

test('a missing stop-loss is never inferred as the no-stop opt-in', function () {
    config()->set('quick_trade_manual.allow_no_stop_loss', true);
    expect(qtmNormalizeCode(['sl_pct' => null, 'no_stop_loss_ack' => true]))->toBe('stop_loss_required')
        ->and(qtmNormalizeCode(['sl_pct' => null, 'no_stop_loss' => false, 'no_stop_loss_ack' => true]))->toBe('stop_loss_required')
        ->and(qtmNormalizeCode(['sl_pct' => null, 'no_stop_loss' => 'no', 'no_stop_loss_ack' => true]))->toBe('stop_loss_required')
        ->and(qtmNormalizeCode(['sl_pct' => null, 'no_stop_loss' => 0, 'no_stop_loss_ack' => true]))->toBe('stop_loss_required');
});

test('no-stop opt-in: needs the server flag, the acknowledgement, no stop-loss value, no trailing, and the lower leverage cap', function () {
    $optIn = ['sl_pct' => null, 'no_stop_loss' => true, 'no_stop_loss_ack' => true];

    config()->set('quick_trade_manual.allow_no_stop_loss', false);
    expect(qtmNormalizeCode($optIn))->toBe('no_stop_loss_disabled');

    config()->set('quick_trade_manual.allow_no_stop_loss', true);
    $ok = qtmNormalize($optIn);
    expect($ok['no_stop_loss'])->toBeTrue()->and($ok['sl_pct'])->toBeNull()
        ->and(qtmNormalizeCode(array_merge($optIn, ['no_stop_loss_ack' => false])))->toBe('no_stop_loss_ack_required')
        ->and(qtmNormalizeCode(array_merge($optIn, ['no_stop_loss_ack' => 'false'])))->toBe('no_stop_loss_ack_required')
        ->and(qtmNormalizeCode(array_merge($optIn, ['sl_pct' => 1])))->toBe('stop_loss_conflict')
        ->and(qtmNormalizeCode(array_merge($optIn, ['leverage' => 6])))->toBe('no_stop_loss_leverage_too_high')
        ->and(qtmNormalizeCode(array_merge($optIn, ['trailing_pct' => 1])))->toBe('trailing_requires_stop_loss');
    // preview mode: the ack is not required for a read-only preview, the flag still is
    expect(qtmNormalizeCode($optIn + ['no_stop_loss_ack' => false], false))->toBeNull();
});

test('take-profit is validated exactly as before with no stop', function () {
    config()->set('quick_trade_manual.allow_no_stop_loss', true);
    $optIn = ['sl_pct' => null, 'no_stop_loss' => true, 'no_stop_loss_ack' => true];
    expect(qtmNormalize(array_merge($optIn, ['tp_pct' => 2]))['tp_pct'])->toBe(2.0)
        ->and(qtmNormalizeCode(array_merge($optIn, ['tp_pct' => -1])))->toBe('invalid_take_profit');
});

test('protection pairs normalize independently and no-stop trades cannot request them', function () {
    $pairs = qtmNormalize(['protection_pairs' => [
        ['tp_mode' => 'percent', 'tp_value' => 2, 'sl_mode' => 'fixed', 'sl_input_mode' => 'percent', 'sl_value' => 0.5],
        ['tp_mode' => 'price', 'tp_value' => 90000, 'sl_mode' => 'trailing', 'sl_input_mode' => 'distance', 'sl_value' => 250],
    ]])['protection_pairs'];
    config()->set('quick_trade_manual.allow_no_stop_loss', true);
    expect($pairs)->toHaveCount(2)->and($pairs[1]['tp_mode'])->toBe('price')->and($pairs[1]['sl_mode'])->toBe('trailing')
        ->and(qtmNormalizeCode(['protection_pairs' => [['tp_value' => 2, 'sl_value' => 1]], 'no_stop_loss' => true, 'no_stop_loss_ack' => true, 'sl_pct' => null]))->toBe('protection_pairs_require_stop_loss');
});
