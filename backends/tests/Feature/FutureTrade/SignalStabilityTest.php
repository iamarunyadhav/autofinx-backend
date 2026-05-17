<?php

use App\Domain\FutureTrade\FutureTradeSignalStabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('signal_key is deterministic for identical inputs', function () {
    $svc = new FutureTradeSignalStabilityService();

    $a = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:30:00+00:00', ['x' => 1]);
    $b = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:30:00+00:00', ['x' => 1]);
    expect($a)->toBe($b);

    $c = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:35:00+00:00', ['x' => 1]);
    expect($a)->not->toBe($c);
});

test('client order id derived from signal_key is deterministic and fits 36 chars', function () {
    $svc = new FutureTradeSignalStabilityService();
    $key = str_repeat('a', 48);
    $entry = $svc->deterministicClientOrderId($key, 'entry');
    $exit = $svc->deterministicClientOrderId($key, 'exit');

    expect(strlen($entry))->toBeLessThanOrEqual(36);
    expect(strlen($exit))->toBeLessThanOrEqual(36);
    expect($entry)->not->toBe($exit);
    expect($svc->deterministicClientOrderId($key, 'entry'))->toBe($entry);
});

test('summarize returns WAIT when same-candle flip is observed', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:30:00+00:00';
    $buffer = [
        ['ts' => time() - 30, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.7],
        ['ts' => time() - 20, 'side' => 'SELL', 'candle' => $candle, 'confidence' => 0.7],
        ['ts' => time() - 10, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.7],
    ];
    $state = $svc->summarize($buffer, 'BUY', $candle);
    expect($state['sameCandleFlip'])->toBeTrue()
        ->and($state['executable'])->toBe('WAIT')
        ->and($state['reason'])->toBe('same_candle_flip_unstable');
});

test('summarize confirms only after enough consecutive scans on same side', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:30:00+00:00';

    $single = $svc->summarize([
        ['ts' => time(), 'side' => 'SELL', 'candle' => $candle, 'confidence' => 0.7],
    ], 'SELL', $candle);
    expect($single['executable'])->toBe('WAIT')
        ->and($single['reason'])->toBe('awaiting_confirmation_scans');

    $double = $svc->summarize([
        ['ts' => time() - 10, 'side' => 'SELL', 'candle' => $candle, 'confidence' => 0.7],
        ['ts' => time(), 'side' => 'SELL', 'candle' => $candle, 'confidence' => 0.7],
    ], 'SELL', $candle);
    expect($double['executable'])->toBe('SELL')
        ->and($double['confirmedScans'])->toBeGreaterThanOrEqual(2);
});
