<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ftLiveCloseConfirmed(array $orderResponse, array $postCloseExchange): bool
{
    $svc = app(FutureTradeBotService::class);
    $method = new ReflectionMethod($svc, 'liveCloseConfirmed');
    $method->setAccessible(true);

    return (bool) $method->invoke($svc, $orderResponse, $postCloseExchange);
}

test('live close is not confirmed from a partial zero-position snapshot while order is still new', function () {
    $confirmed = ftLiveCloseConfirmed([
        'status' => 'NEW',
        'executedQty' => '0.000',
    ], [
        'quantity' => 0,
        'snapshot_partial' => true,
    ]);

    expect($confirmed)->toBeFalse();
});

test('live close can be confirmed by a filled order or a full flat snapshot', function () {
    expect(ftLiveCloseConfirmed([
        'status' => 'FILLED',
        'executedQty' => '0.050',
    ], [
        'quantity' => 0.05,
        'snapshot_partial' => true,
    ]))->toBeTrue();

    expect(ftLiveCloseConfirmed([
        'status' => 'NEW',
        'executedQty' => '0.000',
    ], [
        'quantity' => 0,
        'snapshot_partial' => false,
    ]))->toBeTrue();
});
